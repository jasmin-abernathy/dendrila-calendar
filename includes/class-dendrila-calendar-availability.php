<?php
/** Protect Easy Appointments booking boundaries using read-only external busy windows. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Dendrila_Calendar_Availability {
    const OPTION = 'dendrila_calendar_protect_availability';
    public static function boot() {
        add_filter( 'easy_ea_can_make_reservation', array( __CLASS__, 'check_booking' ), 20, 2 );
        add_action( 'admin_post_dendrila_calendar_availability_save', array( __CLASS__, 'save' ) );
    }
    public static function enabled() { return '1' === (string) get_option( self::OPTION, '0' ); }
    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You cannot manage calendar connections.', 'dendrila-calendar' ) ); }
        check_admin_referer( 'dendrila_calendar_availability_save' );
        $on = isset( $_POST['protect_availability'] ) && '1' === (string) wp_unslash( $_POST['protect_availability'] );
        update_option( self::OPTION, $on && Dendrila_Calendar_OAuth::connections() ? '1' : '0', false );
        wp_safe_redirect( admin_url( 'admin.php?page=dendrila-calendar-sync&dendrila_method=oauth&availability_saved=1' ) );
        exit;
    }
    /** Return local booking boundaries in UTC seconds, with DST and overnight support. */
    public static function parse_slot( $data ) {
        if ( ! is_array( $data ) ) { return false; }
        $day = isset( $data['date'] ) && is_string( $data['date'] ) ? $data['date'] : '';
        $end_day = ! empty( $data['end_date'] ) && is_string( $data['end_date'] ) ? $data['end_date'] : $day;
        $start = isset( $data['start'] ) && is_string( $data['start'] ) ? substr( $data['start'], 0, 5 ) : '';
        $end = isset( $data['end'] ) && is_string( $data['end'] ) ? substr( $data['end'], 0, 5 ) : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $day ) ||
             ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $end_day ) ||
             ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/D', $start ) ||
             ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/D', $end ) ) { return false; }
        $tz = wp_timezone();
        $from = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $day . ' ' . $start, $tz );
        $to = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $end_day . ' ' . $end, $tz );
        if ( ! $from || ! $to || $from->format( 'Y-m-d H:i' ) !== $day . ' ' . $start ||
             $to->format( 'Y-m-d H:i' ) !== $end_day . ' ' . $end ) { return false; }
        if ( $to <= $from && $day === $end_day ) { $to = $to->modify( '+1 day' ); }
        if ( $to <= $from || $to->getTimestamp() - $from->getTimestamp() > 48 * 3600 ) { return false; }
        return array( $from->getTimestamp(), $to->getTimestamp() );
    }
    /** Fail closed only after explicit administrator opt-in. No third-party records edited. */
    public static function check_booking( $result, $data ) {
        if ( ! self::enabled() || ! is_array( $result ) || empty( $result['status'] ) ) { return $result; }
        $slot = self::parse_slot( $data );
        if ( false === $slot ) {
            $result['status'] = false;
            $result['message'] = __( 'Cannot verify this appointment time. Please select another slot.', 'dendrila-calendar' );
            return $result;
        }
        $busy = Dendrila_Calendar_OAuth::busy_intervals(
            gmdate( 'Y-m-d\TH:i:s\Z', $slot[0] ),
            gmdate( 'Y-m-d\TH:i:s\Z', $slot[1] )
        );
        if ( false === $busy ) {
            $result['status'] = false;
            $result['message'] = __( 'Calendar availability is temporarily unavailable. Please try again.', 'dendrila-calendar' );
            return $result;
        }
        foreach ( $busy as $span ) {
            if ( $span[0] < $slot[1] && $span[1] > $slot[0] ) {
                $result['status'] = false;
                $result['message'] = __( 'This time is occupied in a connected calendar. Please choose another.', 'dendrila-calendar' );
                break;
            }
        }
        return $result;
    }
    public static function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $available = (bool) Dendrila_Calendar_OAuth::connections();
        echo '<section class="dendrila-availability-panel"><h2>' . esc_html__( 'Prevent double bookings', 'dendrila-calendar' ) . '</h2>';
        echo '<p>' . esc_html__( 'Check busy times in Google and Outlook before accepting an Easy Appointments booking.', 'dendrila-calendar' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dendrila_calendar_availability_save">';
        wp_nonce_field( 'dendrila_calendar_availability_save' );
        echo '<label class="dendrila-toggle"><input type="checkbox" name="protect_availability" value="1" ' .
            checked( self::enabled(), true, false ) . ( $available ? '' : ' disabled' ) . '><span><strong>' .
            esc_html__( 'Protect Easy Appointments reservations', 'dendrila-calendar' ) . '</strong><small>' .
            esc_html__( 'If a provider cannot be checked, the new reservation is refused rather than double-booked.', 'dendrila-calendar' ) . '</small></span></label>';
        if ( ! $available ) { echo '<p>' . esc_html__( 'Connect at least one Google or Outlook calendar first.', 'dendrila-calendar' ) . '</p>'; }
        echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save availability protection', 'dendrila-calendar' ) . '</button></p></form>';
        echo '<p class="description">' . esc_html__( 'Busy times are checked at booking submission. The Easy Appointments slot picker might still display those times.', 'dendrila-calendar' ) . '</p></section>';
    }
}
