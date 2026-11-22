<?php
/** Verify support email is server-side only, sent once per incompatible plugin/version. */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
$GLOBALS['settings'] = array();
$GLOBALS['mail'] = array();
$GLOBALS['force_failure'] = false;
function get_option( $name, $default = null ) { return $GLOBALS['settings'][ $name ] ?? $default; }
function update_option( $name, $value, $autoload = false ) { $GLOBALS['settings'][ $name ] = $value; return true; }
function wp_mail( $recipient, $subject, $body ) {
    $GLOBALS['mail'][] = compact( 'recipient', 'subject', 'body' );
    return ! $GLOBALS['force_failure'];
}
function sanitize_text_field( $string ) { return trim( strip_tags( (string) $string ) ); }
function home_url( $path = '/' ) { return 'https://example.test/'; }
function get_bloginfo( $param ) { return '7.1'; }
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-setup.php';
$unavailable = array(
    'label' => 'Mystery Bookings',
    'plugin_file' => 'mystery-bookings/loader.php',
    'version' => '1.2.3',
    'source_id' => 'unknown_test',
    'state' => 'unsupported',
    'active' => true,
    'reason' => 'No audited adapter is available.',
);
$inactive = $unavailable;
$inactive['active'] = false;
$inactive['state'] = 'inactive';
$first = Dendrila_Calendar_Setup::report_unusable( array( $unavailable, $inactive ) );
if ( 1 !== $first['sent'] || 0 !== $first['failed'] || 1 !== count( $GLOBALS['mail'] ) ) {
    throw new RuntimeException( 'A single compatibility request should be sent for an active unsupported plugin.' );
}
$message = $GLOBALS['mail'][0];
if ( 'jasmin@lepotager.org' !== $message['recipient'] ||
    false === strpos( $message['body'], 'mystery-bookings/loader.php' ) ||
    false === strpos( $message['body'], '1.2.3' ) ||
    false === strpos( $message['body'], 'No audited adapter is available.' ) ||
    false === strpos( $message['body'], 'https://example.test/' ) ||
    false !== strpos( $message['body'], 'customer@example.net' ) ) {
    throw new RuntimeException( 'Support request must include technical details but not booking/customer data.' );
}
Dendrila_Calendar_Setup::report_unusable( array( $unavailable ) );
if ( 1 !== count( $GLOBALS['mail'] ) ) {
    throw new RuntimeException( 'Repeated wizard submissions must not resend the same compatibility issue.' );
}
$upgrade = $unavailable;
$upgrade['version'] = '1.2.4';
Dendrila_Calendar_Setup::report_unusable( array( $upgrade ) );
if ( 2 !== count( $GLOBALS['mail'] ) ) {
    throw new RuntimeException( 'New plugin versions must open a new compatibility request.' );
}
$GLOBALS['force_failure'] = true;
$failed = $unavailable;
$failed['version'] = '2.0.0';
$result = Dendrila_Calendar_Setup::report_unusable( array( $failed ) );
if ( 1 !== $result['failed'] || 3 !== count( $GLOBALS['mail'] ) ) {
    throw new RuntimeException( 'Failed mail submission must not be reported as successful.' );
}
Dendrila_Calendar_Setup::report_unusable( array( $failed ) );
if ( 3 !== count( $GLOBALS['mail'] ) ) {
    throw new RuntimeException( 'Failed mail attempts must be throttled for a day.' );
}
// Inspect server-rendered PHP source: no support mailbox should appear in frontend translation strings.
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-dendrila-calendar-setup.php' );
if ( substr_count( $source, 'jasmin@lepotager.org' ) !== 1 ) {
    throw new RuntimeException( 'Support address must be confined to server-side submission code.' );
}
echo "Connector support requests: detail, deduplication, retry throttling and privacy passed\n";
