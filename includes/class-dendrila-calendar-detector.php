<?php
/** Discover supported and unsupported booking plugins from WordPress installation metadata. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar_Detector {
    /** Plugin entrypoints verified against the reviewed upstream archives. */
    public static function catalog() {
        return array(
            'oryx' => array( 'label' => 'Oryx Bookings', 'files' => array( 'oryx-bookings/oryx-bookings.php' ), 'supported' => true ),
            'tec' => array( 'label' => 'The Events Calendar', 'files' => array( 'the-events-calendar/the-events-calendar.php' ), 'supported' => true ),
            'event_organiser' => array( 'label' => 'Event Organiser', 'files' => array( 'event-organiser/event-organiser.php' ), 'supported' => true ),
            'events_manager' => array( 'label' => 'Events Manager', 'files' => array( 'events-manager/events-manager.php' ), 'supported' => true ),
            'easy_appointments' => array( 'label' => 'Easy Appointments', 'files' => array( 'easy-appointments/main.php' ), 'supported' => true ),
            'fluent_booking' => array( 'label' => 'FluentBooking', 'files' => array( 'fluent-booking/fluent-booking.php' ), 'supported' => true ),
            'simply_schedule' => array( 'label' => 'Simply Schedule Appointments', 'files' => array( 'simply-schedule-appointments/simply-schedule-appointments.php' ), 'supported' => true ),
            'bookly' => array( 'label' => 'Bookly', 'files' => array( 'bookly-responsive-appointment-booking-tool/main.php' ), 'supported' => true ),
            'amelia' => array( 'label' => 'Amelia', 'files' => array( 'ameliabooking/ameliabooking.php' ), 'supported' => true ),
            'booking_calendar' => array( 'label' => 'Booking Calendar', 'files' => array( 'booking/wpdev-booking.php' ), 'supported' => true ),
            'ics_calendar' => array( 'label' => 'ICS Calendar', 'files' => array( 'ics-calendar/ics-calendar.php' ), 'supported' => false ),
        );
    }

    private static function runtime_ready( $source ) {
        switch ( $source ) {
            case 'oryx':
                return class_exists( '\\OryxBookings\\Repositories\\BookingRepository' );
            case 'tec':
                return function_exists( 'post_type_exists' ) && post_type_exists( 'tribe_events' );
            case 'event_organiser':
                return function_exists( 'eo_get_events' ) && function_exists( 'eo_get_the_start' ) && function_exists( 'eo_get_the_end' );
            case 'events_manager':
                return class_exists( 'EM_Events' );
            case 'easy_appointments':
                return defined( 'EASY_APPOINTMENTS_VERSION' );
            case 'fluent_booking':
                return defined( 'FLUENT_BOOKING_VERSION' );
            case 'simply_schedule':
                return function_exists( 'ssa' ) && class_exists( 'SSA_Appointment_Model' );
            case 'bookly':
                return class_exists( '\\Bookly\\Lib\\Entities\\Appointment' );
            case 'amelia':
                return defined( 'AMELIA_VERSION' );
            case 'booking_calendar':
                return function_exists( 'wpbc_is_table_exists' );
        }
        return false;
    }

    private static function active_paths() {
        $active = function_exists( 'get_option' ) ? get_option( 'active_plugins', array() ) : array();
        $sitewide = function_exists( 'get_site_option' ) ? get_site_option( 'active_sitewide_plugins', array() ) : array();
        return array_unique( array_merge(
            is_array( $active ) ? array_values( $active ) : array(),
            is_array( $sitewide ) ? array_keys( $sitewide ) : array()
        ) );
    }

    /** Plugin API is not always loaded on front-end AJAX/cron requests. */
    private static function installed_plugins() {
        if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $items = function_exists( 'get_plugins' ) ? get_plugins() : array();
        return is_array( $items ) ? $items : array();
    }

    /** For each installed supported plugin, report its active/ready status and technical reason. */
    public static function discover() {
        $active_paths = self::active_paths();
        $installed = self::installed_plugins();
        $results = array();
        $recognized = array();
        foreach ( self::catalog() as $id => $item ) {
            $paths = $item['files'];
            $active = false;
            $present = false;
            $main_file = $paths[0];
            $meta = array();
            foreach ( $paths as $file ) {
                $recognized[ $file ] = true;
                if ( in_array( $file, $active_paths, true ) ) {
                    $active = true;
                    $main_file = $file;
                }
                if ( isset( $installed[ $file ] ) ) {
                    $present = true;
                    $meta = $installed[ $file ];
                    if ( ! $active ) {
                        $main_file = $file;
                    }
                }
            }
            $signature = self::runtime_ready( $id );
            $active = $active || $signature;
            $present = $present || $active;
            if ( ! $present ) {
                continue;
            }
            $api_required = in_array( $id, array( 'oryx', 'tec', 'event_organiser', 'events_manager' ), true );
            $ready = $item['supported'] && $active && ( ! $api_required || $signature );
            $state = ! $active ? 'inactive' : ( ! $item['supported'] ? 'unsupported' : ( $ready ? 'ready' : 'unavailable' ) );
            $reason = 'Ready for read-only event extraction; live compatibility still requires testing.';
            if ( 'inactive' === $state ) {
                $reason = 'Plugin is installed but not activated; no connector error.';
            } elseif ( 'unsupported' === $state ) {
                $reason = 'An import adapter has not been implemented or safely verified for this plugin.';
            } elseif ( 'unavailable' === $state ) {
                $reason = 'Plugin is active but its expected public PHP API is missing.';
            }
            $results[ $id ] = array(
                'label' => $item['label'],
                'plugin_file' => $main_file,
                'version' => isset( $meta['Version'] ) ? sanitize_text_field( (string) $meta['Version'] ) : 'unknown',
                'active' => $active,
                'ready' => $ready,
                'state' => $state,
                'reason' => $reason,
                'source_id' => $id,
            );
        }
        // Find active booking plugins not in the audited catalog; do not guess their database schemas.
        foreach ( $active_paths as $file ) {
            if ( isset( $recognized[ $file ] ) || ! isset( $installed[ $file ] ) || 'dendrila-calendar/dendrila-calendar.php' === $file ) {
                continue;
            }
            $meta = $installed[ $file ];
            $name = isset( $meta['Name'] ) ? (string) $meta['Name'] : '';
            $description = isset( $meta['Description'] ) ? (string) $meta['Description'] : '';
            if ( ! preg_match( '/appointment|booking|réservation|rendez[- ]vous|prise de rendez[- ]vous/iu', $name . ' ' . $description ) ) {
                continue;
            }
            $id = 'unknown_' . substr( hash( 'sha256', $file ), 0, 12 );
            $results[ $id ] = array(
                'label' => sanitize_text_field( $name ),
                'plugin_file' => sanitize_text_field( $file ),
                'version' => isset( $meta['Version'] ) ? sanitize_text_field( (string) $meta['Version'] ) : 'unknown',
                'active' => true,
                'ready' => false,
                'state' => 'unsupported',
                'reason' => 'Active booking plugin detected, but no audited Dendrila Calendar adapter exists.',
                'source_id' => $id,
            );
        }
        return $results;
    }

    public static function is_ready( $source ) {
        $detected = self::discover();
        return isset( $detected[ $source ] ) && $detected[ $source ]['ready'];
    }
}
