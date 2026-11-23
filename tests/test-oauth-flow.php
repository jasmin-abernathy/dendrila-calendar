<?php
/** Simulated Google/Microsoft OAuth consent, token handling and calendar selection. */
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'DENDRILA_CALENDAR_GOOGLE_CLIENT_ID', 'test-google-id' );
define( 'DENDRILA_CALENDAR_GOOGLE_CLIENT_SECRET', 'test-google-secret' );
define( 'DENDRILA_CALENDAR_MICROSOFT_CLIENT_ID', 'test-ms-id' );
define( 'DENDRILA_CALENDAR_MICROSOFT_CLIENT_SECRET', 'test-ms-secret' );

$GLOBALS['transients'] = array();
$GLOBALS['options'] = array();
$GLOBALS['calls'] = array();
$GLOBALS['token_requests'] = array();
function __( $s, $domain = '' ) { return $s; }
function esc_html__( $s, $domain = '' ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return $s; }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $s ) ); }
function sanitize_text_field( $s ) { return strip_tags( $s ); }
function wp_unslash( $s ) { return $s; }
function wp_die( $s ) { throw new RuntimeException( $s ); }
function current_user_can( $cap ) { return $cap === 'manage_options'; }
function get_current_user_id() { return 7; }
function check_admin_referer( $nonce ) { if ( ! in_array( $nonce, array( 'dendrila_calendar_oauth_start', 'dendrila_calendar_oauth_confirm' ), true ) ) { throw new RuntimeException( 'Unexpected nonce action' ); } }
function add_query_arg( $arguments, $url ) {
    return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $arguments );
}
function admin_url( $path = '' ) { return 'https://wordpress.example.test/wp-admin/' . $path; }
function add_action( $tag, $callback ) { $GLOBALS['hooks'][ $tag ] = $callback; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['transients'][ $key ] = $value; }
function get_transient( $key ) { return $GLOBALS['transients'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['transients'][ $key ] ); }
function get_option( $key, $default = array() ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][ $key ] = $value; return true; }
function wp_generate_uuid4() { return '00000000-1111-4000-8000-123456789012'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_timezone() { return new DateTimeZone( 'Europe/Paris' ); }
function is_wp_error( $response ) { return false; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function response( $status, $data ) { return array( 'status' => $status, 'body' => json_encode( $data ) ); }
class RedirectSignal extends RuntimeException {}
function add_filter( $tag, $callback ) { $GLOBALS['redirect_host_filter'] = $callback; }
function remove_filter( $tag, $callback ) { unset( $GLOBALS['redirect_host_filter'] ); }
function wp_redirect( $url, $code = 302, $origin = '' ) { throw new RedirectSignal( $url ); }
function wp_safe_redirect( $url, $code = 302, $origin = '' ) { throw new RedirectSignal( $url ); }
function wp_safe_remote_post( $url, $args ) {
    if ( ! in_array( $url, array( 'https://oauth2.googleapis.com/token', 'https://login.microsoftonline.com/common/oauth2/v2.0/token' ), true ) ) {
        throw new RuntimeException( 'Unexpected token endpoint' );
    }
    $params = $args['body'];
    if ( ! in_array( $params['grant_type'], array( 'authorization_code', 'refresh_token' ), true ) ||
         empty( $params['client_secret'] ) ) { throw new RuntimeException( 'Bad token exchange' ); }
    $GLOBALS['token_requests'][] = $params;
    return response( 200, array( 'access_token' => 'fake-access', 'refresh_token' => 'fake-refresh', 'expires_in' => 3600 ) );
}
function wp_safe_remote_request( $url, $args ) {
    if ( strpos( $url, 'https://www.googleapis.com/calendar/v3/' ) !== 0 &&
         strpos( $url, 'https://graph.microsoft.com/v1.0/' ) !== 0 ) {
        throw new RuntimeException( 'Request escaped OAuth API host allowlist' );
    }
    $GLOBALS['calls'][] = array( $url, $args['method'] );
    if ( 'GET' === $args['method'] ) {
        if ( ! empty( $GLOBALS['busy_should_fail'] ) &&
             ( strpos( $url, '/events?' ) !== false || strpos( $url, '/calendarView?' ) !== false ) ) {
            return response( 503, array( 'error' => 'temporarily_unavailable' ) );
        }
        if ( strpos( $url, '/calendarView?' ) !== false ) {
            return response( 200, array( 'value' => array(
                array( 'id' => 'outlook-busy', 'start' => array( 'dateTime' => '2026-11-24T14:00:00', 'timeZone' => 'UTC' ),
                    'end' => array( 'dateTime' => '2026-11-24T15:00:00', 'timeZone' => 'UTC' ), 'showAs' => 'busy' ),
                array( 'id' => 'outlook-free', 'start' => array( 'dateTime' => '2026-11-24T16:00:00', 'timeZone' => 'UTC' ),
                    'end' => array( 'dateTime' => '2026-11-24T17:00:00', 'timeZone' => 'UTC' ), 'showAs' => 'free' ),
            ) ) );
        }
        if ( strpos( $url, 'googleapis.com' ) !== false && strpos( $url, '/events?' ) !== false ) {
            return response( 200, array( 'items' => array(
                array( 'id' => 'google-busy', 'start' => array( 'dateTime' => '2026-11-24T10:00:00Z' ),
                    'end' => array( 'dateTime' => '2026-11-24T11:00:00Z' ), 'transparency' => 'opaque' ),
                array( 'id' => 'google-transparent', 'start' => array( 'dateTime' => '2026-11-24T11:00:00Z' ),
                    'end' => array( 'dateTime' => '2026-11-24T12:00:00Z' ), 'transparency' => 'transparent' ),
            ) ) );
        }
        return strpos( $url, 'graph.microsoft.com' ) !== false
            ? response( 200, array( 'value' => array( array( 'id' => 'outlook-1', 'name' => 'Outlook test', 'canEdit' => true ) ) ) )
            : response( 200, array( 'items' => array( array( 'id' => 'primary@example.test', 'summary' => 'Google test', 'accessRole' => 'owner' ) ) ) );
    }
    if ( 'POST' === $args['method'] ) {
        return response( strpos( $url, 'googleapis.com' ) !== false ? 200 : 201, array( 'id' => 'test-created-event' ) );
    }
    if ( 'DELETE' === $args['method'] ) {
        return response( 204, array() );
    }
    throw new RuntimeException( 'Unexpected remote write' );
}
class Dendrila_Calendar_Bridge {
    public static function protect( $value ) { return 'encrypted:' . base64_encode( $value ); }
    public static function reveal( $value ) {
        return strpos( $value, 'encrypted:' ) === 0 ? base64_decode( substr( $value, 10 ), true ) : false;
    }
    public static function identity( $event ) { return hash( 'sha256', $event['id'] ); }
    public static function fingerprint( $event ) { return hash( 'sha256', $event['id'] . $event['start'] ); }
    public static function status_is_cancelled( $status ) { return 'cancelled' === $status; }
}
class Dendrila_Calendar_Subscriptions {
    public static function allowed_sources( $requested, $available ) {
        return array_values( array_intersect( $requested, array_keys( $available ) ) );
    }
}
class Dendrila_Calendar_Sources {
    public static function available() { return array( 'easy_appointments' => 'Easy Appointments' ); }
    public static function events( $start, $end, $sources ) { return array(); }
}
class Dendrila_Calendar_Setup { public static function destination_connected() {} }
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-oauth.php';
Dendrila_Calendar_OAuth::boot();
if ( ! isset( $GLOBALS['hooks']['admin_post'] ) ) {
    throw new RuntimeException( 'Query-free OAuth callback routing was not registered.' );
}

function redirected( $callback ) {
    try { $callback(); }
    catch ( RedirectSignal $e ) { return $e->getMessage(); }
    throw new RuntimeException( 'Expected redirect was not issued' );
}
foreach ( array(
    'google' => array( 'accounts.google.com', 'primary@example.test' ),
    'microsoft' => array( 'login.microsoftonline.com', 'outlook-1' ),
) as $provider => $expected ) {
    if ( ! Dendrila_Calendar_OAuth::ready( $provider ) ) {
        throw new RuntimeException( 'Configured provider not available: ' . $provider );
    }
    $_POST = array( 'provider' => $provider );
    $auth_url = redirected( array( 'Dendrila_Calendar_OAuth', 'start' ) );
    if ( strpos( $auth_url, $expected[0] ) === false ||
         strpos( $auth_url, 'code_challenge=' ) === false ||
         strpos( $auth_url, 'code_verifier=' ) !== false ||
         strpos( $auth_url, 'client_secret=' ) !== false ) {
        throw new RuntimeException( 'OAuth authorization URL is insecure.' );
    }
    parse_str( parse_url( $auth_url, PHP_URL_QUERY ), $args );
    if ( $args['redirect_uri'] !== 'https://wordpress.example.test/wp-admin/admin-post.php' ) {
        throw new RuntimeException( 'OAuth callback must not contain a query string for Microsoft personal accounts.' );
    }
    $_GET = array( 'state' => $args['state'], 'code' => 'provider-valid-code' );
    $after = redirected( $GLOBALS['hooks']['admin_post'] );
    if ( strpos( $after, 'oauth_status=choose' ) === false ) {
        throw new RuntimeException( 'Authorization did not reach calendar choice.' );
    }
    $_POST = array( 'calendar' => $expected[1], 'sources' => array( 'easy_appointments' ) );
    $confirmation = redirected( array( 'Dendrila_Calendar_OAuth', 'confirm' ) );
    if ( strpos( $confirmation, 'oauth_status=connected' ) === false ) {
        throw new RuntimeException( 'Authorized connection was not verified.' );
    }
}
$connections = Dendrila_Calendar_OAuth::connections();
if ( count( $connections ) !== 2 ) {
    throw new RuntimeException( 'Google/Microsoft connections were not both saved.' );
}
foreach ( $connections as $connection ) {
    if ( strpos( $connection['access'], 'encrypted:' ) !== 0 ||
         strpos( $connection['refresh'], 'encrypted:' ) !== 0 ) {
        throw new RuntimeException( 'Tokens were not encrypted before storage.' );
    }
}
if ( strpos( json_encode( $connections ), 'fake-refresh' ) !== false ) {
    throw new RuntimeException( 'Plaintext refresh token leaked into options.' );
}
if ( count( array_filter( $GLOBALS['calls'], static function ( $call ) { return $call[1] === 'POST'; } ) ) !== 2 ||
     count( array_filter( $GLOBALS['calls'], static function ( $call ) { return $call[1] === 'DELETE'; } ) ) !== 2 ) {
    throw new RuntimeException( 'Real event creation/deletion probe did not run for both calendars.' );
}
echo "Google/Microsoft simulated consent, provider calendar discovery, write probe and encrypted tokens passed\n";
