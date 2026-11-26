<?php
/** Native booking acceptance, per-profile serialization, holds and Woo reconciliation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Calendar_Booking {
    const HOLD_SECONDS = 1200;
    const EXPIRE_HOOK = 'dendrila_calendar_expire_booking';
    const SYNC_HOOK = 'dendrila_calendar_booking_sync';
    private static $locks = array();
    private static $reconciling = array();
    private static $request_lock = '';

    public static function boot() {
        foreach ( array( 'slots', 'create', 'cancel' ) as $action ) {
            add_action( 'wp_ajax_dendrila_booking_' . $action, array( __CLASS__, 'ajax_' . $action ) );
            add_action( 'wp_ajax_nopriv_dendrila_booking_' . $action, array( __CLASS__, 'ajax_' . $action ) );
        }
        add_action( self::EXPIRE_HOOK, array( __CLASS__, 'expire' ) );
        add_action( self::SYNC_HOOK, array( __CLASS__, 'sync' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
        add_action( 'admin_post_dendrila_booking_cancel_admin', array( __CLASS__, 'admin_cancel' ) );
        add_filter( 'dendrila_calendar_sources', array( __CLASS__, 'add_source' ) );
        add_filter( 'dendrila_calendar_source_events_bookings', array( __CLASS__, 'source_events' ), 10, 3 );
    }

    public static function public_service( $id ) {
        $service = Dendrila_Calendar_Native::service_data( $id );
        if ( ! $service ) { return false; }
        $profile = Dendrila_Calendar_Schedule::profile( $service['profile_id'] );
        if ( ! $profile['enabled'] || ! Dendrila_Calendar_Schedule::rules( $id )['enabled'] ) { return false; }
        $paid = Dendrila_Calendar_Woo::requires_payment( $service );
        if ( $paid && ! Dendrila_Calendar_Woo::product_for_service( $id ) ) { return false; }
        if ( ! $paid && $service['price_cents'] > 0 ) { return false; }
        $bounds = Dendrila_Calendar_Schedule::date_bounds( $id );
        return array_merge( $bounds, array(
            'service_id' => (int) $id, 'profile_id' => $service['profile_id'],
            'title' => get_the_title( $id ), 'profile_title' => get_the_title( $service['profile_id'] ),
            'timezone' => $profile['timezone'], 'duration_minutes' => $service['duration_minutes'],
            'requires_payment' => $paid, 'price_text' => $paid ? Dendrila_Calendar_Woo::price_text( $id ) : __( 'Free', 'dendrila-calendar' ),
        ) );
    }

    public static function public_services() {
        $out = array();
        foreach ( get_posts( array( 'post_type' => Dendrila_Calendar_Native::SERVICE, 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $post ) {
            $service = self::public_service( $post->ID );
            if ( $service ) { $out[] = $service; }
        }
        return $out;
    }

    /** Same lock for every service, slot and daily/monthly quota of a profile. */
    public static function with_profile_lock( $profile_id, $callback ) {
        global $wpdb;
        $profile_id = absint( $profile_id );
        if ( ! $profile_id || ! is_callable( $callback ) || ! isset( $wpdb ) ) { return self::lock_error(); }
        if ( isset( self::$locks[ $profile_id ] ) ) {
            if ( ! self::owns_lock( $profile_id ) ) { return self::lock_error(); }
            return call_user_func( $callback );
        }
        $name = 'dc_booking_' . substr( hash( 'sha256', $wpdb->prefix . '|' . DB_NAME . '|' . $profile_id ), 0, 48 );
        // Connection-scoped SQL lock is released on disconnect/fatal error; never infer success from a transient.
        $acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $name ) );
        if ( '1' !== (string) $acquired ) { return self::lock_error(); }
        self::$locks[ $profile_id ] = $name;
        try {
            // This PHP request may have looked up slots before waiting. Another request's
            // commit does not refresh this process's nonpersistent WP_Query cache.
            wp_cache_set_posts_last_changed();
            wp_cache_delete( $profile_id, 'post_meta' );
            return call_user_func( $callback );
        } finally {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
            unset( self::$locks[ $profile_id ] );
        }
    }

    public static function owns_lock( $profile_id ) {
        global $wpdb;
        if ( ! isset( self::$locks[ $profile_id ] ) ) { return false; }
        // Also detect an automatic database reconnect during a remote availability check.
        return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::$locks[ $profile_id ] ) );
    }

    private static function lock_error() {
        return new WP_Error( 'dendrila_booking_busy', __( 'Another reservation is being processed. Please try again in a moment.', 'dendrila-calendar' ) );
    }

    private static function input( $key, $source = null ) {
        $source = null === $source ? $_POST : $source;
        return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? trim( (string) wp_unslash( $source[ $key ] ) ) : '';
    }

    private static function ajax_guard( $kind ) {
        nocache_headers();
        if ( ! wp_verify_nonce( self::input( 'nonce' ), 'dendrila_booking_public' ) ) {
            self::send_error( new WP_Error( 'dendrila_nonce_expired', __( 'Reload this page before checking availability or booking.', 'dendrila-calendar' ) ), 403 );
        }
        // A short, hashed network bucket stores neither addresses nor customer data.
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $key = 'dc_public_' . substr( hash_hmac( 'sha256', $kind . '|' . $ip . '|' . (int) floor( time() / 60 ), wp_salt( 'nonce' ) ), 0, 32 );
        $count = (int) get_transient( $key );
        if ( $count >= ( 'slots' === $kind ? 60 : 12 ) ) { self::send_error( new WP_Error( 'dendrila_rate_limit', __( 'Please wait a minute before trying again.', 'dendrila-calendar' ) ), 429 ); }
        set_transient( $key, $count + 1, 70 );
    }

    private static function send_error( $error, $status = 400 ) {
        if ( in_array( $error->get_error_code(), array( 'dendrila_booking_busy', 'dendrila_provider_unavailable', 'dendrila_booking_limit', 'dendrila_booking_corrupt' ), true ) ) { $status = 503; }
        if ( 'dendrila_slot_taken' === $error->get_error_code() ) { $status = 409; }
        wp_send_json_error( array( 'message' => $error->get_error_message(), 'code' => $error->get_error_code() ), $status );
    }

    public static function ajax_slots() {
        self::ajax_guard( 'slots' );
        $id = absint( self::input( 'service_id' ) ); $service = self::public_service( $id );
        if ( ! $service ) { self::send_error( new WP_Error( 'dendrila_service_closed', __( 'This service is not open for online booking.', 'dendrila-calendar' ) ) ); }
        $slots = Dendrila_Calendar_Schedule::slots( $id, self::input( 'date' ) );
        if ( is_wp_error( $slots ) ) { self::send_error( $slots ); }
        wp_send_json_success( array( 'slots' => $slots, 'timezone' => $service['timezone'], 'service_title' => $service['title'], 'profile_title' => $service['profile_title'] ) );
    }

    public static function ajax_create() {
        self::ajax_guard( 'create' );
        $result = self::create( $_POST );
        if ( is_wp_error( $result ) ) { self::send_error( $result ); }
        wp_send_json_success( $result );
    }

    /** Public create contract. No caller may bypass the availability check or the shared lock. */
    public static function create( $data ) {
        global $wpdb;
        $request = is_array( $data ) ? self::input( 'request_id', $data ) : '';
        if ( ! preg_match( '/^[A-Za-z0-9_-]{20,80}$/D', $request ) ) { return new WP_Error( 'dendrila_booking_invalid', __( 'Check your booking details.', 'dendrila-calendar' ) ); }
        $name = 'dc_request_' . substr( hash( 'sha256', $wpdb->prefix . '|' . DB_NAME . '|' . $request ), 0, 48 );
        if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $name ) ) ) { return self::lock_error(); }
        self::$request_lock = $name;
        try { return self::create_locked( $data ); }
        finally { $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); self::$request_lock = ''; }
    }

    private static function owns_request_lock() {
        global $wpdb;
        return self::$request_lock && '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::$request_lock ) );
    }

    private static function create_locked( $data ) {
        if ( ! is_array( $data ) ) { return new WP_Error( 'dendrila_booking_invalid', __( 'Check your booking details.', 'dendrila-calendar' ) ); }
        $id = absint( self::input( 'service_id', $data ) );
        $name = sanitize_text_field( self::input( 'name', $data ) );
        $email = sanitize_email( self::input( 'email', $data ) );
        $request = self::input( 'request_id', $data ); $iso = self::input( 'start', $data );
        if ( '' === $name || strlen( $name ) > 200 || ! is_email( $email ) || strlen( $email ) > 254 ||
             '1' !== self::input( 'consent', $data ) || '' !== self::input( 'website', $data ) ||
             ! preg_match( '/^[A-Za-z0-9_-]{20,80}$/D', $request ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00Z$/D', $iso ) ) {
            return new WP_Error( 'dendrila_booking_invalid', __( 'Enter your name, a valid email and an available time, then accept the privacy information.', 'dendrila-calendar' ) );
        }
        $service = Dendrila_Calendar_Native::service_data( $id );
        if ( ! $service ) { return new WP_Error( 'dendrila_service_closed', __( 'This service is not open for online booking.', 'dendrila-calendar' ) ); }
        $hash = hash_hmac( 'sha256', $request, wp_salt( 'auth' ) );
        $customer = array( 'name' => $name, 'email' => $email );
        $start = str_replace( array( 'T', 'Z' ), array( ' ', '' ), $iso );
        $end = gmdate( 'Y-m-d H:i:s', strtotime( $iso ) + $service['duration_minutes'] * 60 );
        $expected_service = $service;
        $booking_id = self::with_profile_lock( $service['profile_id'], static function () use ( $id, $hash, $customer, $start, $end, $expected_service ) {
            wp_cache_delete( $id, 'post_meta' );
            $service = Dendrila_Calendar_Native::service_data( $id );
            if ( ! $service ) { return new WP_Error( 'dendrila_service_closed', __( 'This service is not open for online booking.', 'dendrila-calendar' ) ); }
            if ( $service !== $expected_service ) { return new WP_Error( 'dendrila_schedule_changed', __( 'The availability settings changed. Reload the available times before booking.', 'dendrila-calendar' ) ); }
            $existing = get_posts( array( 'post_type' => Dendrila_Calendar_Native::BOOKING, 'post_status' => array( 'private', 'publish' ), 'numberposts' => 2,
                'meta_key' => '_dendrila_booking_request_hash', 'meta_value' => $hash ) );
            if ( $existing ) {
                $old = (int) $existing[0]->ID;
                wp_cache_delete( $old, 'post_meta' );
                if ( 1 !== count( $existing ) || (int) get_post_meta( $old, Dendrila_Calendar_Native::SERVICE_KEY, true ) !== $id ||
                     get_post_meta( $old, Dendrila_Calendar_Native::START_KEY, true ) !== $start ||
                     get_post_meta( $old, '_dendrila_booking_email', true ) !== $customer['email'] ||
                     get_post_meta( $old, '_dendrila_booking_name', true ) !== $customer['name'] ) {
                    return new WP_Error( 'dendrila_request_reused', __( 'Reload the form before starting a different reservation.', 'dendrila-calendar' ) );
                }
                $status = get_post_meta( $old, Dendrila_Calendar_Native::STATUS_KEY, true );
                if ( ! in_array( $status, array( 'confirmed', 'pending_payment', 'payment_on_hold' ), true ) ||
                     ( 'pending_payment' === $status && (int) get_post_meta( $old, '_dendrila_booking_hold_until', true ) <= time() ) ) {
                    return new WP_Error( 'dendrila_hold_expired', __( 'This reservation has expired or been cancelled. Select a new time.', 'dendrila-calendar' ) );
                }
                return $old;
            }
            if ( ! self::public_service( $id ) ) { return new WP_Error( 'dendrila_service_closed', __( 'This service is not open for online booking.', 'dendrila-calendar' ) ); }
            $valid = Dendrila_Calendar_Native::validate_booking( $id, $service['profile_id'], $start, $end );
            if ( is_wp_error( $valid ) ) { return $valid; }
            $profile_before = Dendrila_Calendar_Schedule::profile( $service['profile_id'] );
            $rules_before = Dendrila_Calendar_Schedule::rules( $id );
            $valid = Dendrila_Calendar_Schedule::validate_slot( $id, $start, $end );
            if ( is_wp_error( $valid ) ) { return $valid; }
            // Availability calls may wait on providers. Reject settings changed during that wait.
            wp_cache_delete( $id, 'post_meta' );
            wp_cache_delete( $service['profile_id'], 'post_meta' );
            clean_post_cache( $id );
            clean_post_cache( $service['profile_id'] );
            if ( Dendrila_Calendar_Native::service_data( $id ) !== $service ||
                 Dendrila_Calendar_Schedule::profile( $service['profile_id'] ) !== $profile_before ||
                 Dendrila_Calendar_Schedule::rules( $id ) !== $rules_before ||
                 strtotime( $start . ' UTC' ) < time() + $rules_before['notice_minutes'] * 60 ) {
                return new WP_Error( 'dendrila_schedule_changed', __( 'The availability settings changed. Reload the available times before booking.', 'dendrila-calendar' ) );
            }
            if ( ! self::owns_lock( $service['profile_id'] ) || ! self::owns_request_lock() ) { return self::lock_error(); }
            $paid = Dendrila_Calendar_Woo::requires_payment( $service );
            $until = min( time() + self::HOLD_SECONDS, strtotime( $start . ' UTC' ) );
            if ( $paid && $until < time() + 60 ) { return new WP_Error( 'dendrila_payment_too_late', __( 'Choose a later time to leave enough time for payment.', 'dendrila-calendar' ) ); }
            $post = array( 'post_type' => Dendrila_Calendar_Native::BOOKING, 'post_status' => 'private',
                /* translators: %s: service name. */
                'post_title' => sprintf( __( 'Booking: %s', 'dendrila-calendar' ), get_the_title( $id ) ),
                'meta_input' => array(
                    Dendrila_Calendar_Native::SERVICE_KEY => $id, Dendrila_Calendar_Native::PROFILE_KEY => $service['profile_id'],
                    Dendrila_Calendar_Native::START_KEY => $start, Dendrila_Calendar_Native::END_KEY => $end,
                    Dendrila_Calendar_Native::STATUS_KEY => $paid ? 'pending_payment' : 'confirmed',
                    '_dendrila_booking_buffer_before' => $service['buffer_before'], '_dendrila_booking_buffer_after' => $service['buffer_after'],
                    '_dendrila_booking_name' => $customer['name'], '_dendrila_booking_email' => $customer['email'],
                    '_dendrila_booking_request_hash' => $hash, '_dendrila_booking_hold_until' => $paid ? $until : 0,
                    '_dendrila_booking_requires_payment' => $paid ? 1 : 0, '_dendrila_booking_product_id' => $service['woo_product_id'],
                    '_dendrila_booking_timezone' => Dendrila_Calendar_Schedule::profile( $service['profile_id'] )['timezone'],
                    '_dendrila_booking_reference' => strtoupper( substr( bin2hex( random_bytes( 12 ) ), 0, 12 ) ),
                    '_dendrila_booking_consent_at' => gmdate( 'Y-m-d H:i:s' ),
                ),
            );
            $saved = wp_insert_post( wp_slash( $post ), true );
            if ( is_wp_error( $saved ) || ! $saved ) { return new WP_Error( 'dendrila_booking_save', __( 'The reservation could not be saved. Please try again.', 'dendrila-calendar' ) ); }
            $complete = self::owns_lock( $service['profile_id'] ) && self::owns_request_lock();
            foreach ( $post['meta_input'] as $key => $value ) {
                if ( (string) get_post_meta( $saved, $key, true ) !== (string) $value ) { $complete = false; break; }
            }
            if ( ! $complete ) {
                wp_delete_post( $saved, true );
                return new WP_Error( 'dendrila_booking_save', __( 'The reservation could not be saved. Please try again.', 'dendrila-calendar' ) );
            }
            if ( $paid ) { wp_schedule_single_event( $until + 1, self::EXPIRE_HOOK, array( (int) $saved ) ); }
            return (int) $saved;
        } );
        if ( is_wp_error( $booking_id ) ) { return $booking_id; }
        $payment = array();
        if ( get_post_meta( $booking_id, '_dendrila_booking_requires_payment', true ) && 'confirmed' !== get_post_meta( $booking_id, Dendrila_Calendar_Native::STATUS_KEY, true ) ) {
            $payment = Dendrila_Calendar_Woo::prepare_payment( $booking_id, $service, $customer );
            if ( is_wp_error( $payment ) ) {
                // Only abandon a hold without a usable order. A lost response must not release a payable order.
                if ( ! get_post_meta( $booking_id, '_dendrila_booking_order_id', true ) ) {
                    self::with_profile_lock( $service['profile_id'], static function () use ( $booking_id ) {
                        update_post_meta( $booking_id, Dendrila_Calendar_Native::STATUS_KEY, 'cancelled' );
                        update_post_meta( $booking_id, '_dendrila_booking_hold_until', 0 );
                    } );
                }
                return $payment;
            }
        }
        self::changed( $booking_id );
        return array_merge( self::summary( $booking_id ), $payment );
    }

    private static function token( $id ) {
        return hash_hmac( 'sha256', 'booking:' . $id . ':' . get_post_meta( $id, '_dendrila_booking_request_hash', true ), wp_salt( 'auth' ) );
    }

    private static function summary( $id ) {
        $service_id = (int) get_post_meta( $id, Dendrila_Calendar_Native::SERVICE_KEY, true );
        $until = (int) get_post_meta( $id, '_dendrila_booking_hold_until', true );
        return array(
            'booking_id' => (int) $id, 'reference' => get_post_meta( $id, '_dendrila_booking_reference', true ),
            'status' => get_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, true ),
            'start' => str_replace( ' ', 'T', get_post_meta( $id, Dendrila_Calendar_Native::START_KEY, true ) ) . 'Z',
            'end' => str_replace( ' ', 'T', get_post_meta( $id, Dendrila_Calendar_Native::END_KEY, true ) ) . 'Z',
            'timezone' => get_post_meta( $id, '_dendrila_booking_timezone', true ), 'service_title' => get_the_title( $service_id ),
            'cancel_token' => self::token( $id ), 'hold_expires' => $until ? gmdate( 'Y-m-d\TH:i:s\Z', $until ) : '',
        );
    }

    public static function ajax_cancel() {
        self::ajax_guard( 'cancel' );
        $result = self::cancel( absint( self::input( 'booking_id' ) ), self::input( 'token' ) );
        if ( is_wp_error( $result ) ) { self::send_error( $result ); }
        wp_send_json_success( array( 'status' => 'cancelled' ) );
    }

    public static function cancel( $id, $token, $admin = false ) {
        $post = get_post( $id );
        if ( ! $post || Dendrila_Calendar_Native::BOOKING !== $post->post_type ||
             ( $admin ? ! current_user_can( 'manage_options' ) : ! hash_equals( self::token( $id ), (string) $token ) ) ) {
            return new WP_Error( 'dendrila_booking_access', __( 'This reservation cannot be changed with this link.', 'dendrila-calendar' ) );
        }
        $profile = (int) get_post_meta( $id, Dendrila_Calendar_Native::PROFILE_KEY, true );
        $result = self::with_profile_lock( $profile, static function () use ( $id, $admin ) {
            wp_cache_delete( $id, 'post_meta' );
            $order = Dendrila_Calendar_Woo::order( get_post_meta( $id, '_dendrila_booking_order_id', true ), true );
            if ( ! $admin && $order && $order->is_paid() ) {
                return new WP_Error( 'dendrila_paid_cancel', __( 'Contact the organizer to cancel a paid booking and discuss any refund.', 'dendrila-calendar' ) );
            }
            update_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, 'cancelled' );
            update_post_meta( $id, '_dendrila_booking_hold_until', 0 );
            Dendrila_Calendar_Woo::cancel_order( $id );
            return true;
        } );
        if ( ! is_wp_error( $result ) ) { self::changed( $id ); }
        return $result;
    }

    public static function can_pay( $id ) {
        $status = get_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, true );
        if ( 'payment_on_hold' === $status || ( 'pending_payment' === $status && (int) get_post_meta( $id, '_dendrila_booking_hold_until', true ) > time() ) ) { return true; }
        return new WP_Error( 'dendrila_hold_expired', __( 'The reserved time has expired or been cancelled. Please choose a new time before paying.', 'dendrila-calendar' ) );
    }

    /** WooCommerce is the payment authority; customer redirects never confirm a booking. */
    public static function reconcile_order( $order_id ) {
        if ( isset( self::$reconciling[ $order_id ] ) ) { return; }
        $order = Dendrila_Calendar_Woo::order( $order_id );
        if ( ! $order ) { return; }
        $id = absint( $order->get_meta( '_dendrila_booking_id', true ) );
        if ( ! $id || (int) get_post_meta( $id, '_dendrila_booking_order_id', true ) !== (int) $order_id ) { return; }
        $profile = (int) get_post_meta( $id, Dendrila_Calendar_Native::PROFILE_KEY, true );
        self::$reconciling[ $order_id ] = true;
        try {
            $result = self::with_profile_lock( $profile, static function () use ( $id, $order_id, $profile ) {
                wp_cache_delete( $id, 'post_meta' );
                // A later webhook may have completed while this callback waited for the profile.
                $order = Dendrila_Calendar_Woo::order( $order_id, true );
                if ( ! $order || (int) $order->get_meta( '_dendrila_booking_id', true ) !== $id ||
                     (int) get_post_meta( $id, '_dendrila_booking_order_id', true ) !== (int) $order_id ) { return false; }
                $status = get_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, true );
                $order_status = $order->get_status();
                $full_refund = (float) $order->get_total() > 0 && (float) $order->get_total_refunded() >= (float) $order->get_total() - 0.00001;
                if ( in_array( $order_status, array( 'failed', 'cancelled', 'refunded' ), true ) || $full_refund ) {
                    update_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, ( 'refunded' === $order_status || $full_refund ) ? 'refunded' : 'cancelled' );
                    update_post_meta( $id, '_dendrila_booking_hold_until', 0 );
                    return true;
                }
                $paid = $order->is_paid();
                if ( ! $paid && 'on-hold' !== $order_status ) { return false; }
                if ( 'confirmed' === $status && $paid ) { return false; }
                $held = 'payment_on_hold' === $status || ( 'pending_payment' === $status && (int) get_post_meta( $id, '_dendrila_booking_hold_until', true ) > time() );
                $valid = true;
                if ( in_array( $status, array( 'cancelled', 'refunded', 'payment_conflict' ), true ) ) {
                    $valid = new WP_Error( 'dendrila_cancelled_payment', 'Cancelled booking' );
                } elseif ( ! $held ) {
                    $valid = Dendrila_Calendar_Schedule::validate_slot(
                        (int) get_post_meta( $id, Dendrila_Calendar_Native::SERVICE_KEY, true ),
                        get_post_meta( $id, Dendrila_Calendar_Native::START_KEY, true ),
                        get_post_meta( $id, Dendrila_Calendar_Native::END_KEY, true ), $id, true
                    );
                }
                if ( ! self::owns_lock( $profile ) ) { return self::lock_error(); }
                if ( is_wp_error( $valid ) ) {
                    update_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, 'payment_conflict' );
                    update_post_meta( $id, '_dendrila_booking_hold_until', 0 );
                    if ( ! $order->get_meta( '_dendrila_booking_payment_review', true ) ) {
                        $order->update_meta_data( '_dendrila_booking_payment_review', 'yes' );
                        $order->add_order_note( __( 'Dendrila: this payment arrived after the booking was released or cancelled. The appointment is NOT confirmed. Contact the customer to arrange another time or a refund.', 'dendrila-calendar' ) );
                        $order->save();
                    }
                    return true;
                }
                update_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, $paid ? 'confirmed' : 'payment_on_hold' );
                update_post_meta( $id, '_dendrila_booking_hold_until', 0 );
                return true;
            } );
            if ( is_wp_error( $result ) ) {
                // A competing checkout may hold the lock; retry the authoritative order transition.
                if ( ! wp_next_scheduled( 'dendrila_calendar_reconcile_order', array( (int) $order_id ) ) ) { wp_schedule_single_event( time() + 15, 'dendrila_calendar_reconcile_order', array( (int) $order_id ) ); }
            } elseif ( $result ) { self::changed( $id ); }
        } finally { unset( self::$reconciling[ $order_id ] ); }
    }

    public static function expire( $id ) {
        $id = absint( $id );
        $order_id = (int) get_post_meta( $id, '_dendrila_booking_order_id', true );
        if ( $order_id ) { self::reconcile_order( $order_id ); }
        $profile = (int) get_post_meta( $id, Dendrila_Calendar_Native::PROFILE_KEY, true );
        $result = self::with_profile_lock( $profile, static function () use ( $id ) {
            wp_cache_delete( $id, 'post_meta' );
            if ( 'pending_payment' !== get_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, true ) || (int) get_post_meta( $id, '_dendrila_booking_hold_until', true ) > time() ) { return false; }
            update_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, 'expired' );
            Dendrila_Calendar_Woo::cancel_order( $id );
            return true;
        } );
        if ( true === $result ) { self::changed( $id ); }
    }

    private static function changed( $id ) {
        if ( ! wp_next_scheduled( self::SYNC_HOOK ) ) { wp_schedule_single_event( time() + 5, self::SYNC_HOOK ); }
        do_action( 'dendrila_calendar_booking_changed', $id );
    }

    public static function sync() {
        // The bridge also runs the OAuth destinations once.
        Dendrila_Calendar_Bridge::run_all();
    }

    public static function add_source( $sources ) {
        $sources['bookings'] = __( 'Your appointments', 'dendrila-calendar' );
        return $sources;
    }

    public static function source_events( $events, $from, $to ) {
        $posts = get_posts( array( 'post_type' => Dendrila_Calendar_Native::BOOKING, 'post_status' => array( 'private', 'publish' ), 'numberposts' => 501,
            'meta_query' => array(
                array( 'key' => Dendrila_Calendar_Native::START_KEY, 'value' => $to, 'compare' => '<', 'type' => 'DATETIME' ),
                array( 'key' => Dendrila_Calendar_Native::END_KEY, 'value' => $from, 'compare' => '>', 'type' => 'DATETIME' ),
            ) ) );
        if ( count( $posts ) > 500 ) { throw new RuntimeException( 'Incomplete booking inventory' ); }
        foreach ( $posts as $post ) {
            $status = get_post_meta( $post->ID, Dendrila_Calendar_Native::STATUS_KEY, true );
            if ( ! in_array( $status, array( 'confirmed', 'payment_on_hold', 'cancelled', 'refunded', 'expired', 'payment_conflict' ), true ) ) { continue; }
            $cancelled = in_array( $status, array( 'cancelled', 'refunded', 'expired', 'payment_conflict' ), true );
            $events[] = array( 'id' => 'booking-' . $post->ID,
                /* translators: %s: service name. */
                'title' => sprintf( __( 'Booking: %s', 'dendrila-calendar' ), get_the_title( get_post_meta( $post->ID, Dendrila_Calendar_Native::SERVICE_KEY, true ) ) ),
                'start' => str_replace( ' ', 'T', get_post_meta( $post->ID, Dendrila_Calendar_Native::START_KEY, true ) ) . 'Z',
                'end' => str_replace( ' ', 'T', get_post_meta( $post->ID, Dendrila_Calendar_Native::END_KEY, true ) ) . 'Z',
                'status' => $cancelled ? 'cancelled' : $status, 'all_day' => false, 'url' => get_edit_post_link( $post->ID, '' ) );
        }
        return $events;
    }

    public static function meta_boxes() {
        add_meta_box( 'dendrila-booking-details', __( 'Reservation details', 'dendrila-calendar' ), array( __CLASS__, 'details' ), Dendrila_Calendar_Native::BOOKING, 'normal', 'high' );
    }

    public static function details( $post ) {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $fields = array( __( 'Reference', 'dendrila-calendar' ) => '_dendrila_booking_reference', __( 'Status', 'dendrila-calendar' ) => Dendrila_Calendar_Native::STATUS_KEY,
            __( 'Customer', 'dendrila-calendar' ) => '_dendrila_booking_name', __( 'Email', 'dendrila-calendar' ) => '_dendrila_booking_email',
            __( 'Start (UTC)', 'dendrila-calendar' ) => Dendrila_Calendar_Native::START_KEY, __( 'End (UTC)', 'dendrila-calendar' ) => Dendrila_Calendar_Native::END_KEY,
            __( 'Timezone', 'dendrila-calendar' ) => '_dendrila_booking_timezone' );
        echo '<dl>';
        foreach ( $fields as $label => $key ) { echo '<dt><strong>' . esc_html( $label ) . '</strong></dt><dd>' . esc_html( get_post_meta( $post->ID, $key, true ) ) . '</dd>'; }
        echo '</dl>';
        $order = Dendrila_Calendar_Woo::order( get_post_meta( $post->ID, '_dendrila_booking_order_id', true ) );
        if ( $order ) { echo '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">' . esc_html__( 'Open WooCommerce order', 'dendrila-calendar' ) . '</a></p>'; }
        $url = wp_nonce_url( admin_url( 'admin-post.php?action=dendrila_booking_cancel_admin&booking_id=' . $post->ID ), 'dendrila_booking_cancel_' . $post->ID );
        echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Cancel this reservation', 'dendrila-calendar' ) . '</a></p>';
        echo '<p class="description">' . esc_html__( 'Cancelling releases the time. Paid orders are not automatically refunded; manage any refund in WooCommerce.', 'dendrila-calendar' ) . '</p>';
    }

    public static function admin_cancel() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You cannot manage bookings.', 'dendrila-calendar' ) ); }
        $id = isset( $_GET['booking_id'] ) && is_scalar( $_GET['booking_id'] ) ? absint( $_GET['booking_id'] ) : 0;
        check_admin_referer( 'dendrila_booking_cancel_' . $id );
        $result = self::cancel( $id, '', true );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
        wp_safe_redirect( get_edit_post_link( $id, 'raw' ) ); exit;
    }
}
