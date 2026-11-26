<?php
/** Real Woo product editor workflow; isolated WordPress only, no gateway, email or external HTTP. */
require __DIR__ . '/booking-lab-bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-dendrila-calendar-schedule-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-dendrila-calendar-woo-service.php';
require_once dirname( __DIR__ ) . '/includes/class-dendrila-calendar-booking-form.php';
Dendrila_Calendar_Native::boot();
Dendrila_Calendar_Schedule_Admin::boot();
Dendrila_Calendar_Woo_Service::boot();
$woo_service_original_cart = null;

function woo_service_request( $product, $profile, $changes = array() ) {
    $rules = Dendrila_Calendar_Schedule::defaults_rules();
    $rules['enabled'] = '1'; $rules['notice_minutes'] = 0; $rules['horizon_days'] = 30; $rules['slot_step'] = 15;
    return array_replace( array(
        'dendrila_product_booking_nonce' => wp_create_nonce( 'dendrila_product_booking_' . $product->get_id() ),
        'dendrila_product_booking' => '1', 'dendrila_product_profile' => (string) $profile,
        'dendrila_product_duration' => '45', 'dendrila_product_before' => '10', 'dendrila_product_after' => '15',
        'dendrila_schedule_rules' => $rules,
    ), $changes );
}

function woo_service_save( $product, $request ) {
    $_POST = $request;
    do_action( 'woocommerce_admin_process_product_object', $product );
    $product->save();
    $_POST = array();
    clean_post_cache( $product->get_id() );
    return wc_get_product( $product->get_id() );
}

function woo_service_snapshot( $service ) {
    $out = array();
    foreach ( array( Dendrila_Calendar_Native::PROFILE_KEY, '_dendrila_duration_minutes', '_dendrila_buffer_before', '_dendrila_buffer_after',
        '_dendrila_woo_product_id', Dendrila_Calendar_Schedule::RULES_META ) as $key ) {
        $out[ $key ] = get_post_meta( $service, $key, true );
    }
    return $out;
}

function woo_service_panel( $product ) {
    $GLOBALS['product_object'] = $product;
    $GLOBALS['post'] = get_post( $product->get_id() );
    ob_start(); Dendrila_Calendar_Woo_Service::panel(); return ob_get_clean();
}

function woo_service_remember( $service ) {
    if ( $service && ! in_array( $service, $GLOBALS['lab_services'], true ) ) { $GLOBALS['lab_services'][] = $service; }
}

