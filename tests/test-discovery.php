<?php
/** Security and deterministic contract checks for guided calendar discovery. */
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function __( $s, $d = '' ) { return $s; }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_http_validate_url( $url ) {
    $p = parse_url( $url );
    $host = $p['host'] ?? '';
    return ( $p['scheme'] ?? '' ) === 'https' && ! in_array( $host, array( '127.0.0.1', 'localhost', '10.0.0.1', '169.254.169.254' ), true );
}
function trailingslashit( $url ) { return rtrim( $url, '/' ) . '/'; }
function sanitize_text_field( $s ) { return trim( strip_tags( $s ) ); }
function get_current_user_id() { return $GLOBALS['test_user_id']; }
function wp_salt( $scheme ) { return 'discovery-test-salt'; }
$GLOBALS['test_user_id'] = 9;
$GLOBALS['test_transients'] = array();
function get_transient( $key ) { return $GLOBALS['test_transients'][ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['test_transients'][ $key ] = $value; }
function delete_transient( $key ) { unset( $GLOBALS['test_transients'][ $key ] ); }
class Dendrila_Calendar_Bridge {
    public static function validate_endpoint( $url, $collection = true ) {
        $p = parse_url( $url );
        return $p && ( $p['scheme'] ?? '' ) === 'https' && ! isset( $p['user'] ) && ! isset( $p['pass'] ) &&
            ! isset( $p['query'] ) && ! isset( $p['fragment'] ) && wp_http_validate_url( $url ) ? $url : false;
    }
}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-discovery.php';
function expect( $truth, $message ) { if ( ! $truth ) { throw new RuntimeException( $message ); } }
$base = Dendrila_Calendar_Discovery::initial_url( 'nextcloud', 'cloud.example.org' );
expect( $base === 'https://cloud.example.org/remote.php/dav/', 'Nextcloud should need only a domain' );
expect( ! Dendrila_Calendar_Discovery::initial_url( 'nextcloud', 'http://cloud.example.org' ), 'No insecure HTTP services' );
expect( ! Dendrila_Calendar_Discovery::initial_url( 'nextcloud', 'http://localhost' ), 'No localhost' );
expect( ! Dendrila_Calendar_Discovery::initial_url( 'nextcloud', 'https://u:p@cloud.example.org' ), 'No URL credentials' );
expect( Dendrila_Calendar_Discovery::initial_url( 'caldav', 'https://dav.example.org/base/') === 'https://dav.example.org/base/', 'Generic CalDAV base must be preserved' );
expect( Dendrila_Calendar_Discovery::resolve_href( '/remote.php/dav/principals/users/alice/', $base, $base, 'nextcloud' ) === 'https://cloud.example.org/remote.php/dav/principals/users/alice/', 'Same host DAV href must resolve' );
expect( ! Dendrila_Calendar_Discovery::resolve_href( 'https://evil.test/steal/', $base, $base, 'nextcloud' ), 'Cannot leak credentials cross-host' );
expect( ! Dendrila_Calendar_Discovery::resolve_href( '/?secret=123', $base, $base, 'nextcloud' ), 'No arbitrary query URL' );
expect( ! Dendrila_Calendar_Discovery::resolve_href( 'http://cloud.example.org/private', $base, $base, 'nextcloud' ), 'No HTTP downgraded link' );
expect( ! Dendrila_Calendar_Discovery::resolve_href( 'https://127.0.0.1/', $base, $base, 'nextcloud' ), 'No localhost redirects' );
expect( Dendrila_Calendar_Discovery::trusted_url( 'https://p100-caldav.icloud.com/', 'https://caldav.icloud.com/', 'icloud' ), 'Apple dedicated DAV hosts supported' );
expect( ! Dendrila_Calendar_Discovery::trusted_url( 'https://icloud.com.example.test/', 'https://caldav.icloud.com/', 'icloud' ), 'Apple host allowlist cannot be bypassed' );
$token = '0123456789abcdef0123456789abcdef';
$method = new ReflectionMethod( 'Dendrila_Calendar_Discovery', 'transient_key' );
$key = $method->invoke( null, $token );
set_transient( $key, array( 'username' => 'alice', 'proof' => hash_hmac( 'sha256', 'password', wp_salt( 'auth' ) . $token ), 'provider' => 'nextcloud', 'urls' => array( 'https://cloud.example.org/remote.php/dav/calendars/alice/personal/' ) ), 600 );
expect( Dendrila_Calendar_Discovery::verified_choice( $token, 'https://cloud.example.org/remote.php/dav/calendars/alice/personal/', 'nextcloud', 'alice', 'password' ), 'Discovered calendar may be selected' );
expect( ! Dendrila_Calendar_Discovery::verified_choice( $token, 'https://evil.test/', 'nextcloud', 'alice', 'password' ), 'Unlisted destinations must be rejected' );
expect( ! Dendrila_Calendar_Discovery::verified_choice( $token, 'https://cloud.example.org/remote.php/dav/calendars/alice/personal/', 'nextcloud', 'mallory', 'password' ), 'Discovery belongs to a particular account' );
expect( ! Dendrila_Calendar_Discovery::verified_choice( $token, 'https://cloud.example.org/remote.php/dav/calendars/alice/personal/', 'nextcloud', 'alice', 'changed' ), 'Discovery is bound to the application password' );
$GLOBALS['test_user_id'] = 10;
expect( ! Dendrila_Calendar_Discovery::verified_choice( $token, 'https://cloud.example.org/remote.php/dav/calendars/alice/personal/', 'nextcloud', 'alice', 'password' ), 'Discovery belongs to the WordPress administrator who requested it' );
$GLOBALS['test_user_id'] = 9;
Dendrila_Calendar_Discovery::consume_choice( $token );
expect( ! Dendrila_Calendar_Discovery::verified_choice( $token, 'https://cloud.example.org/remote.php/dav/calendars/alice/personal/', 'nextcloud', 'alice', 'password' ), 'Discovery token is consumed after connection' );

function wp_safe_remote_request( $url, $options ) {
    $expected = array_shift( $GLOBALS['dav_requests'] );
    expect( $expected && $url === $expected[0], 'Unexpected PROPFIND destination' );
    expect( 'PROPFIND' === $options['method'] && 0 === $options['redirection'] && $options['sslverify'], 'PROPFIND must validate TLS and handle redirects explicitly' );
    expect( $expected[1] === (int) $options['headers']['Depth'], 'Incorrect PROPFIND depth' );
    expect( 'Basic ' . base64_encode( 'alice:password' ) === $options['headers']['Authorization'], 'Expected CalDAV application credentials' );
    return array( 'response' => array( 'code' => $expected[2] ), 'body' => $expected[3], 'headers' => $expected[4] ?? array() );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $header ) { return $response['headers'][ $header ] ?? ''; }

function dav_response( $href, $properties, $status = 200 ) {
    return '<d:response><d:href>' . $href . '</d:href><d:propstat><d:prop>' . $properties
        . '</d:prop><d:status>HTTP/1.1 ' . $status . ' Status</d:status></d:propstat></d:response>';
}
function dav_multistatus( ...$responses ) {
    return '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:" xmlns:cal="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/">'
        . implode( '', $responses ) . '</d:multistatus>';
}

$principal = '/remote.php/dav/principals/users/alice/';
$home = '/remote.php/dav/calendars/alice/';
$calendar_type = '<d:resourcetype><d:collection/><cal:calendar/></d:resourcetype>';
$GLOBALS['dav_requests'] = array(
    array( $base, 0, 207, dav_multistatus( dav_response( '/remote.php/dav/', '<d:current-user-principal><d:href>' . $principal . '</d:href></d:current-user-principal>' ) ) ),
    array( 'https://cloud.example.org' . $principal, 0, 207, dav_multistatus( dav_response( $principal, '<cal:calendar-home-set><d:href>' . $home . '</d:href></cal:calendar-home-set>' ) ) ),
    array( 'https://cloud.example.org' . $home, 1, 207, dav_multistatus(
        dav_response( $home, '<d:resourcetype><d:collection/></d:resourcetype>' ),
        dav_response( $home . 'personal/', $calendar_type . '<d:displayname>Personnel &amp; famille</d:displayname>' ),
        dav_response( $home . 'subscriptions/', '<d:resourcetype><d:collection/><cs:subscribed/></d:resourcetype>' ),
        dav_response( $home . 'unavailable/', $calendar_type, 404 ),
        dav_response( $home . 'foreign-namespace/', '<d:resourcetype><cs:calendar/></d:resourcetype>' )
    ) ),
);
expect( array( array( 'url' => 'https://cloud.example.org' . $home . 'personal/', 'name' => 'Personnel & famille' ) ) ===
    Dendrila_Calendar_Discovery::discover( 'nextcloud', 'cloud.example.org', 'alice', 'password' ), 'Nextcloud-style discovery must return only successful CalDAV collections' );
expect( ! $GLOBALS['dav_requests'], 'Nextcloud discovery should complete all three DAV stages' );

$apple = 'https://p100-caldav.icloud.com';
$GLOBALS['dav_requests'] = array(
    array( 'https://caldav.icloud.com/', 0, 207, dav_multistatus( dav_response( '/', '<d:current-user-principal><d:href>' . $apple . '/123/principal/</d:href></d:current-user-principal>' ) ) ),
    array( $apple . '/123/principal/', 0, 207, dav_multistatus( dav_response( '/123/principal/', '<cal:calendar-home-set><d:href>/123/calendars/</d:href></cal:calendar-home-set>' ) ) ),
    array( $apple . '/123/calendars/', 1, 207, dav_multistatus( dav_response( '/123/calendars/home/', $calendar_type . '<d:displayname>Home</d:displayname>' ) ) ),
);
expect( array( array( 'url' => $apple . '/123/calendars/home/', 'name' => 'Home' ) ) ===
    Dendrila_Calendar_Discovery::discover( 'icloud', '', 'alice', 'password' ), 'Apple-style discovery must support the account-specific CalDAV host' );
expect( ! Dendrila_Calendar_Discovery::trusted_url( $apple . ':8443/', 'https://caldav.icloud.com/', 'icloud' ), 'Apple credentials must stay on the HTTPS default port' );

$GLOBALS['dav_requests'] = array( array( $base, 0, 302, '', array( 'location' => 'https://elsewhere.example.org/dav/' ) ) );
expect( is_wp_error( Dendrila_Calendar_Discovery::discover( 'nextcloud', 'cloud.example.org', 'alice', 'password' ) ), 'A foreign redirect must stop before the next request' );
expect( ! Dendrila_Calendar_Discovery::parse_dav( '<!DOCTYPE evil><x/>' ), 'Reject DOCTYPE external entities' );
expect( ! Dendrila_Calendar_Discovery::parse_dav( ' ' ), 'An empty DAV result must not cause a DOM fatal error' );
expect( ! Dendrila_Calendar_Discovery::parse_dav( '<multistatus/>' ), 'Malformed or non-DAV replies must be rejected' );
echo "CalDAV discovery: account binding, DAV XML, Nextcloud and iCloud response fixtures, and redirect policy passed\n";
