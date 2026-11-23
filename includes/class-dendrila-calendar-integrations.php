<?php
/**
 * Optional read-only calendar providers, audited against the versioned plugins
 * listed in docs/INTEGRATIONS.md. Never save or modify a third-party booking.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar_Integrations {
    private static $last_error = '';

    public static function last_error() { return self::$last_error; }

    /** Distinct source identifiers are part of the stable public filter contract. */
    public static function available() {
        $sources = array();
        foreach ( Dendrila_Calendar_Detector::discover() as $id => $details ) {
            if ( $details['ready'] && ! in_array( $id, array( 'oryx', 'tec', 'ics_calendar' ), true ) ) {
                $sources[ $id ] = $details['label'];
            }
        }
        return $sources;
    }

    public static function events( $source, $start_utc, $end_utc ) {
        self::$last_error = '';
        if ( ! array_key_exists( $source, self::available() ) ) {
            return array();
        }
        $handler = 'from_' . $source;
        return is_callable( array( __CLASS__, $handler ) ) ? self::$handler( $start_utc, $end_utc ) : array();
    }

    /** Convert a column documented as UTC to a strict DateTime. */
    private static function utc_date( $text ) {
        return self::parse_date( $text, new DateTimeZone( 'UTC' ) );
    }

    /** Convert a source column documented as site-local wall clock to UTC. */
    private static function site_date( $text ) {
        return self::parse_date( $text, wp_timezone() );
    }

    private static function parse_date( $text, $zone ) {
        if ( ! is_string( $text ) || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $text ) ) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $text, $zone );
        $errors = DateTimeImmutable::getLastErrors();
        if ( ! $date || ( is_array( $errors ) && ( $errors['error_count'] || $errors['warning_count'] ) ) ) {
            return null;
        }
        return $date->format( 'Y-m-d H:i:s' ) === $text ? $date : null;
    }

    private static function local_bounds( $start, $end ) {
        return array(
            ( new DateTimeImmutable( $start, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() ),
            ( new DateTimeImmutable( $end, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() ),
        );
    }

    private static function result( $id, $title, $start, $end, $status, $url, $lower, $upper, $all_day = false ) {
        if ( ! $start instanceof DateTimeInterface || ! $end instanceof DateTimeInterface ) {
            return null;
        }
        $lower = self::utc_date( $lower );
        $upper = self::utc_date( $upper );
        if ( ! $lower || ! $upper || $end <= $start || $start >= $upper || $end <= $lower ) {
            return null;
        }
        return array(
            'id' => $id,
            'title' => $title,
            'start' => gmdate( 'Y-m-d\TH:i:s\Z', $start->getTimestamp() ),
            'end' => gmdate( 'Y-m-d\TH:i:s\Z', $end->getTimestamp() ),
            'status' => $status,
            'url' => $url,
            'all_day' => (bool) $all_day,
        );
    }

    /**
     * Only constant, read-only query templates are accepted.
     * Table identifiers use WordPress 6.2+ %i placeholders; values use %s.
     * The cache is deliberately brief so new bookings become visible quickly.
     * Never select customer names, email addresses, notes, IPs or phone numbers.
     */
    private static function query( $source, $params ) {
        global $wpdb;
        self::$last_error = '';
        $cache_key = 'sql_' . hash( 'sha256', $source . '|' . json_encode( $params ) );
        $cached = function_exists( 'wp_cache_get' ) ? wp_cache_get( $cache_key, 'dendrila_calendar' ) : false;
        if ( is_array( $cached ) ) {
            return $cached;
        }

        // All SQL statements below are literal, SELECT-only and have bound identifiers and values.
        switch ( $source ) {
            case 'easy_appointments':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT a.id, a.date, a.start, a.end, a.end_date, a.status, s.name AS service_title
             FROM %i a LEFT JOIN %i s ON s.id = a.service
             WHERE a.date <= %s AND COALESCE(NULLIF(a.end_date, '0000-00-00'), a.date) >= %s
             ORDER BY a.date ASC, a.start ASC LIMIT 750",
                    $params
                ) );
                break;
            case 'fluent_booking':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT b.id, b.start_time, b.end_time, b.status, s.title AS service_title
             FROM %i b LEFT JOIN %i s ON s.id = b.event_id
             WHERE b.booking_type = %s AND b.start_time < %s AND b.end_time > %s
             ORDER BY b.start_time ASC LIMIT 750",
                    $params
                ) );
                break;
            case 'simply_schedule':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT a.id, a.start_date, a.end_date, a.status, t.title AS service_title
             FROM %i a LEFT JOIN %i t ON t.id = a.appointment_type_id
             WHERE a.start_date < %s AND a.end_date > %s
             AND a.status NOT IN ('canceled', 'abandoned')
             ORDER BY a.start_date ASC LIMIT 750",
                    $params
                ) );
                break;
            case 'bookly':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT a.id, a.start_date, a.end_date, a.extras_duration,
                    a.custom_service_name, s.title AS service_title
             FROM %i a LEFT JOIN %i s ON s.id = a.service_id
             WHERE a.start_date < %s AND a.end_date > %s
             ORDER BY a.start_date ASC LIMIT 750",
                    $params
                ) );
                break;
            case 'amelia_appointments':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT a.id, a.bookingStart AS begins, a.bookingEnd AS finishes, a.status, s.name AS title
             FROM %i a LEFT JOIN %i s ON s.id = a.serviceId
             WHERE a.bookingStart < %s AND a.bookingEnd > %s
             ORDER BY a.bookingStart ASC LIMIT 600",
                    $params
                ) );
                break;
            case 'amelia_periods':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT p.id, p.periodStart AS begins, p.periodEnd AS finishes, e.name AS title, e.status
             FROM %i p INNER JOIN %i e ON e.id = p.eventId
             WHERE p.periodStart < %s AND p.periodEnd > %s
             ORDER BY p.periodStart ASC LIMIT 600",
                    $params
                ) );
                break;
            case 'booking_calendar':
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT b.booking_id, DATE(d.booking_date) AS booked_day, MAX(d.approved) AS approved
             FROM %i d INNER JOIN %i b ON b.booking_id = d.booking_id
             WHERE b.trash = 0 AND d.booking_date >= %s AND d.booking_date < %s
             GROUP BY b.booking_id, DATE(d.booking_date) ORDER BY booked_day ASC LIMIT 750",
                    $params
                ) );
                break;
            default:
                return array();
        }
        if ( ! empty( $wpdb->last_error ) ) {
            self::$last_error = 'Database query failed for this source. Check plugin compatibility.';
            return array();
        }
        if ( is_array( $rows ) && function_exists( 'wp_cache_set' ) ) {
            wp_cache_set( $cache_key, $rows, 'dendrila_calendar', 10 );
        }
        return is_array( $rows ) ? $rows : array();
    }

    private static function collect( $rows, $build ) {
        $result = array();
        foreach ( $rows as $row ) {
            $item = call_user_func( $build, $row );
            if ( is_array( $item ) ) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /** Public Event Organiser API: one item for each recurring occurrence. */
    private static function from_event_organiser( $start, $end ) {
        list( $local_start, $local_end ) = self::local_bounds( $start, $end );
        $posts = eo_get_events( array(
            'event_start_before' => $local_end->format( 'Y-m-d' ),
            'event_end_after' => $local_start->format( 'Y-m-d' ),
            'numberposts' => 750,
            'post_status' => 'publish',
            'group_events_by' => '',
            'showpastevents' => true,
        ) );
        $events = array();
        foreach ( (array) $posts as $post ) {
            $id = absint( $post->ID ?? 0 );
            $occurrence = absint( $post->occurrence_id ?? 0 );
            if ( ! $id || ! $occurrence ) {
                continue;
            }
            $item = self::result(
                'eo-' . $id . '-' . $occurrence, get_the_title( $id ),
                eo_get_the_start( 'DATETIMEOBJ', $id, $occurrence ),
                eo_get_the_end( 'DATETIMEOBJ', $id, $occurrence ),
                '', get_edit_post_link( $id, '' ), $start, $end
            );
            if ( $item ) {
                $events[] = $item;
            }
        }
        return $events;
    }

    /** Events Manager's own event query and UTC-aware DateTime API. */
    private static function from_events_manager( $start, $end ) {
        list( $local_start, $local_end ) = self::local_bounds( $start, $end );
        $events = \EM_Events::get( array(
            'scope' => array( $local_start->format( 'Y-m-d' ), $local_end->format( 'Y-m-d' ) ),
            'limit' => 750,
            'status' => 1,
            'active' => 1,
            'groupby' => false,
        ) );
        $results = array();
        foreach ( (array) $events as $event ) {
            if ( ! is_object( $event ) || ! method_exists( $event, 'start' ) || ! method_exists( $event, 'end' ) ) {
                continue;
            }
            $id = absint( $event->event_id ?? 0 );
            if ( ! $id ) {
                continue;
            }
            $item = self::result(
                'em-' . $id, (string) ( $event->event_name ?? __( 'Event', 'dendrila-calendar' ) ),
                $event->start( true ), $event->end( true ), '',
                method_exists( $event, 'get_edit_url' ) ? $event->get_edit_url() : '', $start, $end
            );
            if ( $item ) {
                $results[] = $item;
            }
        }
        return $results;
    }

    /** Easy Appointments 4.0.5: date/time fields are in WordPress site timezone. */
    private static function from_easy_appointments( $start, $end ) {
        global $wpdb;
        list( $local_start, $local_end ) = self::local_bounds( $start, $end );
        $table = $wpdb->prefix . 'ea_appointments';
        $services = $wpdb->prefix . 'ea_services';
        $rows = self::query( 'easy_appointments', array( $table, $services, $local_end->format( 'Y-m-d' ), $local_start->format( 'Y-m-d' ) ) );
        return self::collect( $rows, static function ( $row ) use ( $start, $end ) {
            $from = self::site_date( (string) $row->date . ' ' . (string) $row->start );
            $end_day = ! empty( $row->end_date ) && '0000-00-00' !== $row->end_date ? $row->end_date : $row->date;
            $to = self::site_date( (string) $end_day . ' ' . (string) $row->end );
            return self::result(
                'ea-' . absint( $row->id ), $row->service_title ?: __( 'Appointment', 'dendrila-calendar' ),
                $from, $to, (string) $row->status, admin_url( 'admin.php?page=easy-appointments' ), $start, $end
            );
        } );
    }

    /** FluentBooking 2.6.0: only scheduling bookings, never invitation/customer fields. */
    private static function from_fluent_booking( $start, $end ) {
        global $wpdb;
        $bookings = $wpdb->prefix . 'fcal_bookings';
        $slots = $wpdb->prefix . 'fcal_calendar_events';
        $rows = self::query( 'fluent_booking', array( $bookings, $slots, 'scheduling', $end, $start ) );
        return self::collect( $rows, static function ( $row ) use ( $start, $end ) {
            return self::result(
                'fb-' . absint( $row->id ), $row->service_title ?: __( 'Appointment', 'dendrila-calendar' ),
                self::utc_date( $row->start_time ), self::utc_date( $row->end_time ),
                (string) $row->status, admin_url( 'admin.php?page=fluent-booking#/bookings' ), $start, $end
            );
        } );
    }

    /** Simply Schedule Appointments 1.6.13.9: start/end DATETIME stored as UTC. */
    private static function from_simply_schedule( $start, $end ) {
        global $wpdb;
        $appointments = $wpdb->prefix . 'ssa_appointments';
        $types = $wpdb->prefix . 'ssa_appointment_types';
        $rows = self::query( 'simply_schedule', array( $appointments, $types, $end, $start ) );
        return self::collect( $rows, static function ( $row ) use ( $start, $end ) {
            return self::result(
                'ssa-' . absint( $row->id ), $row->service_title ?: __( 'Appointment', 'dendrila-calendar' ),
                self::utc_date( $row->start_date ), self::utc_date( $row->end_date ),
                (string) $row->status, admin_url( 'admin.php?page=ssa/appointments' ), $start, $end
            );
        } );
    }

    /** Bookly 28.8: appointment timestamps are in the WordPress site timezone. */
    private static function from_bookly( $start, $end ) {
        global $wpdb;
        list( $local_start, $local_end ) = self::local_bounds( $start, $end );
        $appointments = $wpdb->prefix . 'bookly_appointments';
        $services = $wpdb->prefix . 'bookly_services';
        $rows = self::query( 'bookly', array( $appointments, $services, $local_end->format( 'Y-m-d H:i:s' ), $local_start->format( 'Y-m-d H:i:s' ) ) );
        return self::collect( $rows, static function ( $row ) use ( $start, $end ) {
            $to = self::site_date( $row->end_date );
            if ( $to && (int) $row->extras_duration > 0 ) {
                $to = $to->modify( '+' . min( 86400, (int) $row->extras_duration ) . ' seconds' );
            }
            return self::result(
                'bookly-' . absint( $row->id ), $row->custom_service_name ?: ( $row->service_title ?: __( 'Appointment', 'dendrila-calendar' ) ),
                self::site_date( $row->start_date ), $to, '',
                admin_url( 'admin.php?page=bookly-calendar' ), $start, $end
            );
        } );
    }

    /** Amelia 2.4.15: appointment and event periods are stored as UTC DATETIME. */
    private static function from_amelia( $start, $end ) {
        global $wpdb;
        $appointments = $wpdb->prefix . 'amelia_appointments';
        $services = $wpdb->prefix . 'amelia_services';
        $events = $wpdb->prefix . 'amelia_events';
        $periods = $wpdb->prefix . 'amelia_events_periods';
        $rows = self::query( 'amelia_appointments', array( $appointments, $services, $end, $start ) );
        $items = self::collect( $rows, static function ( $row ) use ( $start, $end ) {
            return self::result(
                'amelia-a-' . absint( $row->id ), $row->title ?: __( 'Appointment', 'dendrila-calendar' ),
                self::utc_date( $row->begins ), self::utc_date( $row->finishes ),
                (string) $row->status, admin_url( 'admin.php?page=wpamelia-appointments' ), $start, $end
            );
        } );
        // Amelia event periods are distinct occurrences. A recurring parent alone is not an occurrence.
        $period_rows = self::query( 'amelia_periods', array( $periods, $events, $end, $start ) );
        return array_merge( $items, self::collect( $period_rows, static function ( $row ) use ( $start, $end ) {
            return self::result(
                'amelia-e-' . absint( $row->id ), $row->title ?: __( 'Event', 'dendrila-calendar' ),
                self::utc_date( $row->begins ), self::utc_date( $row->finishes ),
                (string) $row->status, admin_url( 'admin.php?page=wpamelia-events' ), $start, $end
            );
        } ) );
    }

    /**
     * Booking Calendar 12.0.1: date allocations are *days*, not guaranteed exact
     * times. Show one all-day entry per booked date rather than invent a duration.
     */
    private static function from_booking_calendar( $start, $end ) {
        global $wpdb;
        list( $local_start, $local_end ) = self::local_bounds( $start, $end );
        $dates = $wpdb->prefix . 'bookingdates';
        $bookings = $wpdb->prefix . 'booking';
        $rows = self::query( 'booking_calendar', array( $dates, $bookings, $local_start->format( 'Y-m-d 00:00:00' ), $local_end->format( 'Y-m-d 00:00:00' ) ) );
        return self::collect( $rows, static function ( $row ) use ( $start, $end ) {
            $begins = self::site_date( $row->booked_day . ' 00:00:00' );
            $finishes = $begins ? $begins->modify( '+1 day' ) : null;
            return self::result(
                'wpbc-' . absint( $row->booking_id ) . '-' . $row->booked_day,
                __( 'Reserved day', 'dendrila-calendar' ), $begins, $finishes,
                $row->approved ? __( 'Approved', 'dendrila-calendar' ) : __( 'Pending', 'dendrila-calendar' ),
                admin_url( 'admin.php?page=wpbc&view_mode=vm_listing' ), $start, $end, true
            );
        } );
    }
}
