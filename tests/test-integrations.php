<?php
/** Fixture-level integration smoke tests. No live WordPress/MySQL server required. */
namespace Bookly\Lib\Entities {
    final class Appointment {}
}
namespace {
    define( 'ABSPATH', '/tmp/' );
    define( 'EASY_APPOINTMENTS_VERSION', '4.0.5' );
    define( 'FLUENT_BOOKING_VERSION', '2.6.0' );
    define( 'AMELIA_VERSION', '2.4.15' );
    function __( $value, $domain = '' ) { return $value; }
    function absint( $value ) { return abs( (int) $value ); }
    function wp_timezone() { return new \DateTimeZone( 'Europe/Paris' ); }
    function get_the_title( $id ) { return 'Recurring team meeting'; }
    function get_edit_post_link( $id, $context = '' ) { return 'https://example.org/wp-admin/post.php?post=' . $id; }
    function admin_url( $path = '' ) { return 'https://example.org/wp-admin/' . $path; }
    function wpbc_is_table_exists( $table = '' ) { return true; }
    function ssa() { return true; }
    class SSA_Appointment_Model {}
    function eo_get_events( $options ) {
        return array( (object) array( 'ID' => 31, 'occurrence_id' => 9 ) );
    }
    function eo_get_the_start( $format, $id, $occurrence ) {
        return new \DateTimeImmutable( '2026-11-24 10:00:00', wp_timezone() );
    }
    function eo_get_the_end( $format, $id, $occurrence ) {
        return new \DateTimeImmutable( '2026-11-24 11:00:00', wp_timezone() );
    }
    class EM_Events {
        public static function get( $options ) {
            return array( new class {
                public $event_id = 44;
                public $event_name = 'Association assembly';
                public function start( $utc ) { return new \DateTimeImmutable( '2026-11-24 12:00:00', new \DateTimeZone( 'UTC' ) ); }
                public function end( $utc ) { return new \DateTimeImmutable( '2026-11-24 13:00:00', new \DateTimeZone( 'UTC' ) ); }
                public function get_edit_url() { return admin_url( 'admin.php?page=events-manager' ); }
            } );
        }
    }
    class Test_Dendrila_DB {
        public $prefix = 'wp_';
        public $last_error = '';
        public $sql = array();
        public function prepare( $sql, $params ) {
            if ( ! is_array( $params ) || ! count( $params ) ) throw new \Exception( 'Missing bound SQL arguments' );
            foreach ( $params as $param ) {
                if ( ! is_string( $param ) ) throw new \Exception( 'Unexpected non-string date bound' );
                $sql = preg_replace( '/%s/', "'" . str_replace( "'", "''", $param ) . "'", $sql, 1 );
            }
            return $sql;
        }
        public function get_results( $sql ) {
            $this->sql[] = $sql;
            if ( ! preg_match( '/^\s*SELECT\b/i', $sql ) || preg_match( '/\b(?:DELETE|UPDATE|INSERT|ALTER)\b/i', $sql ) ) {
                throw new \Exception( 'A provider issued a write query' );
            }
            if ( preg_match( '/\b(?:customer_name|customer_email|email|phone|notes|ip_address|message)\b/i', $sql ) ) {
                throw new \Exception( 'PII requested from a third-party database' );
            }
            $fixtures = array(
                'wp_ea_appointments' => array( 'id' => 55, 'date' => '2026-11-24', 'end_date' => '2026-11-24', 'start' => '14:00:00', 'end' => '14:30:00', 'status' => 'confirmed', 'service_title' => 'Garden care' ),
                'wp_fcal_bookings' => array( 'id' => 56, 'start_time' => '2026-11-24 14:00:00', 'end_time' => '2026-11-24 14:30:00', 'status' => 'scheduled', 'service_title' => 'Web maintenance' ),
                'wp_ssa_appointments' => array( 'id' => 57, 'start_date' => '2026-11-24 15:00:00', 'end_date' => '2026-11-24 15:30:00', 'status' => 'booked', 'service_title' => 'Tutorial' ),
                'wp_bookly_appointments' => array( 'id' => 58, 'start_date' => '2026-11-24 17:00:00', 'end_date' => '2026-11-24 17:45:00', 'extras_duration' => 900, 'custom_service_name' => '', 'service_title' => 'Support' ),
                'wp_amelia_appointments' => array( 'id' => 59, 'begins' => '2026-11-24 18:00:00', 'finishes' => '2026-11-24 19:00:00', 'status' => 'approved', 'title' => 'Appointment' ),
                'wp_amelia_events_periods' => array( 'id' => 60, 'begins' => '2026-11-24 20:00:00', 'finishes' => '2026-11-24 21:00:00', 'status' => 'approved', 'title' => 'Public workshop' ),
                'wp_bookingdates' => array( 'booking_id' => 61, 'booked_day' => '2026-11-24', 'approved' => 1 ),
            );
            foreach ( $fixtures as $needle => $row ) {
                if ( strpos( $sql, $needle ) !== false ) {
                    return array( (object) array_merge( $row, array( 'customer_name' => 'PRIVATE NAME', 'email' => 'private@example.com' ) ) );
                }
            }
            throw new \Exception( 'Unknown table in fixture: ' . $sql );
        }
    }
    $wpdb = new Test_Dendrila_DB();
    require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-integrations.php';
    $sources = \Dendrila_Calendar_Integrations::available();
    if ( count( $sources ) !== 8 ) throw new \Exception( 'Expected eight optional integrations, got ' . count( $sources ) );
    $events = array();
    $range_start = '2026-11-24 00:00:00';
    $range_end = '2026-11-26 00:00:00';
    foreach ( $sources as $source => $label ) {
        $items = \Dendrila_Calendar_Integrations::events( $source, $range_start, $range_end );
        if ( ! count( $items ) ) throw new \Exception( 'No events for source ' . $source );
        foreach ( $items as $event ) {
            $events[$event['id']] = $event;
            if ( ! preg_match( '/^2026-11-2[34]T\d{2}:\d{2}:\d{2}Z$/', $event['start'] ) ) {
                throw new \Exception( 'UTC date mismatch for ' . $source . ': ' . $event['start'] );
            }
        }
    }
    $expected = array(
        'eo-31-9' => '2026-11-24T09:00:00Z',
        'em-44' => '2026-11-24T12:00:00Z',
        'ea-55' => '2026-11-24T13:00:00Z',
        'fb-56' => '2026-11-24T14:00:00Z',
        'ssa-57' => '2026-11-24T15:00:00Z',
        'bookly-58' => '2026-11-24T16:00:00Z',
        'amelia-a-59' => '2026-11-24T18:00:00Z',
        'amelia-e-60' => '2026-11-24T20:00:00Z',
        'wpbc-61-2026-11-24' => '2026-11-23T23:00:00Z',
    );
    foreach ( $expected as $id => $start ) {
        if ( ! isset( $events[$id] ) || $events[$id]['start'] !== $start ) {
            throw new \Exception( 'Time conversion incorrect: ' . $id . ' ' . ( $events[$id]['start'] ?? '(missing)' ) );
        }
    }
    if ( strpos( json_encode( $events ), 'PRIVATE NAME' ) !== false || strpos( json_encode( $events ), 'private@example.com' ) !== false ) {
        throw new \Exception( 'Private data exposed by a provider' );
    }
    // One-day allocations in Booking Calendar must span exactly one local calendar day, even across DST.
    if ( $events['wpbc-61-2026-11-24']['end'] !== '2026-11-24T23:00:00Z' ) {
        throw new \Exception( 'All-day booking period failed' );
    }
    if ( \Dendrila_Calendar_Integrations::events( 'unknown', $range_start, $range_end ) ) {
        throw new \Exception( 'Unknown provider accepted' );
    }
    // All-day reservations must span 23/25 actual hours over Paris DST transitions.
    $method = new \ReflectionMethod( \Dendrila_Calendar_Integrations::class, 'site_date' );
    foreach ( array( array('2027-03-28', '2027-03-29', 23), array('2027-10-31', '2027-11-01', 25) ) as $span ) {
        $day_start = $method->invoke( null, $span[0] . ' 00:00:00' );
        $day_end = $method->invoke( null, $span[1] . ' 00:00:00' );
        if ( ( $day_end->getTimestamp() - $day_start->getTimestamp() ) !== $span[2] * 3600 ) {
            throw new \Exception( 'DST all-day conversion incorrect on ' . $span[0] );
        }
    }
    echo 'Eight integration providers passed, ' . count( $events ) . ' fixture events (no PII/no writes)'.PHP_EOL;
}
