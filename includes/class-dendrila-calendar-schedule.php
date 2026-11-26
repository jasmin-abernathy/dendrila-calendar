<?php
/** Native availability rules. Slots and final acceptance use the same calculation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Calendar_Schedule {
    const PROFILE_META = '_dendrila_booking_schedule';
    const RULES_META = '_dendrila_booking_rules';

    private static function error( $message ) {
        return new WP_Error( 'dendrila_schedule_invalid', $message );
    }

    public static function valid_day( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) { return false; }
        $day = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );
        return $day && $day->format( 'Y-m-d' ) === $value;
    }

    private static function integer( $raw, $min, $max ) {
        if ( ! is_scalar( $raw ) || ! preg_match( '/^\d+$/D', (string) $raw ) ) { return null; }
        $value = (int) $raw;
        return $value >= $min && $value <= $max ? $value : null;
    }

    public static function defaults_profile() {
        return array(
            'enabled' => false, 'timezone' => wp_timezone_string(),
            'weekly' => array_fill( 1, 7, array() ), 'overrides' => array(),
            'max_per_day' => 0, 'max_per_week' => 0, 'max_per_month' => 0,
            'check_external' => true,
        );
    }

    public static function defaults_rules() {
        return array(
            'enabled' => false, 'notice_minutes' => 120, 'horizon_days' => 60,
            'slot_step' => 15, 'date_from' => '', 'date_to' => '',
            'max_per_day' => 0, 'max_per_week' => 0, 'max_per_month' => 0,
        );
    }

    /** Invalid stored settings never open a schedule by accident. */
    public static function profile( $id ) {
        $raw = get_post_meta( absint( $id ), self::PROFILE_META, true );
        if ( ! is_array( $raw ) ) { return self::defaults_profile(); }
        $clean = self::normalize_profile( $raw );
        return is_wp_error( $clean ) ? self::defaults_profile() : $clean;
    }

    public static function rules( $id ) {
        $raw = get_post_meta( absint( $id ), self::RULES_META, true );
        if ( ! is_array( $raw ) ) { return self::defaults_rules(); }
        $clean = self::normalize_rules( $raw );
        return is_wp_error( $clean ) ? self::defaults_rules() : $clean;
    }

    private static function ranges( $rows ) {
        if ( ! is_array( $rows ) || count( $rows ) > 12 ) {
            return self::error( __( 'Use up to twelve time ranges per day.', 'dendrila-calendar' ) );
        }
        $out = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['start'], $row['end'] ) || ! is_string( $row['start'] ) || ! is_string( $row['end'] ) ) {
                return self::error( __( 'Each time range needs a start and an end.', 'dendrila-calendar' ) );
            }
            $start = trim( $row['start'] ); $end = trim( $row['end'] );
            if ( '' === $start && '' === $end ) { continue; }
            if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $start ) ||
                 ! preg_match( '/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/D', $end ) || $start >= $end ) {
                return self::error( __( 'Enter valid, increasing times. Split overnight hours between two days.', 'dendrila-calendar' ) );
            }
            $out[] = array( 'start' => $start, 'end' => $end );
        }
        usort( $out, static function ( $a, $b ) { return strcmp( $a['start'], $b['start'] ); } );
        for ( $i = 1; $i < count( $out ); ++$i ) {
            if ( $out[ $i ]['start'] < $out[ $i - 1 ]['end'] ) {
                return self::error( __( 'Time ranges on the same day must not overlap.', 'dendrila-calendar' ) );
            }
        }
        $merged = array();
        foreach ( $out as $range ) {
            $last = count( $merged ) - 1;
            if ( $last >= 0 && $merged[ $last ]['end'] === $range['start'] ) { $merged[ $last ]['end'] = $range['end']; }
            else { $merged[] = $range; }
        }
        return $merged;
    }

    public static function normalize_profile( $raw ) {
        if ( ! is_array( $raw ) ) { return self::error( __( 'Invalid availability settings.', 'dendrila-calendar' ) ); }
        $out = self::defaults_profile();
        $out['enabled'] = ! empty( $raw['enabled'] );
        $out['check_external'] = array_key_exists( 'check_external', $raw ) ? ! empty( $raw['check_external'] ) : $out['check_external'];
        $timezone = $raw['timezone'] ?? $out['timezone'];
        if ( ! is_string( $timezone ) || strlen( $timezone ) > 100 ) { return self::error( __( 'Select a valid timezone.', 'dendrila-calendar' ) ); }
        try { new DateTimeZone( $timezone ); } catch ( Exception $e ) { return self::error( __( 'Select a valid timezone.', 'dendrila-calendar' ) ); }
        $out['timezone'] = $timezone;
        if ( isset( $raw['weekly'] ) && ! is_array( $raw['weekly'] ) ) { return self::error( __( 'Invalid weekly hours.', 'dendrila-calendar' ) ); }
        for ( $day = 1; $day <= 7; ++$day ) {
            $ranges = self::ranges( $raw['weekly'][ $day ] ?? array() );
            if ( is_wp_error( $ranges ) ) { return $ranges; }
            $out['weekly'][ $day ] = $ranges;
        }
        $overrides = $raw['overrides'] ?? array();
        if ( ! is_array( $overrides ) || count( $overrides ) > 100 ) { return self::error( __( 'Use up to one hundred date exceptions.', 'dendrila-calendar' ) ); }
        foreach ( $overrides as $day => $ranges ) {
            if ( ! self::valid_day( $day ) ) { return self::error( __( 'An exception contains an invalid date.', 'dendrila-calendar' ) ); }
            $ranges = self::ranges( $ranges );
            if ( is_wp_error( $ranges ) ) { return $ranges; }
            $out['overrides'][ $day ] = $ranges;
        }
        ksort( $out['overrides'] );
        foreach ( array( 'day', 'week', 'month' ) as $period ) {
            $field = 'max_per_' . $period;
            $value = self::integer( $raw[ $field ] ?? 0, 0, 10000 );
            if ( null === $value ) { return self::error( __( 'Booking limits must be whole numbers from 0 to 10000.', 'dendrila-calendar' ) ); }
            $out[ $field ] = $value;
        }
        return $out;
    }

    public static function normalize_rules( $raw ) {
        if ( ! is_array( $raw ) ) { return self::error( __( 'Invalid booking rules.', 'dendrila-calendar' ) ); }
        $out = self::defaults_rules();
        $out['enabled'] = ! empty( $raw['enabled'] );
        foreach ( array( 'notice_minutes' => array( 0, 525600 ), 'horizon_days' => array( 0, 3650 ), 'slot_step' => array( 5, 240 ),
            'max_per_day' => array( 0, 10000 ), 'max_per_week' => array( 0, 10000 ), 'max_per_month' => array( 0, 10000 ) ) as $key => $bounds ) {
            $value = self::integer( $raw[ $key ] ?? $out[ $key ], $bounds[0], $bounds[1] );
            if ( null === $value ) { return self::error( __( 'Check the notice, date range, interval and booking limits.', 'dendrila-calendar' ) ); }
            $out[ $key ] = $value;
        }
        foreach ( array( 'date_from', 'date_to' ) as $key ) {
            $value = $raw[ $key ] ?? '';
            if ( ! is_string( $value ) || ( '' !== $value && ! self::valid_day( $value ) ) ) { return self::error( __( 'Enter a valid booking date range.', 'dendrila-calendar' ) ); }
            $out[ $key ] = $value;
        }
        if ( $out['date_from'] && $out['date_to'] && $out['date_from'] > $out['date_to'] ) { return self::error( __( 'The booking end date must follow the start date.', 'dendrila-calendar' ) ); }
        return $out;
    }

    public static function date_bounds( $service_id, $now = null ) {
        $service = Dendrila_Calendar_Native::service_data( $service_id );
        if ( ! $service ) { return false; }
        $profile = self::profile( $service['profile_id'] ); $rules = self::rules( $service_id );
        $time = ( new DateTimeImmutable( '@' . ( null === $now ? time() : (int) $now ) ) )->setTimezone( new DateTimeZone( $profile['timezone'] ) );
        $min = ( new DateTimeImmutable( '@' . ( $time->getTimestamp() + $rules['notice_minutes'] * 60 ) ) )->setTimezone( new DateTimeZone( $profile['timezone'] ) )->format( 'Y-m-d' );
        if ( $rules['date_from'] && $rules['date_from'] > $min ) { $min = $rules['date_from']; }
        $max = $rules['horizon_days'] ? $time->modify( '+' . $rules['horizon_days'] . ' days' )->format( 'Y-m-d' ) : '';
        if ( $rules['date_to'] && ( ! $max || $rules['date_to'] < $max ) ) { $max = $rules['date_to']; }
        return array( 'min_date' => $min, 'max_date' => $max );
    }

    private static function utc( $value ) {
        if ( ! is_string( $value ) ) { return false; }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
        return $date && $date->format( 'Y-m-d H:i:s' ) === $value ? $date->getTimestamp() : false;
    }

    /** One profile is one resource, shared by every service. Holds consume limits too. */
    public static function booking_windows( $profile_id, $from, $to, $now, $exclude = 0 ) {
        $posts = get_posts( array(
            'post_type' => Dendrila_Calendar_Native::BOOKING, 'post_status' => array( 'private', 'publish' ),
            'numberposts' => 2001, 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true,
            'meta_query' => array(
                array( 'key' => Dendrila_Calendar_Native::PROFILE_KEY, 'value' => absint( $profile_id ), 'compare' => '=' ),
                array( 'key' => Dendrila_Calendar_Native::START_KEY, 'value' => gmdate( 'Y-m-d H:i:s', $to ), 'compare' => '<', 'type' => 'DATETIME' ),
                array( 'key' => Dendrila_Calendar_Native::END_KEY, 'value' => gmdate( 'Y-m-d H:i:s', $from ), 'compare' => '>', 'type' => 'DATETIME' ),
            ),
        ) );
        if ( count( $posts ) > 2000 ) { return new WP_Error( 'dendrila_booking_limit', __( 'Availability is temporarily unavailable. Please contact the organizer.', 'dendrila-calendar' ) ); }
        $rows = array();
        foreach ( $posts as $post ) {
            if ( (int) $post->ID === (int) $exclude ) { continue; }
            wp_cache_delete( $post->ID, 'post_meta' );
            $status = get_post_meta( $post->ID, Dendrila_Calendar_Native::STATUS_KEY, true );
            if ( ! in_array( $status, array( 'confirmed', 'pending_payment', 'payment_on_hold' ), true ) ) { continue; }
            if ( 'pending_payment' === $status && (int) get_post_meta( $post->ID, '_dendrila_booking_hold_until', true ) <= $now ) { continue; }
            $start = self::utc( get_post_meta( $post->ID, Dendrila_Calendar_Native::START_KEY, true ) );
            $end = self::utc( get_post_meta( $post->ID, Dendrila_Calendar_Native::END_KEY, true ) );
            if ( false === $start || false === $end || $start >= $end ) { return new WP_Error( 'dendrila_booking_corrupt', __( 'Availability is temporarily unavailable. Please contact the organizer.', 'dendrila-calendar' ) ); }
            $rows[] = array(
                'id' => (int) $post->ID, 'service_id' => (int) get_post_meta( $post->ID, Dendrila_Calendar_Native::SERVICE_KEY, true ),
                'start' => $start, 'end' => $end,
                'busy_start' => $start - min( 1440, absint( get_post_meta( $post->ID, '_dendrila_booking_buffer_before', true ) ) ) * 60,
                'busy_end' => $end + min( 1440, absint( get_post_meta( $post->ID, '_dendrila_booking_buffer_after', true ) ) ) * 60,
            );
        }
        return $rows;
    }

    /** Do not expose titles, guests or occupied event details to a public visitor. */
    private static function other_busy( $from, $to, $profile ) {
        $busy = array();
        $locals = get_posts( array(
            'post_type' => Dendrila_Calendar::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 1001,
            'meta_query' => array(
                array( 'key' => Dendrila_Calendar::START_KEY, 'value' => gmdate( 'Y-m-d H:i:s', $to ), 'compare' => '<', 'type' => 'DATETIME' ),
                array( 'key' => Dendrila_Calendar::END_KEY, 'value' => gmdate( 'Y-m-d H:i:s', $from ), 'compare' => '>', 'type' => 'DATETIME' ),
            ),
        ) );
        if ( count( $locals ) > 1000 ) { return false; }
        foreach ( $locals as $post ) {
            wp_cache_delete( $post->ID, 'post_meta' );
            if ( ! $profile['check_external'] && get_post_meta( $post->ID, '_dendrila_calendar_import_connection', true ) ) { continue; }
            $left = self::utc( get_post_meta( $post->ID, Dendrila_Calendar::START_KEY, true ) );
            $right = self::utc( get_post_meta( $post->ID, Dendrila_Calendar::END_KEY, true ) );
            if ( false === $left || false === $right || $left >= $right ) { return false; }
            $busy[] = array( $left, $right );
        }
        if ( $profile['check_external'] && Dendrila_Calendar_OAuth::connections() ) {
            // Provider requests are bounded; large buffers and a DST day can exceed 48 hours.
            for ( $cursor = $from; $cursor < $to; $cursor = $next ) {
                $next = min( $to, $cursor + 47 * 3600 );
                $remote = Dendrila_Calendar_OAuth::busy_intervals( gmdate( 'Y-m-d\TH:i:s\Z', $cursor ), gmdate( 'Y-m-d\TH:i:s\Z', $next ), false );
                if ( false === $remote ) { return false; }
                $busy = array_merge( $busy, $remote );
            }
        }
        return $busy;
    }

    private static function quota_reached( $rows, $day, $timezone, $profile, $rules, $service_id ) {
        $date = new DateTimeImmutable( $day . ' 00:00:00', $timezone );
        $periods = array(
            'day' => array( $date, $date->modify( '+1 day' ) ),
            'week' => array( $date->modify( 'monday this week' ), $date->modify( 'monday this week' )->modify( '+7 days' ) ),
            'month' => array( $date->modify( 'first day of this month' ), $date->modify( 'first day of next month' ) ),
        );
        foreach ( $periods as $key => $span ) {
            $all = 0; $own = 0;
            foreach ( $rows as $row ) {
                if ( $row['start'] >= $span[0]->getTimestamp() && $row['start'] < $span[1]->getTimestamp() ) {
                    ++$all; if ( (int) $row['service_id'] === (int) $service_id ) { ++$own; }
                }
            }
            if ( ( $profile[ 'max_per_' . $key ] && $all >= $profile[ 'max_per_' . $key ] ) ||
                 ( $rules[ 'max_per_' . $key ] && $own >= $rules[ 'max_per_' . $key ] ) ) { return true; }
        }
        return false;
    }

    /** Test wall-clock points on both sides of DST transitions, never normalize a missing time. */
    private static function fits_range( $start, $end, $day, $range, $timezone ) {
        $points = array( $start, $end - 1 );
        $transitions = $timezone->getTransitions( $start, $end );
        foreach ( is_array( $transitions ) ? $transitions : array() as $change ) {
            if ( $change['ts'] > $start && $change['ts'] < $end ) { $points[] = $change['ts']; $points[] = $change['ts'] - 1; }
        }
        foreach ( $points as $point ) {
            $local = ( new DateTimeImmutable( '@' . $point ) )->setTimezone( $timezone );
            if ( $local->format( 'Y-m-d' ) !== $day || $local->format( 'H:i' ) < $range['start'] || $local->format( 'H:i' ) >= $range['end'] ) { return false; }
        }
        return true;
    }

    public static function slots( $service_id, $day, $now = null, $exclude = 0, $ignore_notice = false ) {
        $now = null === $now ? time() : (int) $now;
        $service = Dendrila_Calendar_Native::service_data( $service_id );
        if ( ! $service || ! self::valid_day( $day ) ) { return new WP_Error( 'dendrila_invalid_service', __( 'Select a valid service and date.', 'dendrila-calendar' ) ); }
        $profile = self::profile( $service['profile_id'] ); $rules = self::rules( $service_id );
        if ( ! $profile['enabled'] || ! $rules['enabled'] ) { return array(); }
        $bounds = self::date_bounds( $service_id, $now );
        $tz = new DateTimeZone( $profile['timezone'] );
        $today = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz )->format( 'Y-m-d' );
        if ( $day < ( $ignore_notice ? $today : $bounds['min_date'] ) || ( $bounds['max_date'] && $day > $bounds['max_date'] ) ) { return array(); }
        $date = new DateTimeImmutable( $day . ' 00:00:00', $tz );
        if ( $date->format( 'Y-m-d' ) !== $day ) { return array(); }
        $ranges = array_key_exists( $day, $profile['overrides'] ) ? $profile['overrides'][ $day ] : $profile['weekly'][ (int) $date->format( 'N' ) ];
        if ( ! $ranges ) { return array(); }
        $left = $date->getTimestamp(); $right = $date->modify( '+1 day' )->getTimestamp();
        $month = $date->modify( 'first day of this month' ); $week = $date->modify( 'monday this week' );
        $query_start = min( $month->getTimestamp(), $week->getTimestamp(), $left ) - 2 * 86400;
        $query_end = max( $date->modify( 'first day of next month' )->getTimestamp(), $week->modify( '+7 days' )->getTimestamp(), $right ) + 2 * 86400;
        $rows = self::booking_windows( $service['profile_id'], $query_start, $query_end, $now, $exclude );
        if ( is_wp_error( $rows ) ) { return $rows; }
        if ( self::quota_reached( $rows, $day, $tz, $profile, $rules, $service_id ) ) { return array(); }
        $before = $service['buffer_before'] * 60; $after = $service['buffer_after'] * 60;
        $busy = self::other_busy( $left - $before, $right + $after, $profile );
        if ( false === $busy ) { return new WP_Error( 'dendrila_provider_unavailable', __( 'Calendar availability is temporarily unavailable. Please try again.', 'dendrila-calendar' ) ); }
        foreach ( $rows as $row ) { $busy[] = array( $row['busy_start'], $row['busy_end'] ); }
        $out = array(); $duration = $service['duration_minutes'] * 60;
        $earliest = $now + ( $ignore_notice ? 0 : $rules['notice_minutes'] * 60 );
        // Iterate real minutes: nonexistent spring times are absent; repeated fall times carry their offset.
        for ( $start = $left; $start < $right; $start += 60 ) {
            if ( $start < $earliest ) { continue; }
            $local = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( $tz );
            $minute = (int) $local->format( 'H' ) * 60 + (int) $local->format( 'i' );
            $end = $start + $duration; $fits = false;
            foreach ( $ranges as $range ) {
                $origin = (int) substr( $range['start'], 0, 2 ) * 60 + (int) substr( $range['start'], 3 );
                if ( $minute >= $origin && 0 === ( $minute - $origin ) % $rules['slot_step'] && self::fits_range( $start, $end, $day, $range, $tz ) ) { $fits = true; break; }
            }
            if ( ! $fits ) { continue; }
            foreach ( $busy as $span ) {
                if ( $span[0] < $end + $after && $span[1] > $start - $before ) { $fits = false; break; }
            }
            if ( $fits ) {
                $out[] = array( 'start' => gmdate( 'Y-m-d\TH:i:s\Z', $start ), 'end' => gmdate( 'Y-m-d\TH:i:s\Z', $end ), 'label' => $local->format( 'H:i' ), 'offset' => $local->format( 'P' ) );
            }
        }
        return $out;
    }

    public static function validate_slot( $service_id, $start_utc, $end_utc, $exclude = 0, $ignore_notice = false ) {
        $service = Dendrila_Calendar_Native::service_data( $service_id );
        $start = self::utc( $start_utc );
        if ( ! $service || false === $start ) { return new WP_Error( 'dendrila_invalid_slot', __( 'Select a valid available time.', 'dendrila-calendar' ) ); }
        $profile = self::profile( $service['profile_id'] );
        $day = ( new DateTimeImmutable( '@' . $start ) )->setTimezone( new DateTimeZone( $profile['timezone'] ) )->format( 'Y-m-d' );
        $slots = self::slots( $service_id, $day, null, $exclude, $ignore_notice );
        if ( is_wp_error( $slots ) ) { return $slots; }
        foreach ( $slots as $slot ) {
            if ( $slot['start'] === str_replace( ' ', 'T', $start_utc ) . 'Z' && $slot['end'] === str_replace( ' ', 'T', $end_utc ) . 'Z' ) { return true; }
        }
        return new WP_Error( 'dendrila_slot_taken', __( 'This time is no longer available. Please choose another.', 'dendrila-calendar' ) );
    }
}
