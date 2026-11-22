<?php
/** Read-only adapters. Third-party booking records are never modified. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar_Sources {
    public static function available() {
        $sources = array( 'native' => __( 'Dendrila events', 'dendrila-calendar' ) );
        if ( class_exists( '\\OryxBookings\\Repositories\\BookingRepository' ) ) {
            $sources['oryx'] = __( 'Oryx Bookings', 'dendrila-calendar' );
        }
        if ( post_type_exists( 'tribe_events' ) ) {
            $sources['tec'] = __( 'The Events Calendar', 'dendrila-calendar' );
        }
        /**
         * Register additional sources by their unique, safe identifier and display name.
         * Handlers are installed with the dendrila_calendar_source_events_{id} filter.
         */
        $sources = apply_filters( 'dendrila_calendar_sources', $sources );
        if ( ! is_array( $sources ) ) {
            return array();
        }
        $clean = array();
        foreach ( $sources as $key => $label ) {
            if ( is_string( $key ) && preg_match( '/^[a-z][a-z0-9_-]{0,31}$/D', $key ) && is_string( $label ) ) {
                $clean[ $key ] = sanitize_text_field( $label );
            }
        }
        return $clean;
    }

    private static function iso( $utc ) {
        try {
            return ( new DateTimeImmutable( (string) $utc, new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d\TH:i:s\Z' );
        } catch ( Exception $e ) {
            return '';
        }
    }

    public static function events( $start_utc, $end_utc, $requested ) {
        $events = array();
        foreach ( $requested as $source ) {
            try {
                if ( 'native' === $source ) {
                    $items = self::native_events( $start_utc, $end_utc );
                } elseif ( 'oryx' === $source ) {
                    $items = self::oryx_events( $start_utc, $end_utc );
                } elseif ( 'tec' === $source ) {
                    $items = self::tec_events( $start_utc, $end_utc );
                } else {
                    $items = apply_filters( 'dendrila_calendar_source_events_' . $source, array(), $start_utc, $end_utc );
                }
                if ( ! is_array( $items ) ) {
                    continue;
                }
                foreach ( array_slice( $items, 0, 1000 ) as $item ) {
                    if ( ! is_array( $item ) || ! isset( $item['title'], $item['start'], $item['end'] ) ) {
                        continue;
                    }
                    $events[] = array(
                        'id' => sanitize_text_field( (string) ( $item['id'] ?? '' ) ),
                        'source' => $source,
                        'title' => sanitize_text_field( (string) $item['title'] ),
                        'start' => sanitize_text_field( (string) $item['start'] ),
                        'end' => sanitize_text_field( (string) $item['end'] ),
                        'status' => sanitize_text_field( (string) ( $item['status'] ?? '' ) ),
                        'url' => isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '',
                    );
                }
            } catch ( Throwable $e ) {
                // A broken optional source must not take down the entire calendar.
                continue;
            }
        }
        usort( $events, static function ( $a, $b ) {
            return strcmp( $a['start'], $b['start'] );
        } );
        return array_slice( $events, 0, 2000 );
    }

    private static function native_events( $start, $end ) {
        $posts = get_posts( array(
            'post_type' => Dendrila_Calendar::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => 500,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(
                'relation' => 'AND',
                array( 'key' => Dendrila_Calendar::START_KEY, 'value' => $end, 'compare' => '<', 'type' => 'DATETIME' ),
                array( 'key' => Dendrila_Calendar::END_KEY, 'value' => $start, 'compare' => '>', 'type' => 'DATETIME' ),
            ),
        ) );
        $results = array();
        foreach ( $posts as $post ) {
            $event_start = self::iso( get_post_meta( $post->ID, Dendrila_Calendar::START_KEY, true ) );
            $event_end = self::iso( get_post_meta( $post->ID, Dendrila_Calendar::END_KEY, true ) );
            if ( $event_start && $event_end ) {
                $results[] = array(
                    'id' => 'native-' . $post->ID,
                    'title' => get_the_title( $post ),
                    'start' => $event_start,
                    'end' => $event_end,
                    'url' => get_edit_post_link( $post->ID, '' ),
                );
            }
        }
        return $results;
    }

    private static function oryx_events( $start, $end ) {
        if ( ! class_exists( '\\OryxBookings\\Repositories\\BookingRepository' ) ) {
            return array();
        }
        $repository = new \OryxBookings\Repositories\BookingRepository();
        $bookings = $repository->get_by_date_range( $start, $end );
        $results = array();
        foreach ( array_slice( $bookings, 0, 1000 ) as $booking ) {
            if ( empty( $booking->start_time_utc ) || empty( $booking->end_time_utc ) ) {
                continue;
            }
            $results[] = array(
                'id' => 'oryx-' . absint( $booking->id ),
                'title' => (string) ( $booking->service_name ?? __( 'Booking', 'dendrila-calendar' ) ),
                'start' => self::iso( $booking->start_time_utc ),
                'end' => self::iso( $booking->end_time_utc ),
                'status' => (string) ( $booking->status ?? '' ),
                'url' => admin_url( 'admin.php?page=oryx-bookings-calendar' ),
            );
        }
        return $results;
    }

    private static function tec_events( $start, $end ) {
        if ( ! post_type_exists( 'tribe_events' ) ) {
            return array();
        }
        // Single occurrences only in this first adapter. Recurrence support is planned.
        $posts = get_posts( array(
            'post_type' => 'tribe_events',
            'post_status' => 'publish',
            'numberposts' => 500,
            'meta_query' => array(
                'relation' => 'AND',
                array( 'key' => '_EventStartDateUTC', 'value' => $end, 'compare' => '<', 'type' => 'DATETIME' ),
                array( 'key' => '_EventEndDateUTC', 'value' => $start, 'compare' => '>', 'type' => 'DATETIME' ),
            ),
        ) );
        $results = array();
        foreach ( $posts as $post ) {
            if ( function_exists( 'tribe_is_recurring_event' ) && tribe_is_recurring_event( $post->ID ) ) {
                continue;
            }
            $event_start = self::iso( get_post_meta( $post->ID, '_EventStartDateUTC', true ) );
            $event_end = self::iso( get_post_meta( $post->ID, '_EventEndDateUTC', true ) );
            if ( $event_start && $event_end ) {
                $results[] = array(
                    'id' => 'tec-' . $post->ID,
                    'title' => get_the_title( $post ),
                    'start' => $event_start,
                    'end' => $event_end,
                    'url' => get_edit_post_link( $post->ID, '' ),
                );
            }
        }
        return $results;
    }
}
