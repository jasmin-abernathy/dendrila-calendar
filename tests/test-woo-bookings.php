<?php
/** Real WordPress + WooCommerce/HPOS integration; no payment gateway or external account. */
require __DIR__ . '/booking-lab-bootstrap.php';
try {
    lab_assert( Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'This integration run must exercise HPOS.' );
    $profile = lab_profile();
    $product = lab_product();
    $service = lab_service( $profile, $product->get_id() );
    update_post_meta( $service, '_dendrila_price_cents', 999999 );
    $product->set_virtual( false ); $product->save();
    lab_assert( ! Dendrila_Calendar_Woo::product_for_service( $service ), 'Physical products cannot back an appointment.' );
    $product->set_virtual( true ); $product->set_stock_status( 'outofstock' ); $product->save();
    lab_assert( ! Dendrila_Calendar_Woo::product_for_service( $service ), 'Out-of-stock products cannot back an appointment.' );
    $product->set_stock_status( 'instock' ); $product->save();

    $data = lab_data( $service, lab_slot( $service ) );
    $created = Dendrila_Calendar_Booking::create( $data );
    lab_assert( ! is_wp_error( $created ), 'Paid booking failed: ' . ( is_wp_error( $created ) ? $created->get_error_message() : '' ) );
    $booking_id = $created['booking_id']; $order_id = $created['order_id'];
    $order = wc_get_order( $order_id );
    lab_assert( 'pending_payment' === $created['status'] && 'private' === get_post_status( $booking_id ), 'Payment must start with a private, unconfirmed hold.' );
    lab_assert( 19.5 === (float) $order->get_total(), 'Woo price must win over legacy service cents.' );
    lab_assert( $order->get_checkout_payment_url() === $created['checkout_url'], 'Use Woo official order-payment URL.' );
    lab_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ) ), 'Order was not saved through HPOS.' );
    $item = current( $order->get_items() );
    lab_assert( (int) $item->get_meta( '_dendrila_booking_id' ) === $booking_id && (int) $item->get_meta( '_dendrila_profile_id' ) === $profile &&
        $item->get_meta( '_dendrila_start_utc' ) === get_post_meta( $booking_id, Dendrila_Calendar_Native::START_KEY, true ), 'Order line lost private booking identifiers or UTC time.' );
    $retry = Dendrila_Calendar_Booking::create( $data );
    lab_assert( ! is_wp_error( $retry ) && $retry['booking_id'] === $booking_id && $retry['order_id'] === $order_id, 'Retry created another booking/order.' );
    $product->set_regular_price( '29.90' ); $product->save();
    $retry = Dendrila_Calendar_Woo::prepare_payment( $booking_id, Dendrila_Calendar_Native::service_data( $service ), array( 'name' => $data['name'], 'email' => $data['email'] ) );
    lab_assert( ! is_wp_error( $retry ) && $retry['order_id'] === $order_id && 19.5 === (float) wc_get_order( $order_id )->get_total(), 'Retry repriced or duplicated an existing order.' );
    $product->set_regular_price( '19.50' ); $product->save();

    Dendrila_Calendar_Woo::before_pay( $order );
    $order->payment_complete( 'local-no-gateway' );
    lab_assert( 'confirmed' === lab_status( $booking_id ), 'Woo payment did not confirm the booking.' );
    lab_assert( is_wp_error( Dendrila_Calendar_Booking::cancel( $booking_id, $created['cancel_token'] ) ), 'Customer must not cancel a paid booking without organizer review.' );
    lab_assert( false === Dendrila_Calendar_Woo::cancel_order( $booking_id ) && wc_get_order( $order_id )->is_paid(), 'cancel_order changed a paid order.' );
    $refund = wc_create_refund( array( 'order_id' => $order_id, 'amount' => 1.00, 'refund_payment' => false, 'restock_items' => false ) );
    lab_assert( ! is_wp_error( $refund ) && 'confirmed' === lab_status( $booking_id ), 'A partial manual refund must retain the appointment.' );
    $refund = wc_create_refund( array( 'order_id' => $order_id, 'amount' => 18.50, 'refund_payment' => false, 'restock_items' => false ) );
    lab_assert( ! is_wp_error( $refund ) && 'refunded' === lab_status( $booking_id ), 'A full manual refund must release the appointment.' );

    $pending = Dendrila_Calendar_Booking::create( lab_data( $service, lab_slot( $service ) ) );
    lab_assert( ! is_wp_error( $pending ), 'Pending cancellation fixture failed.' );
    Dendrila_Calendar_Booking::cancel( $pending['booking_id'], $pending['cancel_token'] );
    lab_assert( wc_get_order( $pending['order_id'] )->has_status( 'cancelled' ) && 'cancelled' === lab_status( $pending['booking_id'] ), 'Customer cancellation did not release the unpaid order.' );

    $manual = Dendrila_Calendar_Booking::create( lab_data( $service, lab_slot( $service ) ) );
    lab_assert( ! is_wp_error( $manual ), 'Manual payment fixture failed.' );
    $manual_order = wc_get_order( $manual['order_id'] ); $manual_order->update_status( 'on-hold' );
    lab_assert( 'payment_on_hold' === lab_status( $manual['booking_id'] ) && true === Dendrila_Calendar_Booking::can_pay( $manual['booking_id'] ), 'Manual payment must retain its reservation.' );
    lab_assert( false === Dendrila_Calendar_Woo::cancel_order( $manual['booking_id'] ) && wc_get_order( $manual['order_id'] )->has_status( 'on-hold' ), 'Only pending unpaid orders may be cancelled automatically.' );
    $manual_order->payment_complete( 'local-manual-confirmation' );
    lab_assert( 'confirmed' === lab_status( $manual['booking_id'] ), 'Manual payment confirmation did not confirm the booking.' );

    // Expired hold, no cron yet, and another visitor takes the same slot.
    $late_start = lab_slot( $service );
    $late = Dendrila_Calendar_Booking::create( lab_data( $service, $late_start ) );
    lab_assert( ! is_wp_error( $late ), 'Late payment fixture failed.' );
    update_post_meta( $late['booking_id'], '_dendrila_booking_hold_until', time() - 2 );
    $replacement = Dendrila_Calendar_Booking::create( lab_data( $service, $late_start ) );
    lab_assert( ! is_wp_error( $replacement ), 'An expired hold still blocked availability.' );
    $late_order = wc_get_order( $late['order_id'] );
    $redirected = false;
    $redirect_filter = static function ( $url ) use ( &$redirected, $late_order ) {
        $redirected = $url === $late_order->get_checkout_order_received_url();
        throw new RuntimeException( 'expected-payment-guard-redirect' );
    };
    add_filter( 'wp_redirect', $redirect_filter );
    $gateways_requested = 0;
    $gateway_filter = static function ( $gateways ) use ( &$gateways_requested ) { ++$gateways_requested; return $gateways; };
    add_filter( 'woocommerce_available_payment_gateways', $gateway_filter );
    global $wp;
    $saved_request = array( $_GET, $_POST, $_REQUEST, $wp->query_vars );
    $_GET = array( 'key' => $late_order->get_order_key() );
    $_POST = array( 'woocommerce_pay' => '1', 'payment_method' => 'dendrila-lab-never-called' );
    $_REQUEST = array( 'woocommerce-pay-nonce' => wp_create_nonce( 'woocommerce-pay' ) );
    $wp->query_vars['order-pay'] = $late_order->get_id();
    $buffer_level = ob_get_level();
    try { WC_Form_Handler::pay_action(); }
    catch ( RuntimeException $error ) { if ( 'expected-payment-guard-redirect' !== $error->getMessage() ) { throw $error; } }
    finally {
        remove_filter( 'wp_redirect', $redirect_filter );
        remove_filter( 'woocommerce_available_payment_gateways', $gateway_filter );
        list( $_GET, $_POST, $_REQUEST, $wp->query_vars ) = $saved_request;
        while ( ob_get_level() > $buffer_level ) { ob_end_clean(); }
    }
    lab_assert( $redirected && wc_notice_count( 'error' ) > 0, 'Expired payment was not stopped with a Woo notice and safe receipt redirect.' );
    lab_assert( 0 === $gateways_requested, 'Expired booking reached Woo gateway selection.' );
    wc_clear_notices();
    $late_order->payment_complete( 'local-delayed-callback' );
    lab_assert( 'payment_conflict' === lab_status( $late['booking_id'] ) && 'pending_payment' === lab_status( $replacement['booking_id'] ), 'Late payment stole a slot or falsely confirmed it.' );
    lab_assert( (bool) wc_get_order( $late['order_id'] )->get_meta( '_dendrila_booking_payment_review' ), 'Late payment needs an organizer review marker.' );

    $late_free = Dendrila_Calendar_Booking::create( lab_data( $service, lab_slot( $service ) ) );
    lab_assert( ! is_wp_error( $late_free ), 'Available late payment fixture failed.' );
    update_post_meta( $late_free['booking_id'], '_dendrila_booking_hold_until', time() - 2 );
    wc_get_order( $late_free['order_id'] )->payment_complete( 'local-delayed-available' );
    lab_assert( 'confirmed' === lab_status( $late_free['booking_id'] ), 'Late payment for a still available slot was not revalidated.' );

    $product->set_regular_price( '0' ); $product->save();
    $zero = Dendrila_Calendar_Booking::create( lab_data( $service, lab_slot( $service ) ) );
    lab_assert( ! is_wp_error( $zero ) && 'confirmed' === $zero['status'] && 0.0 === (float) wc_get_order( $zero['order_id'] )->get_total() &&
        wc_get_order( $zero['order_id'] )->is_paid(), 'Zero-price Woo product must still produce a completed order and confirmed booking.' );

    $changed_product = lab_product( '49.90' );
    $held_start = str_replace( array( 'T', 'Z' ), array( ' ', '' ), lab_slot( $service ) );
    $held = wp_insert_post( array( 'post_type' => Dendrila_Calendar_Native::BOOKING, 'post_status' => 'private', 'post_title' => '[Dendrila lab] Held configuration',
        'meta_input' => array( Dendrila_Calendar_Native::PROFILE_KEY => $profile, Dendrila_Calendar_Native::SERVICE_KEY => $service,
            Dendrila_Calendar_Native::START_KEY => $held_start, Dendrila_Calendar_Native::END_KEY => gmdate( 'Y-m-d H:i:s', strtotime( $held_start . ' UTC' ) + 1800 ),
            Dendrila_Calendar_Native::STATUS_KEY => 'pending_payment', '_dendrila_booking_hold_until' => time() + 1200,
            '_dendrila_booking_product_id' => $product->get_id() ) ), true );
    update_post_meta( $service, '_dendrila_woo_product_id', $changed_product->get_id() );
    $changed = Dendrila_Calendar_Woo::prepare_payment( $held, Dendrila_Calendar_Native::service_data( $service ), array( 'name' => 'Calendar test', 'email' => 'calendar-test@example.invalid' ) );
    lab_assert( is_wp_error( $changed ) && ! get_post_meta( $held, Dendrila_Calendar_Woo::ORDER_KEY, true ), 'Changing the product after the hold created an order for another product.' );
    update_post_meta( $service, '_dendrila_woo_product_id', $product->get_id() );

    wp_set_current_user( 0 ); $_GET = array();
    ob_start(); Dendrila_Calendar_Woo::thankyou( $zero['order_id'] ); $hidden = ob_get_clean();
    lab_assert( '' === $hidden, 'Receipt leaked booking status without an order key.' );
    $_GET['key'] = wc_get_order( $zero['order_id'] )->get_order_key();
    ob_start(); Dendrila_Calendar_Woo::thankyou( $zero['order_id'] ); $receipt = ob_get_clean();
    lab_assert( false !== strpos( $receipt, 'Your appointment is confirmed.' ), 'Receipt did not show authoritative booking confirmation.' );
    $_GET['key'] = wc_get_order( $late['order_id'] )->get_order_key();
    ob_start(); Dendrila_Calendar_Woo::thankyou( $late['order_id'] ); $receipt = ob_get_clean();
    lab_assert( false !== strpos( $receipt, 'could not be confirmed' ), 'Receipt falsely confirmed a late conflicting payment.' );
    wp_set_current_user( 1 );

    update_post_meta( $service, '_dendrila_woo_product_id', 999999999 );
    lab_assert( Dendrila_Calendar_Woo::requires_payment( Dendrila_Calendar_Native::service_data( $service ) ) && false === Dendrila_Calendar_Booking::public_service( $service ), 'Invalid assigned product silently became a free service.' );
    update_post_meta( $service, '_dendrila_woo_product_id', 0 );
    lab_assert( false === Dendrila_Calendar_Booking::public_service( $service ), 'Legacy paid service without a product silently became free.' );
    update_post_meta( $service, '_dendrila_woo_product_id', $product->get_id() );
    ob_start(); Dendrila_Calendar_Woo::render_product_field( $service, $product->get_id() ); $selector = ob_get_clean();
    lab_assert( false !== strpos( $selector, 'Appointment product' ) && false !== strpos( $selector, 'selected=' ), 'Product selector lost its name or saved selection.' );
    echo $GLOBALS['lab_checks'] . " real WordPress/WooCommerce HPOS booking checks passed; no gateway, real email or external HTTP used\n";
} finally {
    lab_cleanup();
}
