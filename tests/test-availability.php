<?php
/** Reservation boundary tests for Google and Microsoft personal calendars. */
require __DIR__ . '/test-oauth-flow.php';
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-availability.php';

$bounds = Dendrila_Calendar_Availability::parse_slot( array(
    'date' => '2026-11-24', 'start' => '11:30', 'end' => '12:00'
) );
if ( ! $bounds || gmdate( 'Y-m-d H:i', $bounds[0] ) !== '2026-11-24 10:30' ) {
    throw new RuntimeException( 'Local appointment times were not converted to UTC.' );
}
$busy = Dendrila_Calendar_OAuth::busy_intervals( '2026-11-24T09:00:00Z', '2026-11-24T18:00:00Z' );
if ( ! is_array( $busy ) || count( $busy ) !== 2 ) {
    throw new RuntimeException( 'Expected one occupied interval per provider; free time must be excluded.' );
}
update_option( Dendrila_Calendar_Availability::OPTION, '1', false );
$overlap = Dendrila_Calendar_Availability::check_booking(
    array( 'status' => true, 'message' => '' ),
    array( 'date' => '2026-11-24', 'start' => '11:30', 'end' => '12:00' )
);
if ( ! is_array( $overlap ) || ! empty( $overlap['status'] ) ) {
    throw new RuntimeException( 'Easy Appointments overlap was incorrectly allowed.' );
}
$outlook = Dendrila_Calendar_Availability::check_booking(
    array( 'status' => true, 'message' => '' ),
    array( 'date' => '2026-11-24', 'start' => '15:20', 'end' => '15:50' )
);
if ( ! empty( $outlook['status'] ) ) {
    throw new RuntimeException( 'Outlook personal account overlap was incorrectly allowed.' );
}
$free = Dendrila_Calendar_Availability::check_booking(
    array( 'status' => true, 'message' => '' ),
    array( 'date' => '2026-11-24', 'start' => '16:10', 'end' => '16:40' )
);
if ( empty( $free['status'] ) ) {
    throw new RuntimeException( 'Unoccupied slot was rejected.' );
}
$GLOBALS['busy_should_fail'] = true;
$failure = Dendrila_Calendar_Availability::check_booking(
    array( 'status' => true, 'message' => '' ),
    array( 'date' => '2026-11-24', 'start' => '16:10', 'end' => '16:40' )
);
if ( ! empty( $failure['status'] ) ) {
    throw new RuntimeException( 'Provider failure must not be interpreted as free.' );
}
$GLOBALS['busy_should_fail'] = false;
update_option( Dendrila_Calendar_Availability::OPTION, '0', false );
$off = Dendrila_Calendar_Availability::check_booking(
    array( 'status' => true, 'message' => '' ),
    array( 'date' => '2026-11-24', 'start' => '11:30', 'end' => '12:00' )
);
if ( empty( $off['status'] ) ) {
    throw new RuntimeException( 'Disabled availability protection changed third-party booking behavior.' );
}
echo "Easy Appointments Google/Outlook availability safeguards passed\n";
