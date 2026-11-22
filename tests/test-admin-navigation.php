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
function add_menu_page( $title, $menu, $cap, $slug, $callback, $icon, $order ) { $GLOBALS['menu_slug'] = $slug; }
function add_submenu_page( $parent, $title, $menu, $cap, $slug, $callback ) {
    $GLOBALS['submenus'][] = compact( 'parent', 'title', 'menu', 'cap', 'slug' );
}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar.php';
Dendrila_Calendar::register_events();
if ( $GLOBALS['post_type_args']['labels']['menu_name'] !== 'Local events' ||
     $GLOBALS['post_type_args']['show_in_menu'] !== 'dendrila-calendar' ) {
    throw new RuntimeException( 'Local-event admin list must be clearly labeled.' );
}
Dendrila_Calendar::admin_menu();
if ( $GLOBALS['menu_slug'] !== 'dendrila-calendar' ||
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
$GLOBALS['fake_screen'] = 'dashboard';
ob_start();
Dendrila_Calendar::local_events_notice();
if ( ob_get_clean() !== '' ) { throw new RuntimeException( 'Unexpected global admin notice.' ); }
echo "Admin navigation regression: OK\n";
