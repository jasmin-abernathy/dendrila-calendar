<?php
/** Verify that an imported booking is not confused with a local WordPress post. */
define( 'ABSPATH', __DIR__ );
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $s, $d = null ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return $s; }
function admin_url( $s ) { return 'https://example.test/wp-admin/' . $s; }
function current_user_can( $role ) { return $role === 'manage_options'; }
function get_current_screen() { return (object) array( 'id' => $GLOBALS['fake_screen'] ); }
function load_plugin_textdomain( $domain, $deprecated, $path ) {}
function register_post_type( $type, $args ) { $GLOBALS['post_type_args'] = $args; }
function wp_enqueue_style( $handle ) { $GLOBALS['enqueued_styles'][] = $handle; }
function wp_add_inline_style( $handle, $css ) { $GLOBALS['inline_styles'][ $handle ] = $css; }
function add_menu_page( $title, $menu, $cap, $slug, $callback, $icon, $order ) { $GLOBALS['menu_slug'] = $slug; $GLOBALS['menu_icon'] = $icon; }
function add_submenu_page( $parent, $title, $menu, $cap, $slug, $callback ) {
    $GLOBALS['submenus'][] = compact( 'parent', 'title', 'menu', 'cap', 'slug' );
}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar.php';
Dendrila_Calendar::register_events();
if ( $GLOBALS['post_type_args']['labels']['menu_name'] !== 'Personal events' ||
     $GLOBALS['post_type_args']['show_in_menu'] !== false ) {
    throw new RuntimeException( 'Local-event admin list must be clearly labeled.' );
}
Dendrila_Calendar::admin_menu();
Dendrila_Calendar::enqueue_menu_icon_style();
if ( ! in_array( 'dashicons', $GLOBALS['enqueued_styles'] ?? array(), true ) ||
     strpos( $GLOBALS['inline_styles']['dashicons'] ?? '', 'font-size: 22px' ) === false ||
     strpos( $GLOBALS['inline_styles']['dashicons'] ?? '', 'color: #f0f6fc' ) === false ) {
    throw new RuntimeException( 'Calendar sidebar icon must remain native-sized and readable.' );
}
if ( $GLOBALS['menu_slug'] !== 'dendrila-calendar' ||
    $GLOBALS['menu_icon'] !== 'dashicons-update' ||
    $GLOBALS['submenus'][0]['menu'] !== 'Calendar view' ||
    $GLOBALS['submenus'][0]['slug'] !== 'dendrila-calendar' ||
    $GLOBALS['submenus'][1]['menu'] !== 'Synchronization' ||
    $GLOBALS['submenus'][1]['slug'] !== 'dendrila-calendar-sync' ) {
    throw new RuntimeException( 'Unified calendar must have its own explicit submenu.' );
}
$GLOBALS['fake_screen'] = 'edit-dendrila_event';
ob_start();
Dendrila_Calendar::local_events_notice();
$notice = ob_get_clean();
if ( strpos( $notice, 'Open unified calendar' ) === false ||
     strpos( $notice, 'admin.php?page=dendrila-calendar' ) === false ) {
    throw new RuntimeException( 'Local events list must link to unified calendar.' );
}
$_GET['page'] = 'dendrila-calendar';
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $key ) ); }
function wp_unslash( $value ) { return $value; }
ob_start();
Dendrila_Calendar::render_support_footer();
$coffee = ob_get_clean();
if ( strpos( $coffee, 'https://app.lepotager.org/soutenir/' ) === false ||
     strpos( $coffee, 'dashicons-coffee' ) === false ||
     strpos( $coffee, 'Offer the developer a coffee' ) === false ||
     strpos( $coffee, 'rel="noopener noreferrer"' ) === false ) {
    throw new RuntimeException( 'Coffee footer must follow the discreet Simple Edit support link.' );
}
unset( $_GET['page'] );
$GLOBALS['fake_screen'] = 'dashboard';
ob_start();
Dendrila_Calendar::local_events_notice();
if ( ob_get_clean() !== '' ) { throw new RuntimeException( 'Unexpected global admin notice.' ); }
echo "Admin navigation regression: OK\n";
