<?php
/** Native booking domain contract; no WordPress runtime or third-party plugins required. */
define( 'ABSPATH', __DIR__ );
$GLOBALS['posts'] = array();
$GLOBALS['meta'] = array();
$GLOBALS['types'] = array();
$GLOBALS['submenus'] = array();
$GLOBALS['hooks'] = array();
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['hooks'][ $hook ][] = $callback; }
function register_post_type( $name, $args ) { $GLOBALS['types'][ $name ] = $args; }
function add_submenu_page( $parent, $title, $label, $cap, $slug, $callback ) { $GLOBALS['submenus'][ $slug ] = $callback; }
function add_meta_box( $id, $title, $callback, $screen, $context, $priority ) { $GLOBALS['boxes'][ $screen ] = $callback; }
function get_posts( $query ) {
    return array_values( array_filter( $GLOBALS['posts'], static function ( $post ) use ( $query ) {
        return $post->post_type === $query['post_type'] && 'publish' === $post->post_status;
    } ) );
}
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_meta( $id, $key, $single ) { return $GLOBALS['meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { $GLOBALS['meta'][ $id ][ $key ] = $value; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); }
function absint( $value ) { return abs( (int) $value ); }
function __( $value, $domain = '' ) { return $value; }
function esc_html__( $value, $domain = '' ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return $value; }
function wp_nonce_field( $action, $field ) {}
function selected( $one, $two, $echo ) { return $one === $two ? ' selected="selected"' : ''; }
function current_user_can( $role ) { return 'manage_options' === $role; }
function wp_verify_nonce( $nonce, $action ) { return 'valid' === $nonce && 'dendrila_calendar_service_save' === $action; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return strip_tags( $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) ); }
function admin_url( $path ) { return 'https://example.test/wp-admin/' . $path; }
function get_edit_post_link( $id, $context ) { return 'https://example.test/wp-admin/post.php?post=' . $id; }
function wp_die( $message ) { throw new RuntimeException( $message ); }
class WP_Query {
    public $posts = array();
    public $max_num_pages = 0;
    public $found_posts = 0;
}
class WP_Error {
    private $code;
    public function __construct( $code, $message ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-native.php';
require dirname( __DIR__ ) . '/includes/class-dendrila-calendar-woo.php';
Dendrila_Calendar_Native::boot();
Dendrila_Calendar_Native::register_types();
foreach ( array( 'dendrila_profile', 'dendrila_service', 'dendrila_booking' ) as $type ) {
    if ( ! isset( $GLOBALS['types'][ $type ] ) || $GLOBALS['types'][ $type ]['public'] ||
         $GLOBALS['types'][ $type ]['show_in_rest'] ) {
        throw new RuntimeException( 'Native post type must remain private and admin-only.' );
    }
}
Dendrila_Calendar_Native::menu();
foreach ( array( 'dendrila-calendar-profiles', 'dendrila-calendar-services', 'dendrila-calendar-bookings' ) as $slug ) {
    if ( ! isset( $GLOBALS['submenus'][ $slug ] ) ) {
        throw new RuntimeException( 'Native booking menu is missing ' . $slug );
    }
}
if ( Dendrila_Calendar_Native::has_profile() ||
     Dendrila_Calendar_Native::valid_profile( 0 ) ) {
    throw new RuntimeException( 'Empty sites must never have a valid booking profile.' );
}
$invalid = Dendrila_Calendar_Native::validate_booking( 31, 11, '2026-12-05 10:00:00', '2026-12-05 10:30:00' );
if ( ! $invalid instanceof WP_Error || 'dendrila_missing_profile' !== $invalid->get_error_code() ) {
    throw new RuntimeException( 'Booking without a profile was not rejected.' );
}
$GLOBALS['posts'][11] = (object) array( 'ID'=>11,'post_type'=>'dendrila_profile','post_status'=>'publish','post_title'=>'M. Exemple' );
$GLOBALS['posts'][12] = (object) array( 'ID'=>12,'post_type'=>'dendrila_profile','post_status'=>'draft','post_title'=>'Inactive' );
$GLOBALS['posts'][31] = (object) array( 'ID'=>31,'post_type'=>'dendrila_service','post_status'=>'publish','post_title'=>'Consultation' );
if ( ! Dendrila_Calendar_Native::has_profile() ||
     Dendrila_Calendar_Native::valid_profile( 12 ) ||
     Dendrila_Calendar_Native::bookable_service( 31 ) ) {
    throw new RuntimeException( 'Inactive or missing profile must never make a service bookable.' );
}
$GLOBALS['meta'][31] = array(
    Dendrila_Calendar_Native::PROFILE_KEY=>12,
    '_dendrila_duration_minutes'=>30,
    '_dendrila_price_cents'=>5000,
);
if ( Dendrila_Calendar_Native::bookable_service( 31 ) ) {
    throw new RuntimeException( 'A draft profile cannot own a bookable service.' );
}
$GLOBALS['meta'][31][ Dendrila_Calendar_Native::PROFILE_KEY ] = 11;
if ( ! Dendrila_Calendar_Native::bookable_service( 31 ) ) {
    throw new RuntimeException( 'A published service with an active profile must be valid.' );
}
$wrong = Dendrila_Calendar_Native::validate_booking( 31, 12, '2026-12-05 10:00:00', '2026-12-05 10:30:00' );
$duration = Dendrila_Calendar_Native::validate_booking( 31, 11, '2026-12-05 10:00:00', '2026-12-05 11:00:00' );
$bad_date = Dendrila_Calendar_Native::validate_booking( 31, 11, '2026-12-05 10:00:00', '2026-12-04 10:30:00' );
if ( ! $wrong instanceof WP_Error || ! $duration instanceof WP_Error || ! $bad_date instanceof WP_Error ||
     $wrong->get_error_code() !== 'dendrila_profile_mismatch' ||
     $duration->get_error_code() !== 'dendrila_wrong_duration' ||
     $bad_date->get_error_code() !== 'dendrila_invalid_slot' ) {
    throw new RuntimeException( 'Profile identity, duration and date must be validated independently.' );
}
$record = Dendrila_Calendar_Native::booking_record( 31, 11, '2026-12-05 10:00:00', '2026-12-05 10:30:00' );
if ( ! is_array( $record ) ||
     $record['post_status'] !== 'private' ||
     $record['meta_input'][ Dendrila_Calendar_Native::SERVICE_KEY ] !== 31 ||
     $record['meta_input'][ Dendrila_Calendar_Native::PROFILE_KEY ] !== 11 ) {
    throw new RuntimeException( 'Booking record must retain both immutable profile and service identifiers.' );
}
$GLOBALS['posts'][11]->post_status = 'draft';
if ( Dendrila_Calendar_Native::bookable_service( 31 ) ) {
    throw new RuntimeException( 'Disabling the last profile must close related services.' );
}
$GLOBALS['posts'][11]->post_status = 'publish';
$_POST = array(
    'dendrila_calendar_service_nonce'=>'valid',
    'dendrila_profile_id'=>'12',
    'dendrila_service_duration'=>'45',
    'dendrila_service_before'=>'10',
    'dendrila_service_after'=>'15',
    'dendrila_service_price'=>'7000',
    'dendrila_service_product'=>'123',
);
Dendrila_Calendar_Native::save_service( 31 );
if ( Dendrila_Calendar_Native::bookable_service( 31 ) ||
     get_post_meta( 31, '_dendrila_duration_minutes', true ) !== 45 ) {
    throw new RuntimeException( 'Saving a service with an inactive profile must clear its assignment.' );
}
$_POST['dendrila_profile_id'] = '11';
Dendrila_Calendar_Native::save_service( 31 );
if ( ! Dendrila_Calendar_Native::bookable_service( 31 ) ||
     get_post_meta( 31, Dendrila_Calendar_Native::PROFILE_KEY, true ) !== 11 ) {
    throw new RuntimeException( 'Cannot save an active service assigned to a valid profile.' );
}
if ( Dendrila_Calendar_Woo::available() || Dendrila_Calendar_Woo::product_for_service(31) ) {
    throw new RuntimeException( 'Optional WooCommerce must not be required for native profile and service setup.' );
}
ob_start();
Dendrila_Calendar_Native::render_services();
$markup = ob_get_clean();
if ( strpos( $markup, 'Add a service' ) === false ||
     strpos( $markup, 'No services yet.' ) === false ) {
    throw new RuntimeException( 'Native service administration was not rendered.' );
}
echo "Native profiles, service assignment, booking record and WooCommerce-optional tests passed\n";
