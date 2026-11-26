<?php
/** Real integration fixtures. Requires an explicitly isolated local WordPress database. */
$lab_root = getenv( 'DENDRILA_BOOKING_WP_ROOT' );
if ( ! $lab_root || ! is_file( $lab_root . '/wp-load.php' ) ) {
    throw new RuntimeException( 'Set DENDRILA_BOOKING_WP_ROOT to the isolated WordPress booking lab.' );
}
$_SERVER['HTTP_HOST'] = '127.0.0.1:8097';
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once $lab_root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! preg_match( '/_lab$/', DB_NAME ) || ! WP_HTTP_BLOCK_EXTERNAL ) {
    throw new RuntimeException( 'Integration tests refuse a non-local database or external HTTP access.' );
}
set_exception_handler( static function ( $error ) {
    fwrite( STDERR, get_class( $error ) . ': ' . $error->getMessage() . "\n" );
    exit( 1 );
} );
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );
require_once dirname( __DIR__ ) . '/includes/class-dendrila-calendar.php';
foreach ( array( 'native', 'oauth', 'schedule', 'booking', 'woo' ) as $component ) {
    require_once dirname( __DIR__ ) . '/includes/class-dendrila-calendar-' . $component . '.php';
}
Dendrila_Calendar_Native::register_types();
Dendrila_Calendar_Booking::boot();
Dendrila_Calendar_Woo::boot();
wp_set_current_user( 1 );
$GLOBALS['lab_profiles'] = array();
$GLOBALS['lab_services'] = array();
$GLOBALS['lab_products'] = array();
$GLOBALS['lab_checks'] = 0;

function lab_assert( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$GLOBALS['lab_checks'];
}

function lab_profile( $name = 'Profile' ) {
    $id = wp_insert_post( array( 'post_type' => Dendrila_Calendar_Native::PROFILE, 'post_status' => 'publish', 'post_title' => '[Dendrila lab] ' . $name ), true );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
    $GLOBALS['lab_profiles'][] = $id;
    $settings = Dendrila_Calendar_Schedule::defaults_profile();
    $settings['enabled'] = true;
    $settings['timezone'] = 'Europe/Paris';
    $settings['check_external'] = false;
    $settings['weekly'] = array_fill( 1, 7, array( array( 'start' => '09:00', 'end' => '18:00' ) ) );
    update_post_meta( $id, Dendrila_Calendar_Schedule::PROFILE_META, $settings );
    return $id;
}

function lab_service( $profile, $product_id = 0, $name = 'Service' ) {
    $id = wp_insert_post( array( 'post_type' => Dendrila_Calendar_Native::SERVICE, 'post_status' => 'publish', 'post_title' => '[Dendrila lab] ' . $name,
        'meta_input' => array( Dendrila_Calendar_Native::PROFILE_KEY => $profile, '_dendrila_duration_minutes' => 30,
            '_dendrila_buffer_before' => 0, '_dendrila_buffer_after' => 0, '_dendrila_price_cents' => 0, '_dendrila_woo_product_id' => $product_id ) ), true );
    if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
    $GLOBALS['lab_services'][] = $id;
    $rules = Dendrila_Calendar_Schedule::defaults_rules();
    $rules['enabled'] = true; $rules['notice_minutes'] = 0; $rules['horizon_days'] = 10; $rules['slot_step'] = 30;
    update_post_meta( $id, Dendrila_Calendar_Schedule::RULES_META, $rules );
    return $id;
}

function lab_product( $price = '19.50' ) {
    $product = new WC_Product_Simple();
    $product->set_name( '[Dendrila lab] Appointment product' );
    $product->set_virtual( true ); $product->set_regular_price( $price ); $product->set_status( 'publish' );
    $product->set_stock_status( 'instock' ); $product->save();
    $GLOBALS['lab_products'][] = $product->get_id();
    return $product;
}

function lab_data( $service_id, $start, $request = '' ) {
    return array( 'service_id' => $service_id, 'start' => $start, 'name' => 'Calendar test', 'email' => 'calendar-test@example.invalid',
        'consent' => '1', 'website' => '', 'request_id' => $request ?: bin2hex( random_bytes( 16 ) ) );
}

function lab_slot( $service_id, $index = 0 ) {
    $day = ( new DateTimeImmutable( 'tomorrow', new DateTimeZone( 'Europe/Paris' ) ) )->format( 'Y-m-d' );
    $slots = Dendrila_Calendar_Schedule::slots( $service_id, $day );
    if ( is_wp_error( $slots ) || ! isset( $slots[ $index ] ) ) { throw new RuntimeException( 'No fixture slot available' ); }
    return $slots[ $index ]['start'];
}

function lab_status( $id ) {
    wp_cache_delete( $id, 'post_meta' );
    return get_post_meta( $id, Dendrila_Calendar_Native::STATUS_KEY, true );
}

function lab_cleanup() {
    wp_set_current_user( 1 );
    foreach ( $GLOBALS['lab_profiles'] as $profile ) {
        foreach ( get_posts( array( 'post_type' => Dendrila_Calendar_Native::BOOKING, 'post_status' => 'any', 'numberposts' => -1,
            'meta_key' => Dendrila_Calendar_Native::PROFILE_KEY, 'meta_value' => $profile ) ) as $booking ) {
            $order = Dendrila_Calendar_Woo::order( get_post_meta( $booking->ID, Dendrila_Calendar_Woo::ORDER_KEY, true ) );
            if ( $order ) { $order->delete( true ); }
            wp_clear_scheduled_hook( Dendrila_Calendar_Booking::EXPIRE_HOOK, array( (int) $booking->ID ) );
            wp_delete_post( $booking->ID, true );
        }
    }
    foreach ( array_merge( $GLOBALS['lab_services'], $GLOBALS['lab_profiles'] ) as $id ) { wp_delete_post( $id, true ); }
    foreach ( $GLOBALS['lab_products'] as $id ) { $product = wc_get_product( $id ); if ( $product ) { $product->delete( true ); } }
}
