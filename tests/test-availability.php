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


// The public Easy Appointments picker must mark remote-busy slots unavailable
// before selection, while keeping Google/Outlook event titles private.
$slots_response = wp_json_encode( array( 'calendar_slots' => array(
    array( 'value' => '11:30', 'show' => '11:30', 'count' => 1 ),
    array( 'value' => '15:20', 'show' => '15:20', 'count' => 1 ),
    array( 'value' => '17:10', 'show' => '17:10', 'count' => 1 ),
), 'connection_details' => array( 'repeat_booking' => 0 ) ) );
$ctx = array(
    'date' => '2026-11-24', 'duration' => 30, 'before' => 0, 'after' => 0,
    'busy' => Dendrila_Calendar_OAuth::busy_intervals( '2026-11-24T00:00:00Z', '2026-11-25T00:00:00Z' )
);
$slots = json_decode( Dendrila_Calendar_Availability::filter_public_slots_json( $slots_response, $ctx ), true );
if ( $slots['calendar_slots'][0]['count'] !== 0 ||
     $slots['calendar_slots'][1]['count'] !== 0 ||
     $slots['calendar_slots'][2]['count'] !== 1 ||
     ! isset( $slots['connection_details'] ) ) {
    throw new RuntimeException( 'Public picker did not disable Google/Outlook busy slots while preserving free slots and metadata.' );
}
$edge_ctx = array( 'date' => '2026-11-24', 'duration' => 30, 'before' => 15, 'after' => 15,
    'busy' => array( array( strtotime( '2026-11-24T11:00:00Z' ), strtotime( '2026-11-24T11:30:00Z' ) ) ) );
$edges = json_decode( Dendrila_Calendar_Availability::filter_public_slots_json( wp_json_encode( array(
    'calendar_slots' => array(
        array( 'value' => '11:45', 'count' => 1 ), // 10:45 UTC with 15-min buffer; touches but does not overlap.
        array( 'value' => '12:00', 'count' => 1 ), // 11:00 UTC, busy.
    ),
) ), $edge_ctx ), true );
if ( $edges['calendar_slots'][0]['count'] !== 0 || $edges['calendar_slots'][1]['count'] !== 0 ) {
    throw new RuntimeException( 'Service buffers must respect occupied boundaries.' );
}
$down = json_decode( Dendrila_Calendar_Availability::filter_public_slots_json( $slots_response,
    array( 'date'=>'2026-11-24', 'duration'=>30, 'busy'=>false ) ), true );
if ( array_sum( array_column( $down['calendar_slots'], 'count' ) ) !== 0 ) {
    throw new RuntimeException( 'Provider outage must disable all public slots, not offer false availability.' );
}
if ( Dendrila_Calendar_Availability::filter_public_slots_json( '{"err":true}', $ctx ) !== '{"err":true}' ) {
    throw new RuntimeException( 'Public JSON adapter must preserve errors and unrelated plugin responses.' );
}
update_option( Dendrila_Calendar_Availability::OPTION, '1', false );
$final = Dendrila_Calendar_Availability::check_final_booking(
    array( 'status'=>true, 'message'=>'' ),
    array( 'status'=>'reservation', 'date'=>'2026-11-24', 'start'=>'11:30', 'end'=>'12:00' ),
    array()
);
if ( ! is_array( $final ) || ! empty( $final['status'] ) ) {
    throw new RuntimeException( 'Final appointment confirmation could bypass the remote calendar guard.' );
}
update_option( Dendrila_Calendar_Availability::OPTION, '0', false );
echo "Public Easy Appointments picker and final reservation safeguards passed\n";
