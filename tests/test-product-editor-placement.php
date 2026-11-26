<?php
/** Product editor shortcut must appear after the description, not above the title. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['registered_actions'] = array();
$GLOBALS['registered_filters'] = array();
$GLOBALS['screen_type'] = 'product';
$GLOBALS['editable'] = true;
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['registered_actions'][ $tag ][] = $callback; }
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) { $GLOBALS['registered_filters'][ $tag ][] = $callback; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['editable'] ); }
function get_current_screen() { return (object) array( 'post_type' => $GLOBALS['screen_type'] ); }
function __( $s, $domain = '' ) { return $s; }
function esc_html__( $s, $domain = '' ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return $s; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function wp_unslash( $s ) { return $s; }
function sanitize_text_field( $s ) { return strip_tags( $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $s ) ); }
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-woo-service.php';
Dendrila_Calendar_Woo_Service::boot();
if ( empty( $GLOBALS['registered_actions']['edit_form_after_editor'] ) ||
    isset( $GLOBALS['registered_actions']['edit_form_after_title'] ) ||
    isset( $GLOBALS['registered_actions']['admin_notices'] ) ) {
    throw new RuntimeException( 'Shortcut must be hooked below product description only' );
}
function render_test_shortcut() {
    ob_start();
    Dendrila_Calendar_Woo_Service::product_editor_notice();
    return ob_get_clean();
}
$_GET = array( 'post_type' => 'product', 'dendrila_new_service' => '1' );
$html = render_test_shortcut();
if ( false === strpos( $html, 'data-dendrila-new-service="1"' ) ||
    false === strpos( $html, 'data-dendrila-open-booking-tab' ) ||
    false === strpos( $html, 'dendrila-product-editor-help' ) ||
    false === strpos( $html, 'Step-by-step guide' ) ) {
    throw new RuntimeException( 'New product needs the booking shortcut under its description' );
}
$_GET = array( 'dendrila_edit_service' => '1' );
if ( false === strpos( render_test_shortcut(), 'data-dendrila-edit-service="1"' ) ) {
    throw new RuntimeException( 'Editing a linked product must retain booking-tab intent' );
}
$GLOBALS['screen_type'] = 'post';
if ( '' !== render_test_shortcut() ) { throw new RuntimeException( 'Shortcut must not appear on articles' ); }
$GLOBALS['screen_type'] = 'product';
$GLOBALS['editable'] = false;
if ( '' !== render_test_shortcut() ) { throw new RuntimeException( 'Shortcut must not appear without product edit rights' ); }
echo "WooCommerce product editor shortcut position: OK\n";
