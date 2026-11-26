<?php
/** Protect Easy Appointments booking boundaries using read-only external busy windows. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Dendrila_Calendar_Availability {
    const OPTION = 'dendrila_calendar_protect_availability';
    public static function boot() {
        add_filter( 'easy_ea_can_make_reservation', array( __CLASS__, 'check_booking' ), 20, 2 );
        add_filter( 'easy_ea_can_update_reservation', array( __CLASS__, 'check_final_booking' ), 20, 3 );
        add_action( 'wp_ajax_nopriv_ea_date_selected', array( __CLASS__, 'protect_public_slot_display' ), 0 );
        add_action( 'wp_ajax_ea_date_selected', array( __CLASS__, 'protect_public_slot_display' ), 0 );
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

    /**
     * Easy Appointments 4.x renders public time choices from ea_date_selected.
     * That endpoint offers no slot-output filter. Intercept *only* its already
     * nonce-validated JSON response via a scoped output buffer, leaving the
     * plugin's endpoint, permissions, and response contract untouched.
     *
     * The remote API is called BEFORE output buffering: no WordPress/HTTP work
     * is attempted inside a PHP output handler.
     */
    public static function protect_public_slot_display() {
        if ( ! self::enabled() || ! class_exists( 'EAAjax' ) ||
             ! function_exists( 'check_ajax_referer' ) ||
             false === check_ajax_referer( 'ea-bootstrap-form', 'check', false ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above with the EA frontend nonce.
        $date = isset( $_GET['date'] ) && is_string( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified above with the EA frontend nonce.
        $service_raw = isset( $_GET['service'] ) && is_scalar( $_GET['service'] ) ? (string) wp_unslash( $_GET['service'] ) : '';
        $service = ctype_digit( $service_raw ) ? absint( $service_raw ) : 0;
        if ( ! self::valid_day( $date ) || $service < 1 ) {
            return;
        }
        global $wpdb;
        if ( ! isset( $wpdb ) || ! isset( $wpdb->prefix ) ) {
            return;
        }
        $table = $wpdb->prefix . 'ea_services';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own registered Easy Appointments service table.
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT duration, block_before, block_after FROM {$table} WHERE id = %d", $service ), ARRAY_A );
        if ( ! is_array( $row ) || empty( $row['duration'] ) ) {
            return; // Let EA handle an unknown service or invalid parameters.
        }
        $duration = absint( $row['duration'] );
        $before = min( 1440, absint( $row['block_before'] ?? 0 ) );
        $after = min( 1440, absint( $row['block_after'] ?? 0 ) );
        if ( $duration < 1 || $duration > 2880 ) {
            return;
        }
        // Day bounds are computed in the WordPress timezone, respecting DST.
        try {
            $start = new DateTimeImmutable( $date . ' 00:00:00', wp_timezone() );
        } catch ( Exception $exception ) {
            return;
        }
        // busy_intervals() intentionally caps windows to 48 hours; fetch
        // the specific day, plus the buffers from the preceding/following day.
        $day_start = $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
        $day_end = $start->modify( '+1 day' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
        $key = 'dendrila_ea_busy_' . md5( $date . '|' . get_option( self::OPTION, '0' ) );
        $cached = get_transient( $key );
        if ( is_array( $cached ) && isset( $cached['intervals'] ) ) {
            $busy = $cached['intervals'];
        } else {
            // Cache only the public display for 20 seconds. Final reservation
            // validation always consults live provider data independently.
            $busy = Dendrila_Calendar_OAuth::busy_intervals( $day_start, $day_end );
            set_transient( $key, array( 'intervals' => $busy ), 20 );
        }
        $context = array(
            'date' => $date,
            'duration' => $duration,
            'before' => $before,
            'after' => $after,
            'busy' => $busy,
        );
        // PHP flushes the response upon EA's die(json_encode(...)); the
        // callback only transforms its calendar_slots values.
        ob_start( static function ( $output ) use ( $context ) {
            return self::filter_public_slots_json( $output, $context );
        } );
    }

    private static function valid_day( $day ) {
        if ( ! is_string( $day ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $day ) ) {
            return false;
        }
        $test = DateTimeImmutable::createFromFormat( '!Y-m-d', $day, wp_timezone() );
        return $test && $test->format( 'Y-m-d' ) === $day;
    }

    /**
     * Pure transformation of EA's public JSON payload. Never leaks remote
     * event titles, contact information, or provider credentials.
     */
    public static function filter_public_slots_json( $json, $context ) {
        if ( ! is_string( $json ) || strlen( $json ) > 1048576 ||
             ! is_array( $context ) || ! isset( $context['date'], $context['duration'], $context['busy'] ) ) {
            return $json;
        }
        $payload = json_decode( $json, true );
        if ( ! is_array( $payload ) || ! isset( $payload['calendar_slots'] ) ||
             ! is_array( $payload['calendar_slots'] ) ) {
            return $json; // Never modify an EA error or unsupported response.
        }
        $busy = $context['busy'];
        $duration = (int) $context['duration'];
        $before = (int) ( $context['before'] ?? 0 );
        $after = (int) ( $context['after'] ?? 0 );
        if ( ! self::valid_day( $context['date'] ) || $duration < 1 || $duration > 2880 ) {
            return $json;
        }
        foreach ( $payload['calendar_slots'] as &$slot ) {
            if ( ! is_array( $slot ) || ! isset( $slot['value'] ) ||
                 ! is_string( $slot['value'] ) ||
                 ! preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/D', $slot['value'] ) ) {
                continue;
            }
            try {
                $local = DateTimeImmutable::createFromFormat(
                    '!Y-m-d H:i',
                    $context['date'] . ' ' . $slot['value'],
                    wp_timezone()
                );
                if ( ! $local || $local->format( 'Y-m-d H:i' ) !== $context['date'] . ' ' . $slot['value'] ) {
                    $slot['count'] = 0;
                    continue;
                }
                $left = $local->getTimestamp() - $before * 60;
                $right = $local->getTimestamp() + ( $duration + $after ) * 60;
            } catch ( Exception $exception ) {
                $slot['count'] = 0;
                continue;
            }
            // An unavailable provider is not evidence that a slot is free.
            if ( false === $busy || ! is_array( $busy ) ) {
                $slot['count'] = 0;
                continue;
            }
            foreach ( $busy as $span ) {
                if ( is_array( $span ) && count( $span ) >= 2 &&
                     $span[0] < $right && $span[1] > $left ) {
                    $slot['count'] = 0;
                    break;
                }
            }
        }
        unset( $slot );
        $encoded = wp_json_encode( $payload );
        return is_string( $encoded ) ? $encoded : $json;
    }

    /**
     * A pre-reservation may be made earlier than the final customer
     * confirmation. Recheck remote busy windows when EA moves a reservation
     * into pending/confirmed, too.
     */
    public static function check_final_booking( $result, $appointment, $data ) {
        if ( ! self::enabled() || ! is_array( $appointment ) ||
             ( $appointment['status'] ?? '' ) !== 'reservation' ) {
            return $result;
        }
        return self::check_booking( $result, $appointment );
    }

    public static function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        // Historical bridge only. Native Dendrila bookings check availability independently.
        if ( ! defined( 'EASY_APPOINTMENTS_VERSION' ) && ! class_exists( 'EAAjax' ) ) { return; }
        $available = (bool) Dendrila_Calendar_OAuth::connections();
        echo '<details class="dendrila-availability-panel dendrila-legacy-compat"' . ( self::enabled() ? ' open' : '' ) . '>';
        echo '<summary>' . esc_html__( 'Easy Appointments compatibility (optional)', 'dendrila-calendar' ) . '</summary>';
        echo '<h2>' . esc_html__( 'Prevent double bookings in Easy Appointments', 'dendrila-calendar' ) . '</h2>';
        echo '<p>' . esc_html__( 'Check busy times in Google and Outlook before accepting an Easy Appointments booking.', 'dendrila-calendar' ) . '</p>';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dendrila_calendar_availability_save">';
        wp_nonce_field( 'dendrila_calendar_availability_save' );
        echo '<label class="dendrila-toggle"><input type="checkbox" name="protect_availability" value="1" ' .
            checked( self::enabled(), true, false ) . ( $available ? '' : ' disabled' ) . '><span><strong>' .
            esc_html__( 'Protect Easy Appointments reservations', 'dendrila-calendar' ) . '</strong><small>' .
            esc_html__( 'If a provider cannot be checked, the new reservation is refused rather than double-booked.', 'dendrila-calendar' ) . '</small></span></label>';
        if ( ! $available ) { echo '<p>' . esc_html__( 'Connect at least one Google or Outlook calendar first.', 'dendrila-calendar' ) . '</p>'; }
        echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save availability protection', 'dendrila-calendar' ) . '</button></p></form>';
        echo '<p class="description">' . esc_html__( 'Busy times also disable public Easy Appointments slots. Final booking submission is rechecked directly with connected calendars.', 'dendrila-calendar' ) . '</p></details>';
    }
}
