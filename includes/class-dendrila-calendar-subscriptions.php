<?php
/** Revocable, read-only live calendar subscriptions. Never stores a plaintext feed token. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar_Subscriptions {
    const OPTION = 'dendrila_calendar_subscriptions';
    const MAX_FEEDS = 12;
    const MAX_EVENTS = 2500;

    public static function boot() {
        add_action( 'wp_ajax_dendrila_calendar_create_subscription', array( __CLASS__, 'create' ) );
        add_action( 'wp_ajax_dendrila_calendar_revoke_subscription', array( __CLASS__, 'revoke' ) );
        // The query-string route works with both pretty and plain permalinks.
        add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
    }

    public static function subscriptions() {
        $stored = get_option( self::OPTION, array() );
        return is_array( $stored ) ? $stored : array();
    }

    private static function save( $subscriptions ) {
        // Never autoload a list of token hashes on regular front-end page requests.
        return update_option( self::OPTION, $subscriptions, false );
    }

    public static function allowed_sources( $requested, $available ) {
        if ( ! is_array( $requested ) || ! is_array( $available ) ) {
            return array();
        }
        $allowed = array();
        foreach ( $requested as $source ) {
            if ( is_string( $source ) && array_key_exists( $source, $available ) && ! in_array( $source, $allowed, true ) ) {
                $allowed[] = $source;
            }
        }
        return $allowed;
    }

    public static function lookup_token( $token, $subscriptions ) {
        if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{64}$/D', $token ) || ! is_array( $subscriptions ) ) {
            return null;
        }
        $fingerprint = hash( 'sha256', $token );
        foreach ( $subscriptions as $id => $subscription ) {
            if ( is_array( $subscription ) && isset( $subscription['hash'] ) && is_string( $subscription['hash'] ) &&
                strlen( $subscription['hash'] ) === 64 && hash_equals( $subscription['hash'], $fingerprint ) ) {
                return $subscription;
            }
        }
        return null;
    }

    private static function authorized() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You cannot manage calendar subscriptions.', 'dendrila-calendar' ) ), 403 );
        }
    }

    public static function create() {
        self::authorized();
        check_ajax_referer( 'dendrila_calendar_subscription', 'nonce' );
        if ( 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) {
            wp_send_json_error( array( 'message' => __( 'HTTPS is required for private calendar subscriptions.', 'dendrila-calendar' ) ), 400 );
        }
        $available = Dendrila_Calendar_Sources::available();
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Subsequent sanitize_key() and allowed_sources() enforce a strict allowlist.
        $submitted = isset( $_POST['sources'] ) && is_array( $_POST['sources'] ) ? wp_unslash( $_POST['sources'] ) : array();
        $requested = array_map( 'sanitize_key', array_filter( $submitted, 'is_string' ) );
        $sources = self::allowed_sources( $requested, $available );
        if ( ! $sources ) {
            wp_send_json_error( array( 'message' => __( 'Select at least one calendar source.', 'dendrila-calendar' ) ), 400 );
        }
        $label = isset( $_POST['label'] ) && is_string( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
        $label = wp_html_excerpt( $label, 60, '' );
        if ( '' === trim( $label ) ) {
            $label = __( 'My calendar', 'dendrila-calendar' );
        }
        $subscriptions = self::subscriptions();
        if ( count( $subscriptions ) >= self::MAX_FEEDS ) {
            wp_send_json_error( array( 'message' => __( 'Maximum number of subscriptions reached. Revoke an old link first.', 'dendrila-calendar' ) ), 400 );
        }
        try {
            $token = bin2hex( random_bytes( 32 ) );
            $id = bin2hex( random_bytes( 8 ) );
        } catch ( Exception $error ) {
            wp_send_json_error( array( 'message' => __( 'Unable to generate a private link.', 'dendrila-calendar' ) ), 500 );
        }
        $created = gmdate( 'Y-m-d H:i:s' );
        $subscriptions[ $id ] = array(
            'hash' => hash( 'sha256', $token ),
            'name' => $label,
            'sources' => $sources,
            'created' => $created,
            'user_id' => get_current_user_id(),
        );
        if ( ! self::save( $subscriptions ) ) {
            wp_send_json_error( array( 'message' => __( 'The subscription could not be saved. Try again.', 'dendrila-calendar' ) ), 500 );
        }
        if ( class_exists( 'Dendrila_Calendar_Setup' ) ) {
            Dendrila_Calendar_Setup::destination_connected();
        }
        wp_send_json_success( array(
            'id' => $id,
            'name' => $label,
            'created' => $created,
            'sources' => $sources,
            // Only returned at creation. Nothing in the DB can recreate this URL.
            'url' => add_query_arg( 'dendrila_calendar_feed', $token, home_url( '/' ) ),
        ) );
    }

    public static function revoke() {
        self::authorized();
        check_ajax_referer( 'dendrila_calendar_subscription', 'nonce' );
        $id = isset( $_POST['id'] ) && is_string( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
        $subscriptions = self::subscriptions();
        if ( ! preg_match( '/^[a-f0-9]{16}$/D', $id ) || ! isset( $subscriptions[ $id ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Subscription not found.', 'dendrila-calendar' ) ), 404 );
        }
        unset( $subscriptions[ $id ] );
        self::save( $subscriptions );
        wp_send_json_success( array( 'id' => $id ) );
    }

    public static function event_uid( $event, $site ) {
        // Stable across fetches, token renewals and providers; does not expose booking IDs.
        return 'dc-' . substr( hash( 'sha256', $site . '|' . $event['source'] . '|' . $event['id'] ), 0, 32 ) . '@dendrila-calendar';
    }

    private static function escape_ical( $value ) {
        $value = (string) $value;
        // RFC 5545 TEXT escaping: backslash before comma/semicolon/newline.
        return str_replace(
            array( '\\', "\r\n", "\r", "\n", ';', ',' ),
            array( '\\\\', '\\n', '\\n', '\\n', '\\;', '\\,' ),
            $value
        );
    }

    private static function fold_line( $line ) {
        // Fold at 75 OCTETS (UTF-8 characters must not be split in the middle).
        $characters = preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $characters ) ) {
            return '';
        }
        $out = '';
        $octets = 0;
        foreach ( $characters as $char ) {
            $bytes = strlen( $char );
            if ( $octets + $bytes > 75 ) {
                $out .= "\r\n ";
                $octets = 1;
            }
            $out .= $char;
            $octets += $bytes;
        }
        return $out;
    }

    private static function utc_stamp( $date ) {
        try {
            return ( new DateTimeImmutable( $date ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
        } catch ( Exception $e ) {
            return '';
        }
    }

    public static function build_ical( $events, $source_labels, $site, $timezone, $now = null ) {
        $now = $now instanceof DateTimeImmutable ? $now : new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
        $stamp = $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
        $lines = array(
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Dendrila Calendar//NONSGML Dendrila Calendar 0.0.1//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Dendrila Calendar',
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        );
        $seen = array();
        foreach ( $events as $event ) {
            if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['source'] ) || empty( $event['start'] ) || empty( $event['end'] ) ||
                ! isset( $source_labels[ $event['source'] ] ) ) {
                continue;
            }
            $start = self::utc_stamp( $event['start'] );
            $end = self::utc_stamp( $event['end'] );
            if ( '' === $start || '' === $end || strcmp( $start, $end ) >= 0 ) {
                continue;
            }
            $uid = self::event_uid( $event, $site );
            if ( isset( $seen[ $uid ] ) ) {
                continue;
            }
            $seen[ $uid ] = true;
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . $stamp;
            if ( ! empty( $event['all_day'] ) ) {
                try {
                    $first = ( new DateTimeImmutable( $event['start'] ) )->setTimezone( $timezone )->format( 'Ymd' );
                    $last = ( new DateTimeImmutable( $event['end'] ) )->setTimezone( $timezone )->format( 'Ymd' );
                    if ( strcmp( $last, $first ) <= 0 ) {
                        // Invalid all-day interval: do not export a malformed event.
                        array_splice( $lines, -3 );
                        unset( $seen[ $uid ] );
                        continue;
                    }
                    $lines[] = 'DTSTART;VALUE=DATE:' . $first;
                    $lines[] = 'DTEND;VALUE=DATE:' . $last;
                } catch ( Exception $e ) {
                    array_splice( $lines, -3 );
                    unset( $seen[ $uid ] );
                    continue;
                }
            } else {
                $lines[] = 'DTSTART:' . $start;
                $lines[] = 'DTEND:' . $end;
            }
            $title = isset( $event['title'] ) ? $event['title'] : __( 'Event', 'dendrila-calendar' );
            $lines[] = 'SUMMARY:' . self::escape_ical( $title );
            $lines[] = 'CATEGORIES:' . self::escape_ical( $source_labels[ $event['source'] ] );
            // Do not include customer identities, URLs with admin tokens or payment data.
            if ( isset( $event['status'] ) && '' !== trim( (string) $event['status'] ) ) {
                $lines[] = 'DESCRIPTION:' . self::escape_ical( $event['status'] );
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode( "\r\n", array_map( array( __CLASS__, 'fold_line' ), $lines ) ) . "\r\n";
    }

    public static function events_for_feed( $sources, $start, $end ) {
        $events = array();
        $seen = array();
        $cursor = $start;
        while ( $cursor < $end && count( $events ) < self::MAX_EVENTS ) {
            $next = $cursor->modify( '+85 days' );
            if ( $next > $end ) {
                $next = $end;
            }
            $chunk = Dendrila_Calendar_Sources::events(
                $cursor->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
                $next->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
                $sources
            );
            foreach ( $chunk as $event ) {
                if ( empty( $event['source'] ) || empty( $event['id'] ) ) {
                    continue;
                }
                $identity = $event['source'] . ':' . $event['id'];
                if ( ! isset( $seen[ $identity ] ) ) {
                    $seen[ $identity ] = true;
                    $events[] = $event;
                    if ( count( $events ) >= self::MAX_EVENTS ) {
                        break;
                    }
                }
            }
            $cursor = $next;
        }
        return $events;
    }

    public static function serve() {
        // Public bearer-token endpoint: a WordPress nonce would prevent external calendar clients from fetching it.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only private feed, 64-char token verified below.
        if ( ! isset( $_GET['dendrila_calendar_feed'] ) ) {
            return;
        }
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard cache-plugin compatibility constant, not a plugin-defined API.
            define( 'DONOTCACHEPAGE', true );
        }
        // Public external calendars cannot supply WordPress nonces: their secret URL token is checked cryptographically below.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Authenticated bearer-token read, not a WordPress form.
        $token = isset( $_GET['dendrila_calendar_feed'] ) && is_string( $_GET['dendrila_calendar_feed'] ) ?
            sanitize_text_field( wp_unslash( $_GET['dendrila_calendar_feed'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $subscription = self::lookup_token( $token, self::subscriptions() );
        if ( ! $subscription ) {
            self::error_response( 404 );
        }
        if ( ! in_array( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET', array( 'GET', 'HEAD' ), true ) ) {
            header( 'Allow: GET, HEAD' );
            self::error_response( 405 );
        }
        $available = Dendrila_Calendar_Sources::available();
        $sources = self::allowed_sources( isset( $subscription['sources'] ) ? $subscription['sources'] : array(), $available );
        $now = new DateTimeImmutable( 'now', wp_timezone() );
        $start = $now->setTime( 0, 0 )->modify( '-30 days' );
        $end = $now->setTime( 0, 0 )->modify( '+365 days' );
        try {
            $events = self::events_for_feed( $sources, $start, $end );
            $calendar = self::build_ical( $events, $available, home_url( '/' ), wp_timezone() );
        } catch ( Throwable $e ) {
            self::error_response( 503 );
        }
        self::no_cache_headers();
        status_header( 200 );
        header( 'Content-Type: text/calendar; charset=UTF-8' );
        header( 'Content-Disposition: inline; filename="dendrila-calendar.ics"' );
        if ( 'HEAD' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' ) ) {
            echo $calendar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RFC 5545 serializer.
        }
        exit;
    }

    private static function no_cache_headers() {
        nocache_headers();
        header( 'Cache-Control: private, no-store, max-age=0' );
        header( 'Referrer-Policy: no-referrer' );
        header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
        header( 'X-Content-Type-Options: nosniff' );
    }

    private static function error_response( $code ) {
        self::no_cache_headers();
        status_header( $code );
        header( 'Content-Type: text/plain; charset=UTF-8' );
        echo 'Calendar unavailable.';
        exit;
    }

    public static function render_settings() {
        $sources = Dendrila_Calendar_Sources::available();
        echo '<section class="dendrila-subscriptions" aria-labelledby="dendrila-sync-heading">';
        echo '<h2 id="dendrila-sync-heading">' . esc_html__( 'Subscribe your personal calendar', 'dendrila-calendar' ) . '</h2>';
        echo '<p>' . esc_html__( 'Select your WordPress sources, create a private calendar URL, then subscribe to it in your calendar app. The app refreshes events automatically on its own schedule; this is a read-only subscription, not instant or two-way synchronization.', 'dendrila-calendar' ) . '</p>';
        echo '<p class="description">' . esc_html__( 'Treat the link as a password. Anyone holding it can read the selected event titles and dates. Only HTTPS sites can create links.', 'dendrila-calendar' ) . '</p>';
        echo '<div class="dendrila-sync-providers"><h3>' . esc_html__( 'Where will you use your calendar?', 'dendrila-calendar' ) . '</h3>';
        echo '<label for="dendrila-sync-provider">' . esc_html__( 'Show instructions for this application (not a connection)', 'dendrila-calendar' ) . '</label> ';
        echo '<select id="dendrila-sync-provider">';
        foreach ( array(
            'google' => 'Google Calendar',
            'apple' => 'Apple Calendar (iPhone / Mac)',
            'outlook' => 'Microsoft Outlook',
            'nextcloud' => 'Nextcloud Calendar',
            'other' => 'Other iCalendar / ICS application',
        ) as $key => $label ) {
            echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Choosing an app only changes the instructions. Create an actual private link below and add it in that app.', 'dendrila-calendar' ) . '</p>';
        echo '<div id="dendrila-provider-instructions" class="dendrila-provider-instructions" aria-live="polite"></div></div>';
        echo '<form id="dendrila-subscription-form">';
        echo '<p><label for="dendrila-subscription-label">' . esc_html__( 'Subscription name (e.g. personal calendar)', 'dendrila-calendar' ) . '</label> ';
        echo '<input id="dendrila-subscription-label" name="label" type="text" maxlength="60" value="" placeholder="' . esc_attr__( 'My calendar', 'dendrila-calendar' ) . '"></p>';
        echo '<fieldset><legend>' . esc_html__( 'Sources to synchronize', 'dendrila-calendar' ) . '</legend>';
        foreach ( $sources as $id => $label ) {
            echo '<label class="dendrila-source-option"><input type="checkbox" name="sources[]" value="' . esc_attr( $id ) . '" checked> ' . esc_html( $label ) . '</label> ';
        }
        echo '</fieldset><p><button type="submit" class="button button-primary">' . esc_html__( 'Create subscription link', 'dendrila-calendar' ) . '</button></p></form>';
        echo '<div id="dendrila-subscription-result" role="status" aria-live="polite"></div>';
        echo '<ul id="dendrila-subscription-list" class="dendrila-subscription-list">';
        foreach ( self::subscriptions() as $id => $item ) {
            if ( ! is_array( $item ) || ! preg_match( '/^[a-f0-9]{16}$/D', (string) $id ) ) {
                continue;
            }
            $name = isset( $item['name'] ) ? $item['name'] : '';
            $created = isset( $item['created'] ) ? $item['created'] : '';
            echo '<li data-subscription-id="' . esc_attr( $id ) . '">';
            echo '<span>' . esc_html( $name ) . ' — ' . esc_html( $created ) . ' UTC</span> ';
            echo '<button type="button" class="button button-small dendrila-subscription-revoke" data-subscription-id="' . esc_attr( $id ) . '">' . esc_html__( 'Revoke link', 'dendrila-calendar' ) . '</button></li>';
        }
        echo '</ul><p class="description">' . esc_html__( 'The full link is displayed only once after creation. If it is lost, revoke it and create a new one.', 'dendrila-calendar' ) . '</p>';
        echo '</section>';
    }
}
