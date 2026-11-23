<?php
/** OAuth UI security regression: never claim Google/Microsoft is connected without publisher credentials. */
define( 'ABSPATH', '/' );
function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $url ) { return $url; }
function admin_url( $path = '' ) { return 'https://example.org/wp-admin/' . $path; }
function current_user_can( $cap ) { return 'manage_options' === $cap; }
function get_option( $name, $default = array() ) { return $default; }
function get_transient( $key ) { return false; }
function get_current_user_id() { return 1; }
function wp_nonce_field( $name ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
function disabled( $actual, $expected, $echo = true ) {
    $result = $actual == $expected ? ' disabled="disabled"' : '';
    if ( $echo ) { echo $result; }
    return $result;
}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-oauth.php';
if ( Dendrila_Calendar_OAuth::ready( 'google' ) ||
     Dendrila_Calendar_OAuth::ready( 'microsoft' ) ||
     Dendrila_Calendar_OAuth::ready( 'unknown' ) ) {
    throw new RuntimeException( 'OAuth provider must be disabled until publisher app credentials exist.' );
}
ob_start();
Dendrila_Calendar_OAuth::render_settings();
$html = ob_get_clean();
if ( substr_count( $html, 'disabled="disabled"' ) !== 2 ||
     strpos( $html, 'Connect with Google Calendar' ) === false ||
     strpos( $html, 'Connect with Microsoft Outlook' ) === false ||
     strpos( $html, 'Use a read-only calendar subscription instead' ) === false ||
     strpos( $html, 'name="client_secret"' ) === false ||
     strpos( $html, 'type="password"' ) === false ||
     strpos( $html, 'value="test-google-secret"' ) !== false ) {
    throw new RuntimeException( 'OAuth buttons or disabled explanation are broken.' );
}
echo "Google/Microsoft OAuth controls: guarded, translated and without exposed secrets\n";
