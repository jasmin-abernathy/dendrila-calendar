<?php
/** Cross-process HPOS regression: refresh both cached objects and datastore rows under the booking lock. */
require __DIR__ . '/booking-lab-bootstrap.php';

if ( '--pay-worker' === ( $argv[1] ?? '' ) ) {
    $order_id = absint( $argv[2] );
    foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_changed', 'woocommerce_order_refunded' ) as $hook ) {
        remove_action( $hook, array( 'Dendrila_Calendar_Woo', 'reconcile_order' ), 10 );
    }
    $order = wc_get_order( $order_id );
    $order->payment_complete( 'local-cache-probe' );
    $order->update_meta_data( '_dendrila_cache_probe', 'changed-by-another-process' );
    $order->save();
    if ( 'confirmed' === ( $argv[3] ?? '' ) ) {
        $id = (int) $order->get_meta( '_dendrila_booking_id' );
        update_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, 'confirmed' );
        update_post_meta( $id, '_dendrila_booking_hold_until', 0 );
    }
    echo json_encode( array( 'paid' => $order->is_paid(), 'status' => $order->get_status() ) ) . "\n";
    exit;
}

$cache_option = 'woocommerce_hpos_datastore_caching_enabled';
$previous_cache_option = get_option( $cache_option, 'no' );
try {
    update_option( $cache_option, 'yes' );
    lab_assert( Automattic\WooCommerce\Utilities\OrderUtil::orders_cache_usage_is_enabled() &&
        Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_datastore_cache_enabled(), 'Both HPOS cache layers must be enabled for this regression.' );
    $profile = lab_profile( 'HPOS cache' );
    $product = lab_product();
    $service = lab_service( $profile, $product->get_id() );
    foreach ( array( 'pending', 'on-hold' ) as $initial_status ) {
        $result = Dendrila_Calendar_Booking::create( lab_data( $service, lab_slot( $service ) ) );
        lab_assert( ! is_wp_error( $result ), 'Cache fixture booking failed.' );
        $order_id = $result['order_id']; $booking_id = $result['booking_id'];
        $order = wc_get_order( $order_id );
        if ( 'on-hold' === $initial_status ) { $order->update_status( 'on-hold' ); }
        $order->update_meta_data( '_dendrila_cache_probe', 'old-value' ); $order->save();
        $cached = wc_get_order( $order_id );
        lab_assert( $initial_status === $cached->get_status() && 'old-value' === $cached->get_meta( '_dendrila_cache_probe' ), 'Parent did not prewarm order state and metadata.' );

        $process = proc_open( array( getenv( 'DENDRILA_BOOKING_PHP' ) ?: PHP_BINARY, __FILE__, '--pay-worker', (string) $order_id,
            'on-hold' === $initial_status ? 'confirmed' : 'unreconciled' ),
            array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
        fclose( $pipes[0] );
        $output = stream_get_contents( $pipes[1] ); $errors = stream_get_contents( $pipes[2] );
        fclose( $pipes[1] ); fclose( $pipes[2] );
        $exit = proc_close( $process ); $worker = json_decode( trim( $output ), true );
        lab_assert( 0 === $exit && ! empty( $worker['paid'] ), 'Payment worker failed: ' . $output . $errors );

        $stale = wc_get_order( $order_id );
        lab_assert( ! $stale->is_paid() && $initial_status === $stale->get_status(), 'The object-cache regression was not reproduced.' );
        wc_get_container()->get( Automattic\WooCommerce\Caches\OrderCache::class )->remove( $order_id );
        $data_stale = wc_get_order( $order_id );
        lab_assert( ! $data_stale->is_paid() && 'old-value' === $data_stale->get_meta( '_dendrila_cache_probe' ), 'The datastore-cache regression was not reproduced.' );

        if ( 'pending' === $initial_status ) {
            $fresh = Dendrila_Calendar_Booking::with_profile_lock( $profile, static function () use ( $order_id ) {
                return Dendrila_Calendar_Woo::order( $order_id, true );
            } );
            lab_assert( $fresh && $fresh->is_paid() && 'changed-by-another-process' === $fresh->get_meta( '_dendrila_cache_probe' ), 'Fresh order retained stale status or metadata.' );
        }
        // For on-hold, the order is still stale on entry. Reconciliation itself
        // must refresh it and must not demote the booking confirmed by worker B.
        Dendrila_Calendar_Booking::reconcile_order( $order_id );
        lab_assert( 'confirmed' === lab_status( $booking_id ), 'A cached order demoted or failed to confirm the booking.' );
        lab_assert( Dendrila_Calendar_Woo::order( $order_id )->is_paid(), 'Reconciliation left an unpaid order object in cache.' );
    }
    echo $GLOBALS['lab_checks'] . " HPOS cross-process cache checks passed: object cache, datastore cache, metadata freshness and no booking demotion\n";
} finally {
    lab_cleanup();
    update_option( $cache_option, $previous_cache_option );
}
