<?php
/** First-run setup must stay server-side, accessible and free of email secrets. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['config'] = array( 'active_plugins' => array( 'easy-appointments/main.php', 'ics-calendar/ics-calendar.php' ) );
$GLOBALS['sent'] = 0;
function get_option( $key, $default = null ) { return $GLOBALS['config'][ $key ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = false ) { $GLOBALS['config'][ $key ] = $value; return true; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['config'][ $key ] = $value; return true; }
function get_site_option( $key, $default = array() ) { return $default; }
function get_plugins() {
    return array(
        'easy-appointments/main.php' => array( 'Name' => 'Easy Appointments', 'Version' => '4.0.5' ),
        'ics-calendar/ics-calendar.php' => array( 'Name' => 'ICS Calendar', 'Version' => '12.3.0' ),
    );
}
function sanitize_text_field( $str ) { return strip_tags( (string) $str ); }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function __( $s, $domain = '' ) { return $s; }
function esc_html__( $s, $domain = '' ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return $s; }
function admin_url( $part ) { return 'https://example.test/wp-admin/' . $part; }
function checked( $one, $two, $echo ) { return $one === $two ? 'checked="checked"' : ''; }
function wp_nonce_field( $name ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function submit_button( $label ) { echo '<button type="submit">' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ) . '</button>'; }
function absint( $value ) { return abs( (int) $value ); }
function wp_mail() { ++$GLOBALS['sent']; return true; }
class Dendrila_Calendar_Bridge {
    public static $connections = array();
    public static function connections() { return self::$connections; }
}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-detector.php';
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-setup.php';
Dendrila_Calendar_Setup::activate();
if ( Dendrila_Calendar_Setup::complete() || 1 !== get_option( Dendrila_Calendar_Setup::REDIRECT_OPTION ) ) {
    throw new RuntimeException( 'Activation must require first-run configuration.' );
}
ob_start();
Dendrila_Calendar_Setup::render();
$html = ob_get_clean();
if ( false === strpos( $html, 'Easy Appointments' ) || false === strpos( $html, 'ICS Calendar' ) ||
    false === strpos( $html, 'name="sources[]" value="easy_appointments" checked="checked"' ) ||
    false === strpos( $html, 'name="sources[]" value="ics_calendar" disabled' ) ||
    false === strpos( $html, 'name="destination" value="caldav"' ) ||
    false !== strpos( $html, 'jasmin@lepotager.org' ) ) {
    throw new RuntimeException( 'Setup screen must preselect a compatible source, flag unsupported sources and hide the support mailbox.' );
}
if ( $GLOBALS['sent'] ) {
    throw new RuntimeException( 'Viewing setup without confirmation must not send a support email.' );
}
$GLOBALS['config'][ Dendrila_Calendar_Setup::OPTION ] = array( 'sources' => array( 'easy_appointments' ), 'mode' => 'caldav', 'completed' => false );
if ( Dendrila_Calendar_Setup::complete() ) { throw new RuntimeException( 'Choosing CalDAV cannot finish setup without a connection' ); }
Dendrila_Calendar_Bridge::$connections = array( 'test' => array( 'label' => 'Test destination', 'sources' => array( 'easy_appointments' ) ) );
Dendrila_Calendar_Setup::destination_connected();
if ( ! Dendrila_Calendar_Setup::complete() || Dendrila_Calendar_Setup::selected_sources() !== array( 'easy_appointments' ) ) {
    throw new RuntimeException( 'A validated CalDAV connection must complete setup without altering selected sources.' );
}
$GLOBALS['config'][ Dendrila_Calendar_Setup::OPTION ] = array( 'sources' => array( 'easy_appointments' ), 'mode' => 'subscription', 'completed' => true );
if ( Dendrila_Calendar_Setup::complete() ) { throw new RuntimeException( 'Choosing an app must not count as a real subscription' ); }
class Dendrila_Calendar_Subscriptions { public static $items = array(); public static function subscriptions() { return self::$items; } }
Dendrila_Calendar_Subscriptions::$items = array( 'test' => array( 'sources' => array( 'easy_appointments' ) ) );
if ( ! Dendrila_Calendar_Setup::complete() ) { throw new RuntimeException( 'Saved subscription must count as a configured feed' ); }
echo "First-run assistant: verified destinations, auto-preselection and no mailbox leak passed\n";
