<?php
/** Native booking payments use WooCommerce orders and its order-payment page. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Calendar_Woo {
    const ORDER_KEY = '_dendrila_booking_order_id';
    const BOOKING_KEY = '_dendrila_booking_id';
    const READY_KEY = '_dendrila_booking_payment_ready';

    public static function boot() {
        add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_compatibility' ) );
        foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_changed', 'woocommerce_order_refunded' ) as $hook ) {
            add_action( $hook, array( __CLASS__, 'reconcile_order' ), 10, 1 );
        }
        add_action( 'woocommerce_before_pay_action', array( __CLASS__, 'before_pay' ), 5, 1 );
        add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou' ), 20, 1 );
        add_filter( 'woocommerce_order_hold_stock_minutes', array( __CLASS__, 'stock_hold_minutes' ), 10, 2 );
    }

    public static function declare_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', dirname( __DIR__ ) . '/dendrila-calendar.php', true );
        }
    }

    public static function available() {
        return function_exists( 'wc_get_product' ) && function_exists( 'wc_get_order' );
    }

    /** An invalid assigned product must never silently turn a service into a free booking. */
    public static function requires_payment( $service ) {
        return is_array( $service ) && ! empty( $service['woo_product_id'] );
    }

    private static function usable_product( $product ) {
        return $product && $product->exists() && $product->is_type( 'simple' ) && $product->is_virtual() &&
            $product->is_purchasable() && $product->is_in_stock() && ! $product->is_on_backorder( 1 );
    }

    public static function product_for_service( $service_id ) {
        $service = Dendrila_Calendar_Native::service_data( $service_id );
        if ( ! $service || ! self::available() || ! $service['woo_product_id'] ) {
            return false;
        }
        $product = wc_get_product( $service['woo_product_id'] );
        return self::usable_product( $product ) ? $product : false;
    }

    /** Plain display text; WooCommerce remains the price and tax authority. */
    public static function price_text( $service_id ) {
        $product = self::product_for_service( $service_id );
        if ( ! $product ) { return __( 'Payment unavailable', 'dendrila-calendar' ); }
        return html_entity_decode( wp_strip_all_tags( wc_price( wc_get_price_to_display( $product ) ) ), ENT_QUOTES, 'UTF-8' );
    }

    /** Pick a named virtual product instead of asking the administrator for an ID. */
    public static function render_product_field( $service_id, $selected ) {
        $products = self::available() && function_exists( 'wc_get_products' ) ? wc_get_products( array(
            'type' => 'simple', 'virtual' => true, 'status' => 'publish', 'limit' => 200, 'orderby' => 'name', 'order' => 'ASC',
        ) ) : array();
        $choices = array();
        foreach ( $products as $product ) {
            if ( self::usable_product( $product ) ) { $choices[ $product->get_id() ] = $product->get_name(); }
        }
        $selected = absint( $selected );
        if ( $selected && ! isset( $choices[ $selected ] ) ) {
            $current = self::available() ? wc_get_product( $selected ) : false;
            /* translators: %d: WooCommerce product ID. */
            $choices[ $selected ] = $current ? $current->get_name() : sprintf( __( 'Unavailable product #%d', 'dendrila-calendar' ), $selected );
        }
        echo '<p><label for="dendrila-service-product">' . esc_html__( 'WooCommerce payment product', 'dendrila-calendar' ) . '</label></p>';
        echo '<select id="dendrila-service-product" name="dendrila_service_product">';
        echo '<option value="0">' . esc_html__( 'No payment product (free service)', 'dendrila-calendar' ) . '</option>';
        foreach ( $choices as $id => $name ) {
            echo '<option value="' . esc_attr( $id ) . '"' . selected( $selected, (int) $id, false ) . '>' . esc_html( $name ) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__( 'Choose a simple, virtual product that can be purchased now. Its WooCommerce price is used for payment.', 'dendrila-calendar' ) . '</p>';
    }

    /** Called after the booking hold has been saved and its initial lock released. */
    public static function prepare_payment( $booking_id, array $service, array $customer ) {
        if ( ! self::available() || ! function_exists( 'wc_create_order' ) ) {
            return self::payment_error();
        }
        $booking_id = absint( $booking_id );
        return Dendrila_Calendar_Booking::with_profile_lock( $service['profile_id'] ?? 0, static function () use ( $booking_id, $service, $customer ) {
            wp_cache_delete( $booking_id, 'post_meta' );
            $booking = get_post( $booking_id );
            $service_id = (int) get_post_meta( $booking_id, Dendrila_Calendar_Native::SERVICE_KEY, true );
            $profile_id = (int) get_post_meta( $booking_id, Dendrila_Calendar_Native::PROFILE_KEY, true );
            if ( ! $booking || Dendrila_Calendar_Native::BOOKING !== $booking->post_type || 'private' !== $booking->post_status ||
                 $service_id !== (int) ( $service['service_id'] ?? 0 ) || $profile_id !== (int) ( $service['profile_id'] ?? 0 ) ) {
                return self::payment_error();
            }
            // A retry reuses this order, including its original price. Never create a second order.
            $order_id = absint( get_post_meta( $booking_id, self::ORDER_KEY, true ) );
            if ( $order_id ) {
                $order = self::order( $order_id, true );
                if ( ! $order || (int) $order->get_meta( self::BOOKING_KEY, true ) !== $booking_id || ! $order->get_meta( self::READY_KEY, true ) ) {
                    return self::payment_error();
                }
                return self::payment_result( $booking_id, $order );
            }
            $valid = Dendrila_Calendar_Booking::can_pay( $booking_id );
            if ( is_wp_error( $valid ) || true !== $valid ) { return is_wp_error( $valid ) ? $valid : self::payment_error(); }
            $product = self::product_for_service( $service_id );
            $held_product = (int) get_post_meta( $booking_id, '_dendrila_booking_product_id', true );
            if ( $held_product && ( ! $product || $product->get_id() !== $held_product ) ) { return self::payment_error(); }
            $name = sanitize_text_field( $customer['name'] ?? '' );
            $email = sanitize_email( $customer['email'] ?? '' );
            if ( ! $product || '' === $name || ! is_email( $email ) ) { return self::payment_error(); }
            $order = false;
            try {
                $order = wc_create_order( array( 'status' => 'pending', 'customer_id' => get_current_user_id(), 'created_via' => 'dendrila-calendar' ) );
                if ( is_wp_error( $order ) ) { return self::payment_error(); }
                $meta = array(
                    self::BOOKING_KEY => $booking_id, '_dendrila_service_id' => $service_id, '_dendrila_profile_id' => $profile_id,
                    '_dendrila_start_utc' => get_post_meta( $booking_id, Dendrila_Calendar_Native::START_KEY, true ),
                    '_dendrila_end_utc' => get_post_meta( $booking_id, Dendrila_Calendar_Native::END_KEY, true ),
                );
                foreach ( $meta as $key => $value ) { $order->update_meta_data( $key, $value ); }
                $order->set_billing_first_name( $name );
                $order->set_billing_email( $email );
                $order->save();
                update_post_meta( $booking_id, self::ORDER_KEY, $order->get_id() );
                if ( (int) get_post_meta( $booking_id, self::ORDER_KEY, true ) !== $order->get_id() ) { throw new RuntimeException( 'Booking order link not saved' ); }
                $item_id = $order->add_product( $product, 1 );
                $item = $item_id ? $order->get_item( $item_id ) : false;
                if ( ! $item ) { throw new RuntimeException( 'Booking order item not saved' ); }
                foreach ( $meta as $key => $value ) { $item->add_meta_data( $key, $value, true ); }
                $item->save();
                $order->calculate_totals();
                if ( function_exists( 'wc_reserve_stock_for_order' ) ) { wc_reserve_stock_for_order( $order ); }
                $order->update_meta_data( self::READY_KEY, 1 );
                $order->save();
                // A zero-price Woo product still has a real order and follows the same reconciliation hooks.
                if ( 0.0 === (float) $order->get_total() && ! $order->payment_complete() ) {
                    throw new RuntimeException( 'Free order was not completed' );
                }
                return self::payment_result( $booking_id, $order );
            } catch ( Throwable $error ) {
                if ( $order && ! is_wp_error( $order ) && $order->has_status( 'pending' ) && ! $order->is_paid() ) {
                    try { $order->update_status( 'cancelled', __( 'Dendrila could not prepare this booking payment.', 'dendrila-calendar' ) ); }
                    catch ( Throwable $cleanup_error ) { /* Keep the recorded order ID: a retry must never duplicate it. */ }
                }
                return self::payment_error();
            }
        } );
    }

    private static function payment_error() {
        return new WP_Error( 'dendrila_payment_unavailable', __( 'Payment could not be prepared. Please choose a time again or contact the organizer.', 'dendrila-calendar' ) );
    }

    private static function payment_result( $booking_id, $order ) {
        $status = get_post_meta( $booking_id, Dendrila_Calendar_Native::STATUS_KEY, true );
        if ( $order->is_paid() || $order->has_status( 'on-hold' ) ) {
            return array( 'order_id' => $order->get_id(), 'checkout_url' => $order->get_checkout_order_received_url(), 'status' => $status );
        }
        $valid = Dendrila_Calendar_Booking::can_pay( $booking_id );
        if ( true !== $valid ) { return is_wp_error( $valid ) ? $valid : self::payment_error(); }
        if ( ! $order->has_status( 'pending' ) || ! $order->needs_payment() ) { return self::payment_error(); }
        return array( 'order_id' => $order->get_id(), 'checkout_url' => $order->get_checkout_payment_url(), 'status' => 'pending_payment' );
    }

    public static function reconcile_order( $order_id ) {
        Dendrila_Calendar_Booking::reconcile_order( absint( $order_id ) );
    }

    /** The standard Woo pay action runs this before choosing or calling a gateway. */
    public static function before_pay( $order ) {
        $booking_id = absint( $order->get_meta( self::BOOKING_KEY, true ) );
        if ( ! $booking_id ) { return; }
        wp_cache_delete( $booking_id, 'post_meta' );
        $valid = (int) get_post_meta( $booking_id, self::ORDER_KEY, true ) === $order->get_id() && $order->get_meta( self::READY_KEY, true ) ?
            Dendrila_Calendar_Booking::can_pay( $booking_id ) : self::payment_error();
        if ( true === $valid ) { return; }
        wc_add_notice( is_wp_error( $valid ) ? $valid->get_error_message() : self::payment_error()->get_error_message(), 'error' );
        wp_safe_redirect( $order->get_checkout_order_received_url() );
        exit;
    }

    public static function stock_hold_minutes( $minutes, $order ) {
        $id = absint( $order->get_meta( self::BOOKING_KEY, true ) );
        if ( ! $id ) { return $minutes; }
        return max( 1, (int) ceil( ( (int) get_post_meta( $id, '_dendrila_booking_hold_until', true ) - time() ) / 60 ) );
    }

    /** Caller has already checked the booking token or administrator capability. */
    public static function cancel_order( $booking_id ) {
        $profile = (int) get_post_meta( $booking_id, Dendrila_Calendar_Native::PROFILE_KEY, true );
        return Dendrila_Calendar_Booking::with_profile_lock( $profile, static function () use ( $booking_id ) {
            $order = self::order( get_post_meta( $booking_id, self::ORDER_KEY, true ), true );
            if ( $order && (int) $order->get_meta( self::BOOKING_KEY, true ) === (int) $booking_id && $order->has_status( 'pending' ) && ! $order->is_paid() ) {
                $order->update_status( 'cancelled', __( 'The appointment hold was released.', 'dendrila-calendar' ) );
                return true;
            }
            return false;
        } );
    }

    public static function thankyou( $order_id ) {
        $order = self::order( $order_id );
        if ( ! $order ) { return; }
        $id = absint( $order->get_meta( self::BOOKING_KEY, true ) );
        if ( ! $id || (int) get_post_meta( $id, self::ORDER_KEY, true ) !== $order->get_id() ) { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only order receipt, authorized by Woo's order key.
        $key = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
        if ( ! current_user_can( 'manage_woocommerce' ) && ! hash_equals( $order->get_order_key(), $key ) ) { return; }
        $status = get_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, true );
        $message = __( 'This appointment is not confirmed. Please contact the organizer or choose another time.', 'dendrila-calendar' );
        if ( 'confirmed' === $status ) { $message = __( 'Your appointment is confirmed.', 'dendrila-calendar' ); }
        elseif ( 'payment_on_hold' === $status ) { $message = __( 'Your payment is awaiting confirmation. Your appointment time remains reserved.', 'dendrila-calendar' ); }
        elseif ( 'pending_payment' === $status && $order->is_paid() ) { $message = __( 'Payment has been received. Appointment confirmation is still being checked.', 'dendrila-calendar' ); }
        elseif ( 'pending_payment' === $status && true === Dendrila_Calendar_Booking::can_pay( $id ) ) { $message = __( 'Your appointment is awaiting payment. It is not confirmed yet.', 'dendrila-calendar' ); }
        elseif ( 'payment_conflict' === $status ) { $message = __( 'Payment was received, but the appointment could not be confirmed. Please contact the organizer.', 'dendrila-calendar' ); }
        echo '<section class="dendrila-booking-confirmation"><h2>' . esc_html__( 'Your appointment', 'dendrila-calendar' ) . '</h2><p>' . esc_html( $message ) . '</p></section>';
    }

    /** Under a booking lock, refresh both Woo cache layers before reading authoritative order state. */
    public static function order( $order_id, $fresh = false ) {
        $order_id = absint( $order_id );
        if ( ! self::available() || $order_id < 1 ) {
            return false;
        }
        if ( $fresh ) {
            if ( function_exists( 'wc_get_container' ) && class_exists( '\Automattic\WooCommerce\Caches\OrderCache' ) ) {
                wc_get_container()->get( \Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
            }
            if ( class_exists( 'WC_Data_Store' ) ) {
                $store = WC_Data_Store::load( 'order' );
                // The wrapper exposes __call for every name. Check the actual
                // datastore's public methods before using its optional cache API.
                if ( in_array( 'clear_cached_data', get_class_methods( $store->get_current_class_name() ), true ) ) {
                    $store->clear_cached_data( array( $order_id ) );
                }
            }
            clean_post_cache( $order_id );
            wp_cache_delete( $order_id, 'post_meta' );
        }
        return wc_get_order( $order_id );
    }
}
