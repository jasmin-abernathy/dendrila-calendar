<?php
/** Simulated Google/Microsoft OAuth consent, token handling and calendar selection. */
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
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
function check_admin_referer( $nonce ) { if ( ! in_array( $nonce, array( 'dendrila_calendar_oauth_start', 'dendrila_calendar_oauth_confirm', 'dendrila_calendar_oauth_dedupe', 'dendrila_calendar_oauth_disconnect', 'dendrila_calendar_oauth_mode' ), true ) ) { throw new RuntimeException( 'Unexpected nonce action' ); } }
function wp_nonce_field( $nonce ) { echo '<input type="hidden" name="_wpnonce" value="test">'; }
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
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['post_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['post_meta'][ $id ][ $key ] = $value; return true; }
function wp_generate_uuid4() { return '00000000-1111-4000-8000-123456789012'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function checked( $value, $expected = true, $echo = true ) { return $value == $expected ? ' checked="checked"' : ''; }
function wp_timezone() { return new DateTimeZone( 'Europe/Paris' ); }
function is_wp_error( $response ) { return false; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_response_code( $response ) { return $response['status']; }
function response( $status, $data ) { return array( 'status' => $status, 'body' => json_encode( $data ) ); }
class RedirectSignal extends RuntimeException {}
class WP_Upgrader {
    public static function create_lock( $name, $ttl ) { return true; }
    public static function release_lock( $name ) {}
}
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
    public static function available() { return array( 'easy_appointments' => 'Easy Appointments', 'native' => 'Local events' ); }
    public static function events( $start, $end, $sources ) { return array(); }
}
class Dendrila_Calendar_Two_Way {
    public static function sync( $id, $item, $access, $events, $from, $to ) {
        $GLOBALS['two_way_calls'][] = $id;
        return array( 'errors' => 0, 'conflicts' => array() );
    }
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


// Regression: reauthorizing the same destination updates its token in place,
// preserving remote-event identities. No third connection may appear.
$original = Dendrila_Calendar_OAuth::connections();
$google_id = '';
foreach ( $original as $id => $connection ) {
    if ( 'google' === $connection['provider'] ) { $google_id = $id; break; }
}
if ( '' === $google_id ) { throw new RuntimeException( 'Google fixture connection missing.' ); }
$original[ $google_id ]['map'] = array(
    'existing-source-key' => array( 'remote_id' => 'dc-existing-event', 'fingerprint' => 'before-reauth' ),
);
update_option( Dendrila_Calendar_OAuth::OPTION, $original, false );
$_POST = array( 'provider' => 'google' );
$repeat_url = redirected( array( 'Dendrila_Calendar_OAuth', 'start' ) );
parse_str( parse_url( $repeat_url, PHP_URL_QUERY ), $repeat_args );
$_GET = array( 'state' => $repeat_args['state'], 'code' => 'repeat-consent' );
$after_repeat = redirected( $GLOBALS['hooks']['admin_post'] );
if ( strpos( $after_repeat, 'oauth_status=choose' ) === false ) {
    throw new RuntimeException( 'Repeat OAuth consent did not reach calendar selection.' );
}
$_POST = array( 'calendar' => 'primary@example.test', 'sources' => array( 'easy_appointments' ) );
$repeat_confirm = redirected( array( 'Dendrila_Calendar_OAuth', 'confirm' ) );
if ( strpos( $repeat_confirm, 'oauth_status=updated' ) === false ) {
    throw new RuntimeException( 'Already connected destination was not updated in place.' );
}
$updated = Dendrila_Calendar_OAuth::connections();
if ( count( $updated ) !== 2 || empty( $updated[ $google_id ]['map']['existing-source-key'] ) ) {
    throw new RuntimeException( 'Reconnection added a duplicate or discarded event identities.' );
}

// An older version could already have saved duplicates. Consolidation is
// explicitly initiated by the administrator; it must not delete remote events.
$extra = $updated[ $google_id ];
$extra['map'] = array(
    'additional-source-key' => array( 'remote_id' => 'dc-additional-event', 'fingerprint' => 'other-event' ),
    'existing-source-key' => array( 'remote_id' => 'dc-conflicting-remote-id', 'fingerprint' => 'older-event' ),
);
$updated['duplicategoogle'] = $extra;
update_option( Dendrila_Calendar_OAuth::OPTION, $updated, false );
$before_cleanup_calls = count( $GLOBALS['calls'] );
$_POST = array( 'connection_id' => 'duplicategoogle' );
$cleanup = redirected( array( 'Dendrila_Calendar_OAuth', 'consolidate_duplicate' ) );
if ( strpos( $cleanup, 'oauth_status=deduped' ) === false ) {
    throw new RuntimeException( 'Duplicate consolidation did not complete.' );
}
$clean = Dendrila_Calendar_OAuth::connections();
if ( count( $clean ) !== 2 || isset( $clean['duplicategoogle'] ) ||
     ( $clean[ $google_id ]['map']['existing-source-key']['remote_id'] ?? '' ) !== 'dc-existing-event' ||
     ( $clean[ $google_id ]['map']['additional-source-key']['remote_id'] ?? '' ) !== 'dc-additional-event' ) {
    throw new RuntimeException( 'Consolidation deleted a mapping, changed the primary event or retained the duplicate.' );
}
if ( count( $GLOBALS['calls'] ) !== $before_cleanup_calls ) {
    throw new RuntimeException( 'Consolidating connections must not delete or modify remote events.' );
}
echo "OAuth same-calendar reauthorization and safe duplicate consolidation passed\n";

// Disconnect forgets credentials while retaining the destination's identities for later reconnection.
$saved_links = array( 88 => array( 'remote' => 'remote-import', 'baseline' => 'baseline', 'generation' => 0 ) );
update_option( 'dc_two_way_links_' . $google_id, $saved_links );
$_POST = array( 'connection_id' => $google_id );
$disconnected = redirected( array( 'Dendrila_Calendar_OAuth', 'disconnect' ) );
$archive = get_option( Dendrila_Calendar_OAuth::ARCHIVES_OPTION );
if ( isset( Dendrila_Calendar_OAuth::connections()[ $google_id ] ) ||
     false !== strpos( json_encode( $archive ), 'encrypted:' ) ||
     false !== strpos( json_encode( $archive ), 'fake-access' ) ) {
    throw new RuntimeException( 'Disconnected destination retained credentials' );
}
$_POST = array( 'provider' => 'google' );
$reconnect_url = redirected( array( 'Dendrila_Calendar_OAuth', 'start' ) );
parse_str( parse_url( $reconnect_url, PHP_URL_QUERY ), $reconnect_args );
$_GET = array( 'state' => $reconnect_args['state'], 'code' => 'reconnect-consent' );
redirected( $GLOBALS['hooks']['admin_post'] );
$_POST = array( 'calendar' => 'primary@example.test', 'sources' => array( 'easy_appointments' ) );
redirected( array( 'Dendrila_Calendar_OAuth', 'confirm' ) );
$reconnected = Dendrila_Calendar_OAuth::connections();
if ( ! isset( $reconnected[ $google_id ]['map']['existing-source-key'] ) ||
     $saved_links !== get_option( 'dc_two_way_links_' . $google_id ) ) {
    throw new RuntimeException( 'Disconnect/reconnect lost destination identity or two-way links' );
}

// Compatible links and import markers migrate with an explicitly consolidated duplicate.
$reconnected['duplicate-with-links'] = $reconnected[ $google_id ];
update_option( Dendrila_Calendar_OAuth::OPTION, $reconnected );
update_option( 'dc_two_way_links_duplicate-with-links', array( 99 => array( 'remote' => 'second-import', 'baseline' => 'other', 'generation' => 0 ) ) );
update_post_meta( 99, '_dendrila_calendar_import_connection', 'duplicate-with-links' );
$_POST = array( 'connection_id' => 'duplicate-with-links' );
redirected( array( 'Dendrila_Calendar_OAuth', 'consolidate_duplicate' ) );
if ( ! isset( get_option( 'dc_two_way_links_' . $google_id )[99] ) ||
     $google_id !== get_post_meta( 99, '_dendrila_calendar_import_connection' ) ||
     hash( 'sha256', $google_id . '|second-import' ) !== get_post_meta( 99, '_dendrila_calendar_import_identity' ) ) {
    throw new RuntimeException( 'Consolidation lost two-way links or orphaned imported events' );
}
echo "OAuth disconnect/reconnect identity retention and two-way link consolidation passed\n";

// Direction defaults belong to the selected destination, including saved history.
$availability_fixture_options = $GLOBALS['options'];
foreach ( array( 'google', 'microsoft' ) as $provider ) {
    $base = array(
        'provider' => $provider, 'access' => Dendrila_Calendar_Bridge::protect( 'fake-access' ),
        'refresh' => Dendrila_Calendar_Bridge::protect( 'fake-refresh' ), 'expires' => time() + 3600,
        'sources' => array( 'easy_appointments' ), 'map' => array(), 'last_run' => '', 'last_error' => '',
    );
    $known = array();
    foreach ( array( 'known-export' => false, 'known-two-way' => true ) as $calendar => $mode ) {
        $known[ $calendar ] = $base + array( 'calendar' => $calendar, 'label' => $calendar, 'two_way' => $mode );
    }
    $history = array();
    foreach ( array( 'archived-export' => false, 'archived-two-way' => true, 'archived-legacy' => null, 'known-export' => true ) as $calendar => $mode ) {
        $history[ hash( 'sha256', $provider . '|' . $calendar ) ] = array( 'id' => $calendar, 'map' => array() );
        if ( null !== $mode ) { $history[ hash( 'sha256', $provider . '|' . $calendar ) ]['two_way'] = $mode; }
    }
    // The same calendar ID at another provider is not this destination's history.
    $history[ hash( 'sha256', ( 'google' === $provider ? 'microsoft' : 'google' ) . '|new-calendar' ) ] = array( 'id' => 'other-provider', 'two_way' => false );
    $expected_modes = array( 'new-calendar' => true, 'known-export' => false, 'known-two-way' => true,
        'archived-export' => false, 'archived-two-way' => true, 'archived-legacy' => false );
    $pending = $base + array( 'calendars' => array_combine( array_keys( $expected_modes ), array_keys( $expected_modes ) ) );
    $fixture_options = array( Dendrila_Calendar_OAuth::OPTION => $known, Dendrila_Calendar_OAuth::ARCHIVES_OPTION => $history );
    $GLOBALS['options'] = $fixture_options;
    $_GET = array();
    foreach ( $expected_modes as $first_calendar => $expected_mode ) {
        $view = $pending;
        $view['calendars'] = array( $first_calendar => $first_calendar ) + $pending['calendars'];
        set_transient( 'dc_oauth_pending_7', $view );
        ob_start();
        Dendrila_Calendar_OAuth::render_settings();
        $html = ob_get_clean();
        if ( ! preg_match( '/<input[^>]+id="dendrila-oauth-two-way"[^>]*>/', $html, $checkbox ) ||
             ( false !== strpos( $checkbox[0], ' checked' ) ) !== $expected_mode ||
             false === strpos( $html, 'id="dendrila-oauth-mode-calendar" value="' . $first_calendar . '"' ) ) {
            throw new RuntimeException( 'Wrong initial direction for ' . $provider . '/' . $first_calendar );
        }
        foreach ( $expected_modes as $calendar => $mode ) {
            if ( false === strpos( $html, '<option value="' . $calendar . '" data-two-way="' . ( $mode ? '1' : '0' ) . '">' ) ) {
                throw new RuntimeException( 'Missing destination-specific mode for ' . $calendar );
            }
        }
        if ( false === strpos( $html, 'Connect and synchronize this calendar' ) ||
             false === strpos( $html, 'Deletions propagate.' ) || false === strpos( $html, '<noscript>' ) ||
             $GLOBALS['options'] !== $fixture_options ) {
            throw new RuntimeException( 'Rendering changed saved options or lost connection guidance' );
        }
    }

    $cases = array(
        array( 'new-calendar', 'new-calendar', true, true ),
        array( 'new-calendar', 'new-calendar', false, false ),
        array( 'known-export', 'known-export', false, false ),
        array( 'known-two-way', 'known-two-way', true, true ),
        array( 'known-export', 'known-export', true, true ),
        array( 'known-two-way', 'known-two-way', false, false ),
        array( 'known-export', 'new-calendar', true, false ),
        array( 'known-two-way', 'known-export', false, true ),
        array( 'known-export', null, true, false ),
        array( 'archived-export', 'new-calendar', true, false ),
        array( 'archived-two-way', 'known-export', false, true ),
        array( 'archived-legacy', 'new-calendar', true, false ),
        array( 'archived-export', 'archived-export', true, true ),
    );
    foreach ( $cases as $case ) {
        list( $calendar, $displayed_calendar, $checked, $expected_mode ) = $case;
        $GLOBALS['options'] = $fixture_options;
        $GLOBALS['two_way_calls'] = array();
        set_transient( 'dc_oauth_pending_7', $pending );
        // No selected sources when two-way is expected: native must be added
        // after the effective direction is resolved, including without JS.
        $_POST = array( 'calendar' => $calendar, 'sources' => $expected_mode ? array() : array( 'easy_appointments' ) );
        if ( null !== $displayed_calendar ) { $_POST['two_way_calendar'] = $displayed_calendar; }
        if ( $checked ) { $_POST['two_way'] = '1'; }
        $result = redirected( array( 'Dendrila_Calendar_OAuth', 'confirm' ) );
        if ( ! preg_match( '/oauth_status=(connected|updated)/', $result ) ) {
            throw new RuntimeException( 'Direction confirmation failed: ' . $result );
        }
        $matched = null;
        foreach ( Dendrila_Calendar_OAuth::connections() as $item ) {
            if ( $item['provider'] === $provider && $item['calendar'] === $calendar ) { $matched = $item; }
        }
        if ( ! $matched || $matched['two_way'] !== $expected_mode ||
             ( $expected_mode && ! in_array( 'native', $matched['sources'], true ) ) ||
             count( $GLOBALS['two_way_calls'] ) !== ( $expected_mode ? 1 : 0 ) ) {
            throw new RuntimeException( 'Direction was changed unexpectedly or not applied: ' . json_encode( $case ) );
        }
    }

    // Disconnect archives both explicit modes without retaining any credentials.
    foreach ( array( false, true ) as $mode ) {
        $GLOBALS['options'] = $fixture_options;
        $GLOBALS['options'][ Dendrila_Calendar_OAuth::OPTION ]['known-export']['two_way'] = $mode;
        $_POST = array( 'connection_id' => 'known-export' );
        redirected( array( 'Dendrila_Calendar_OAuth', 'disconnect' ) );
        $saved = get_option( Dendrila_Calendar_OAuth::ARCHIVES_OPTION )[ hash( 'sha256', $provider . '|known-export' ) ];
        if ( $saved['two_way'] !== $mode || array_diff( array_keys( $saved ), array( 'id', 'map', 'two_way' ) ) ) {
            throw new RuntimeException( 'Disconnect lost direction or archived credentials' );
        }
        set_transient( 'dc_oauth_pending_7', $pending );
        $_POST = array( 'calendar' => 'known-export', 'two_way_calendar' => 'new-calendar', 'sources' => array( 'easy_appointments' ) );
        if ( ! $mode ) { $_POST['two_way'] = '1'; }
        redirected( array( 'Dendrila_Calendar_OAuth', 'confirm' ) );
        if ( Dendrila_Calendar_OAuth::connections()['known-export']['two_way'] !== $mode ) {
            throw new RuntimeException( 'Reconnection did not restore archived direction' );
        }
    }
}
$GLOBALS['options'] = $availability_fixture_options;
echo "OAuth direction defaults: new, active, archived, legacy, explicit choices and no-JS destination changes passed for both providers\n";
