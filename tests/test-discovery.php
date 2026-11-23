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
function get_current_user_id() { return 9; }
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
set_transient( $key, array( 'username' => 'alice', 'provider' => 'nextcloud', 'urls' => array( 'https://cloud.example.org/remote.php/dav/calendars/alice/personal/' ) ), 600 );
expect( Dendrila_Calendar_Discovery::verified_choice( $token, 'https://cloud.example.org/remote.php/dav/calendars/alice/personal/', 'nextcloud', 'alice' ), 'Discovered calendar may be selected' );
expect( ! Dendrila_Calendar_Discovery::verified_choice( $token, 'https://evil.test/', 'nextcloud', 'alice' ), 'Unlisted destinations must be rejected' );
expect( ! Dendrila_Calendar_Discovery::verified_choice( $token, 'https://cloud.example.org/remote.php/dav/calendars/alice/personal/', 'nextcloud', 'mallory' ), 'Discovery belongs to a particular account' );
Dendrila_Calendar_Discovery::consume_choice( $token );
expect( ! Dendrila_Calendar_Discovery::verified_choice( $token, 'https://cloud.example.org/remote.php/dav/calendars/alice/personal/', 'nextcloud', 'alice' ), 'Discovery token is one-time' );
if ( class_exists( 'DOMDocument' ) ) {
    $fixture = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response><d:href>/remote.php/dav/calendars/alice/personal/</d:href><d:propstat><d:prop><d:displayname>Personal</d:displayname><d:resourcetype><d:collection/><c:calendar/></d:resourcetype></d:prop></d:propstat></d:response></d:multistatus>';
    $parsed = Dendrila_Calendar_Discovery::parse_dav( $fixture );
    expect( count( $parsed['calendars'] ) === 1 && $parsed['calendars'][0]['name'] === 'Personal', 'Calendar collection found via DAV XML' );
    expect( ! Dendrila_Calendar_Discovery::parse_dav( '<!DOCTYPE evil><x/>' ), 'Reject DOCTYPE external entities' );
}
echo "Guided CalDAV discovery: HTTPS, same-origin, account binding, token expiry and XML safety passed\n";
