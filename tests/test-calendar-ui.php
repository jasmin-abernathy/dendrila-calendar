<?php
/** Check that the admin UI wires the ICS exporter and search without requiring WordPress to run. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['queued_scripts'] = array();
$GLOBALS['localized_scripts'] = array();
function __( $s, $domain = null ) { return $s; }
function esc_html__( $s, $domain = null ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $s, $domain = null ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return $s; }
function current_user_can( $role ) { return $role === 'manage_options'; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
function admin_url( $path = '' ) { return 'https://example.org/wp-admin/' . $path; }
function plugin_dir_url( $path ) { return 'https://example.org/wp-content/plugins/dendrila-calendar/'; }
function wp_enqueue_style( $handle, $url, $deps, $version ) { $GLOBALS['queued_styles'][ $handle ] = compact( 'url', 'version' ); }
function wp_enqueue_script( $handle, $url, $deps, $version ) { $GLOBALS['queued_scripts'][ $handle ] = compact( 'url', 'deps', 'version' ); }
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['localized_scripts'][ $handle ] = $data; }
function wp_create_nonce( $name ) { return 'testnonce'; }
function wp_timezone_string() { return 'Europe/Paris'; }
function get_user_locale() { return 'fr_FR'; }
function get_option( $name, $default ) { return $default; }
class Dendrila_Calendar_Sources {
    public static function available() { return array( 'native' => 'Local events' ); }
}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar.php';
Dendrila_Calendar::enqueue( 'not-the-calendar' );
if ( ! empty( $GLOBALS['queued_scripts'] ) ) {
    throw new RuntimeException( 'Calendar assets loaded on unrelated admin screen' );
}
Dendrila_Calendar::enqueue( 'toplevel_page_dendrila-calendar' );
if ( ! isset( $GLOBALS['queued_scripts']['dendrila-calendar-ics'], $GLOBALS['queued_scripts']['dendrila-calendar-admin'] ) ) {
    throw new RuntimeException( 'ICS exporter or calendar UI asset not enqueued' );
}
if ( $GLOBALS['queued_scripts']['dendrila-calendar-admin']['deps'] !== array( 'dendrila-calendar-ics' ) ) {
    throw new RuntimeException( 'ICS dependency load order is not enforced' );
}
if ( $GLOBALS['queued_scripts']['dendrila-calendar-admin']['version'] !== '0.0.1' ) {
    throw new RuntimeException( 'Development version mismatch' );
}
ob_start();
Dendrila_Calendar::render();
$markup = ob_get_clean();
foreach ( array( 'id="dendrila-grid"', 'id="dendrila-search"', 'id="dendrila-export"', 'id="dendrila-source-toggles"' ) as $id ) {
    if ( strpos( $markup, $id ) === false ) {
        throw new RuntimeException( 'Missing UI control: ' . $id );
    }
}
if ( strpos( $markup, 'Export filtered events (ICS)' ) === false || strpos( $markup, 'type="search"' ) === false ) {
    throw new RuntimeException( 'Missing functional calendar controls' );
}
echo "Admin UI and asset loading passed\n";
