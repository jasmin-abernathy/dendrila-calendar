<?php
/** Offline regression tests for private, revocable calendar subscription feeds. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['options'] = array();
function __( $value, $domain = null ) { return $value; }
function get_option( $name, $default = false ) { return isset( $GLOBALS['options'][ $name ] ) ? $GLOBALS['options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
function current_user_can( $permission ) { return 'manage_options' === $permission; }
function check_ajax_referer( $action, $key ) { if ( $action !== 'dendrila_calendar_subscription' ) { throw new RuntimeException( 'Invalid nonce action' ); } }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function home_url( $url = '/' ) { return 'https://example.test' . $url; }
function wp_unslash( $item ) { return $item; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function wp_html_excerpt( $text, $length, $more = null ) { return substr( $text, 0, $length ); }
function get_current_user_id() { return 1; }
function add_query_arg( $name, $value, $url ) { return $url . '?' . rawurlencode( $name ) . '=' . rawurlencode( $value ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $key ) ); }
function wp_send_json_error( $data, $status ) { throw new RuntimeException( 'HTTP ' . $status . ': ' . $data['message'] ); }
function wp_send_json_success( $data ) { throw new SuccessResponse( $data ); }
class SuccessResponse extends Exception {
    public $data;
    public function __construct( $data ) { $this->data = $data; parent::__construct(); }
}
class Dendrila_Calendar_Sources {
    public static function available() { return array( 'native' => 'Dendrila events', 'oryx' => 'Oryx', 'bookly' => 'Bookly' ); }
    public static $ranges = array();
    public static function events( $start, $end, $sources ) {
        self::$ranges[] = array( $start, $end, $sources );
        return array( array(
            'source' => 'oryx', 'id' => 'oryx-7', 'title' => 'Consultation',
            'start' => '2026-11-22T12:30:00Z', 'end' => '2026-11-22T13:30:00Z',
            'status' => 'confirmed',
        ) );
    }
}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-subscriptions.php';

function verify( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$selected = Dendrila_Calendar_Subscriptions::allowed_sources( array( 'oryx', 'not-installed', 'oryx', 'native' ), Dendrila_Calendar_Sources::available() );
verify( $selected === array( 'oryx', 'native' ), 'Source allowlist and de-duplication failed' );
$_POST = array( 'nonce' => 'test', 'label' => 'My Android', 'sources' => array( 'oryx', 'bookly', 'unknown' ) );
try { Dendrila_Calendar_Subscriptions::create(); throw new RuntimeException( 'Creation did not respond' ); }
catch ( SuccessResponse $result ) { $subscription = $result->data; }
verify( preg_match( '/^https:\/\/example\.test\/\?dendrila_calendar_feed=[a-f0-9]{64}$/', $subscription['url'] ) === 1, 'Subscription URL missing or insufficiently random' );
$token = substr( $subscription['url'], -64 );
$stored = get_option( Dendrila_Calendar_Subscriptions::OPTION );
verify( ! strpos( serialize( $stored ), $token ), 'Plaintext subscription token was stored' );
verify( Dendrila_Calendar_Subscriptions::lookup_token( $token, $stored )['sources'] === array( 'oryx', 'bookly' ), 'Allowed source selection not saved' );
verify( Dendrila_Calendar_Subscriptions::lookup_token( str_repeat( '0', 64 ), $stored ) === null, 'Invalid token accepted' );
verify( Dendrila_Calendar_Subscriptions::lookup_token( array( $token ), $stored ) === null, 'Array token accepted' );

$events = array(
    array( 'source' => 'oryx', 'id' => 'booking-7', 'title' => "Réunion, A;B\\C\nParis", 'start' => '2026-11-22T12:00:00Z', 'end' => '2026-11-22T13:00:00Z', 'status' => 'confirmed', 'private' => 'NEVER-EXPOSE-ME' ),
    array( 'source' => 'oryx', 'id' => 'booking-7', 'title' => 'Duplicate', 'start' => '2026-11-22T12:00:00Z', 'end' => '2026-11-22T13:00:00Z' ),
    array( 'source' => 'native', 'id' => 'all-day-1', 'title' => 'Day event', 'start' => '2026-03-28T23:00:00Z', 'end' => '2026-03-29T22:00:00Z', 'all_day' => true ),
    array( 'source' => 'bookly', 'id' => 'second', 'title' => str_repeat( 'É', 55 ), 'start' => '2026-12-01T09:00:00Z', 'end' => '2026-12-01T09:30:00Z' ),
);
$now = new DateTimeImmutable( '2026-11-22T17:00:00Z' );
$calendar = Dendrila_Calendar_Subscriptions::build_ical( $events, Dendrila_Calendar_Sources::available(), 'https://example.test/', new DateTimeZone( 'Europe/Paris' ), $now );
verify( substr_count( $calendar, 'BEGIN:VEVENT' ) === 3, 'Missing events or duplicate calendar entries' );
verify( strpos( $calendar, 'NEVER-EXPOSE-ME' ) === false, 'Private field leaked' );
verify( strpos( $calendar, 'DTSTART:20261122T120000Z' ) !== false, 'UTC timestamp invalid' );
verify( strpos( $calendar, 'SUMMARY:Réunion\\, A\\;B\\\\C\\nParis' ) !== false, 'Text not escaped for iCalendar' );
verify( strpos( $calendar, "DTSTART;VALUE=DATE:20260329\r\nDTEND;VALUE=DATE:20260330" ) !== false, 'DST-spanning all-day event incorrect' );
verify( strpos( $calendar, 'REFRESH-INTERVAL;VALUE=DURATION:PT1H' ) !== false, 'Refresh hint missing' );
foreach ( explode( "\r\n", $calendar ) as $line ) {
    verify( strlen( $line ) <= 75, 'RFC 5545 content line exceeds 75 octets: ' . substr( $line, 0, 80 ) );
}
$again = Dendrila_Calendar_Subscriptions::build_ical( $events, Dendrila_Calendar_Sources::available(), 'https://example.test/', new DateTimeZone( 'Europe/Paris' ), $now->modify( '+1 hour' ) );
preg_match_all( '/UID:(.+)\r\n/', $calendar, $uids1 );
preg_match_all( '/UID:(.+)\r\n/', $again, $uids2 );
verify( $uids1[1] === $uids2[1], 'UIDs changed between synchronizations' );

$range = Dendrila_Calendar_Subscriptions::events_for_feed( array( 'oryx' ), new DateTimeImmutable( '2026-11-01', new DateTimeZone( 'UTC' ) ), new DateTimeImmutable( '2027-02-01', new DateTimeZone( 'UTC' ) ) );
verify( count( $range ) === 1 && count( Dendrila_Calendar_Sources::$ranges ) === 2, 'Range chunk deduplication failed' );
verify( Dendrila_Calendar_Sources::$ranges[0][2] === array( 'oryx' ), 'Source choice was ignored' );

$_POST = array( 'nonce' => 'test', 'id' => $subscription['id'] );
try { Dendrila_Calendar_Subscriptions::revoke(); throw new RuntimeException( 'Revocation did not respond' ); }
catch ( SuccessResponse $result ) { verify( $result->data['id'] === $subscription['id'], 'Wrong revocation ID' ); }
verify( Dendrila_Calendar_Subscriptions::lookup_token( $token, get_option( Dendrila_Calendar_Subscriptions::OPTION ) ) === null, 'Revoked token remains valid' );
echo "Private subscription creation, sources, iCalendar, dedupe, timezone and revocation passed\n";