try {
    lab_assert( current_user_can( 'manage_options' ), 'Product settings require the isolated administrator fixture.' );
    $profile = lab_profile( 'Product appointment profile' );
    $other_profile = lab_profile( 'Replacement product profile' );
    $tabs = apply_filters( 'woocommerce_product_data_tabs', array() );
    lab_assert( isset( $tabs['dendrila_booking'] ) && 'dendrila-product-booking' === $tabs['dendrila_booking']['target'], 'Woo product data needs its appointment settings tab.' );

    // The classic new-product screen already owns an auto-draft post ID before rendering its nonce.
    $product_id = wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'auto-draft', 'post_title' => '[Dendrila lab] New appointment product' ), true );
    lab_assert( ! is_wp_error( $product_id ), 'Could not create the new product editor fixture.' );
    $GLOBALS['lab_products'][] = $product_id;
    $product = new WC_Product_Simple( $product_id );
    $panel = woo_service_panel( $product );
    lab_assert( false !== strpos( $panel, 'dendrila_product_booking_nonce' ) && false !== strpos( $panel, 'dendrila_schedule_rules' ), 'New product panel needs a nonce and booking-rule controls.' );
    preg_match( '/name="dendrila_product_booking_nonce" value="([^"]+)"/', $panel, $nonce_match );
    lab_assert( ! empty( $nonce_match[1] ) && wp_verify_nonce( html_entity_decode( $nonce_match[1], ENT_QUOTES ), 'dendrila_product_booking_' . $product_id ), 'The product panel nonce must match the real auto-draft product ID.' );
    $product->set_name( '[Dendrila lab] Consultation produit' );
    $product->set_description( '<p>Description complète de la consultation.</p>' );
    $product->set_short_description( 'Présentation courte.' );
    $product->set_regular_price( '23.75' ); $product->set_tax_status( 'none' );
    $product->set_virtual( false ); $product->set_stock_status( 'instock' ); $product->set_status( 'publish' );
    $request = woo_service_request( $product, $profile );
    $request['dendrila_product_booking_nonce'] = html_entity_decode( $nonce_match[1], ENT_QUOTES );
    $product = woo_service_save( $product, $request );
    $service = Dendrila_Calendar_Woo_Service::service_id( $product_id );
    woo_service_remember( $service );
    lab_assert( $service > 0, 'Saving a new appointment product must create its internal service.' );
    lab_assert( $product->is_virtual() && 'yes' === $product->get_meta( Dendrila_Calendar_Woo_Service::ENABLED_KEY, true ), 'Enabling appointment booking must persist a virtual enabled product.' );
    lab_assert( (int) $product->get_meta( Dendrila_Calendar_Woo_Service::SERVICE_KEY, true ) === $service &&
        (int) get_post_meta( $service, '_dendrila_woo_product_id', true ) === $product_id, 'Product and service must reference each other.' );
    lab_assert( get_the_title( $service ) === $product->get_name() && get_post_field( 'post_content', $service ) === $product->get_description() &&
        get_post_field( 'post_excerpt', $service ) === $product->get_short_description(), 'Service content must mirror the normal Woo product fields.' );
    lab_assert( (int) get_post_meta( $service, Dendrila_Calendar_Native::PROFILE_KEY, true ) === $profile &&
        45 === (int) get_post_meta( $service, '_dendrila_duration_minutes', true ) &&
        10 === (int) get_post_meta( $service, '_dendrila_buffer_before', true ) && 15 === (int) get_post_meta( $service, '_dendrila_buffer_after', true ), 'Product appointment settings did not reach the service.' );
    lab_assert( Dendrila_Calendar_Schedule::rules( $service )['enabled'] && 15 === Dendrila_Calendar_Schedule::rules( $service )['slot_step'], 'Product booking rules must open the intended service.' );
    $public = Dendrila_Calendar_Booking::public_service( $service );
    lab_assert( is_array( $public ) && $public['requires_payment'] && Dendrila_Calendar_Woo::price_text( $service ) === $public['price_text'], 'Public service must display its WooCommerce price.' );
    lab_assert( 0 === (int) get_post_meta( $service, '_dendrila_price_cents', true ), 'The projection must not maintain an independent legacy price.' );
    lab_assert( get_edit_post_link( $service, 'raw' ) === get_edit_post_link( $product_id, 'raw' ), 'Editing a paid service must lead to its product editor.' );

    // Actual Woo template hook and block filters expose the selector instead of direct add-to-cart.
    $old_query = $GLOBALS['wp_query'];
    $query = new WP_Query(); $query->is_singular = true; $query->is_single = true;
    $query->queried_object = get_post( $product_id ); $query->queried_object_id = $product_id;
    $GLOBALS['wp_query'] = $query; $GLOBALS['product'] = $product; $GLOBALS['post'] = get_post( $product_id );
    $cart_priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart' );
    try {
        Dendrila_Calendar_Woo_Service::product_page();
        lab_assert( false === has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart' ) &&
            30 === has_action( 'woocommerce_single_product_summary', array( 'Dendrila_Calendar_Woo_Service', 'product_form' ) ), 'Classic product page must replace direct add-to-cart with the booking form.' );
        ob_start(); Dendrila_Calendar_Woo_Service::product_form(); $public_form = ob_get_clean();
        lab_assert( false !== strpos( $public_form, 'data-dendrila-booking' ) &&
            false === strpos( $public_form, 'single_add_to_cart_button' ), 'The product page must render a real booking form, not a plain buy button.' );
    } finally {
        $GLOBALS['wp_query'] = $old_query;
        remove_action( 'woocommerce_single_product_summary', array( 'Dendrila_Calendar_Woo_Service', 'product_form' ), 30 );
        if ( false !== $cart_priority ) { add_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', $cart_priority ); }
    }
    $loop_button = apply_filters( 'woocommerce_loop_add_to_cart_link', '<button>Buy directly</button>', $product );
    lab_assert( false !== strpos( $loop_button, 'Choose an appointment time' ) && false !== strpos( $loop_button, esc_url( get_permalink( $product_id ) ) ), 'Catalog purchase button must lead to time selection.' );
    $block = new WP_Block( array( 'blockName' => 'woocommerce/add-to-cart-form', 'attrs' => array( 'productId' => $product_id ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ), array( 'postId' => $product_id ) );
    foreach ( array( 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options' ) as $block_name ) {
        $block_form = apply_filters( 'render_block', '<button>Buy directly</button>', array( 'blockName' => $block_name, 'attrs' => array( 'productId' => $product_id ) ), $block );
        lab_assert( false !== strpos( $block_form, 'data-dendrila-booking' ), 'Block product form must require a chosen time: ' . $block_name );
    }
    $block_link = apply_filters( 'render_block', '<button>Buy directly</button>', array( 'blockName' => 'woocommerce/product-button', 'attrs' => array( 'productId' => $product_id ) ), $block );
    lab_assert( false !== strpos( $block_link, 'Choose an appointment time' ), 'Product button block must lead to booking.' );

    if ( ! WC()->cart ) { wc_load_cart(); }
    $woo_service_original_cart = WC()->cart->get_cart();
    WC()->cart->set_cart_contents( array() ); wc_clear_notices();
    $_REQUEST = array( 'add-to-cart' => (string) $product_id, 'quantity' => '1' );
    $unexpected_redirect = static function ( $url ) { throw new RuntimeException( 'Direct purchase unexpectedly redirected before time selection.' ); };
    add_filter( 'wp_redirect', $unexpected_redirect );
    try { WC_Form_Handler::add_to_cart_action(); }
    finally { remove_filter( 'wp_redirect', $unexpected_redirect ); $_REQUEST = array(); }
    lab_assert( WC()->cart->is_empty() && wc_notice_count( 'error' ) > 0, 'Real classic add-to-cart handler must reject appointment products without a selected slot.' );
    wc_clear_notices();
    $store_controller = new Automattic\WooCommerce\StoreApi\Utilities\CartController();
    $store_blocked = false;
    // Exercise the Store API's dedicated guard independently of its legacy classic validation filter.
    remove_filter( 'woocommerce_add_to_cart_validation', array( 'Dendrila_Calendar_Woo_Service', 'guard_cart' ), 20 );
    try {
        $store_controller->add_to_cart( array( 'id' => $product_id, 'quantity' => 1 ) );
    } catch ( Automattic\WooCommerce\StoreApi\Exceptions\RouteException $error ) {
        $store_blocked = 'dendrila_appointment_time_required' === $error->getErrorCode() && 400 === $error->getCode();
    } finally { add_filter( 'woocommerce_add_to_cart_validation', array( 'Dendrila_Calendar_Woo_Service', 'guard_cart' ), 20, 2 ); }
    lab_assert( $store_blocked && WC()->cart->is_empty(), 'Real Store API controller must reject an appointment without a slot through its own guard.' );

    // A product added before it became a service must not bypass validation through an old cart.
    $old_item = array( 'product_id' => $product_id, 'variation_id' => 0, 'variation' => array(), 'quantity' => 1, 'data' => $product, 'data_hash' => wc_get_cart_item_data_hash( $product ), 'line_total' => 23.75, 'line_subtotal' => 23.75, 'line_tax' => 0, 'line_subtotal_tax' => 0, 'line_tax_data' => array( 'subtotal' => array(), 'total' => array() ) );
    WC()->cart->set_cart_contents( array( 'old-appointment-cart' => $old_item ) );
    do_action( 'woocommerce_check_cart_items' );
    lab_assert( wc_notice_count( 'error' ) > 0, 'An old classic cart must also require time selection.' );
    $cart_errors = new WP_Error();
    do_action( 'woocommerce_store_api_cart_errors', $cart_errors, WC()->cart );
    lab_assert( in_array( 'dendrila_appointment_time_required', $cart_errors->get_error_codes(), true ), 'An old Store API cart must also require time selection.' );
    WC()->cart->set_cart_contents( array() ); wc_clear_notices();

    $product->set_name( '[Dendrila lab] Consultation renommée' );
    $product->set_description( '<p>Texte révisé avec une apostrophe : l’association.</p>' );
    $product->set_short_description( 'Résumé révisé.' );
    $product->set_regular_price( '31.25' );
    $updated = woo_service_request( $product, $other_profile, array( 'dendrila_product_duration' => '60', 'dendrila_product_before' => '20', 'dendrila_product_after' => '25' ) );
    $updated['dendrila_schedule_rules']['notice_minutes'] = 90;
    $updated['dendrila_schedule_rules']['slot_step'] = 20;
    $product = woo_service_save( $product, $updated );
    lab_assert( $service === Dendrila_Calendar_Woo_Service::service_id( $product_id ), 'Updating the product must reuse the original internal service.' );
    $mirrors = get_posts( array( 'post_type' => Dendrila_Calendar_Native::SERVICE, 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_dendrila_woo_product_id', 'meta_value' => $product_id ) );
    lab_assert( 1 === count( $mirrors ), 'Repeated product saves must not duplicate the service.' );
    lab_assert( get_the_title( $service ) === $product->get_name() && get_post_field( 'post_content', $service ) === $product->get_description() &&
        get_post_field( 'post_excerpt', $service ) === $product->get_short_description(), 'A product rename and description update must reach its projection.' );
    lab_assert( (int) get_post_meta( $service, Dendrila_Calendar_Native::PROFILE_KEY, true ) === $other_profile &&
        60 === (int) get_post_meta( $service, '_dendrila_duration_minutes', true ) &&
        20 === (int) get_post_meta( $service, '_dendrila_buffer_before', true ) && 25 === (int) get_post_meta( $service, '_dendrila_buffer_after', true ), 'Updated profile, duration or buffers were lost.' );
    lab_assert( 90 === Dendrila_Calendar_Schedule::rules( $service )['notice_minutes'] && 20 === Dendrila_Calendar_Schedule::rules( $service )['slot_step'], 'Updated booking rules were lost.' );
    lab_assert( 31.25 === (float) wc_get_product( $product_id )->get_price() &&
        Dendrila_Calendar_Woo::price_text( $service ) === Dendrila_Calendar_Booking::public_service( $service )['price_text'], 'A Woo price update must be reflected without a separate booking price.' );

    // Simulate exactly the editor's submitted fields, including any unexpected nested service nonce.
    $snapshot = woo_service_snapshot( $service );
    $panel = woo_service_panel( $product );
    $invalid = woo_service_request( $product, $profile, array( 'dendrila_product_duration' => '0' ) );
    $invalid['dendrila_schedule_rules']['slot_step'] = 60;
    if ( preg_match( '/name="dendrila_calendar_schedule_rules_nonce" value="([^"]+)"/', $panel, $rules_nonce ) ) {
        $invalid['dendrila_calendar_schedule_rules_nonce'] = html_entity_decode( $rules_nonce[1], ENT_QUOTES );
    }
    $product = woo_service_save( $product, $invalid );
    lab_assert( $snapshot === woo_service_snapshot( $service ), 'Invalid duration must preserve every booking setting, including rules submitted through the product panel.' );
    $invalid = woo_service_request( $product, $profile ); $invalid['dendrila_schedule_rules']['slot_step'] = '0';
    $product = woo_service_save( $product, $invalid );
    lab_assert( $snapshot === woo_service_snapshot( $service ), 'Invalid slot rules must preserve the full saved configuration.' );
    $product = woo_service_save( $product, woo_service_request( $product, $profile, array( 'dendrila_product_profile' => array( $profile ) ) ) );
    lab_assert( $snapshot === woo_service_snapshot( $service ), 'A malformed profile field must not reassign the service.' );
    $product = woo_service_save( $product, woo_service_request( $product, $profile, array( 'dendrila_product_booking_nonce' => 'invalid-nonce' ) ) );
    lab_assert( $snapshot === woo_service_snapshot( $service ), 'Invalid nonce must not change appointment settings.' );
    $product = woo_service_save( $product, woo_service_request( $product, 999999999 ) );
    lab_assert( $snapshot === woo_service_snapshot( $service ), 'An unpublished or missing profile must not replace the current profile.' );

    $product->set_name( '[Dendrila lab] Renommé par Woo CRUD' );
    $product = woo_service_save( $product, array() );
    lab_assert( $snapshot === woo_service_snapshot( $service ) && get_the_title( $service ) === $product->get_name(), 'Woo CRUD saves without booking POST should mirror content while preserving rules.' );
    $product->set_status( 'draft' ); $product = woo_service_save( $product, array() );
    lab_assert( 'draft' === get_post_status( $service ) && false === Dendrila_Calendar_Booking::public_service( $service ), 'A draft product must close its internal service.' );
    $product->set_status( 'publish' ); $product = woo_service_save( $product, array() );
    lab_assert( 'publish' === get_post_status( $service ) && is_array( Dendrila_Calendar_Booking::public_service( $service ) ), 'Republishing a product must restore its eligible service.' );

    // A real private hold and a pending Woo order, with no gateway invocation.
    update_post_meta( $service, '_dendrila_price_cents', 999999 );
    $booking = Dendrila_Calendar_Booking::create( lab_data( $service, lab_slot( $service ) ) );
    lab_assert( ! is_wp_error( $booking ), 'The product-created service must accept a real booking: ' . ( is_wp_error( $booking ) ? $booking->get_error_message() : '' ) );
    $order = wc_get_order( $booking['order_id'] );
    lab_assert( 'pending_payment' === $booking['status'] && 31.25 === (float) $order->get_total(), 'Booking must use the current Woo price, not legacy cents or a fake confirmed state.' );
    lab_assert( $booking['checkout_url'] === $order->get_checkout_payment_url(), 'The product-created booking must lead to its real Woo payment page.' );
    Dendrila_Calendar_Booking::cancel( $booking['booking_id'], $booking['cancel_token'] );
    lab_assert( 'cancelled' === lab_status( $booking['booking_id'] ) && wc_get_order( $booking['order_id'] )->has_status( 'cancelled' ), 'Unpaid test hold and order must both cancel cleanly.' );

    $product = woo_service_save( $product, woo_service_request( $product, $other_profile, array( 'dendrila_product_booking' => '0' ) ) );
    lab_assert( 'no' === $product->get_meta( Dendrila_Calendar_Woo_Service::ENABLED_KEY, true ) && ! Dendrila_Calendar_Schedule::rules( $service )['enabled'] &&
        false === Dendrila_Calendar_Booking::public_service( $service ), 'Disabling appointments from the product must immediately close booking.' );
    lab_assert( $service === Dendrila_Calendar_Woo_Service::service_id( $product_id ), 'Disabling appointments must retain the linked identity.' );
    $product = woo_service_save( $product, woo_service_request( $product, $other_profile ) );
    lab_assert( $service === Dendrila_Calendar_Woo_Service::service_id( $product_id ) && is_array( Dendrila_Calendar_Booking::public_service( $service ) ), 'Re-enabling appointments must reuse the existing service.' );

    $unrelated = lab_product( '9.00' );
    $unrelated = woo_service_save( $unrelated, array() );
    lab_assert( 0 === Dendrila_Calendar_Woo_Service::service_id( $unrelated->get_id() ), 'Ordinary Woo products must not silently become appointment services.' );
    lab_assert( true === apply_filters( 'woocommerce_add_to_cart_validation', true, $unrelated->get_id() ) &&
        '<button>Buy directly</button>' === Dendrila_Calendar_Woo_Service::loop_button( '<button>Buy directly</button>', $unrelated ), 'Normal product purchases and their buttons must remain available.' );
    $normal_cart_id = $store_controller->add_to_cart( array( 'id' => $unrelated->get_id(), 'quantity' => 1 ) );
    lab_assert( is_string( $normal_cart_id ) && '' !== $normal_cart_id && 1 === count( WC()->cart->get_cart() ), 'Store API must still accept an ordinary product.' );
    WC()->cart->set_cart_contents( array() );
    $bad_flag = woo_service_request( $unrelated, $profile, array( 'dendrila_product_booking' => array( '1' ) ) );
    $unrelated = woo_service_save( $unrelated, $bad_flag );
    lab_assert( 0 === Dendrila_Calendar_Woo_Service::service_id( $unrelated->get_id() ), 'Malformed activation fields must not create an internal service.' );
    wp_set_current_user( 0 );
    lab_assert( ! isset( Dendrila_Calendar_Woo_Service::tab( array() )['dendrila_booking'] ), 'Appointment configuration must not be exposed to visitors.' );
    $unrelated = woo_service_save( $unrelated, woo_service_request( $unrelated, $profile ) );
    lab_assert( 0 === Dendrila_Calendar_Woo_Service::service_id( $unrelated->get_id() ), 'Visitors cannot configure appointment products even with a generated nonce.' );
    wp_set_current_user( 1 );

    echo $GLOBALS['lab_checks'] . " Woo product-service integration checks passed; real WordPress/WooCommerce, no gateway, email or external HTTP\n";
} finally {
    $_POST = array();
    $_REQUEST = array();
    if ( null !== $woo_service_original_cart && WC()->cart ) { WC()->cart->set_cart_contents( $woo_service_original_cart ); }
    wc_clear_notices();
    wp_set_current_user( 1 );
    foreach ( $GLOBALS['lab_products'] as $id ) { woo_service_remember( Dendrila_Calendar_Woo_Service::service_id( $id ) ); }
    lab_cleanup();
}
