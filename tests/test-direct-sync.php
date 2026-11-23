<?php
/** No-network regression checks for direct CalDAV destination writes. */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['values'] = array();
$GLOBALS['calls'] = array();
$GLOBALS['locks'] = array();
$GLOBALS['test_events'] = array();
class WP_Error {
    public function __construct( $code, $message ) {}
}
function __( $string, $domain = null ) { return $string; }
function wp_generate_uuid4() { return '63938b36-d9b8-4efe-8c97-829469b8a202'; }
function wp_salt( $context ) { return 'unit-test-salt-for-encryption'; }
function wp_parse_url( $url ) { return parse_url( $url ); }
function wp_http_validate_url( $url ) { return false === stripos( $url, 'localhost' ) ? $url : false; }
function trailingslashit( $string ) { return rtrim( $string, '/' ) . '/'; }
function is_wp_error( $response ) { return $response instanceof WP_Error; }
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_safe_remote_request( $url, $args ) {
    $GLOBALS['calls'][] = array( $url, $args['method'], $args['body'] );
    if ( isset( $GLOBALS['response_override'] ) ) {
        return array( 'response' => array( 'code' => array_shift( $GLOBALS['response_override'] ) ), 'body' => '' );
    }
    $status = 'PROPFIND' === $args['method'] ? 207 : ( 'PUT' === $args['method'] ? 201 : 204 );
    return array( 'response' => array( 'code' => $status ), 'body' => 'PROPFIND' === $args['method'] ? '<cal:calendar xmlns:cal="urn:ietf:params:xml:ns:caldav"/>' : '' );
}
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_timezone() { return new DateTimeZone( 'Europe/Paris' ); }
function home_url( $path = '' ) { return 'https://example.org/' . ltrim( $path, '/' ); }
function get_option( $name, $fallback = array() ) { return $GLOBALS['values'][ $name ] ?? $fallback; }
function update_option( $name, $value, $autoload = false ) { $GLOBALS['values'][ $name ] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['locks'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['locks'][ $key ] = $value; }
function delete_transient( $key ) { unset( $GLOBALS['locks'][ $key ] ); }
class Dendrila_Calendar_Subscriptions {
    public static function allowed_sources( $sources, $available ) { return array_values( array_intersect( $sources, array_keys( $available ) ) ); }
    public static function build_ical( $events, $labels, $site, $timezone ) {
        return "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:testing\r\nSUMMARY:" . $events[0]['title'] . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}
class Dendrila_Calendar_Sources {
    public static function available() { return array( 'easy_appointments' => 'Easy Appointments' ); }
    public static function events( $start, $end, $sources ) { return $GLOBALS['test_events']; }
}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-bridge.php';
function require_test( $value, $message ) { if ( ! $value ) { throw new RuntimeException( $message ); } }
$encrypted = Dendrila_Calendar_Bridge::protect( 'app-secret-example' );
require_test( is_string( $encrypted ) && false === strpos( $encrypted, 'app-secret-example' ), 'App password must be encrypted' );
require_test( 'app-secret-example' === Dendrila_Calendar_Bridge::reveal( $encrypted ), 'Encrypted credentials must round-trip' );
require_test( false === Dendrila_Calendar_Bridge::reveal( 'not-a-secret' ), 'Malformed ciphertext must be rejected' );
require_test( false === Dendrila_Calendar_Bridge::validate_endpoint( 'http://example.org/calendars/user/default/' ), 'HTTP destination accepted' );
require_test( false === Dendrila_Calendar_Bridge::validate_endpoint( 'https://localhost/private/' ), 'Local destination accepted' );
require_test( false === Dendrila_Calendar_Bridge::validate_endpoint( 'https://example.org/calendar/?password=x' ), 'Secret-bearing URL accepted' );
require_test( 'https://example.org/calendars/user/default/' === Dendrila_Calendar_Bridge::validate_endpoint( 'https://example.org/calendars/user/default/' ), 'Calendar URL failed validation' );
require_test( true === Dendrila_Calendar_Bridge::probe( 'https://example.org/calendars/user/default/', 'alice', 'password' ), 'CalDAV probe failed' );
require_test( array( 'PUT', 'DELETE' ) === array_column( $GLOBALS['calls'], 1 ), 'Probe must add and remove a temporary event' );
require_test( $GLOBALS['calls'][0][0] === $GLOBALS['calls'][1][0], 'Probe must clean up only its own event' );
$GLOBALS['response_override'] = array( 403 );
require_test( ! Dendrila_Calendar_Bridge::probe( 'https://example.org/calendars/user/default/', 'alice', 'password' ), 'Read-only calendar cannot pass the write test' );
$GLOBALS['response_override'] = array( 201, 403 );
require_test( ! Dendrila_Calendar_Bridge::probe( 'https://example.org/calendars/user/default/', 'alice', 'password' ), 'Unsuccessful cleanup cannot pass the write test' );
unset( $GLOBALS['response_override'] );
$GLOBALS['calls'] = array();
$start = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+1 day' )->format( 'Y-m-d\TH:i:s\Z' );
$end = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+1 day +1 hour' )->format( 'Y-m-d\TH:i:s\Z' );
$event = array( 'source' => 'easy_appointments', 'id' => 'ea-1', 'title' => 'Prestation test', 'start' => $start, 'end' => $end, 'status' => 'pending', 'all_day' => false );
$GLOBALS['test_events'] = array( $event );
update_option( Dendrila_Calendar_Bridge::OPTION, array( 'first' => array(
    'label' => 'Test', 'provider' => 'caldav', 'url' => 'https://example.org/calendars/user/default/',
    'username' => 'alice', 'password' => $encrypted, 'sources' => array( 'easy_appointments' ),
    'map' => array(), 'last_run' => '', 'last_error' => '',
) ) );
Dendrila_Calendar_Bridge::run_one( 'first' );
$first = $GLOBALS['calls'];
require_test( count( $first ) === 1 && 'PUT' === $first[0][1], 'First sync should add one remote event' );
require_test( 0 === substr_compare( $first[0][0], '.ics', -4 ), 'CalDAV resource URL must end with .ics (no slash)' );
require_test( false === strpos( $first[0][2], 'alice' ), 'Identity exposed in event content' );
Dendrila_Calendar_Bridge::run_one( 'first' );
require_test( count( $GLOBALS['calls'] ) === 1, 'Unmodified event should not be pushed twice' );
$GLOBALS['test_events'][0]['title'] = 'Prestation test modifiée';
Dendrila_Calendar_Bridge::run_one( 'first' );
require_test( count( $GLOBALS['calls'] ) === 2 && 'PUT' === $GLOBALS['calls'][1][1] && $first[0][0] === $GLOBALS['calls'][1][0], 'Changed event must update the same CalDAV resource' );
$GLOBALS['test_events'][0]['status'] = 'cancelled';
Dendrila_Calendar_Bridge::run_one( 'first' );
require_test( count( $GLOBALS['calls'] ) === 3 && 'DELETE' === $GLOBALS['calls'][2][1], 'Explicit cancellation must remove previously synced resource' );
require_test( empty( get_option( Dendrila_Calendar_Bridge::OPTION )['first']['map'] ), 'Cancelled resource remains mapped' );
echo "Direct CalDAV sync: encrypted credentials, idempotent creation, update, cancellation and SSRF guard passed\n";
