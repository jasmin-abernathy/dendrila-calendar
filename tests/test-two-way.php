<?php
/** Behavioural regression tests: the real sync engine and HTTP adapter, simulated providers. */
error_reporting( E_ALL );
set_error_handler( static function ( $severity, $message, $file, $line ) { throw new ErrorException( $message, 0, $severity, $file, $line ); } );
define( 'ABSPATH', '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'EMPTY_TRASH_DAYS', 30 );
$GLOBALS['options'] = $GLOBALS['posts'] = $GLOBALS['meta'] = $GLOBALS['remote'] = $GLOBALS['calls'] = $GLOBALS['transients'] = array();
$GLOBALS['next_id'] = 100;
$GLOBALS['checks'] = 0;
function __( $s, $domain = '' ) { return $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_slash( $v ) { return $v; }
function wp_salt( $v ) { return 'calendar-test-salt'; }
function wp_timezone() { return new DateTimeZone( 'Europe/Paris' ); }
function wp_timezone_string() { return 'Europe/Paris'; }
function wp_parse_url( $v ) { return parse_url( $v ); }
function home_url( $v = '' ) { return 'https://example.test/' . ltrim( $v, '/' ); }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function get_option( $k, $default = array() ) { return $GLOBALS['options'][ $k ] ?? $default; }
function update_option( $k, $v, $autoload = false ) { $GLOBALS['options'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['transients'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function get_posts( $args ) {
    $ids = array();
    foreach ( $GLOBALS['posts'] as $id => $post ) {
        if ( $post->post_type === $args['post_type'] && in_array( $post->post_status, $args['post_status'], true ) &&
            get_post_meta( $id, $args['meta_key'] ) === $args['meta_value'] ) { $ids[] = $id; }
    }
    return array_slice( $ids, 0, $args['numberposts'] );
}
function wp_insert_post( $data, $error = false ) {
    $id = $data['ID'] ?? ++$GLOBALS['next_id'];
    $GLOBALS['posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => $data['post_type'], 'post_status' => $data['post_status'], 'post_title' => $data['post_title'] );
    $GLOBALS['meta'][ $id ] = array_merge( $GLOBALS['meta'][ $id ] ?? array(), $data['meta_input'] ?? array() );
    return $id;
}
function wp_trash_post( $id ) { $GLOBALS['posts'][ $id ]->post_status = 'trash'; return get_post( $id ); }
function is_wp_error( $v ) { return false; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_response_code( $r ) { return $r['status']; }
function response( $status, $data ) { return array( 'status' => $status, 'body' => json_encode( $data ) ); }
function wp_safe_remote_request( $url, $args ) {
    $GLOBALS['calls'][] = array( 'url' => $url, 'args' => $args );
    $provider = false !== strpos( $url, 'googleapis.com' ) ? 'google' : 'microsoft';
    $method = $args['method'];
    $path = parse_url( $url, PHP_URL_PATH );
    $collection = preg_match( '#/(?:events|calendarView)$#', $path );
    $id = rawurldecode( basename( $path ) );
    parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
    if ( isset( $GLOBALS['override'] ) ) {
        $override = call_user_func( $GLOBALS['override'], $url, $args );
        if ( null !== $override ) { return $override; }
    }
    if ( 'GET' === $method && $collection ) {
        $rows = array_values( array_filter( $GLOBALS['remote'], static function ( $row ) { return empty( $row['outside'] ) && 'cancelled' !== ( $row['status'] ?? '' ); } ) );
        return response( 200, array( 'google' === $provider ? 'items' : 'value' => $rows ) );
    }
    if ( 'GET' === $method ) { return isset( $GLOBALS['remote'][ $id ] ) ? response( 200, $GLOBALS['remote'][ $id ] ) : response( 404, array() ); }
    $body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : array();
    if ( 'POST' === $method ) {
        $id = $body['id'] ?? 'ms-' . $body['transactionId'];
        if ( isset( $GLOBALS['remote'][ $id ] ) ) { return response( 'google' === $provider ? 409 : 201, $GLOBALS['remote'][ $id ] ); }
        $body['id'] = $id;
        $body[ 'google' === $provider ? 'etag' : '@odata.etag' ] = '"v1"';
        if ( 'microsoft' === $provider ) { $body['type'] = 'singleInstance'; }
        $GLOBALS['remote'][ $id ] = $body;
        return response( 201, $body );
    }
    if ( ! isset( $GLOBALS['remote'][ $id ] ) ) { return response( 404, array() ); }
    $etag = $GLOBALS['remote'][ $id ][ 'google' === $provider ? 'etag' : '@odata.etag' ];
    if ( ! isset( $args['headers']['If-Match'] ) || $args['headers']['If-Match'] !== $etag ) {
        throw new RuntimeException( 'Every two-way update/delete must use the exact remote revision' );
    }
    if ( 'DELETE' === $method ) { unset( $GLOBALS['remote'][ $id ] ); return response( 204, array() ); }
    if ( 'PATCH' === $method ) {
        $GLOBALS['remote'][ $id ] = array_replace_recursive( $GLOBALS['remote'][ $id ], $body );
        foreach ( array( 'start', 'end' ) as $part ) {
            if ( ! empty( $GLOBALS['remote'][ $id ][ $part ]['date'] ) && ! empty( $GLOBALS['remote'][ $id ][ $part ]['dateTime'] ) ) {
                throw new RuntimeException( 'Google PATCH cannot combine date and dateTime' );
            }
        }
        return response( 200, $GLOBALS['remote'][ $id ] );
    }
    throw new RuntimeException( 'Unexpected request' );
}
class WP_Upgrader {
    public static function create_lock( $name, $ttl ) { return empty( $GLOBALS['blocked_lock'] ); }
    public static function release_lock( $name ) {}
}
class Dendrila_Calendar { const POST_TYPE = 'dendrila_event'; const START_KEY = '_start'; const END_KEY = '_end'; }
class Dendrila_Calendar_Subscriptions {
    public static function allowed_sources( $s, $available ) { return array_values( array_intersect( $s, array_keys( $available ) ) ); }
}
class Dendrila_Calendar_Sources {
    public static function available() { return array( 'native' => 'Local events' ); }
    public static function events( $from, $until, $sources ) { return events(); }
}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-bridge.php';
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-oauth.php';
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-two-way.php';
function check( $condition, $message ) { ++$GLOBALS['checks']; if ( ! $condition ) { throw new RuntimeException( $message ); } }
function reset_case() {
    $GLOBALS['options'] = $GLOBALS['posts'] = $GLOBALS['meta'] = $GLOBALS['remote'] = $GLOBALS['calls'] = $GLOBALS['transients'] = array();
    $GLOBALS['next_id'] = 100;
    unset( $GLOBALS['override'], $GLOBALS['blocked_lock'] );
}
function native( $title = 'Local appointment' ) {
    return wp_insert_post( array( 'post_type' => Dendrila_Calendar::POST_TYPE, 'post_status' => 'publish', 'post_title' => $title,
        'meta_input' => array( '_start' => '2026-11-27 10:00:00', '_end' => '2026-11-27 11:00:00' ) ) );
}
function events() {
    $events = array();
    foreach ( $GLOBALS['posts'] as $id => $post ) {
        if ( 'publish' !== $post->post_status || get_post_meta( $id, Dendrila_Calendar_Two_Way::ORIGIN ) ) { continue; }
        $events[] = array( 'id' => 'native-' . $id, 'source' => 'native', 'title' => $post->post_title, 'status' => '',
            'start' => str_replace( ' ', 'T', get_post_meta( $id, '_start' ) ) . 'Z', 'end' => str_replace( ' ', 'T', get_post_meta( $id, '_end' ) ) . 'Z', 'all_day' => (bool) get_post_meta( $id, Dendrila_Calendar_Two_Way::ALL_DAY ) );
    }
    return $events;
}
function item( $provider ) { return array( 'provider' => $provider, 'calendar' => 'test', 'map' => array(), 'sources' => array( 'native' ), 'two_way' => true ); }
function sync( $provider, $connection = 'one', $custom = null ) {
    $settings = $custom ?? item( $provider );
    update_option( Dendrila_Calendar_OAuth::OPTION, array( $connection => $settings ) );
    return Dendrila_Calendar_Two_Way::sync( $connection, $settings, 'test-token', events(), '2026-11-20T00:00:00Z', '2027-02-20T00:00:00Z' );
}
function row( $provider, $id = 'remote-one', $title = 'Remote appointment' ) {
    if ( 'google' === $provider ) { return array( 'id' => $id, 'summary' => $title, 'etag' => '"r1"', 'start' => array( 'dateTime' => '2026-11-27T10:00:00Z' ), 'end' => array( 'dateTime' => '2026-11-27T11:00:00Z' ) ); }
    return array( 'id' => $id, 'subject' => $title, '@odata.etag' => '"r1"', 'type' => 'singleInstance', 'start' => array( 'dateTime' => '2026-11-27T10:00:00.0000000', 'timeZone' => 'UTC' ), 'end' => array( 'dateTime' => '2026-11-27T11:00:00.0000000', 'timeZone' => 'UTC' ) );
}
function writes() { return array_values( array_filter( $GLOBALS['calls'], static function ( $call ) { return 'GET' !== $call['args']['method']; } ) ); }

foreach ( array( 'google', 'microsoft' ) as $provider ) {
    reset_case();
    $title_key = 'google' === $provider ? 'summary' : 'subject';
    $id = native();
    $report = sync( $provider );
    check( 1 === $report['exported'] && 0 === $report['errors'] && 1 === count( $GLOBALS['remote'] ), "$provider creates one event" );
    $rid = array_key_first( $GLOBALS['remote'] );
    sync( $provider );
    check( 1 === count( writes() ), "$provider unchanged sync is idempotent" );
    $GLOBALS['posts'][ $id ]->post_title = 'Changed locally';
    sync( $provider );
    check( 'Changed locally' === $GLOBALS['remote'][ $rid ][ $title_key ], "$provider local change exported" );
    check( ! isset( json_decode( writes()[1]['args']['body'], true )['showAs'] ), "$provider update preserves unrelated remote fields" );
    $GLOBALS['meta'][ $id ]['_start'] = '2026-11-26 23:00:00';
    $GLOBALS['meta'][ $id ]['_end'] = '2026-11-27 23:00:00';
    $GLOBALS['meta'][ $id ][ Dendrila_Calendar_Two_Way::ALL_DAY ] = 1;
    check( 0 === sync( $provider )['errors'] && Dendrila_Calendar_Two_Way::normalize( $GLOBALS['remote'][ $rid ] )['all_day'], "$provider timed-to-all-day PATCH clears incompatible date fields" );
    $GLOBALS['meta'][ $id ]['_start'] = '2026-11-27 10:00:00';
    $GLOBALS['meta'][ $id ]['_end'] = '2026-11-27 11:00:00';
    $GLOBALS['meta'][ $id ][ Dendrila_Calendar_Two_Way::ALL_DAY ] = 0;
    check( 0 === sync( $provider )['errors'] && ! Dendrila_Calendar_Two_Way::normalize( $GLOBALS['remote'][ $rid ] )['all_day'], "$provider all-day-to-timed PATCH clears incompatible date fields" );
    $GLOBALS['remote'][ $rid ][ $title_key ] = 'Changed remotely';
    sync( $provider );
    check( 'Changed remotely' === get_post( $id )->post_title, "$provider remote change imported" );
    $GLOBALS['posts'][ $id ]->post_title = 'Local conflict';
    $GLOBALS['remote'][ $rid ][ $title_key ] = 'Remote conflict';
    $count = count( writes() );
    $report = sync( $provider );
    check( array( $id ) === $report['conflicts'] && $count === count( writes() ) && 'Local conflict' === get_post( $id )->post_title, "$provider simultaneous changes never overwrite either copy" );
    $GLOBALS['posts'][ $id ]->post_title = 'Remote conflict';
    check( array() === sync( $provider )['conflicts'], "$provider matching the copies resolves conflict" );
    $GLOBALS['posts'][ $id ]->post_title = 'Another edit';
    $GLOBALS['override'] = static function ( $url, $args ) { return 'PATCH' === $args['method'] ? response( 412, array() ) : null; };
    check( array( $id ) === sync( $provider )['conflicts'] && 'Remote conflict' === $GLOBALS['remote'][ $rid ][ $title_key ], "$provider concurrent HTTP revision is a conflict" );
    unset( $GLOBALS['override'] );
    sync( $provider );
    wp_trash_post( $id );
    sync( $provider );
    check( empty( $GLOBALS['remote'] ), "$provider local deletion propagates" );
    sync( $provider );
    check( empty( $GLOBALS['remote'] ), "$provider deleted event is not recreated" );
    $GLOBALS['posts'][ $id ]->post_status = 'publish';
    sync( $provider );
    check( 1 === count( $GLOBALS['remote'] ) && ! isset( $GLOBALS['remote'][ $rid ] ), "$provider restore uses a new stable identity" );
    $rid = array_key_first( $GLOBALS['remote'] );
    $GLOBALS['remote'][ $rid ]['outside'] = true;
    sync( $provider );
    check( 'publish' === get_post( $id )->post_status, "$provider out-of-window linked event is checked by ID, not deleted" );
    unset( $GLOBALS['remote'][ $rid ] );
    sync( $provider );
    check( 'trash' === get_post( $id )->post_status, "$provider confirmed remote deletion moves local copy to Trash" );

    reset_case();
    $GLOBALS['remote']['remote-one'] = row( $provider );
    $report = sync( $provider );
    $id = array_key_first( $GLOBALS['posts'] );
    check( 1 === $report['imported'] && 'one' === get_post_meta( $id, Dendrila_Calendar_Two_Way::ORIGIN ), "$provider remote import is private and marked to avoid cross-calendar loops" );
    sync( $provider );
    check( 1 === count( $GLOBALS['posts'] ) && empty( writes() ), "$provider imports are idempotent" );
    $GLOBALS['posts'][ $id ]->post_title = 'Edited import';
    sync( $provider );
    check( 'Edited import' === $GLOBALS['remote']['remote-one'][ $title_key ], "$provider imported local event can update its source calendar" );
    $GLOBALS['posts'][ $id ]->post_title = 'Unsent local edit';
    unset( $GLOBALS['remote']['remote-one'] );
    check( array( $id ) === sync( $provider )['conflicts'] && 'publish' === get_post( $id )->post_status, "$provider deletion never erases a concurrent local edit" );

    reset_case();
    $GLOBALS['remote']['invite'] = row( $provider, 'invite' ) + array( 'attendees' => array( array( 'email' => 'guest@example.test' ) ) );
    $recurring = row( $provider, 'recurring' );
    if ( 'google' === $provider ) { $recurring['recurrence'] = array( 'RRULE:FREQ=WEEKLY' ); } else { $recurring['type'] = 'occurrence'; }
    $GLOBALS['remote']['recurring'] = $recurring;
    sync( $provider );
    check( empty( $GLOBALS['posts'] ) && empty( writes() ), "$provider invitations and recurring events are skipped" );

    reset_case();
    $id = native();
    sync( $provider );
    $GLOBALS['calls'] = array();
    $GLOBALS['posts'][ $id ]->post_title = 'Waiting edit';
    $GLOBALS['override'] = static function ( $url, $args ) { return response( 503, array() ); };
    $thrown = false;
    try { sync( $provider ); } catch ( RuntimeException $error ) { $thrown = true; }
    check( $thrown, 'Inventory failure must abort' );
    check( empty( writes() ) && 'publish' === get_post( $id )->post_status, "$provider failed inventory produces no writes or deletions" );
    unset( $GLOBALS['override'] );
    $GLOBALS['posts'][ $id ]->post_status = 'draft';
    sync( $provider );
    check( 1 === count( $GLOBALS['remote'] ) && empty( writes() ), "$provider draft is neither exported nor interpreted as deletion" );
}

reset_case();
$day = array( 'summary' => 'DST day', 'start' => array( 'date' => '2026-03-29' ), 'end' => array( 'date' => '2026-03-30' ) );
$norm = Dendrila_Calendar_Two_Way::normalize( $day );
check( '2026-03-28 23:00:00' === $norm['start'] && '2026-03-29 22:00:00' === $norm['end'], 'All-day DST uses local calendar dates' );
$dates = $norm;
foreach ( array( 'start', 'end' ) as $key ) { $dates[ $key ] = str_replace( ' ', 'T', $norm[ $key ] ) . 'Z'; }
$formatted = Dendrila_Calendar_OAuth::format_event( 'microsoft', $dates );
check( '2026-03-29T00:00:00' === $formatted['start']['dateTime'] && '2026-03-30T00:00:00' === $formatted['end']['dateTime'], 'Microsoft all-day dates must be midnight and remain on the chosen day' );
check( $norm === Dendrila_Calendar_Two_Way::normalize( $formatted ), 'Microsoft all-day round-trip preserves dates through DST' );
check( '2026-03-29' === Dendrila_Calendar_OAuth::format_event( 'google', $dates )['start']['date'], 'Google all-day date round-trip' );
$offset = array( 'title' => 'Offset', 'start' => '2026-07-01T10:00:00+02:00', 'end' => '2026-07-01T11:00:00+02:00' );
check( '2026-07-01T08:00:00Z' === Dendrila_Calendar_OAuth::format_event( 'google', $offset )['start']['dateTime'], 'Outbound offset is converted to UTC before appending Z' );
$bad = $day; $bad['start']['date'] = '2026-02-31';
$thrown = false;
try { Dendrila_Calendar_Two_Way::normalize( $bad ); } catch ( RuntimeException $error ) { $thrown = true; }
check( $thrown, 'Invalid day should fail' );
check( null === Dendrila_Calendar_Two_Way::normalize( row( 'google' ) + array( 'organizer' => array( 'self' => false ) ) ), 'Remote invitations without attendees are skipped' );

reset_case();
foreach ( array( 'https://evil.example/calendar/v3/events', 'https://www.googleapis.com:8443/calendar/v3/events', 'https://user@www.googleapis.com/calendar/v3/events', 'https://www.googleapis.com/not-calendar', 'http://www.googleapis.com/calendar/v3/events' ) as $url ) {
    check( false === Dendrila_Calendar_OAuth::api( 'google', 'secret', 'GET', $url ), 'Bearer token destination guard' );
}
check( empty( $GLOBALS['calls'] ), 'Unsafe URLs never reach HTTP client' );
check( false === Dendrila_Calendar_OAuth::api( 'google', 'secret', 'GET', 'https://www.googleapis.com/calendar/v3/events', null, array( 'If-Match' => 'bad' ) ), 'Invalid header type rejected' );
check( false === Dendrila_Calendar_OAuth::api( 'google', 'secret', 'GET', 'https://www.googleapis.com/calendar/v3/events', null, "revision\r\nInjected: yes" ), 'Header injection rejected' );

reset_case();
$id = native();
$e = events()[0];
$custom = item( 'google' );
$custom['map'][ Dendrila_Calendar_Bridge::identity( $e ) ] = array( 'remote_id' => 'legacy', 'fingerprint' => Dendrila_Calendar_Bridge::fingerprint( $e ) );
$GLOBALS['remote']['legacy'] = row( 'google', 'legacy', $e['title'] );
sync( 'google', 'one', $custom );
check( empty( writes() ) && 1 === count( $GLOBALS['posts'] ), 'Switching an outbound connection to two-way adopts existing identities' );

reset_case();
$GLOBALS['override'] = static function ( $url, $args ) {
    if ( false === strpos( $url, 'pageToken=' ) ) {
        return response( 200, array( 'items' => array( row( 'google', 'page-one' ) ), 'nextPageToken' => 'page-two' ) );
    }
    return response( 200, array( 'items' => array( row( 'google', 'page-two' ) ) ) );
};
sync( 'google' );
check( 2 === count( $GLOBALS['posts'] ), 'Google pagination consumes every page before imports' );
reset_case();
$GLOBALS['override'] = static function ( $url, $args ) {
    return response( 200, array( 'value' => array( row( 'microsoft' ) ), '@odata.nextLink' => 'https://evil.example/v1.0/events' ) );
};
$thrown = false;
try { sync( 'microsoft' ); } catch ( RuntimeException $error ) { $thrown = true; }
check( $thrown, 'Untrusted nextLink should fail' );
check( empty( $GLOBALS['posts'] ) && 1 === count( $GLOBALS['calls'] ), 'Untrusted Graph pagination cannot leak token or partially import' );

reset_case();
$id = native();
$settings = item( 'google' ) + array( 'access' => Dendrila_Calendar_Bridge::protect( 'test-token' ), 'expires' => time() + 3600, 'refresh' => '', 'last_error' => '' );
update_option( Dendrila_Calendar_OAuth::OPTION, array( 'one' => $settings ) );
Dendrila_Calendar_OAuth::run_one( 'one' );
check( 1 === count( $GLOBALS['remote'] ) && '' === get_option( Dendrila_Calendar_OAuth::OPTION )['one']['last_error'], 'OAuth run_one invokes the real two-way engine successfully' );
$rid = array_key_first( $GLOBALS['remote'] );
$GLOBALS['posts'][ $id ]->post_title = 'Local conflict';
$GLOBALS['remote'][ $rid ]['summary'] = 'Remote conflict';
Dendrila_Calendar_OAuth::run_one( 'one' );
check( false !== strpos( get_option( Dendrila_Calendar_OAuth::OPTION )['one']['last_error'], 'Both copies changed' ), 'OAuth integration exposes actionable conflict status' );
$before = count( $GLOBALS['calls'] );
$GLOBALS['blocked_lock'] = true;
Dendrila_Calendar_OAuth::run_one( 'one' );
check( $before === count( $GLOBALS['calls'] ), 'Concurrent synchronization is excluded by atomic lock' );
$settings['two_way'] = false;
update_option( Dendrila_Calendar_OAuth::OPTION, array( 'one' => $settings ) );
Dendrila_Calendar_OAuth::run_one( 'one' );
check( $before === count( $GLOBALS['calls'] ), 'Outbound-only synchronization honors the same atomic lock' );
reset_case();
$GLOBALS['override'] = static function ( $url, $args ) {
    update_option( Dendrila_Calendar_OAuth::OPTION, array() );
    return response( 200, array( 'items' => array( row( 'google' ) ) ) );
};
$thrown = false;
try { sync( 'google' ); } catch ( RuntimeException $error ) { $thrown = true; }
check( $thrown && empty( $GLOBALS['posts'] ) && empty( writes() ), 'Disconnect during remote read stops writes and imports' );
echo $GLOBALS['checks'] . " two-way synchronization checks passed (Google and Microsoft, simulated HTTP)\n";
