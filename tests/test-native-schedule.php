<?php
/** Deterministic native availability regressions; no WordPress install or provider requests. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['schedule_posts'] = array();
$GLOBALS['schedule_meta'] = array();
$GLOBALS['schedule_remote'] = array();
$GLOBALS['schedule_connections'] = array();
$GLOBALS['schedule_remote_calls'] = array();
$GLOBALS['schedule_checks'] = 0;

function __( $text, $domain = '' ) { return $text; }
function absint( $value ) { return abs( (int) $value ); }
function wp_timezone_string() { return 'Europe/Paris'; }
function wp_cache_delete( $key, $group = '' ) { return true; }
function get_post( $id ) { return $GLOBALS['schedule_posts'][ $id ] ?? null; }
function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['schedule_meta'][ $id ][ $key ] ?? ''; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function get_posts( $args ) {
    $posts = array_filter( $GLOBALS['schedule_posts'], static function ( $post ) use ( $args ) {
        if ( $post->post_type !== $args['post_type'] || ! in_array( $post->post_status, (array) ( $args['post_status'] ?? 'publish' ), true ) ) { return false; }
        foreach ( $args['meta_query'] ?? array() as $condition ) {
            $value = get_post_meta( $post->ID, $condition['key'], true );
            $compare = $condition['compare'] ?? '=';
            if ( ( '=' === $compare && (string) $value !== (string) $condition['value'] ) ||
                 ( '<' === $compare && $value >= $condition['value'] ) ||
                 ( '>' === $compare && $value <= $condition['value'] ) ) { return false; }
        }
        return true;
    } );
    return array_slice( array_values( $posts ), 0, $args['numberposts'] ?? 100 );
}
class Dendrila_Calendar {
    const POST_TYPE = 'dendrila_event';
    const START_KEY = '_event_start';
    const END_KEY = '_event_end';
}
class Dendrila_Calendar_OAuth {
    public static function connections() { return $GLOBALS['schedule_connections']; }
    public static function busy_intervals( $from, $to, $ignore_exports = true ) {
        $GLOBALS['schedule_remote_calls'][] = array( $from, $to, $ignore_exports );
        if ( false === $GLOBALS['schedule_remote'] ) { return false; }
        return array_values( array_filter( $GLOBALS['schedule_remote'], static function ( $span ) use ( $from, $to ) {
            return $span[0] < strtotime( $to ) && $span[1] > strtotime( $from );
        } ) );
    }
}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-native.php';
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-schedule.php';

function schedule_assert( $condition, $message ) {
    ++$GLOBALS['schedule_checks'];
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function schedule_same( $expected, $actual, $message ) {
    schedule_assert( $expected === $actual, $message . ' Expected ' . json_encode( $expected ) . ', got ' . json_encode( $actual ) );
}
function schedule_range( $start = '09:00', $end = '12:00' ) { return array( 'start' => $start, 'end' => $end ); }
function schedule_profile( $changes = array(), $id = 11 ) {
    $base = array_merge( Dendrila_Calendar_Schedule::defaults_profile(), array(
        'enabled' => true, 'check_external' => true, 'timezone' => 'Europe/Paris',
        'weekly' => array_fill( 1, 7, array( schedule_range() ) ),
    ) );
    $GLOBALS['schedule_meta'][ $id ][ Dendrila_Calendar_Schedule::PROFILE_META ] = array_replace( $base, $changes );
}
function schedule_rules( $changes = array(), $id = 31 ) {
    $base = array_merge( Dendrila_Calendar_Schedule::defaults_rules(), array( 'enabled' => true, 'notice_minutes' => 0, 'horizon_days' => 0, 'slot_step' => 30 ) );
    $GLOBALS['schedule_meta'][ $id ][ Dendrila_Calendar_Schedule::RULES_META ] = array_replace( $base, $changes );
}
function schedule_fixture() {
    $GLOBALS['schedule_posts'] = array(); $GLOBALS['schedule_meta'] = array();
    $GLOBALS['schedule_remote'] = array(); $GLOBALS['schedule_connections'] = array(); $GLOBALS['schedule_remote_calls'] = array();
    foreach ( array( 11, 12 ) as $id ) {
        $GLOBALS['schedule_posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'dendrila_profile', 'post_status' => 'publish' );
        schedule_profile( array(), $id );
    }
    foreach ( array( 31 => 11, 32 => 11, 33 => 12 ) as $id => $profile ) {
        $GLOBALS['schedule_posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'dendrila_service', 'post_status' => 'publish' );
        $GLOBALS['schedule_meta'][ $id ] = array(
            Dendrila_Calendar_Native::PROFILE_KEY => $profile,
            '_dendrila_duration_minutes' => 30, '_dendrila_buffer_before' => 0, '_dendrila_buffer_after' => 0,
        );
        schedule_rules( array(), $id );
    }
}
function schedule_booking( $id, $local_start, $local_end, $service = 31, $status = 'confirmed', $extra = array() ) {
    $GLOBALS['schedule_posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'dendrila_booking', 'post_status' => 'private' );
    $utc = static function ( $time ) { return ( new DateTimeImmutable( $time, new DateTimeZone( 'Europe/Paris' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ); };
    $GLOBALS['schedule_meta'][ $id ] = array_merge( array(
        Dendrila_Calendar_Native::PROFILE_KEY => 33 === $service ? 12 : 11,
        Dendrila_Calendar_Native::SERVICE_KEY => $service,
        Dendrila_Calendar_Native::START_KEY => $utc( $local_start ), Dendrila_Calendar_Native::END_KEY => $utc( $local_end ),
        Dendrila_Calendar_Native::STATUS_KEY => $status,
        '_dendrila_booking_buffer_before' => 0, '_dendrila_booking_buffer_after' => 0,
    ), $extra );
}
function schedule_slots( $day = '2030-06-05', $now = null, $service = 31, $exclude = 0 ) {
    return Dendrila_Calendar_Schedule::slots( $service, $day, null === $now ? strtotime( '2030-06-01T00:00:00Z' ) : $now, $exclude );
}
function schedule_labels( $slots ) {
    if ( is_wp_error( $slots ) ) { throw new RuntimeException( $slots->get_error_message() ); }
    return array_column( $slots, 'label' );
}

schedule_fixture();
schedule_assert( Dendrila_Calendar_Schedule::valid_day( '2032-02-29' ), 'Leap day must validate.' );
schedule_assert( ! Dendrila_Calendar_Schedule::valid_day( '2030-02-29' ), 'Impossible dates must be rejected.' );
schedule_assert( is_wp_error( Dendrila_Calendar_Schedule::normalize_profile( array( 'weekly' => array( 1 => array( schedule_range( '09:00', '11:00' ), schedule_range( '10:00', '12:00' ) ) ) ) ) ), 'Overlapping opening hours must be rejected.' );
schedule_assert( is_wp_error( Dendrila_Calendar_Schedule::normalize_profile( array( 'timezone' => 'Not/A_Timezone' ) ) ), 'Invalid timezones must be rejected.' );
schedule_assert( is_wp_error( Dendrila_Calendar_Schedule::normalize_rules( array( 'slot_step' => 0 ) ) ), 'Zero slot increments must never reach a loop.' );
schedule_assert( is_wp_error( Dendrila_Calendar_Schedule::normalize_rules( array( 'date_from' => '2030-06-10', 'date_to' => '2030-06-09' ) ) ), 'Reversed date range must be rejected.' );
schedule_same( true, Dendrila_Calendar_Schedule::normalize_profile( array() )['check_external'], 'Missing external-calendar setting keeps protection enabled by default.' );
schedule_same( array( '09:00', '09:30', '10:00', '10:30', '11:00', '11:30' ), schedule_labels( schedule_slots() ), 'Basic hours must include the final appointment ending at closing.' );

schedule_profile( array( 'weekly' => array_fill( 1, 7, array( schedule_range( '09:00', '10:00' ), schedule_range( '10:00', '11:00' ) ) ) ) );
$GLOBALS['schedule_meta'][31]['_dendrila_duration_minutes'] = 90;
schedule_same( array( '09:00', '09:30' ), schedule_labels( schedule_slots() ), 'Touching opening ranges form one continuous interval for longer appointments.' );
schedule_fixture();

schedule_profile( array( 'weekly' => array_fill( 1, 7, array( schedule_range( '09:00', '10:00' ), schedule_range( '14:00', '15:00' ) ) ) ) );
schedule_same( array( '09:00', '09:30', '14:00', '14:30' ), schedule_labels( schedule_slots() ), 'Lunch break must not be offered.' );
schedule_profile( array( 'overrides' => array( '2030-06-05' => array() ) ) );
schedule_same( array(), schedule_slots(), 'An empty date override closes that date.' );
schedule_profile( array( 'overrides' => array( '2030-06-05' => array( schedule_range( '16:00', '17:00' ) ) ) ) );
schedule_same( array( '16:00', '16:30' ), schedule_labels( schedule_slots() ), 'A date override replaces weekly hours.' );
schedule_profile( array( 'enabled' => false ) );
schedule_same( array(), schedule_slots(), 'A disabled profile never opens slots.' );
schedule_fixture(); schedule_rules( array( 'enabled' => false ) );
schedule_same( array(), schedule_slots(), 'A disabled service never opens slots.' );

schedule_fixture();
schedule_profile( array( 'weekly' => array_fill( 1, 7, array( schedule_range( '09:10', '11:00' ) ) ) ) );
schedule_rules( array( 'slot_step' => 20 ) );
schedule_same( array( '09:10', '09:30', '09:50', '10:10', '10:30' ), schedule_labels( schedule_slots() ), 'Start increments are independent from duration and anchored to opening.' );
schedule_fixture(); schedule_rules( array( 'notice_minutes' => 120 ) );
schedule_same( array( '10:00', '10:30', '11:00', '11:30' ), schedule_labels( schedule_slots( '2030-06-05', strtotime( '2030-06-05T06:00:00Z' ) ) ), 'Notice boundary is inclusive and measured before start.' );
schedule_same( array( '10:30', '11:00', '11:30' ), schedule_labels( schedule_slots( '2030-06-05', strtotime( '2030-06-05T06:00:01Z' ) ) ), 'One second before the notice boundary must not be rounded down.' );
schedule_rules( array( 'horizon_days' => 2 ) );
schedule_assert( count( schedule_slots( '2030-06-07', strtotime( '2030-06-05T06:00:00Z' ) ) ) > 0, 'The last horizon day remains bookable.' );
schedule_same( array(), schedule_slots( '2030-06-08', strtotime( '2030-06-05T06:00:00Z' ) ), 'Dates beyond the rolling horizon are closed.' );
schedule_rules( array( 'date_from' => '2030-06-06', 'date_to' => '2030-06-07' ) );
schedule_same( array(), schedule_slots(), 'Fixed booking start must be enforced.' );
schedule_same( array(), schedule_slots( '2030-06-08' ), 'Fixed booking end must be enforced.' );
schedule_assert( count( schedule_slots( '2030-06-07' ) ) > 0, 'The fixed end date is inclusive.' );

schedule_fixture();
schedule_profile( array( 'weekly' => array_fill( 1, 7, array( schedule_range( '01:00', '04:00' ) ) ) ) );
$spring = schedule_slots( '2030-03-31', strtotime( '2030-03-30T00:00:00Z' ) );
schedule_same( array( '01:00', '01:30', '03:00', '03:30' ), schedule_labels( $spring ), 'Spring DST must not invent nonexistent 02:00 times.' );
foreach ( $spring as $one ) { schedule_same( 1800, strtotime( $one['end'] ) - strtotime( $one['start'] ), 'Duration must remain elapsed time across DST.' ); }
$fall = schedule_slots( '2030-10-27', strtotime( '2030-10-26T00:00:00Z' ) );
schedule_same( array( '01:00', '01:30', '02:00', '02:30', '02:00', '02:30', '03:00', '03:30' ), schedule_labels( $fall ), 'Both real occurrences of repeated autumn hours must remain selectable.' );
schedule_same( array( '+02:00', '+01:00' ), array_values( array_column( array_filter( $fall, static function ( $one ) { return '02:00' === $one['label']; } ), 'offset' ) ), 'Repeated hours need their distinct offsets.' );
schedule_same( 8, count( array_unique( array_column( $fall, 'start' ) ) ), 'Repeated wall times must have different UTC identities.' );
$GLOBALS['schedule_meta'][31]['_dendrila_duration_minutes'] = 60;
schedule_profile( array( 'weekly' => array_fill( 1, 7, array( schedule_range( '02:30', '03:30' ) ) ) ) );
$restricted = schedule_slots( '2030-10-27', strtotime( '2030-10-26T00:00:00Z' ) );
schedule_same( 1, count( $restricted ), 'A fall-back appointment cannot cross a closed wall-clock interval.' );
schedule_same( '+01:00', $restricted[0]['offset'], 'Only the second 02:30 appointment fits this opening.' );
schedule_rules( array( 'notice_minutes' => 1490 ) );
$notice_bounds = Dendrila_Calendar_Schedule::date_bounds( 31, strtotime( '2030-10-26T21:30:00Z' ) );
schedule_same( '2030-10-27', $notice_bounds['min_date'], 'Elapsed notice across fall-back must not skip an extra local date.' );

schedule_fixture();
schedule_booking( 100, '2030-06-05 10:00:00', '2030-06-05 10:30:00', 32 );
schedule_same( array( '09:00', '09:30', '10:30', '11:00', '11:30' ), schedule_labels( schedule_slots() ), 'Bookings of another service on the same profile block overlaps; adjacent bookings are allowed.' );
schedule_booking( 101, '2030-06-05 09:00:00', '2030-06-05 12:00:00', 33 );
schedule_same( 5, count( schedule_slots() ), 'Another profile must not occupy this resource.' );
schedule_profile( array( 'max_per_day' => 1 ) );
schedule_same( array(), schedule_slots(), 'Profile quota includes its other services.' );
schedule_profile(); schedule_rules( array( 'max_per_day' => 1 ) );
schedule_same( 5, count( schedule_slots() ), 'Service quota does not count another service.' );
schedule_booking( 102, '2030-06-05 08:00:00', '2030-06-05 08:30:00', 31 );
schedule_same( array(), schedule_slots(), 'Own service quota counts a non-overlapping booking that day.' );

schedule_fixture(); schedule_profile( array( 'max_per_week' => 1 ) );
schedule_booking( 100, '2030-06-02 10:00:00', '2030-06-02 10:30:00', 32 );
schedule_assert( count( schedule_slots() ) > 0, 'A booking in the previous calendar week does not consume this week.' );
schedule_booking( 101, '2030-06-03 10:00:00', '2030-06-03 10:30:00', 32 );
schedule_same( array(), schedule_slots(), 'Weekly quota starts on Monday and includes other dates.' );
schedule_fixture(); schedule_profile( array( 'max_per_month' => 1 ) );
schedule_booking( 100, '2030-05-31 23:00:00', '2030-05-31 23:30:00', 32 );
schedule_assert( count( schedule_slots() ) > 0, 'Previous local month does not consume the current monthly quota.' );
schedule_booking( 101, '2030-06-01 00:30:00', '2030-06-01 01:00:00', 32 );
schedule_same( array(), schedule_slots(), 'Monthly quota follows the profile timezone, including UTC previous-day bookings.' );

schedule_fixture(); schedule_profile( array( 'max_per_day' => 1 ) );
$now = strtotime( '2030-06-05T05:00:00Z' );
schedule_booking( 100, '2030-06-05 10:00:00', '2030-06-05 10:30:00', 31, 'pending_payment', array( '_dendrila_booking_hold_until' => $now + 60 ) );
schedule_same( array(), schedule_slots( '2030-06-05', $now ), 'Active payment holds consume quotas.' );
schedule_assert( count( schedule_slots( '2030-06-05', $now + 60 ) ) > 0, 'Expired holds release capacity at the exact expiry time.' );
schedule_assert( count( schedule_slots( '2030-06-05', $now, 31, 100 ) ) > 0, 'Payment revalidation can exclude its own existing hold.' );
$GLOBALS['schedule_meta'][100][Dendrila_Calendar_Native::STATUS_KEY] = 'payment_on_hold';
schedule_same( array(), schedule_slots( '2030-06-05', $now + 60 ), 'On-hold orders continue to consume capacity until explicitly reconciled.' );
$GLOBALS['schedule_meta'][100][Dendrila_Calendar_Native::STATUS_KEY] = 'cancelled';
schedule_assert( count( schedule_slots( '2030-06-05', $now ) ) > 0, 'Cancelled reservations release capacity.' );

schedule_fixture(); schedule_rules( array( 'slot_step' => 5 ) );
$GLOBALS['schedule_meta'][31]['_dendrila_buffer_before'] = 10;
$GLOBALS['schedule_meta'][31]['_dendrila_buffer_after'] = 5;
schedule_booking( 100, '2030-06-05 10:00:00', '2030-06-05 10:30:00', 32, 'confirmed', array( '_dendrila_booking_buffer_before' => 15, '_dendrila_booking_buffer_after' => 20 ) );
$buffered = schedule_labels( schedule_slots() );
schedule_assert( in_array( '09:10', $buffered, true ) && ! in_array( '09:15', $buffered, true ), 'Candidate after-buffer and existing before-buffer must both fit before an appointment.' );
schedule_assert( ! in_array( '10:55', $buffered, true ) && in_array( '11:00', $buffered, true ), 'Existing after-buffer and candidate before-buffer must both fit after an appointment.' );
$GLOBALS['schedule_meta'][32]['_dendrila_buffer_before'] = 90;
$GLOBALS['schedule_meta'][32]['_dendrila_buffer_after'] = 90;
schedule_same( $buffered, schedule_labels( schedule_slots() ), 'Editing a service must not rewrite existing booking buffer snapshots.' );
schedule_fixture();
$GLOBALS['schedule_meta'][31]['_dendrila_buffer_before'] = 60;
$GLOBALS['schedule_meta'][31]['_dendrila_buffer_after'] = 60;
schedule_same( 6, count( schedule_slots() ), 'Buffers may extend outside weekly opening hours if they remain free.' );
$GLOBALS['schedule_posts'][200] = (object) array( 'ID' => 200, 'post_type' => Dendrila_Calendar::POST_TYPE, 'post_status' => 'publish' );
$GLOBALS['schedule_meta'][200] = array( Dendrila_Calendar::START_KEY => '2030-06-05 06:30:00', Dendrila_Calendar::END_KEY => '2030-06-05 06:45:00' );
schedule_assert( ! in_array( '09:00', schedule_labels( schedule_slots() ), true ), 'A native event outside working hours still blocks an overlapping buffer.' );

schedule_fixture();
$GLOBALS['schedule_connections'] = array( 'mock-provider' );
$GLOBALS['schedule_remote'] = array( array( strtotime( '2030-06-05T08:00:00Z' ), strtotime( '2030-06-05T08:30:00Z' ) ) );
schedule_assert( ! in_array( '10:00', schedule_labels( schedule_slots() ), true ), 'Remote busy intervals must block native bookings.' );
schedule_same( false, $GLOBALS['schedule_remote_calls'][0][2], 'Native bookings must include other source events already exported to providers.' );
$GLOBALS['schedule_remote'] = false;
$unavailable = schedule_slots();
schedule_assert( is_wp_error( $unavailable ) && 'dendrila_provider_unavailable' === $unavailable->get_error_code(), 'Provider errors must fail closed.' );
schedule_profile( array( 'check_external' => false ) );
schedule_same( 6, count( schedule_slots() ), 'An explicit external-calendar opt-out remains usable during an outage.' );

schedule_fixture();
$GLOBALS['schedule_meta'][31]['_dendrila_buffer_before'] = 1440;
$GLOBALS['schedule_meta'][31]['_dendrila_buffer_after'] = 1440;
$GLOBALS['schedule_connections'] = array( 'mock-provider' );
schedule_slots( '2030-10-27', strtotime( '2030-10-26T00:00:00Z' ) );
schedule_assert( count( $GLOBALS['schedule_remote_calls'] ) > 1, 'Large buffers on a 25-hour day must split provider requests.' );
foreach ( $GLOBALS['schedule_remote_calls'] as $call ) {
    schedule_assert( strtotime( $call[1] ) - strtotime( $call[0] ) <= 47 * 3600, 'No busy query exceeds the provider adapter limit.' );
}

echo 'Native schedule: ' . $GLOBALS['schedule_checks'] . " checks passed (hours, overrides, notice, horizon, DST, quotas, holds, buffers, providers).\n";
