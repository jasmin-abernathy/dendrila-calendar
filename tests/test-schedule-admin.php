<?php
/** Schedule editor contracts, using the actual schedule normalizers and no site or provider. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['schedule_test_meta'] = array();
$GLOBALS['schedule_test_notices'] = array();
$GLOBALS['schedule_test_user'] = 7;
$GLOBALS['schedule_test_admin'] = true;
$GLOBALS['schedule_test_revision'] = false;
$GLOBALS['schedule_test_autosave'] = false;
$GLOBALS['schedule_test_screen'] = (object) array( 'post_type' => 'dendrila_profile' );
$GLOBALS['schedule_test_types'] = array( 11 => 'dendrila_profile', 31 => 'dendrila_service', 99 => 'post' );
$GLOBALS['schedule_test_writes'] = 0;

function __( $text, $domain = '' ) { return $text; }
function esc_html__( $text, $domain = '' ) { return esc_html( $text ); }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $text ) { return esc_attr( $text ); }
function absint( $value ) { return abs( (int) $value ); }
function current_user_can( $cap ) { return 'manage_options' === $cap && $GLOBALS['schedule_test_admin']; }
function get_current_user_id() { return $GLOBALS['schedule_test_user']; }
function get_current_screen() { return $GLOBALS['schedule_test_screen']; }
function get_post_type( $id ) { return $GLOBALS['schedule_test_types'][ $id ] ?? false; }
function wp_is_post_revision( $id ) { return $GLOBALS['schedule_test_revision']; }
function wp_is_post_autosave( $id ) { return $GLOBALS['schedule_test_autosave']; }
function wp_timezone_string() { return 'Europe/Paris'; }
function get_user_locale() { return 'fr_FR'; }
function wp_timezone_choice( $selected, $locale ) {
    return '<option value="Europe/Paris"' . ( 'Europe/Paris' === $selected ? ' selected' : '' ) . '>Europe/Paris</option><option value="UTC"' . ( 'UTC' === $selected ? ' selected' : '' ) . '>UTC</option>';
}
function get_post_meta( $id, $key, $single ) { return $GLOBALS['schedule_test_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { ++$GLOBALS['schedule_test_writes']; $GLOBALS['schedule_test_meta'][ $id ][ $key ] = $value; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['schedule_test_notices'][ $key ] = $value; }
function get_transient( $key ) { return $GLOBALS['schedule_test_notices'][ $key ] ?? false; }
function delete_transient( $key ) { unset( $GLOBALS['schedule_test_notices'][ $key ] ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $text ) { return strip_tags( $text ); }
function wp_verify_nonce( $nonce, $action ) { return 'nonce:' . $action === $nonce; }
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( 'nonce:' . $action ) . '">'; }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function get_edit_post_link( $id, $context ) { return admin_url( 'post.php?post=' . (int) $id . '&action=edit' ); }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/dendrila-calendar/'; }
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['schedule_test_hooks'][ $hook ][] = $callback; }
function add_meta_box( $id, $title, $callback, $type, $context, $priority ) { $GLOBALS['schedule_test_boxes'][ $type ] = $callback; }
function wp_enqueue_style( $name, $url, $deps, $version ) { $GLOBALS['schedule_test_styles'][ $name ] = compact( 'url', 'version' ); }
function wp_enqueue_script( $name, $url, $deps, $version, $footer ) { $GLOBALS['schedule_test_scripts'][ $name ] = compact( 'url', 'version', 'deps' ); }
function wp_localize_script( $name, $object, $data ) { $GLOBALS['schedule_test_messages'] = $data; }
class WP_Error {
    private $code;
    private $message;
    public function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }

require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-schedule.php';
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-schedule-admin.php';

function schedule_profile_payload() {
    return array(
        'enabled' => '1', 'timezone' => 'Europe/Paris', 'closed' => array( 1 => '0', 2 => '1', 3 => '1', 4 => '1', 5 => '1', 6 => '1', 7 => '1' ),
        'weekly' => array( 1 => array( array( 'start' => '09:00', 'end' => '12:00' ), array( 'start' => '14:00', 'end' => '00:00' ) ) ),
        'exceptions' => array(
            array( 'date' => '2026-12-25', 'closed' => '1' ),
            array( 'date' => '2026-12-26', 'closed' => '0', 'slots' => array( array( 'start' => '10:00', 'end' => '13:00' ) ) ),
        ),
        'max_per_day' => '2', 'max_per_week' => '5', 'max_per_month' => '20', 'check_external' => '1',
    );
}
function schedule_rules_payload() {
    return array( 'enabled' => '1', 'notice_minutes' => '120', 'horizon_days' => '60', 'slot_step' => '15', 'date_from' => '', 'date_to' => '', 'max_per_day' => '0', 'max_per_week' => '0', 'max_per_month' => '0' );
}
function schedule_post( $part, $payload ) {
    $id = 'profile' === $part ? 11 : 31;
    $_POST = array( 'dendrila_calendar_schedule_' . $part . '_nonce' => 'nonce:dendrila_calendar_schedule_' . $part . '_' . $id, 'dendrila_schedule_' . $part => $payload );
}
function schedule_capture( $callback ) { ob_start(); $callback(); return ob_get_clean(); }
function schedule_expect( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
    ++$GLOBALS['schedule_test_checks'];
}

$GLOBALS['schedule_test_checks'] = 0;
schedule_post( 'profile', schedule_profile_payload() );
Dendrila_Calendar_Schedule_Admin::save_profile( 11 );
schedule_post( 'rules', schedule_rules_payload() );
Dendrila_Calendar_Schedule_Admin::save_rules( 31 );
$GLOBALS['schedule_test_meta'][31]['_dendrila_booking_profile_id'] = 11;
$GLOBALS['schedule_test_meta'][31]['_dendrila_price_cents'] = 5000;
$GLOBALS['schedule_test_meta'][31]['_dendrila_woo_product_id'] = 0;

// Optional modes let the browser test render real PHP output and submit actual form data.
if ( in_array( '--fixtures', $argv, true ) ) {
    Dendrila_Calendar_Schedule_Admin::enqueue( 'post.php' );
    echo json_encode( array(
        'profile' => schedule_capture( static function () { Dendrila_Calendar_Schedule_Admin::render_profile( (object) array( 'ID' => 11 ) ); } ),
        'rules' => schedule_capture( static function () { Dendrila_Calendar_Schedule_Admin::render_rules( (object) array( 'ID' => 31 ) ); } ),
        'messages' => $GLOBALS['schedule_test_messages'],
    ) );
    exit;
}
if ( in_array( '--submit-profile', $argv, true ) || in_array( '--submit-rules', $argv, true ) ) {
    parse_str( file_get_contents( 'php://stdin' ), $_POST );
    $profile = in_array( '--submit-profile', $argv, true );
    if ( $profile ) { Dendrila_Calendar_Schedule_Admin::save_profile( 11 ); } else { Dendrila_Calendar_Schedule_Admin::save_rules( 31 ); }
    echo json_encode( array(
        'settings' => $profile ? Dendrila_Calendar_Schedule::profile( 11 ) : Dendrila_Calendar_Schedule::rules( 31 ),
        'errors' => array_values( $GLOBALS['schedule_test_notices'] ),
    ) );
    exit;
}

Dendrila_Calendar_Schedule_Admin::boot();
Dendrila_Calendar_Schedule_Admin::meta_boxes();
schedule_expect( isset( $GLOBALS['schedule_test_hooks']['save_post_dendrila_profile'], $GLOBALS['schedule_test_hooks']['save_post_dendrila_service'] ), 'Both post types need separate save hooks.' );
schedule_expect( isset( $GLOBALS['schedule_test_boxes']['dendrila_profile'], $GLOBALS['schedule_test_boxes']['dendrila_service'] ), 'Both editors need their own metabox.' );
$profile = Dendrila_Calendar_Schedule::profile( 11 );
schedule_expect( '24:00' === $profile['weekly'][1][1]['end'] && '09:00' === $profile['weekly'][1][0]['start'], 'End-of-day midnight must be normalized without changing start times.' );
schedule_expect( array() === $profile['weekly'][2] && array() === $profile['overrides']['2026-12-25'], 'Closed days and closed exceptions must remain explicit.' );
schedule_expect( 2 === $profile['max_per_day'] && $profile['check_external'], 'Shared limits and external checks must pass through the real normalizer.' );

$saved = $GLOBALS['schedule_test_meta'];
$writes = $GLOBALS['schedule_test_writes'];
$_POST = array();
Dendrila_Calendar_Schedule_Admin::save_profile( 11 );
schedule_post( 'profile', schedule_profile_payload() );
$_POST['dendrila_calendar_schedule_profile_nonce'] = 'invalid';
Dendrila_Calendar_Schedule_Admin::save_profile( 11 );
schedule_post( 'profile', schedule_profile_payload() );
Dendrila_Calendar_Schedule_Admin::save_profile( 31 );
$GLOBALS['schedule_test_admin'] = false;
Dendrila_Calendar_Schedule_Admin::save_profile( 11 );
$GLOBALS['schedule_test_admin'] = true;
$GLOBALS['schedule_test_revision'] = true;
Dendrila_Calendar_Schedule_Admin::save_profile( 11 );
$GLOBALS['schedule_test_revision'] = false;
$GLOBALS['schedule_test_autosave'] = true;
Dendrila_Calendar_Schedule_Admin::save_profile( 11 );
$GLOBALS['schedule_test_autosave'] = false;
schedule_expect( $saved === $GLOBALS['schedule_test_meta'] && $writes === $GLOBALS['schedule_test_writes'], 'Missing/invalid nonce, wrong post type, low privilege, revisions and autosaves must never write.' );

$invalid_profiles = array();
$bad = schedule_profile_payload(); $bad['weekly'][1][] = array( 'start' => '11:30', 'end' => '13:00' ); $invalid_profiles[] = $bad;
$bad = schedule_profile_payload(); $bad['exceptions'][] = array( 'date' => '2026-12-25', 'closed' => '1' ); $invalid_profiles[] = $bad;
$bad = schedule_profile_payload(); $bad['timezone'] = array( 'Europe/Paris' ); $invalid_profiles[] = $bad;
$bad = schedule_profile_payload(); $bad['weekly'][1] = array(); $invalid_profiles[] = $bad;
$bad = schedule_profile_payload(); $bad['weekly'][2] = array( array( 'start' => '99:00', 'end' => '12:00' ) ); $invalid_profiles[] = $bad;
$bad = schedule_profile_payload(); $bad['closed'][1] = array( '1' ); $invalid_profiles[] = $bad;
$bad = schedule_profile_payload(); $bad['exceptions'][1]['date'] = '2026-02-30'; $invalid_profiles[] = $bad;
$bad = schedule_profile_payload(); unset( $bad['max_per_month'] ); $invalid_profiles[] = $bad;
foreach ( $invalid_profiles as $bad ) {
    schedule_post( 'profile', $bad );
    Dendrila_Calendar_Schedule_Admin::save_profile( 11 );
    schedule_expect( $saved === $GLOBALS['schedule_test_meta'], 'Invalid or partial profile form replaced saved settings.' );
    schedule_expect( false !== get_transient( 'dendrila_schedule_error_7_11' ), 'Invalid profile input must produce an administrator notice.' );
}

$_GET['post'] = 11;
$GLOBALS['schedule_test_user'] = 8;
schedule_expect( '' === schedule_capture( array( 'Dendrila_Calendar_Schedule_Admin', 'admin_notices' ) ), 'Error notices must not leak between administrators.' );
$GLOBALS['schedule_test_user'] = 7;
$_GET['post'] = 31;
schedule_expect( '' === schedule_capture( array( 'Dendrila_Calendar_Schedule_Admin', 'admin_notices' ) ), 'Error notices must not leak to another post.' );
$_GET['post'] = 11;
set_transient( 'dendrila_schedule_error_7_11', '<script>unsafe()</script>', 600 );
$notice = schedule_capture( array( 'Dendrila_Calendar_Schedule_Admin', 'admin_notices' ) );
schedule_expect( false !== strpos( $notice, 'role="alert"' ) && false === strpos( $notice, '<script>' ) && false !== strpos( $notice, '&lt;script&gt;' ), 'Save errors must be accessible and escaped.' );
schedule_expect( false === get_transient( 'dendrila_schedule_error_7_11' ), 'A displayed error should be consumed once.' );

$blank = schedule_profile_payload();
$blank['weekly'][1][] = array( 'start' => '', 'end' => '' );
$blank['exceptions'][] = array( 'date' => '', 'closed' => '1', 'slots' => array( array( 'start' => '', 'end' => '' ) ) );
schedule_post( 'profile', $blank );
Dendrila_Calendar_Schedule_Admin::save_profile( 11 );
schedule_expect( $profile === Dendrila_Calendar_Schedule::profile( 11 ), 'Empty progressive-enhancement rows must not erase or invent hours.' );

$rules = Dendrila_Calendar_Schedule::rules( 31 );
foreach ( array( array( 'slot_step', '1' ), array( 'notice_minutes', '-1' ), array( 'date_to', '2026-02-30' ), array( 'max_per_day', array( '1' ) ) ) as $change ) {
    $bad = schedule_rules_payload(); $bad[ $change[0] ] = $change[1];
    schedule_post( 'rules', $bad );
    Dendrila_Calendar_Schedule_Admin::save_rules( 31 );
    schedule_expect( $rules === Dendrila_Calendar_Schedule::rules( 31 ), 'Invalid service policy must keep previous rules.' );
}
$unlimited = schedule_rules_payload(); $unlimited['horizon_days'] = '0'; $unlimited['date_from'] = '2026-12-01'; $unlimited['date_to'] = '2026-12-31';
schedule_post( 'rules', $unlimited );
Dendrila_Calendar_Schedule_Admin::save_rules( 31 );
schedule_expect( 0 === Dendrila_Calendar_Schedule::rules(31)['horizon_days'] && false === get_transient( 'dendrila_schedule_error_7_31' ), 'Valid rules, including an unlimited rolling horizon, must save and clear stale errors.' );

$GLOBALS['schedule_test_screen']->post_type = 'post';
Dendrila_Calendar_Schedule_Admin::enqueue( 'post.php' );
$GLOBALS['schedule_test_screen']->post_type = 'dendrila_profile';
Dendrila_Calendar_Schedule_Admin::enqueue( 'edit.php' );
schedule_expect( empty( $GLOBALS['schedule_test_scripts'] ), 'Schedule assets must not load on unrelated editors or list pages.' );
Dendrila_Calendar_Schedule_Admin::enqueue( 'post-new.php' );
schedule_expect( isset( $GLOBALS['schedule_test_scripts']['dendrila-calendar-schedule-admin'] ) && 1 === count( $GLOBALS['schedule_test_scripts'] ), 'Profile editor should load only schedule-specific JavaScript.' );
schedule_expect( (string) filemtime( dirname( __DIR__ ) . '/assets/calendar-schedule-admin.js' ) === $GLOBALS['schedule_test_scripts']['dendrila-calendar-schedule-admin']['version'], 'Schedule assets need file-based cache invalidation.' );
$markup = schedule_capture( static function () { Dendrila_Calendar_Schedule_Admin::render_rules( (object) array( 'ID' => 31 ) ); } );
schedule_expect( false !== strpos( html_entity_decode( $markup, ENT_QUOTES, 'UTF-8' ), '[dendrila_booking service="31"]' ) && false !== strpos( $markup, '[dendrila_booking]' ), 'The service editor must expose both booking shortcodes.' );
schedule_expect( false === strpos( $markup, 'name="dendrila_service_duration"' ) && false === strpos( $markup, 'name="dendrila_service_before"' ) && false === strpos( $markup, 'name="dendrila_service_price"' ), 'Do not duplicate duration, buffers or prices in booking rules.' );
schedule_expect( false !== strpos( $markup, 'Edit profile availability' ) && false !== strpos( $markup, 'no WooCommerce product' ), 'Services need a profile link and a clear paid-service configuration notice.' );
$product_markup = schedule_capture( static function () { Dendrila_Calendar_Schedule_Admin::render_rules( (object) array( 'ID' => 0 ), false ); } );
schedule_expect( false === strpos( $product_markup, '[dendrila_booking' ) && false !== strpos( $product_markup, 'name="dendrila_schedule_rules[notice_minutes]"' ), 'A new WooCommerce product can reuse booking policy fields without an invalid service shortcode.' );
$embedded_markup = schedule_capture( static function () { Dendrila_Calendar_Schedule_Admin::render_rules( (object) array( 'ID' => 31 ), true, false ); } );
schedule_expect( false === strpos( $embedded_markup, 'dendrila_calendar_schedule_rules_nonce' ) && false !== strpos( $embedded_markup, '[dendrila_booking' ), 'The product editor must use only its product nonce, avoiding partial rules writes during internal service creation.' );
$GLOBALS['schedule_test_admin'] = false;
schedule_expect( '' === schedule_capture( static function () { Dendrila_Calendar_Schedule_Admin::render_profile( (object) array( 'ID' => 11 ) ); } ), 'Profile settings must remain administrator-only.' );
echo 'Schedule admin: ' . $GLOBALS['schedule_test_checks'] . " checks passed; no live calendar or site modified.\n";
