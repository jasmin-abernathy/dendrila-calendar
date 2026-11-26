<?php
/** Verify detection of active, inactive, unsupported and unrecognized booking plugins. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['active_plugins'] = array(
    'easy-appointments/main.php',
    'oryx-bookings/oryx-bookings.php',
    'ics-calendar/ics-calendar.php',
    'other-appointments/plugin.php',
);
function get_option( $name, $default = null ) {
    return 'active_plugins' === $name ? $GLOBALS['active_plugins'] : $default;
}
function get_site_option( $key, $default = null ) { return $default; }
function get_plugins() {
    return array(
        'easy-appointments/main.php' => array( 'Name' => 'Easy Appointments', 'Version' => '4.0.5' ),
        'oryx-bookings/oryx-bookings.php' => array( 'Name' => 'Oryx Bookings', 'Version' => '1.1.3' ),
        'ics-calendar/ics-calendar.php' => array( 'Name' => 'ICS Calendar', 'Version' => '12.3.0' ),
        'event-organiser/event-organiser.php' => array( 'Name' => 'Event Organiser', 'Version' => '3.12.12' ),
        'other-appointments/plugin.php' => array( 'Name' => 'Other Appointments', 'Version' => '4.1.2', 'Description' => 'Appointment bookings' ),
    );
}
function sanitize_text_field( $text ) { return strip_tags( $text ); }
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-detector.php';
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-setup.php';
$found = Dendrila_Calendar_Detector::discover();
if ( ! isset( $found['easy_appointments'] ) || ! $found['easy_appointments']['ready'] || '4.0.5' !== $found['easy_appointments']['version'] ) {
    throw new RuntimeException( 'Active Easy Appointments must be auto-detected and preselected even without a PHP version constant.' );
}
if ( 'unavailable' !== $found['oryx']['state'] || false !== $found['oryx']['ready'] ) {
    throw new RuntimeException( 'Missing required Oryx API should be detected as unavailable.' );
}
if ( 'unsupported' !== $found['ics_calendar']['state'] || '12.3.0' !== $found['ics_calendar']['version'] ) {
    throw new RuntimeException( 'ICS Calendar must be detected but never advertised as supported.' );
}
if ( 'inactive' !== $found['event_organiser']['state'] || $found['event_organiser']['active'] ) {
    throw new RuntimeException( 'Installed but inactive plugins should not cause compatibility alerts.' );
}
$unknown = 'unknown_' . substr( hash( 'sha256', 'other-appointments/plugin.php' ), 0, 12 );
if ( 'unsupported' !== $found[ $unknown ]['state'] ) {
    throw new RuntimeException( 'Unrecognized booking provider must be reported without guessing table schemas.' );
}
$ready = Dendrila_Calendar_Setup::ready_sources( $found );
if ( ! in_array( 'easy_appointments', $ready, true ) || in_array( 'ics_calendar', $ready, true ) || in_array( 'oryx', $ready, true ) ) {
    throw new RuntimeException( 'Setup must preselect only sources with ready adapters.' );
}
echo "WordPress plugin detection: active, unavailable, inactive, unsupported and unknown sources passed\n";
