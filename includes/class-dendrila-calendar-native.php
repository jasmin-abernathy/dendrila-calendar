<?php
/**
 * First-party booking domain: mandatory profiles, services and private bookings.
 *
 * Profiles and services supply the native availability and locked booking engine.
 * Existing read-only connectors remain optional.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar_Native {
    const PROFILE = 'dendrila_profile';
    const SERVICE = 'dendrila_service';
    const BOOKING = 'dendrila_booking';

    const PROFILE_KEY = '_dendrila_booking_profile_id';
    const SERVICE_KEY = '_dendrila_booking_service_id';
    const START_KEY = '_dendrila_booking_start_utc';
    const END_KEY = '_dendrila_booking_end_utc';
    const STATUS_KEY = '_dendrila_booking_status';

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'register_types' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
        add_action( 'save_post_' . self::SERVICE, array( __CLASS__, 'save_service' ) );
        add_action( 'admin_notices', array( __CLASS__, 'profile_notice' ) );
    }

    private static function caps() {
        $all = array(
            'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts',
            'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts',
            'delete_published_posts', 'delete_others_posts', 'edit_private_posts',
            'edit_published_posts', 'create_posts',
        );
        return array_fill_keys( $all, 'manage_options' );
    }

    public static function register_types() {
        register_post_type( self::PROFILE, array(
            'labels' => array(
                'name' => __( 'Booking profiles', 'dendrila-calendar' ),
                'singular_name' => __( 'Booking profile', 'dendrila-calendar' ),
                'add_new_item' => __( 'Add booking profile', 'dendrila-calendar' ),
                'edit_item' => __( 'Edit booking profile', 'dendrila-calendar' ),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => array( 'title', 'editor', 'thumbnail' ),
            'capabilities' => self::caps(),
            'map_meta_cap' => false,
        ) );
        register_post_type( self::SERVICE, array(
            'labels' => array(
                'name' => __( 'Services', 'dendrila-calendar' ),
                'singular_name' => __( 'Service', 'dendrila-calendar' ),
                'add_new_item' => __( 'Add service', 'dendrila-calendar' ),
                'edit_item' => __( 'Edit service', 'dendrila-calendar' ),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => array( 'title', 'editor', 'excerpt' ),
            'capabilities' => self::caps(),
            'map_meta_cap' => false,
        ) );
        register_post_type( self::BOOKING, array(
            'labels' => array(
                'name' => __( 'Your appointments', 'dendrila-calendar' ),
                'singular_name' => __( 'Native booking', 'dendrila-calendar' ),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => array( 'title' ),
            'capabilities' => self::caps(),
            'map_meta_cap' => false,
        ) );
    }

    public static function menu() {
        add_submenu_page(
            'dendrila-calendar',
            __( 'Booking profiles', 'dendrila-calendar' ),
            __( 'Profiles', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar-profiles',
            array( __CLASS__, 'render_profiles' )
        );
        add_submenu_page(
            'dendrila-calendar',
            __( 'Services', 'dendrila-calendar' ),
            __( 'Services', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar-services',
            array( __CLASS__, 'render_services' )
        );
        add_submenu_page(
            'dendrila-calendar',
            __( 'Your appointments', 'dendrila-calendar' ),
            __( 'Bookings', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar-bookings',
            array( __CLASS__, 'render_bookings' )
        );
    }

    public static function profiles() {
        return get_posts( array(
            'post_type' => self::PROFILE,
            'post_status' => 'publish',
            'numberposts' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
        ) );
    }

    /** At least one published profile is mandatory before bookings can open. */
    public static function has_profile() {
        return ! empty( self::profiles() );
    }

    public static function valid_profile( $profile_id ) {
        if ( absint( $profile_id ) < 1 ) { return false; }
        $profile = get_post( absint( $profile_id ) );
        return $profile && self::PROFILE === $profile->post_type && 'publish' === $profile->post_status;
    }

    public static function service_profile_id( $service_id ) {
        if ( absint( $service_id ) < 1 ) { return 0; }
        $post = get_post( absint( $service_id ) );
        if ( ! $post || self::SERVICE !== $post->post_type || 'publish' !== $post->post_status ) {
            return 0;
        }
        $profile_id = absint( get_post_meta( $post->ID, self::PROFILE_KEY, true ) );
        return self::valid_profile( $profile_id ) ? $profile_id : 0;
    }

    /** Only complete, owned, published services may be booked. */
    public static function bookable_service( $service_id ) {
        $profile_id = self::service_profile_id( $service_id );
        $duration = absint( get_post_meta( absint( $service_id ), '_dendrila_duration_minutes', true ) );
        return 0 !== $profile_id && $duration >= 5 && $duration <= 1440;
    }

    public static function service_data( $service_id ) {
        if ( ! self::bookable_service( $service_id ) ) {
            return false;
        }
        $service_id = absint( $service_id );
        return array(
            'service_id' => $service_id,
            'profile_id' => self::service_profile_id( $service_id ),
            'duration_minutes' => absint( get_post_meta( $service_id, '_dendrila_duration_minutes', true ) ),
            'buffer_before' => absint( get_post_meta( $service_id, '_dendrila_buffer_before', true ) ),
            'buffer_after' => absint( get_post_meta( $service_id, '_dendrila_buffer_after', true ) ),
            'price_cents' => absint( get_post_meta( $service_id, '_dendrila_price_cents', true ) ),
            'woo_product_id' => absint( get_post_meta( $service_id, '_dendrila_woo_product_id', true ) ),
        );
    }

    public static function meta_boxes() {
        add_meta_box(
            'dendrila-service-settings',
            __( 'Duration, profile and price', 'dendrila-calendar' ),
            array( __CLASS__, 'render_service_fields' ),
            self::SERVICE,
            'normal',
            'high'
        );
    }

    public static function render_service_fields( $post ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_nonce_field( 'dendrila_calendar_service_save', 'dendrila_calendar_service_nonce' );
        $profiles = self::profiles();
        $selected = absint( get_post_meta( $post->ID, self::PROFILE_KEY, true ) );
        echo '<p><label for="dendrila-profile-id"><strong>' . esc_html__( 'Responsible profile (required)', 'dendrila-calendar' ) . '</strong></label></p>';
        echo '<select id="dendrila-profile-id" name="dendrila_profile_id" required>';
        echo '<option value="">' . esc_html__( 'Select a profile', 'dendrila-calendar' ) . '</option>';
        foreach ( $profiles as $profile ) {
            echo '<option value="' . esc_attr( $profile->ID ) . '"' . selected( $selected, (int) $profile->ID, false ) . '>' . esc_html( $profile->post_title ) . '</option>';
        }
        echo '</select>';
        if ( ! $profiles ) {
            echo '<p class="notice notice-warning inline">' . esc_html__( 'Create and publish at least one booking profile before offering a service.', 'dendrila-calendar' ) . '</p>';
            echo '<p><a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::PROFILE ) ) . '">' .
                esc_html__( 'Create a profile', 'dendrila-calendar' ) . '</a></p>';
        }
        $fields = array(
            'duration' => array( __( 'Duration in minutes', 'dendrila-calendar' ), '_dendrila_duration_minutes', 30, 5, 1440 ),
            'before' => array( __( 'Preparation time before (minutes)', 'dendrila-calendar' ), '_dendrila_buffer_before', 0, 0, 1440 ),
            'after' => array( __( 'Recovery time after (minutes)', 'dendrila-calendar' ), '_dendrila_buffer_after', 0, 0, 1440 ),
            'price' => array( __( 'Price in cents (0 = free)', 'dendrila-calendar' ), '_dendrila_price_cents', 0, 0, 100000000 ),
            'product' => array( __( 'WooCommerce product ID (optional)', 'dendrila-calendar' ), '_dendrila_woo_product_id', 0, 0, 2147483647 ),
        );
        foreach ( $fields as $key => $field ) {
            $saved = get_post_meta( $post->ID, $field[1], true );
            $value = '' === (string) $saved ? $field[2] : absint( $saved );
            if ( 'product' === $key && class_exists( 'Dendrila_Calendar_Woo' ) ) {
                Dendrila_Calendar_Woo::render_product_field( $post->ID, $value );
                continue;
            }
            echo '<p><label for="dendrila-service-' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label> ';
            echo '<input id="dendrila-service-' . esc_attr( $key ) . '" type="number" step="1" min="' .
                esc_attr( $field[3] ) . '" max="' . esc_attr( $field[4] ) . '" name="dendrila_service_' . esc_attr( $key ) .
                '" value="' . esc_attr( $value ) . '"></p>';
        }
        echo '<p class="description">' .
            esc_html__( 'For paid appointments, select a published, purchasable simple virtual WooCommerce product. Its current price and taxes apply. A nonzero price without a valid product keeps online booking closed.', 'dendrila-calendar' ) .
            '</p>';
    }

    public static function save_service( $post_id ) {
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
             ! current_user_can( 'manage_options' ) ||
             ! isset( $_POST['dendrila_calendar_service_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dendrila_calendar_service_nonce'] ) ), 'dendrila_calendar_service_save' ) ) {
            return;
        }
        $profile_id = isset( $_POST['dendrila_profile_id'] ) ? absint( wp_unslash( $_POST['dendrila_profile_id'] ) ) : 0;
        if ( self::valid_profile( $profile_id ) ) {
            update_post_meta( $post_id, self::PROFILE_KEY, $profile_id );
        } else {
            // An unassigned service is never bookable; don't fall back to another profile.
            delete_post_meta( $post_id, self::PROFILE_KEY );
        }
        $fields = array(
            'duration' => array( '_dendrila_duration_minutes', 30, 5, 1440 ),
            'before' => array( '_dendrila_buffer_before', 0, 0, 1440 ),
            'after' => array( '_dendrila_buffer_after', 0, 0, 1440 ),
            'price' => array( '_dendrila_price_cents', 0, 0, 100000000 ),
            'product' => array( '_dendrila_woo_product_id', 0, 0, 2147483647 ),
        );
        foreach ( $fields as $key => $definition ) {
            $field = 'dendrila_service_' . $key;
            $value = isset( $_POST[ $field ] ) ? absint( wp_unslash( $_POST[ $field ] ) ) : $definition[1];
            // Never silently accept a duration of zero or extreme buffer size.
            $value = min( $definition[3], max( $definition[2], $value ) );
            update_post_meta( $post_id, $definition[0], $value );
        }
    }

    /**
     * Validate the identity tuple before any future reservation is saved.
     * This is called independently of the public interface and payment provider.
     */
    public static function validate_booking( $service_id, $profile_id, $start_utc, $end_utc ) {
        $service = self::service_data( $service_id );
        if ( ! $service || ! self::has_profile() ) {
            return new WP_Error( 'dendrila_missing_profile', __( 'Create a profile and assign it to a published service before accepting bookings.', 'dendrila-calendar' ) );
        }
        if ( $service['profile_id'] !== absint( $profile_id ) ) {
            return new WP_Error( 'dendrila_profile_mismatch', __( 'The selected profile does not provide this service.', 'dendrila-calendar' ) );
        }
        try {
            $start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $start_utc, new DateTimeZone( 'UTC' ) );
            $end = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $end_utc, new DateTimeZone( 'UTC' ) );
            if ( ! $start || ! $end || $start->format( 'Y-m-d H:i:s' ) !== $start_utc ||
                 $end->format( 'Y-m-d H:i:s' ) !== $end_utc || $end <= $start ) {
                throw new Exception( 'Invalid date' );
            }
        } catch ( Exception $exception ) {
            return new WP_Error( 'dendrila_invalid_slot', __( 'This reservation has an invalid time period.', 'dendrila-calendar' ) );
        }
        if ( $end->getTimestamp() - $start->getTimestamp() !== $service['duration_minutes'] * 60 ) {
            return new WP_Error( 'dendrila_wrong_duration', __( 'The reservation duration must match its service.', 'dendrila-calendar' ) );
        }
        return true;
    }

    /** Draft storage contract. Public confirmation must use Dendrila_Calendar_Booking::create(). */
    public static function booking_record( $service_id, $profile_id, $start_utc, $end_utc ) {
        $valid = self::validate_booking( $service_id, $profile_id, $start_utc, $end_utc );
        if ( true !== $valid ) {
            return $valid;
        }
        return array(
            'post_type' => self::BOOKING,
            'post_status' => 'private',
            'meta_input' => array(
                self::SERVICE_KEY => absint( $service_id ),
                self::PROFILE_KEY => absint( $profile_id ),
                self::START_KEY => $start_utc,
                self::END_KEY => $end_utc,
                self::STATUS_KEY => 'draft',
            ),
        );
    }

    public static function profile_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // Only on Dendrila screens, never across the entire WordPress admin.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $type = $screen && isset( $screen->post_type ) ? $screen->post_type : '';
        if ( 0 !== strpos( $page, 'dendrila-calendar' ) && ! in_array( $type, array( self::PROFILE, self::SERVICE, self::BOOKING ), true ) ) {
            return;
        }
        if ( self::has_profile() ) { return; }
        echo '<div class="notice notice-info"><p>' .
            esc_html__( 'Native booking setup: create at least one profile before offering services or taking reservations.', 'dendrila-calendar' ) .
            ' <a href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::PROFILE ) ) . '">' .
            esc_html__( 'Create the first profile', 'dendrila-calendar' ) . '</a></p></div>';
    }

    private static function head( $heading, $intro ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You cannot manage Dendrila booking profiles.', 'dendrila-calendar' ) );
        }
        echo '<div class="wrap dendrila-native">';
        echo '<header class="dendrila-native-header"><p class="dendrila-native-brand">Dendrila Calendar</p><h1>' . esc_html( $heading ) . '</h1><p>' . esc_html( $intro ) . '</p></header>';
    }

    public static function render_profiles() {
        self::head(
            __( 'Booking profiles', 'dendrila-calendar' ),
            __( 'Each reservation belongs to a profile. Create one per professional or resource, then set its hours and date exceptions.', 'dendrila-calendar' )
        );
        echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::PROFILE ) ) . '">' .
            esc_html__( 'Add a profile', 'dendrila-calendar' ) . '</a> <a class="button" href="' .
            esc_url( admin_url( 'edit.php?post_type=' . self::PROFILE ) ) . '">' . esc_html__( 'Manage profiles', 'dendrila-calendar' ) . '</a></p>';
        $profiles = self::profiles();
        if ( ! $profiles ) {
            echo '<p class="notice notice-warning inline">' . esc_html__( 'No active profile yet. Bookings cannot be offered.', 'dendrila-calendar' ) . '</p>';
        } else {
            echo '<ul>';
            foreach ( $profiles as $profile ) {
                echo '<li><a href="' . esc_url( get_edit_post_link( $profile->ID, '' ) ) . '">' . esc_html( $profile->post_title ) . '</a>';
                if ( class_exists( 'Dendrila_Calendar_Schedule' ) ) {
                    $schedule = Dendrila_Calendar_Schedule::profile( $profile->ID );
                    echo '<br><span class="description">' . esc_html( $schedule['timezone'] . ' — ' . ( $schedule['enabled'] ? __( 'Availability enabled', 'dendrila-calendar' ) : __( 'Availability closed', 'dendrila-calendar' ) ) ) . '</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /** A service and its WooCommerce product are one offer, never two list entries. */
    private static function render_service_card( $service ) {
        $id = (int) $service->ID;
        $product_id = absint( get_post_meta( $id, '_dendrila_woo_product_id', true ) );
        $profile_id = absint( get_post_meta( $id, self::PROFILE_KEY, true ) );
        $profile_valid = self::valid_profile( $profile_id );
        $profile = $profile_valid ? get_post( $profile_id ) : false;
        $rules = class_exists( 'Dendrila_Calendar_Schedule' ) ? Dendrila_Calendar_Schedule::rules( $id ) : array();
        $hours = $profile_valid && class_exists( 'Dendrila_Calendar_Schedule' ) ? Dendrila_Calendar_Schedule::profile( $profile_id ) : array();
        $duration = absint( get_post_meta( $id, '_dendrila_duration_minutes', true ) );
        $product = false;
        $product_ready = false;
        $price_known = true;
        $price = '';
        $warnings = array();
        $title = $service->post_title;

        if ( $product_id ) {
            // A missing or unpublished product never becomes a free native service.
            $record = get_post( $product_id );
            if ( $record && 'product' === $record->post_type && function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $product_id );
            }
            $product_ready = $product && method_exists( $product, 'exists' ) && $product->exists() &&
                method_exists( $product, 'get_status' ) && 'publish' === $product->get_status();
            if ( $product && method_exists( $product, 'get_name' ) ) {
                $title = $product->get_name();
            }
            if ( ! $product_ready ) {
                $price_known = false;
                $warnings[] = __( 'Linked WooCommerce product unavailable. Check the product before reopening bookings.', 'dendrila-calendar' );
            } else {
                $price = method_exists( $product, 'get_price' ) ? $product->get_price() : '';
                $price_known = is_scalar( $price ) && '' !== (string) $price;
                if ( ! $price_known ) {
                    $warnings[] = __( 'Price unavailable. Check the linked WooCommerce product.', 'dendrila-calendar' );
                }
            }
        } else {
            $native_price = absint( get_post_meta( $id, '_dendrila_price_cents', true ) );
            if ( $native_price > 0 ) {
                $price_known = false;
                $warnings[] = __( 'Price unavailable. Link a WooCommerce product before accepting paid bookings.', 'dendrila-calendar' );
            }
        }

        if ( ! $profile_valid ) {
            $warnings[] = __( 'No published profile. Bookings are closed until a profile is assigned.', 'dendrila-calendar' );
        }
        $purchase_ok = ! $product_id || ( $product_ready && $price_known &&
            method_exists( $product, 'is_purchasable' ) && $product->is_purchasable() &&
            method_exists( $product, 'is_virtual' ) && $product->is_virtual() &&
            method_exists( $product, 'is_in_stock' ) && $product->is_in_stock() &&
            method_exists( $product, 'is_on_backorder' ) && ! $product->is_on_backorder( 1 ) &&
            'no' !== $product->get_meta( Dendrila_Calendar_Woo_Service::ENABLED_KEY, true ) );
        $active = 'publish' === $service->post_status && $profile_valid &&
            ! empty( $rules['enabled'] ) && ! empty( $hours['enabled'] ) &&
            $duration >= 5 && $duration <= 1440 && $price_known && $purchase_ok;
        $status = $active ? __( 'Active', 'dendrila-calendar' ) : __( 'Disabled — bookings closed', 'dendrila-calendar' );
        $editing_id = $product_id ? $product_id : $id;
        $can_edit = current_user_can( 'edit_post', $editing_id );
        $edit = $can_edit && ( ! $product_id || $product ) ? get_edit_post_link( $editing_id, 'raw' ) : '';
        if ( $edit && $product_id ) {
            // Intent only: this never modifies a product until WooCommerce verifies its save nonce.
            $edit = add_query_arg( 'dendrila_edit_service', '1', $edit ) . '#dendrila-product-booking';
        }

        echo '<article class="dendrila-service-card">';
        echo '<div class="dendrila-service-card-top"><div class="dendrila-service-title">';
        echo '<h3>' . esc_html( $title ) . '</h3>';
        $type_label = $product_id ? __( 'WooCommerce appointment', 'dendrila-calendar' ) :
            ( $price_known ? __( 'Free native appointment', 'dendrila-calendar' ) : __( 'Native appointment', 'dendrila-calendar' ) );
        echo '<span class="dendrila-service-type">' . esc_html( $type_label ) . '</span>';
        echo '</div><span class="dendrila-service-status ' . ( $active ? 'is-active' : 'is-disabled' ) . '">' . esc_html( $status ) . '</span></div>';
        echo '<dl class="dendrila-service-facts">';
        echo '<div><dt>' . esc_html__( 'Responsible profile', 'dendrila-calendar' ) . '</dt><dd>' .
            esc_html( $profile ? $profile->post_title : __( 'No published profile', 'dendrila-calendar' ) ) . '</dd></div>';
        echo '<div><dt>' . esc_html__( 'Duration', 'dendrila-calendar' ) . '</dt><dd>' .
            /* translators: %d: appointment duration in minutes. */
            esc_html( $duration ? sprintf( __( '%d minutes', 'dendrila-calendar' ), $duration ) : __( 'Not configured', 'dendrila-calendar' ) ) . '</dd></div>';
        echo '<div><dt>' . esc_html__( 'Price', 'dendrila-calendar' ) . '</dt><dd>';
        if ( ! $price_known ) {
            echo esc_html__( 'Price unavailable', 'dendrila-calendar' );
        } elseif ( ! $product_id || 0.0 === (float) $price ) {
            echo esc_html__( 'Free', 'dendrila-calendar' );
        } else {
            // WooCommerce is the price authority, including sale prices and tax formatting.
            $html = method_exists( $product, 'get_price_html' ) ? $product->get_price_html() : '';
            echo $html ? wp_kses_post( $html ) : esc_html__( 'Price unavailable', 'dendrila-calendar' );
        }
        echo '</dd></div></dl>';
        if ( $warnings ) {
            foreach ( array_unique( $warnings ) as $warning ) {
                echo '<p class="dendrila-service-warning" role="status">' . esc_html( $warning ) . '</p>';
            }
        } elseif ( ! $active && $profile_valid ) {
            echo '<p class="dendrila-service-note">' . esc_html__( 'Booking is not enabled for this offer or its profile.', 'dendrila-calendar' ) . '</p>';
        }
        if ( $edit ) {
            echo '<div class="dendrila-service-actions"><a class="button" href="' . esc_url( $edit ) . '" aria-label="' .
                /* translators: %s: service name. */
                esc_attr( sprintf( __( 'Edit %s', 'dendrila-calendar' ), $title ) ) . '">' . esc_html__( 'Edit', 'dendrila-calendar' ) . '</a></div>';
        } elseif ( $can_edit && $product_id && ! $product_ready ) {
            echo '<p class="dendrila-service-note">' . esc_html__( 'Editing is unavailable until the linked product is restored.', 'dendrila-calendar' ) . '</p>';
        } elseif ( $can_edit ) {
            echo '<p class="dendrila-service-warning" role="status">' .
                esc_html__( 'Editing unavailable. Check the editor link or product settings.', 'dendrila-calendar' ) . '</p>';
        }
        echo '</article>';
    }

    public static function render_services() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You cannot manage Dendrila booking profiles.', 'dendrila-calendar' ) );
        }
        $native_url = admin_url( 'post-new.php?post_type=' . self::SERVICE );
        $woo = Dendrila_Calendar_Woo::available() && current_user_can( 'edit_products' );
        $add_url = $woo ? admin_url( 'edit.php?post_type=product&page=dendrila-add-service' ) : $native_url;
        echo '<div class="wrap dendrila-native dendrila-calendar-services"><header class="dendrila-calendar-services-head"><h1>' . esc_html__( 'Services', 'dendrila-calendar' ) . '</h1>';
        echo '<a class="button button-primary" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add a service', 'dendrila-calendar' ) . '</a></header><hr class="wp-header-end">';
        // Read-only pagination, no direct SQL or edits. Drafts are shown so disabled offers can be repaired.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
        $page = isset( $_GET['dc_services_page'] ) ? max( 1, absint( wp_unslash( $_GET['dc_services_page'] ) ) ) : 1;
        $query = new WP_Query( array(
            'post_type' => self::SERVICE,
            'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ),
            'posts_per_page' => 20, 'paged' => $page, 'orderby' => array( 'title' => 'ASC', 'ID' => 'ASC' ),
            'update_post_term_cache' => false,
        ) );
        echo '<section class="dendrila-services-panel" aria-labelledby="dendrila-services-title">';
        echo '<div class="dendrila-services-heading"><div><h2 id="dendrila-services-title">' . esc_html__( 'Your services', 'dendrila-calendar' ) . '</h2>';
        echo '<p>' . esc_html__( 'Active does not guarantee a free time slot. Availability also depends on hours and existing bookings.', 'dendrila-calendar' ) . '</p></div>';
        /* translators: %d: total number of offers. */
        echo '<span class="dendrila-services-count">' . esc_html( sprintf( __( '%d registered services', 'dendrila-calendar' ), (int) $query->found_posts ) ) . '</span></div>';
        if ( $query->posts ) {
            echo '<div class="dendrila-services-list">';
            foreach ( $query->posts as $service ) { self::render_service_card( $service ); }
            echo '</div>';
        } elseif ( $page > 1 ) {
            echo '<p class="dendrila-services-empty">' . esc_html__( 'This page no longer exists. Return to the first page.', 'dendrila-calendar' ) . '</p>';
        } else {
            echo '<div class="dendrila-services-empty"><strong>' . esc_html__( 'No services yet.', 'dendrila-calendar' ) . '</strong><p>' .
                esc_html__( 'Add your first service to offer online appointments.', 'dendrila-calendar' ) . '</p></div>';
        }
        if ( (int) $query->max_num_pages > 1 || $page > 1 ) {
            echo '<nav class="dendrila-services-pagination" aria-label="' . esc_attr__( 'Services pages', 'dendrila-calendar' ) . '">';
            if ( $page > 1 ) {
                echo '<a class="button" href="' . esc_url( add_query_arg( 'dc_services_page', max( 1, min( $page - 1, (int) $query->max_num_pages ) ), admin_url( 'admin.php?page=dendrila-calendar-services' ) ) ) . '">' .
                    esc_html__( 'Previous page', 'dendrila-calendar' ) . '</a>';
            }
            /* translators: 1: current page, 2: number of pages. */
            echo '<span>' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'dendrila-calendar' ), $page, (int) $query->max_num_pages ) ) . '</span>';
            if ( $page < (int) $query->max_num_pages ) {
                echo '<a class="button" href="' . esc_url( add_query_arg( 'dc_services_page', $page + 1, admin_url( 'admin.php?page=dendrila-calendar-services' ) ) ) . '">' .
                    esc_html__( 'Next page', 'dendrila-calendar' ) . '</a>';
            }
            echo '</nav>';
        }
        echo '</section>';
        if ( ! self::has_profile() ) {
            echo '<p class="notice notice-warning inline">' . esc_html__( 'No published profile. Bookings are closed until a profile is assigned.', 'dendrila-calendar' ) .
                ' <a href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::PROFILE ) ) . '">' . esc_html__( 'Create a profile', 'dendrila-calendar' ) . '</a></p>';
        }
        if ( $woo ) {
            echo '<details class="dendrila-optional-services"><summary>' . esc_html__( 'Free service without WooCommerce (optional)', 'dendrila-calendar' ) . '</summary><p><a class="button" href="' .
                esc_url( $native_url ) . '">' . esc_html__( 'Create a free service', 'dendrila-calendar' ) . '</a></p></details>';
        }
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-guide' ) ) . '">' . esc_html__( 'Booking guide', 'dendrila-calendar' ) . '</a></p></div>';
    }

    public static function render_bookings() {
        self::head(
            __( 'Your appointments', 'dendrila-calendar' ),
            __( 'Reservations are linked to a service and its profile. Free bookings are confirmed immediately; paid bookings follow their WooCommerce order. Add [dendrila_booking] to a page to offer your enabled services.', 'dendrila-calendar' )
        );
        echo '<p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . self::BOOKING ) ) . '">' .
            esc_html__( 'View private booking records', 'dendrila-calendar' ) . '</a></p></div>';
    }
}
