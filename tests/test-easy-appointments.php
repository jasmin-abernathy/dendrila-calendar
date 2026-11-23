<?php
/** Regression test with the shape of a real Easy Appointments 4.0.5 database record. */
error_reporting( E_ALL );
define( 'ABSPATH', '/' );
define( 'EASY_APPOINTMENTS_VERSION', '4.0.5' );
function __( $text ) { return $text; }
function wp_timezone() { return new DateTimeZone( 'Europe/Paris' ); }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function absint( $n ) { return abs( (int) $n ); }
class Fake_EA_WPDB {
    public $prefix = 'wp_';
    public $last_error = '';
    public $last_sql = '';
    public $rows = array();
    public function prepare( $sql, $params ) {
        if ( count( $params ) !== 4 || $params[0] !== 'wp_ea_appointments' || $params[1] !== 'wp_ea_services' ) { throw new Exception( 'Expected two escaped table names and two date bounds' ); }
        if ( substr_count( $sql, '%i' ) !== 2 || substr_count( $sql, '%s' ) !== 2 ) { throw new Exception( 'Missing table/date placeholders in EA query' ); }
        $this->last_sql = $sql;
        return $sql;
    }
    public function get_results( $sql ) {
        if ( strpos( $sql, "NULLIF(a.end_date, '0000-00-00')" ) === false ) {
            throw new Exception( 'Missing zero end date safeguard in SQL query.' );
        }
        return $this->rows;
    }
}
$wpdb = new Fake_EA_WPDB();
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-detector.php';
    require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-integrations.php';
$wpdb->rows = array( (object) array(
    'id' => '1', 'date' => '2026-11-27', 'start' => '13:30:00',
    'end' => '14:00:00', 'end_date' => '2026-11-27',
    'status' => 'pending', 'service_title' => 'Prestation test',
) );
$events = Dendrila_Calendar_Integrations::events( 'easy_appointments', '2026-10-25 23:00:00', '2026-12-06 23:00:00' );
if ( count( $events ) !== 1 || $events[0]['title'] !== 'Prestation test' ||
    $events[0]['start'] !== '2026-11-27T12:30:00Z' ||
    $events[0]['end'] !== '2026-11-27T13:00:00Z' ||
    $events[0]['status'] !== 'pending' ) { throw new Exception( 'EA pending booking must appear in the November calendar.' ); }
$wpdb->rows[0]->end_date = '0000-00-00';
$events = Dendrila_Calendar_Integrations::events( 'easy_appointments', '2026-10-25 23:00:00', '2026-12-06 23:00:00' );
if ( count( $events ) !== 1 ) { throw new Exception( 'Legacy zero end date must fall back to booking day.' ); }
$wpdb->rows[0]->date = '2026-04-15';
$wpdb->rows[0]->end_date = '2026-04-15';
$events = Dendrila_Calendar_Integrations::events( 'easy_appointments', '2026-10-25 23:00:00', '2026-12-06 23:00:00' );
if ( $events ) { throw new Exception( 'Events outside the requested range must not leak.' ); }
echo "Easy Appointments 4.0.5 live-schema fixtures: OK\n";
