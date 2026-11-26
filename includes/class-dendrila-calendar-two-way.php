<?php
/** Reconcile editable, single native events with one explicitly selected calendar. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Calendar_Two_Way {
    const ORIGIN = '_dendrila_calendar_import_connection';
    const REMOTE = '_dendrila_calendar_import_identity';
    const ALL_DAY = '_dendrila_calendar_all_day';

    private static function still_connected( $connection, $item ) {
        $connections = Dendrila_Calendar_OAuth::connections();
        $current = $connections[ $connection ] ?? array();
        if ( empty( $current['two_way'] ) || ( $current['provider'] ?? '' ) !== $item['provider'] ||
            ( $current['calendar'] ?? '' ) !== $item['calendar'] ) {
            throw new RuntimeException( 'Connection changed; synchronization stopped' );
        }
    }

    /** Finish every page before making any changes. A partial list never means deletion. */
    private static function inventory( $item, $access, $start, $end ) {
        $google = 'google' === $item['provider'];
        $collection = Dendrila_Calendar_OAuth::calendar_url( $item );
        $base = $google ? $collection : substr( $collection, 0, -6 ) . 'calendarView';
        $params = $google ? array( 'timeMin' => $start, 'timeMax' => $end, 'maxResults' => 100, 'singleEvents' => 'false' )
            : array( 'startDateTime' => $start, 'endDateTime' => $end, '$top' => 100,
                '$select' => 'id,subject,start,end,isAllDay,isCancelled,type,changeKey,attendees,isOrganizer,transactionId' );
        $url = add_query_arg( $params, $base );
        $list = array();
        $seen = array();
        while ( $url ) {
            if ( ! is_string( $url ) || isset( $seen[ $url ] ) || count( $seen ) >= 100 ) {
                throw new RuntimeException( 'Incomplete calendar inventory' );
            }
            $seen[ $url ] = true;
            $response = Dendrila_Calendar_OAuth::api( $item['provider'], $access, 'GET', $url );
            $rows = $response['data'][ $google ? 'items' : 'value' ] ?? null;
            if ( 200 !== ( $response['status'] ?? 0 ) || ! is_array( $rows ) ) { throw new RuntimeException( 'Calendar read failed' ); }
            foreach ( $rows as $row ) {
                if ( is_array( $row ) && isset( $row['id'] ) && is_string( $row['id'] ) && '' !== $row['id'] ) {
                    $list[ $row['id'] ] = $row;
                }
            }
            $next = $response['data'][ $google ? 'nextPageToken' : '@odata.nextLink' ] ?? '';
            if ( ! is_string( $next ) ) { throw new RuntimeException( 'Invalid calendar pagination' ); }
            $url = $google && $next ? add_query_arg( $params + array( 'pageToken' => $next ), $base ) : $next;
        }
        return $list;
    }

    private static function date( $part, $all_day ) {
        $value = $part['date'] ?? $part['dateTime'] ?? '';
        if ( ! is_string( $value ) || '' === $value ) { throw new RuntimeException( 'Missing event time' ); }
        if ( $all_day ) {
            $value = substr( $value, 0, 10 );
            $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
            $errors = DateTimeImmutable::getLastErrors();
            if ( ! $date || ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d' ) !== $value ) {
                throw new RuntimeException( 'Invalid all-day date' );
            }
            return $date->getTimestamp();
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})?$/D', $value ) ) {
            throw new RuntimeException( 'Invalid event time' );
        }
        // Graph's DateTimeOffset may contain seven fractional digits; PHP uses six.
        $value = preg_replace( '/(\.\d{6})\d/', '$1', $value );
        $date = new DateTimeImmutable( $value, new DateTimeZone( $part['timeZone'] ?? 'UTC' ) );
        $errors = DateTimeImmutable::getLastErrors();
        if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) { throw new RuntimeException( 'Invalid event date' ); }
        return $date->getTimestamp();
    }

    /** Meetings are excluded: updating them could notify attendees or change their invitation. */
    public static function normalize( $row ) {
        if ( ! is_array( $row ) || ! empty( $row['recurrence'] ) || ! empty( $row['recurringEventId'] ) ||
            ( isset( $row['type'] ) && 'singleInstance' !== $row['type'] ) ||
            ( isset( $row['eventType'] ) && 'default' !== $row['eventType'] ) ||
            ! empty( $row['attendees'] ) || ( isset( $row['isOrganizer'] ) && ! $row['isOrganizer'] ) ||
            ( isset( $row['organizer']['self'] ) && ! $row['organizer']['self'] ) ) { return null; }
        $all_day = ! empty( $row['isAllDay'] ) || ! empty( $row['start']['date'] );
        $title = sanitize_text_field( $row['summary'] ?? $row['subject'] ?? '' );
        $start = self::date( $row['start'] ?? array(), $all_day );
        $end = self::date( $row['end'] ?? array(), $all_day );
        if ( '' === $title || $start >= $end ) { return null; }
        return array( 'title' => $title, 'start' => gmdate( 'Y-m-d H:i:s', $start ), 'end' => gmdate( 'Y-m-d H:i:s', $end ), 'all_day' => $all_day );
    }

    private static function local( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post || Dendrila_Calendar::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) { return null; }
        if ( 'publish' !== $post->post_status ) { return false; }
        $start = get_post_meta( $post_id, Dendrila_Calendar::START_KEY, true );
        $end = get_post_meta( $post_id, Dendrila_Calendar::END_KEY, true );
        if ( ! $start || $end <= $start ) { return false; }
        return array( 'title' => sanitize_text_field( $post->post_title ), 'start' => $start, 'end' => $end, 'all_day' => (bool) get_post_meta( $post_id, self::ALL_DAY, true ) );
    }

    public static function signature( $event ) {
        return hash( 'sha256', wp_json_encode( $event ) );
    }

    private static function save_local( $event, $id, $connection, $remote ) {
        $post = array( 'post_type' => Dendrila_Calendar::POST_TYPE, 'post_status' => 'publish', 'post_title' => $event['title'],
            'meta_input' => array( Dendrila_Calendar::START_KEY => $event['start'], Dendrila_Calendar::END_KEY => $event['end'], self::ALL_DAY => (int) $event['all_day'] ) );
        if ( $id ) { $post['ID'] = $id; }
        else {
            $post['meta_input'][ self::ORIGIN ] = $connection;
            $post['meta_input'][ self::REMOTE ] = hash( 'sha256', $connection . '|' . $remote );
        }
        $id = wp_insert_post( wp_slash( $post ), true );
        if ( is_wp_error( $id ) || ! $id || self::local( $id ) !== $event ) { throw new RuntimeException( 'Local update failed' ); }
        return $id;
    }

    private static function payload( $provider, $event ) {
        $dates = $event;
        foreach ( array( 'start', 'end' ) as $key ) { $dates[ $key ] = str_replace( ' ', 'T', $event[ $key ] ) . 'Z'; }
        $body = Dendrila_Calendar_OAuth::format_event( $provider, $dates );
        // Updating a title or date must not change the owner's free/busy choice.
        unset( $body['showAs'] );
        return $body;
    }

    private static function link( $remote, $event, $generation = 0 ) {
        return array( 'remote' => $remote, 'baseline' => self::signature( $event ), 'generation' => $generation );
    }

    private static function create_remote( $item, $access, $connection, $post_id, $local, $remote, $generation = 0 ) {
        $key = Dendrila_Calendar_Bridge::identity( array( 'source' => 'native', 'id' => 'native-' . $post_id ) );
        $identifier = substr( hash( 'sha256', $connection . '|' . $key . '|' . $generation ), 0, 36 );
        $google = 'google' === $item['provider'];
        $body = self::payload( $item['provider'], $local );
        $body[ $google ? 'id' : 'transactionId' ] = $google ? 'dc' . $identifier : $identifier;
        // Recover a previous successful write whose HTTP response was lost.
        $found = null;
        foreach ( $remote as $row ) {
            if ( ( $google && ( $row['id'] ?? '' ) === $body['id'] ) ||
                ( ! $google && ( $row['transactionId'] ?? '' ) === $identifier ) ) { $found = $row; break; }
        }
        $result = $found ? array( 'status' => 200, 'data' => $found ) :
            null;
        if ( ! $result ) {
            self::still_connected( $connection, $item );
            $result = Dendrila_Calendar_OAuth::api( $item['provider'], $access, 'POST', Dendrila_Calendar_OAuth::calendar_url( $item ), $body );
        }
        if ( $google && 409 === ( $result['status'] ?? 0 ) ) {
            $result = Dendrila_Calendar_OAuth::api( 'google', $access, 'GET', Dendrila_Calendar_OAuth::calendar_url( $item, $body['id'] ) );
            $found = $result['data'] ?? null;
        }
        $rid = $result['data']['id'] ?? '';
        if ( ! is_string( $rid ) || '' === $rid || ! in_array( $result['status'] ?? 0, array( 200, 201 ), true ) ) {
            throw new RuntimeException( 'Remote creation failed' );
        }
        if ( $found && ( 'cancelled' === ( $found['status'] ?? '' ) || ! empty( $found['isCancelled'] ) ) ) {
            throw new RuntimeException( 'Remote event was deleted' );
        }
        // With an interrupted creation there is no trustworthy baseline if the copies differ.
        $baseline = $found && self::normalize( $found ) !== $local ? '' : self::signature( $local );
        return array( 'remote' => $rid, 'baseline' => $baseline, 'generation' => $generation );
    }

    /** Reconcile a linked pair. Tombstones let an explicitly restored event be synchronized again. */
    private static function reconcile( $item, $access, $connection, $post_id, $link, $remote, $inventory ) {
        $local = self::local( $post_id );
        if ( false === $local ) { return $link; } // Drafts and malformed dates are never exported or erased.
        if ( null === $local && self::signature( null ) === $link['baseline'] && null === $remote ) { return $link; }
        $url = Dendrila_Calendar_OAuth::calendar_url( $item, $link['remote'] );
        if ( null === $remote ) {
            $result = Dendrila_Calendar_OAuth::api( $item['provider'], $access, 'GET', $url );
            $status = $result['status'] ?? 0;
            if ( ! in_array( $status, array( 200, 404, 410 ), true ) ) { throw new RuntimeException( 'Remote lookup failed' ); }
            $remote = 200 === $status ? $result['data'] : array( 'status' => 'cancelled' );
        }
        $deleted = 'cancelled' === ( $remote['status'] ?? '' ) || ! empty( $remote['isCancelled'] );
        $remote_event = $deleted ? null : self::normalize( $remote );
        if ( ! $deleted && null === $remote_event ) { return $link; }
        $old = $link['baseline'];
        $lh = self::signature( $local );
        $rh = self::signature( $remote_event );
        $generation = (int) ( $link['generation'] ?? 0 );
        if ( $lh === $rh ) { return self::link( $link['remote'], $local, $generation ); }
        if ( $lh !== $old && $rh !== $old ) { throw new RuntimeException( 'Both versions changed', 409 ); }
        if ( $rh !== $old ) {
            self::still_connected( $connection, $item );
            if ( self::local( $post_id ) !== $local ) { throw new RuntimeException( 'Local event changed', 409 ); }
            if ( $deleted ) {
                if ( ! EMPTY_TRASH_DAYS || ! wp_trash_post( $post_id ) ) { throw new RuntimeException( 'Cannot move event to Trash' ); }
            } else { self::save_local( $remote_event, $post_id, $connection, $link['remote'] ); }
            return self::link( $link['remote'], $remote_event, $generation );
        }
        if ( $deleted && $local ) {
            return self::create_remote( $item, $access, $connection, $post_id, $local, $inventory, $generation + 1 );
        }
        $etag = $remote['etag'] ?? $remote['@odata.etag'] ?? '';
        if ( ! is_string( $etag ) || '' === $etag ) { throw new RuntimeException( 'Missing remote revision' ); }
        self::still_connected( $connection, $item );
        $response = Dendrila_Calendar_OAuth::api( $item['provider'], $access, $local ? 'PATCH' : 'DELETE', $url,
            $local ? self::payload( $item['provider'], $local ) : null, $etag );
        if ( 412 === ( $response['status'] ?? 0 ) ) { throw new RuntimeException( 'Remote event changed', 409 ); }
        if ( ! in_array( $response['status'] ?? 0, array( 200, 204 ), true ) ) { throw new RuntimeException( 'Remote update failed' ); }
        return self::link( $link['remote'], $local, $generation );
    }

    /** Returns bounded error and conflict information; never includes provider payloads or tokens. */
    public static function sync( $connection, $item, $access, $events, $from, $until ) {
        $option = 'dc_two_way_links_' . $connection;
        $links = get_option( $option, array() );
        $links = is_array( $links ) ? $links : array();
        $remote = self::inventory( $item, $access, $from, $until );
        self::still_connected( $connection, $item );
        $reserved = array_column( $item['map'] ?? array(), 'remote_id' );
        $report = array( 'errors' => 0, 'conflicts' => array(), 'imported' => 0, 'exported' => 0 );
        // Adopt native links from outbound mode. Other plugins' bookings remain export-only.
        foreach ( $events as $event ) {
            if ( 'native' !== $event['source'] ) { continue; }
            $local_id = (int) substr( $event['id'], 7 );
            $legacy = $item['map'][ Dendrila_Calendar_Bridge::identity( $event ) ] ?? array();
            if ( ! isset( $links[ $local_id ] ) && ! empty( $legacy['remote_id'] ) ) {
                $local = self::local( $local_id );
                $baseline = $local && Dendrila_Calendar_Bridge::fingerprint( $event ) === ( $legacy['fingerprint'] ?? '' ) ? self::signature( $local ) : '';
                $links[ $local_id ] = array( 'remote' => $legacy['remote_id'], 'baseline' => $baseline, 'generation' => 0 );
            }
        }
        $handled = array_fill_keys( array_merge( $reserved, array_column( $links, 'remote' ) ), true );
        foreach ( $links as $id => $link ) {
            self::still_connected( $connection, $item );
            try {
                $links[ $id ] = self::reconcile( $item, $access, $connection, $id, $link, $remote[ $link['remote'] ] ?? null, $remote );
                $handled[ $links[ $id ]['remote'] ] = true;
                update_option( $option, $links, false );
            } catch ( Throwable $error ) {
                if ( 409 === $error->getCode() ) { $report['conflicts'][] = (int) $id; }
                else { ++$report['errors']; }
            }
        }
        foreach ( $events as $event ) {
            self::still_connected( $connection, $item );
            if ( 'native' !== $event['source'] ) { continue; }
            $id = (int) substr( $event['id'], 7 );
            if ( isset( $links[ $id ] ) || get_post_meta( $id, self::ORIGIN, true ) ) { continue; }
            $local = self::local( $id );
            if ( ! $local ) { continue; }
            try {
                $links[ $id ] = self::create_remote( $item, $access, $connection, $id, $local, $remote );
                $handled[ $links[ $id ]['remote'] ] = true;
                if ( '' === $links[ $id ]['baseline'] ) { $report['conflicts'][] = $id; }
                ++$report['exported'];
                update_option( $option, $links, false );
            } catch ( Throwable $error ) { ++$report['errors']; }
        }
        foreach ( $remote as $rid => $row ) {
            self::still_connected( $connection, $item );
            if ( isset( $handled[ $rid ] ) || 'cancelled' === ( $row['status'] ?? '' ) || ! empty( $row['isCancelled'] ) ) { continue; }
            try {
                $event = self::normalize( $row );
                if ( ! $event ) { continue; }
                $existing = get_posts( array( 'post_type' => Dendrila_Calendar::POST_TYPE, 'post_status' => array( 'publish', 'trash', 'draft', 'pending', 'private', 'future' ),
                    'fields' => 'ids', 'numberposts' => 1, 'meta_key' => self::REMOTE, 'meta_value' => hash( 'sha256', $connection . '|' . $rid ) ) );
                $id = $existing ? (int) $existing[0] : self::save_local( $event, 0, $connection, $rid );
                $links[ $id ] = self::link( $rid, $event );
                if ( $existing && self::local( $id ) !== $event ) {
                    $links[ $id ]['baseline'] = '';
                    $report['conflicts'][] = $id;
                }
                ++$report['imported'];
                update_option( $option, $links, false );
            } catch ( Throwable $error ) { ++$report['errors']; }
        }
        update_option( $option, $links, false );
        $report['conflicts'] = array_values( array_unique( $report['conflicts'] ) );
        return $report;
    }
}
