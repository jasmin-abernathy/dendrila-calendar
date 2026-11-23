<?php
/** Check the WordPress admin UI wires search and secure subscription management. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
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
function add_query_arg( $key, $value, $url ) { return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . urlencode( $key ) . '=' . urlencode( $value ); }
function plugin_dir_url( $path ) { return 'https://example.org/wp-content/plugins/dendrila-calendar/'; }
function wp_enqueue_style( $handle, $url, $deps, $version ) { $GLOBALS['queued_styles'][ $handle ] = compact( 'url', 'version' ); }
function wp_enqueue_script( $handle, $url, $deps, $version ) { $GLOBALS['queued_scripts'][ $handle ] = compact( 'url', 'deps', 'version' ); }
function wp_localize_script( $handle, $name, $data ) { $GLOBALS['localized_scripts'][ $handle ] = $data; }
function wp_create_nonce( $name ) { return 'testnonce'; }
function wp_timezone_string() { return 'Europe/Paris'; }
function wp_timezone() { return new DateTimeZone( 'Europe/Paris' ); }
function get_user_locale() { return 'fr_FR'; }
function get_option( $name, $default ) { return $default; }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $key ) ); }
function wp_unslash( $value ) { return $value; }
class Dendrila_Calendar_Sources {
    public static function available() { return array( 'native' => 'Local events', 'easy_appointments' => 'Easy Appointments' ); }
    public static function events( $start, $end, $sources ) { return array( array( 'source' => 'easy_appointments', 'id' => 'ea-1', 'title' => 'Prestation test', 'start' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ), 'end' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS + 1800 ), 'status' => 'pending' ) ); }
    public static function diagnostics() { return array(); }
}
class Dendrila_Calendar_Bridge { public static function render_settings() { echo '<h2>Direct synchronization into an existing calendar</h2><select id="dendrila-connect-provider"></select>'; } }
class Dendrila_Calendar_Discovery { const NONCE_ACTION = 'dendrila_calendar_discover'; }
class Dendrila_Calendar_OAuth { public static function render_settings() { echo '<h2>OAuth provider account authorization</h2>'; } }
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar.php';
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-subscriptions.php';
Dendrila_Calendar::enqueue( 'not-the-calendar' );
if ( ! empty( $GLOBALS['queued_scripts'] ) ) { throw new RuntimeException( 'Calendar assets loaded on unrelated admin screen' ); }
Dendrila_Calendar::enqueue( 'toplevel_page_dendrila-calendar' );
if ( ! isset( $GLOBALS['queued_scripts']['dendrila-calendar-subscriptions'], $GLOBALS['queued_scripts']['dendrila-calendar-admin'] ) ) {
    throw new RuntimeException( 'Subscription or calendar UI asset not enqueued' );
}
if ( $GLOBALS['queued_scripts']['dendrila-calendar-admin']['deps'] !== array() ) {
    throw new RuntimeException( 'Unexpected calendar JS dependencies' );
}
if ( $GLOBALS['queued_scripts']['dendrila-calendar-admin']['version'] !== (string) filemtime( dirname( __DIR__ ) . '/assets/calendar.js' ) ) {
    throw new RuntimeException( 'Calendar asset version must reflect its file modification time' );
}
if ( Dendrila_Calendar::VERSION !== '0.0.1' ) {
    throw new RuntimeException( 'Plugin version must stay 0.0.1 during development' );
}
ob_start();
Dendrila_Calendar::render();
$markup = ob_get_clean();
foreach ( array( 'id="dendrila-grid"', 'id="dendrila-search"', 'id="dendrila-source-toggles"', 'id="dendrila-month"', 'type="month"' ) as $id ) {
    if ( strpos( $markup, $id ) === false ) {
        throw new RuntimeException( 'Missing UI control: ' . $id );
    }
}
if ( strpos( $markup, 'Prestation test' ) === false || strpos( $markup, 'Upcoming appointments (server view)' ) === false || strpos( $markup, 'Synchronize appointments with your external calendar' ) === false || strpos( $markup, 'type="search"' ) === false || strpos( $markup, 'Export filtered events' ) !== false ) {
    throw new RuntimeException( 'Missing functional calendar controls' );
}
$GLOBALS['queued_scripts'] = array();
Dendrila_Calendar::enqueue( 'dendrila-calendar_page_dendrila-calendar-sync' );
if ( isset( $GLOBALS['queued_scripts']['dendrila-calendar-admin'] ) ||
     ! isset( $GLOBALS['queued_scripts']['dendrila-calendar-connect'] ) ||
     ! isset( $GLOBALS['queued_scripts']['dendrila-calendar-subscriptions'] ) ) {
    throw new RuntimeException( 'Sync page must load connector-specific JS' );
}
if ( $GLOBALS['queued_scripts']['dendrila-calendar-connect']['version'] !== (string) filemtime( dirname( __DIR__ ) . '/assets/calendar-connect.js' ) ) {
    throw new RuntimeException( 'Connector JS must use cache busting' );
}
ob_start();
Dendrila_Calendar::render_sync_page();
$sync_markup = ob_get_clean();
if ( strpos( $sync_markup, 'id="dendrila-connect-provider"' ) === false ||
     strpos( $sync_markup, 'Choose an agenda' ) === false ||
     strpos( $sync_markup, 'Which calendar do you use?' ) === false ||
     strpos( $sync_markup, 'id="dendrila-subscription-form"' ) !== false ) {
    throw new RuntimeException( 'Direct connection must be the only visible workflow on the sync page' );
}
$_GET['dendrila_method'] = 'oauth';
ob_start();
Dendrila_Calendar::render_sync_page();
$oauth_markup = ob_get_clean();
if ( strpos( $oauth_markup, 'OAuth provider account authorization' ) === false ||
     strpos( $oauth_markup, 'id="dendrila-connect-provider"' ) !== false ) {
    throw new RuntimeException( 'OAuth must use its own connection screen.' );
}
$_GET['dendrila_method'] = 'subscription';
ob_start();
Dendrila_Calendar::render_sync_page();
$subscription_markup = ob_get_clean();
if ( strpos( $subscription_markup, 'id="dendrila-subscription-form"' ) === false ||
     strpos( $subscription_markup, 'id="dendrila-connect-provider"' ) !== false ) {
    throw new RuntimeException( 'Subscription mode must show only the subscription flow' );
}
echo "Admin UI, separate connection methods, asset loading passed\n";
