<?php
/**
 * Google/Microsoft authorization, outbound bookings and opt-in two-way local events.
 * OAuth application IDs/secrets belong to the plugin publisher, never to users.
 * This connector is deliberately unavailable until publisher credentials exist.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar_OAuth {
    const OPTION = 'dendrila_calendar_oauth_connections';
    const CREDENTIALS_OPTION = 'dendrila_calendar_oauth_app_credentials';
    const ARCHIVES_OPTION = 'dendrila_calendar_oauth_destination_history';
    const MAX_CONNECTIONS = 5;

    public static function boot() {
        add_action( 'admin_post_dendrila_calendar_oauth_start', array( __CLASS__, 'start' ) );
        add_action( 'admin_post_dendrila_calendar_oauth_save_credentials', array( __CLASS__, 'save_credentials' ) );
        // Microsoft personal accounts reject query strings in OAuth redirect URIs.
        // admin-post.php dispatches requests without an action through admin_post.
        add_action( 'admin_post', array( __CLASS__, 'callback_if_state' ) );
        add_action( 'admin_post_dendrila_calendar_oauth_callback', array( __CLASS__, 'callback' ) );
        add_action( 'admin_post_dendrila_calendar_oauth_confirm', array( __CLASS__, 'confirm' ) );
        add_action( 'admin_post_dendrila_calendar_oauth_disconnect', array( __CLASS__, 'disconnect' ) );
        add_action( 'admin_post_dendrila_calendar_oauth_dedupe', array( __CLASS__, 'consolidate_duplicate' ) );
        add_action( 'admin_post_dendrila_calendar_oauth_sync', array( __CLASS__, 'sync_now' ) );
        add_action( 'admin_post_dendrila_calendar_oauth_mode', array( __CLASS__, 'save_mode' ) );
    }

    private static function config( $provider ) {
        $names = array(
            'google' => array( 'DENDRILA_CALENDAR_GOOGLE_CLIENT_ID', 'DENDRILA_CALENDAR_GOOGLE_CLIENT_SECRET' ),
            'microsoft' => array( 'DENDRILA_CALENDAR_MICROSOFT_CLIENT_ID', 'DENDRILA_CALENDAR_MICROSOFT_CLIENT_SECRET' ),
        );
        if ( ! isset( $names[ $provider ] ) ) {
            return array();
        }
        list( $id_name, $secret_name ) = $names[ $provider ];
        // Publisher-managed constants take precedence. For staging sites, admins can
        // enter encrypted credentials through the WordPress dashboard on mobile.
        $id = defined( $id_name ) && is_string( constant( $id_name ) ) ? trim( constant( $id_name ) ) : '';
        $secret = defined( $secret_name ) && is_string( constant( $secret_name ) ) ? trim( constant( $secret_name ) ) : '';
        if ( '' === $id || '' === $secret ) {
            $stored = get_option( self::CREDENTIALS_OPTION, array() );
            $entry = is_array( $stored ) && isset( $stored[ $provider ] ) && is_array( $stored[ $provider ] ) ? $stored[ $provider ] : array();
            $id = '' === $id ? ( $entry['id'] ?? '' ) : $id;
            $secret = '' === $secret && class_exists( 'Dendrila_Calendar_Bridge' ) ? Dendrila_Calendar_Bridge::reveal( $entry['secret'] ?? '' ) : $secret;
        }
        if ( ! is_string( $id ) || ! is_string( $secret ) || '' === trim( $id ) || '' === trim( $secret ) ) {
            return array();
        }
        $google = 'google' === $provider;
        return array(
            'id' => $id,
            'secret' => $secret,
            'authorize' => $google ? 'https://accounts.google.com/o/oauth2/v2/auth' : 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token' => $google ? 'https://oauth2.googleapis.com/token' : 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'scope' => $google ?
                'https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/calendar.events' :
                'offline_access Calendars.ReadWrite',
        );
    }

    /** Dashboard-only setup for a staging installation; never reveal saved secrets. */
    public static function save_credentials() {
        self::permitted();
        check_admin_referer( 'dendrila_calendar_oauth_save_credentials' );
        $provider = isset( $_POST['provider'] ) && is_string( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
        if ( ! in_array( $provider, array( 'google', 'microsoft' ), true ) ||
             'https' !== wp_parse_url( self::callback_url(), PHP_URL_SCHEME ) ) {
            self::redirect( 'invalid', $provider );
        }
        $submitted_id = isset( $_POST['client_id'] ) && is_string( $_POST['client_id'] ) ?
            trim( sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) ) : '';
        $submitted_secret = isset( $_POST['client_secret'] ) && is_string( $_POST['client_secret'] ) ?
            trim( wp_unslash( $_POST['client_secret'] ) ) : '';
        if ( strlen( $submitted_id ) > 512 || strlen( $submitted_secret ) > 4096 ||
             preg_match( '/[\\x00-\\x1f\\x7f]/', $submitted_id . $submitted_secret ) ) {
            self::redirect( 'invalid_credentials', $provider );
        }
        $stored = get_option( self::CREDENTIALS_OPTION, array() );
        if ( ! is_array( $stored ) ) {
            $stored = array();
        }
        $old = isset( $stored[ $provider ] ) && is_array( $stored[ $provider ] ) ? $stored[ $provider ] : array();
        // Empty fields intentionally keep previously saved values.
        $client_id = '' !== $submitted_id ? $submitted_id : ( $old['id'] ?? '' );
        $encrypted = '' !== $submitted_secret ? Dendrila_Calendar_Bridge::protect( $submitted_secret ) : ( $old['secret'] ?? '' );
        if ( ! is_string( $client_id ) || '' === $client_id || ! is_string( $encrypted ) ||
             false === Dendrila_Calendar_Bridge::reveal( $encrypted ) ) {
            self::redirect( 'invalid_credentials', $provider );
        }
        $stored[ $provider ] = array( 'id' => $client_id, 'secret' => $encrypted );
        update_option( self::CREDENTIALS_OPTION, $stored, false );
        self::redirect( 'credentials_saved', $provider );
    }

    public static function ready( $provider ) {
        return (bool) self::config( $provider );
    }

    public static function connections() {
        $saved = get_option( self::OPTION, array() );
        return is_array( $saved ) ? $saved : array();
    }

    /** Two authorizations for one provider/calendar should be one connection. */
    private static function same_destination( $item, $provider, $calendar ) {
        return is_array( $item ) &&
            isset( $item['provider'], $item['calendar'] ) &&
            $item['provider'] === $provider &&
            $item['calendar'] === $calendar;
    }

    private static function destination_key( $provider, $calendar ) {
        return hash( 'sha256', $provider . '|' . $calendar );
    }

    /** Null means new; legacy entries without a direction remain export-only. */
    private static function saved_destination_mode( $provider, $calendar ) {
        foreach ( self::connections() as $item ) {
            if ( self::same_destination( $item, $provider, $calendar ) ) {
                return ! empty( $item['two_way'] );
            }
        }
        $archives = get_option( self::ARCHIVES_OPTION, array() );
        $key = self::destination_key( $provider, $calendar );
        if ( is_array( $archives ) && isset( $archives[ $key ] ) && is_array( $archives[ $key ] ) ) {
            return ! empty( $archives[ $key ]['two_way'] );
        }
        return null;
    }

    private static function busy( $id ) {
        return get_transient( 'dc_oauth_sync_' . $id ) ||
            (int) get_option( 'dc_oauth_sync_' . $id . '.lock', 0 ) > time() - HOUR_IN_SECONDS;
    }

    private static function store( $items ) {
        return update_option( self::OPTION, $items, false );
    }

    private static function callback_url() {
        // Query-free callback works for Google, Microsoft 365 and Outlook.com.
        return admin_url( 'admin-post.php' );
    }

    private static function pending_key() {
        return 'dc_oauth_pending_' . get_current_user_id();
    }

    private static function state_key( $state ) {
        return 'dc_oauth_state_' . substr( hash( 'sha256', $state ), 0, 32 );
    }

    private static function redirect( $status, $provider = '' ) {
        wp_safe_redirect( add_query_arg(
            array( 'dendrila_method' => 'oauth', 'oauth_status' => $status, 'oauth_provider' => $provider ),
            admin_url( 'admin.php?page=dendrila-calendar-sync' )
        ) );
        exit;
    }

    private static function permitted() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You cannot manage calendar connections.', 'dendrila-calendar' ) );
        }
    }

    private static function random_urlsafe( $length = 32 ) {
        return rtrim( strtr( base64_encode( random_bytes( $length ) ), '+/', '-_' ), '=' );
    }

    /** Launch the provider authorization page; client secrets never reach HTML or JavaScript. */
    public static function start() {
        self::permitted();
        check_admin_referer( 'dendrila_calendar_oauth_start' );
        $provider = isset( $_POST['provider'] ) && is_string( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
        $config = self::config( $provider );
        if ( ! $config || 'https' !== wp_parse_url( self::callback_url(), PHP_URL_SCHEME ) ) {
            self::redirect( 'unavailable', $provider );
        }
        try {
            $state = self::random_urlsafe();
            $verifier = self::random_urlsafe( 48 );
        } catch ( Throwable $error ) {
            self::redirect( 'failed', $provider );
        }
        set_transient( self::state_key( $state ), array(
            'provider' => $provider,
            'user' => get_current_user_id(),
            'verifier' => $verifier,
        ), 10 * MINUTE_IN_SECONDS );
        $query = array(
            'client_id' => $config['id'],
            'redirect_uri' => self::callback_url(),
            'response_type' => 'code',
            'scope' => $config['scope'],
            'state' => $state,
            'code_challenge' => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
            'code_challenge_method' => 'S256',
        );
        if ( 'google' === $provider ) {
            $query['access_type'] = 'offline';
            $query['prompt'] = 'consent';
        }
        // Allow only this provider's fixed official OAuth host for this one redirect.
        $host = wp_parse_url( $config['authorize'], PHP_URL_HOST );
        $allow_host = static function ( $hosts ) use ( $host ) {
            $hosts[] = $host;
            return array_unique( $hosts );
        };
        add_filter( 'allowed_redirect_hosts', $allow_host );
        wp_safe_redirect( add_query_arg( $query, $config['authorize'] ), 302, 'Dendrila Calendar' );
        remove_filter( 'allowed_redirect_hosts', $allow_host );
        exit;
    }

    private static function token_request( $config, $body ) {
        $response = wp_safe_remote_post( $config['token'], array(
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'body' => array_merge( $body, array(
                'client_id' => $config['id'],
                'client_secret' => $config['secret'],
            ) ),
            'limit_response_size' => 30000,
        ) );
        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $data ) && ! empty( $data['access_token'] ) && is_string( $data['access_token'] ) ? $data : false;
    }

    public static function api( $provider, $access, $method, $url, $payload = null, $if_match = '' ) {
        // Never accept arbitrary hostnames or redirects when sending bearer tokens.
        $hosts = array( 'google' => 'www.googleapis.com', 'microsoft' => 'graph.microsoft.com' );
        $parts = wp_parse_url( $url );
        if ( ! isset( $hosts[ $provider ] ) || ! is_array( $parts ) ||
            'https' !== ( $parts['scheme'] ?? '' ) || $hosts[ $provider ] !== ( $parts['host'] ?? '' ) ||
            isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ||
            ( isset( $parts['port'] ) && 443 !== $parts['port'] ) ||
            0 !== strpos( $parts['path'] ?? '', 'google' === $provider ? '/calendar/v3/' : '/v1.0/' ) ) {
            return false;
        }
        $args = array(
            'method' => $method,
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array( 'Authorization' => 'Bearer ' . $access, 'Accept' => 'application/json' ),
            'limit_response_size' => 2000000,
        );
        if ( 'microsoft' === $provider ) { $args['headers']['Prefer'] = 'outlook.timezone="UTC"'; }
        if ( '' !== $if_match ) {
            if ( ! is_string( $if_match ) || preg_match( '/[\r\n]/', $if_match ) ) { return false; }
            $args['headers']['If-Match'] = $if_match;
        }
        if ( null !== $payload ) {
            $args['body'] = wp_json_encode( $payload );
            $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
        }
        $response = wp_safe_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return array(
            'status' => (int) wp_remote_retrieve_response_code( $response ),
            'data' => is_array( $body ) ? $body : array(),
        );
    }

    private static function calendars( $provider, $access ) {
        $url = 'google' === $provider ?
            'https://www.googleapis.com/calendar/v3/users/me/calendarList?minAccessRole=writer&maxResults=250' :
            'https://graph.microsoft.com/v1.0/me/calendars?$select=id,name,canEdit&$top=100';
        $response = self::api( $provider, $access, 'GET', $url );
        if ( ! $response || 200 !== $response['status'] ) {
            return false;
        }
        $items = 'google' === $provider ? ( $response['data']['items'] ?? array() ) : ( $response['data']['value'] ?? array() );
        $result = array();
        foreach ( (array) $items as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['id'] ) || ! is_string( $entry['id'] ) ) {
                continue;
            }
            if ( 'google' === $provider && ! in_array( $entry['accessRole'] ?? '', array( 'owner', 'writer' ), true ) ) {
                continue;
            }
            if ( 'microsoft' === $provider && isset( $entry['canEdit'] ) && ! $entry['canEdit'] ) {
                continue;
            }
            $name = 'google' === $provider ? ( $entry['summary'] ?? '' ) : ( $entry['name'] ?? '' );
            $result[ $entry['id'] ] = sanitize_text_field( (string) $name );
        }
        return $result;
    }

    /**
     * WordPress admin-post.php fires the admin_post hook when no action is given.
     * Only handle our one-time state, leaving unrelated admin-post requests alone.
     */
    public static function callback_if_state() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- One-time OAuth state is validated in callback().
        $state = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        if ( ! preg_match( '/^[A-Za-z0-9_-]{30,100}$/D', $state ) ||
             ! get_transient( self::state_key( $state ) ) ) {
            return;
        }
        self::callback();
    }

    /** Return from Google/Microsoft: state checked, then tokens encrypted server-side. */
    public static function callback() {
        self::permitted();
        // OAuth callback is authenticated with one-time unpredictable state, not a WordPress form nonce.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Provider redirects cannot carry WP nonces.
        $state = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        if ( ! preg_match( '/^[A-Za-z0-9_-]{30,100}$/D', $state ) ) {
            self::redirect( 'failed' );
        }
        $pending = get_transient( self::state_key( $state ) );
        delete_transient( self::state_key( $state ) ); // State can never be replayed.
        if ( ! is_array( $pending ) || (int) ( $pending['user'] ?? 0 ) !== get_current_user_id() ) {
            self::redirect( 'failed' );
        }
        $provider = $pending['provider'];
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth code is verified by state and provider token exchange.
        $code = isset( $_GET['code'] ) && is_string( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        if ( '' === $code || strlen( $code ) > 4096 ) {
            self::redirect( 'cancelled', $provider );
        }
        $config = self::config( $provider );
        if ( ! $config ) {
            self::redirect( 'unavailable', $provider );
        }
        $tokens = self::token_request( $config, array(
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::callback_url(),
            'code_verifier' => $pending['verifier'],
        ) );
        if ( ! $tokens || empty( $tokens['refresh_token'] ) || ! is_string( $tokens['refresh_token'] ) ) {
            self::redirect( 'failed', $provider );
        }
        $calendars = self::calendars( $provider, $tokens['access_token'] );
        if ( ! is_array( $calendars ) || ! $calendars ) {
            self::redirect( 'no_calendars', $provider );
        }
        $access = Dendrila_Calendar_Bridge::protect( $tokens['access_token'] );
        $refresh = Dendrila_Calendar_Bridge::protect( $tokens['refresh_token'] );
        if ( ! $access || ! $refresh ) {
            self::redirect( 'failed', $provider );
        }
        set_transient( self::pending_key(), array(
            'provider' => $provider,
            'access' => $access,
            'refresh' => $refresh,
            'expires' => time() + max( 60, (int) ( $tokens['expires_in'] ?? 3600 ) ) - 30,
            'calendars' => $calendars,
        ), 10 * MINUTE_IN_SECONDS );
        self::redirect( 'choose', $provider );
    }

    public static function calendar_url( $item, $event_id = '' ) {
        $calendar = rawurlencode( $item['calendar'] );
        if ( 'google' === $item['provider'] ) {
            $url = 'https://www.googleapis.com/calendar/v3/calendars/' . $calendar . '/events';
        } else {
            $url = 'https://graph.microsoft.com/v1.0/me/calendars/' . $calendar . '/events';
        }
        return $url . ( '' === $event_id ? '' : '/' . rawurlencode( $event_id ) );
    }

    public static function format_event( $provider, $event ) {
        $start = ( new DateTimeImmutable( $event['start'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
        $end = ( new DateTimeImmutable( $event['end'], new DateTimeZone( 'UTC' ) ) )->setTimezone( new DateTimeZone( 'UTC' ) );
        if ( 'google' === $provider ) {
            $dates = ! empty( $event['all_day'] ) ?
                array( 'start' => array( 'date' => $start->setTimezone( wp_timezone() )->format( 'Y-m-d' ), 'dateTime' => null, 'timeZone' => null ), 'end' => array( 'date' => $end->setTimezone( wp_timezone() )->format( 'Y-m-d' ), 'dateTime' => null, 'timeZone' => null ) ) :
                array( 'start' => array( 'dateTime' => $start->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i:sP' ), 'timeZone' => wp_timezone_string() ), 'end' => array( 'dateTime' => $end->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i:sP' ), 'timeZone' => wp_timezone_string() ) );
            return array_merge( array( 'summary' => $event['title'] ), $dates );
        }
        if ( ! empty( $event['all_day'] ) ) {
            // All-day dates are calendar dates, not instants shifted by the site's UTC offset.
            $start = new DateTimeImmutable( $start->setTimezone( wp_timezone() )->format( 'Y-m-d' ), new DateTimeZone( 'UTC' ) );
            $end = new DateTimeImmutable( $end->setTimezone( wp_timezone() )->format( 'Y-m-d' ), new DateTimeZone( 'UTC' ) );
        }
        return array(
            'subject' => $event['title'],
            'start' => array( 'dateTime' => $start->format( 'Y-m-d\TH:i:s' ), 'timeZone' => 'UTC' ),
            'end' => array( 'dateTime' => $end->format( 'Y-m-d\TH:i:s' ), 'timeZone' => 'UTC' ),
            'showAs' => 'busy',
            'isAllDay' => ! empty( $event['all_day'] ),
        );
    }

    private static function refresh_access( &$item ) {
        $token = Dendrila_Calendar_Bridge::reveal( $item['access'] ?? '' );
        if ( $token && (int) ( $item['expires'] ?? 0 ) > time() + 60 ) {
            return $token;
        }
        $refresh = Dendrila_Calendar_Bridge::reveal( $item['refresh'] ?? '' );
        $config = self::config( $item['provider'] ?? '' );
        if ( ! $refresh || ! $config ) {
            return false;
        }
        $response = self::token_request( $config, array(
            'grant_type' => 'refresh_token',
            'refresh_token' => $refresh,
        ) );
        if ( ! $response ) {
            return false;
        }
        $access = Dendrila_Calendar_Bridge::protect( $response['access_token'] );
        $rotated = ! empty( $response['refresh_token'] ) ?
            Dendrila_Calendar_Bridge::protect( $response['refresh_token'] ) : $item['refresh'];
        if ( ! $access || ! $rotated ) {
            return false;
        }
        $item['access'] = $access;
        $item['refresh'] = $rotated;
        $item['expires'] = time() + max( 60, (int) ( $response['expires_in'] ?? 3600 ) );
        return $response['access_token'];
    }

    private static function probe( $item, $access ) {
        $event = array(
            'title' => 'Dendrila Calendar test',
            'start' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
            'end' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS + 60 ),
            'all_day' => false,
        );
        $body = self::format_event( $item['provider'], $event );
        if ( 'google' === $item['provider'] ) {
            $body['id'] = 'dc' . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 36 );
        } else {
            $body['transactionId'] = wp_generate_uuid4();
        }
        $created = self::api( $item['provider'], $access, 'POST', self::calendar_url( $item ), $body );
        if ( ! $created || ! in_array( $created['status'], 'google' === $item['provider'] ? array( 200, 201 ) : array( 201 ), true ) || empty( $created['data']['id'] ) ) {
            // A Google insert may create the event despite an unexpected status.
            if ( 'google' === $item['provider'] && ! empty( $body['id'] ) ) {
                self::api( 'google', $access, 'DELETE', self::calendar_url( $item, $body['id'] ) );
            }
            return false;
        }
        $deleted = self::api( $item['provider'], $access, 'DELETE', self::calendar_url( $item, $created['data']['id'] ) );
        return $deleted && in_array( $deleted['status'], array( 200, 202, 204, 404 ), true );
    }

    public static function confirm() {
        self::permitted();
        check_admin_referer( 'dendrila_calendar_oauth_confirm' );
        $pending = get_transient( self::pending_key() );
        if ( ! is_array( $pending ) ) {
            self::redirect( 'expired' );
        }
        $provider = $pending['provider'];
        $calendar = isset( $_POST['calendar'] ) && is_string( $_POST['calendar'] ) ? sanitize_text_field( wp_unslash( $_POST['calendar'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Input array is filtered to strings, sanitized and matched to allowlisted source IDs.
        $submitted = isset( $_POST['sources'] ) && is_array( $_POST['sources'] ) ? wp_unslash( $_POST['sources'] ) : array();
        $requested = array_map( 'sanitize_key', array_filter( $submitted, 'is_string' ) );
        $two_way = isset( $_POST['two_way'] ) && '1' === $_POST['two_way'];
        $mode_calendar = isset( $_POST['two_way_calendar'] ) && is_string( $_POST['two_way_calendar'] ) ? sanitize_text_field( wp_unslash( $_POST['two_way_calendar'] ) ) : '';
        $saved_mode = self::saved_destination_mode( $provider, $calendar );
        // Without JavaScript, choosing another destination must not apply the
        // first calendar's checkbox to an already configured calendar.
        if ( $mode_calendar !== $calendar && null !== $saved_mode ) {
            $two_way = $saved_mode;
        }
        if ( $two_way ) { $requested[] = 'native'; }
        $sources = Dendrila_Calendar_Subscriptions::allowed_sources( $requested, Dendrila_Calendar_Sources::available() );
        if ( ! isset( $pending['calendars'][ $calendar ] ) || ! $sources ) {
            self::redirect( 'invalid', $provider );
        }
        $connections = self::connections();
        $existing_id = '';
        foreach ( $connections as $candidate_id => $candidate ) {
            if ( self::same_destination( $candidate, $provider, $calendar ) ) {
                $existing_id = $candidate_id;
                break;
            }
        }
        if ( '' === $existing_id && count( $connections ) >= self::MAX_CONNECTIONS ) {
            self::redirect( 'limit', $provider );
        }
        $archives = get_option( self::ARCHIVES_OPTION, array() );
        $archive = is_array( $archives ) ? ( $archives[ self::destination_key( $provider, $calendar ) ] ?? array() ) : array();
        $old = '' !== $existing_id ? $connections[ $existing_id ] : $archive;
        $id = '' !== $existing_id ? $existing_id : ( $archive['id'] ?? substr( self::destination_key( $provider, $calendar ), 0, 16 ) );
        if ( self::busy( $id ) ) { self::redirect( 'busy', $provider ); }
        $item = array(
            'provider' => $provider,
            'calendar' => $calendar,
            'label' => $pending['calendars'][ $calendar ],
            'access' => $pending['access'],
            'refresh' => $pending['refresh'],
            'expires' => $pending['expires'],
            'sources' => $sources,
            'two_way' => $two_way,
            // Reauthorizing an existing calendar MUST retain synchronization identities.
            'map' => isset( $old['map'] ) && is_array( $old['map'] ) ? $old['map'] : array(),
            'last_error' => '',
            'last_run' => $old['last_run'] ?? '',
        );
        $access = Dendrila_Calendar_Bridge::reveal( $item['access'] );
        if ( ! $access || ! self::probe( $item, $access ) ) {
            self::redirect( 'probe', $provider );
        }
        // Preserve mappings when an account is disconnected and later authorized again.
        // The archive contains only identities and direction, never access or refresh tokens.
        $connections[ $id ] = $item;
        if ( ! self::store( $connections ) ) {
            self::redirect( 'failed', $provider );
        }
        delete_transient( self::pending_key() );
        if ( class_exists( 'Dendrila_Calendar_Setup' ) ) {
            Dendrila_Calendar_Setup::destination_connected();
        }
        self::run_one( $id );
        self::redirect( '' === $existing_id ? 'connected' : 'updated', $provider );
    }


    /**
     * Consolidate an already-existing duplicate without deleting remote events.
     * Keep the primary map, merge nonconflicting mapping keys and current tokens.
     * Conflicting remote IDs are not deleted: doing so could remove a real event.
     */
    public static function consolidate_duplicate() {
        self::permitted();
        check_admin_referer( 'dendrila_calendar_oauth_dedupe' );
        $id = isset( $_POST['connection_id'] ) && is_string( $_POST['connection_id'] )
            ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
        $connections = self::connections();
        if ( ! isset( $connections[ $id ] ) ) {
            self::redirect( 'invalid' );
        }
        $duplicate = $connections[ $id ];
        $primary_id = '';
        foreach ( $connections as $candidate_id => $item ) {
            if ( $candidate_id !== $id &&
                 self::same_destination( $item, $duplicate['provider'], $duplicate['calendar'] ) ) {
                $primary_id = $candidate_id;
                break;
            }
        }
        if ( '' === $primary_id ) {
            self::redirect( 'invalid' );
        }
        $primary = $connections[ $primary_id ];
        if ( self::busy( $id ) || self::busy( $primary_id ) ) { self::redirect( 'busy' ); }
        $primary_links = get_option( 'dc_two_way_links_' . $primary_id, array() );
        $duplicate_links = get_option( 'dc_two_way_links_' . $id, array() );
        $primary_links = is_array( $primary_links ) ? $primary_links : array();
        $duplicate_links = is_array( $duplicate_links ) ? $duplicate_links : array();
        foreach ( $duplicate_links as $post_id => $link ) {
            if ( isset( $primary_links[ $post_id ] ) && $primary_links[ $post_id ] !== $link ) { self::redirect( 'duplicate_conflict' ); }
            foreach ( $primary_links as $other_id => $other_link ) {
                if ( $other_id !== $post_id && $other_link['remote'] === $link['remote'] ) { self::redirect( 'duplicate_conflict' ); }
            }
            $primary_links[ $post_id ] = $link;
        }
        if ( ! isset( $primary['map'] ) || ! is_array( $primary['map'] ) ) {
            $primary['map'] = array();
        }
        foreach ( (array) ( $duplicate['map'] ?? array() ) as $source_key => $remote ) {
            if ( ! isset( $primary['map'][ $source_key ] ) && is_array( $remote ) &&
                 ! empty( $remote['remote_id'] ) ) {
                $primary['map'][ $source_key ] = $remote;
            } elseif ( isset( $primary['map'][ $source_key ]['remote_id'], $remote['remote_id'] ) &&
                       $primary['map'][ $source_key ]['remote_id'] !== $remote['remote_id'] ) {
                // Keep abandoned mirrors reserved so two-way sync cannot import them as new events.
                $primary['map'][ 'duplicate-' . $id . '-' . $source_key ] = $remote;
            }
        }
        $primary['sources'] = Dendrila_Calendar_Subscriptions::allowed_sources(
            array_values( array_unique( array_merge(
                (array) ( $primary['sources'] ?? array() ),
                (array) ( $duplicate['sources'] ?? array() )
            ) ) ),
            Dendrila_Calendar_Sources::available()
        );
        if ( (int) ( $duplicate['expires'] ?? 0 ) > (int) ( $primary['expires'] ?? 0 ) ) {
            foreach ( array( 'access', 'refresh', 'expires' ) as $token_key ) {
                if ( isset( $duplicate[ $token_key ] ) ) {
                    $primary[ $token_key ] = $duplicate[ $token_key ];
                }
            }
        }
        if ( (string) ( $duplicate['last_run'] ?? '' ) > (string) ( $primary['last_run'] ?? '' ) ) {
            $primary['last_run'] = $duplicate['last_run'];
        }
        $connections[ $primary_id ] = $primary;
        unset( $connections[ $id ] );
        if ( ! self::store( $connections ) ) {
            self::redirect( 'failed' );
        }
        update_option( 'dc_two_way_links_' . $primary_id, $primary_links, false );
        foreach ( $duplicate_links as $post_id => $link ) {
            if ( get_post_meta( $post_id, '_dendrila_calendar_import_connection', true ) === $id ) {
                update_post_meta( $post_id, '_dendrila_calendar_import_connection', $primary_id );
                update_post_meta( $post_id, '_dendrila_calendar_import_identity', hash( 'sha256', $primary_id . '|' . $link['remote'] ) );
            }
        }
        self::redirect( 'deduped' );
    }

    public static function disconnect() {
        self::permitted();
        check_admin_referer( 'dendrila_calendar_oauth_disconnect' );
        $id = isset( $_POST['connection_id'] ) && is_string( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
        $connections = self::connections();
        if ( isset( $connections[ $id ] ) ) {
            $item = $connections[ $id ];
            $archives = get_option( self::ARCHIVES_OPTION, array() );
            $archives = is_array( $archives ) ? $archives : array();
            $archives[ self::destination_key( $item['provider'], $item['calendar'] ) ] = array(
                'id' => $id, 'map' => $item['map'] ?? array(),
                'two_way' => ! empty( $item['two_way'] ),
            );
            update_option( self::ARCHIVES_OPTION, $archives, false );
            unset( $connections[ $id ] );
            self::store( $connections );
        }
        self::redirect( 'disconnected' );
    }

    public static function sync_now() {
        self::permitted();
        check_admin_referer( 'dendrila_calendar_oauth_sync' );
        $id = isset( $_POST['connection_id'] ) && is_string( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
        self::run_one( $id );
        self::redirect( 'synced' );
    }

    /** Change synchronization direction without asking the account to authorize again. */
    public static function save_mode() {
        self::permitted();
        check_admin_referer( 'dendrila_calendar_oauth_mode' );
        $id = isset( $_POST['connection_id'] ) && is_string( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
        $connections = self::connections();
        if ( ! isset( $connections[ $id ] ) ) { self::redirect( 'invalid' ); }
        if ( self::busy( $id ) ) { self::redirect( 'busy' ); }
        $connections[ $id ]['two_way'] = isset( $_POST['two_way'] ) && '1' === $_POST['two_way'];
        if ( $connections[ $id ]['two_way'] ) {
            $connections[ $id ]['sources'] = array_values( array_unique( array_merge( $connections[ $id ]['sources'], array( 'native' ) ) ) );
        }
        self::store( $connections );
        self::run_one( $id );
        self::redirect( 'mode_saved' );
    }

    public static function run_all() {
        foreach ( array_keys( self::connections() ) as $id ) {
            self::run_one( $id );
        }
    }

    public static function run_one( $id ) {
        $lock = 'dc_oauth_sync_' . $id;
        $connections = self::connections();
        if ( empty( $connections[ $id ] ) || get_transient( $lock ) ) {
            return;
        }
        if ( ! class_exists( 'WP_Upgrader' ) ) { require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php'; }
        if ( ! WP_Upgrader::create_lock( $lock, HOUR_IN_SECONDS ) ) { return; }
        set_transient( $lock, 1, 4 * MINUTE_IN_SECONDS );
        try {
            $connections = self::connections();
            if ( empty( $connections[ $id ] ) ) { return; }
            $two_way = ! empty( $connections[ $id ]['two_way'] );
            $item = $connections[ $id ];
            $access = self::refresh_access( $item );
            if ( ! $access ) {
                $item['last_error'] = __( 'Reconnect this calendar to renew authorization.', 'dendrila-calendar' );
            } else {
                $sources = Dendrila_Calendar_Subscriptions::allowed_sources( $item['sources'], Dendrila_Calendar_Sources::available() );
                // Native booking records join a destination already selected for Dendrila.
                // Customer names and emails are never part of their event payload.
                if ( in_array( 'native', $sources, true ) && class_exists( 'Dendrila_Calendar_Booking' ) && ! in_array( 'bookings', $sources, true ) ) {
                    $sources[] = 'bookings';
                }
                $now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
                $available_events = Dendrila_Calendar_Sources::events( $now->modify( '-7 days' )->format( 'Y-m-d H:i:s' ), $now->modify( '+85 days' )->format( 'Y-m-d H:i:s' ), $sources );
                $source_warnings = method_exists( 'Dendrila_Calendar_Sources', 'diagnostics' ) ? Dendrila_Calendar_Sources::diagnostics() : array();
                $limited = count( $available_events ) > 500 || ! empty( $source_warnings );
                $events = array_slice( $available_events, 0, 500 );
                $map = isset( $item['map'] ) && is_array( $item['map'] ) ? $item['map'] : array();
                $failures = 0;
                $links = get_option( 'dc_two_way_links_' . $id, array() );
                foreach ( $events as $event ) {
                    if ( ( self::connections()[ $id ] ?? null ) !== $connections[ $id ] ) { return; }
                    if ( empty( $event['id'] ) || empty( $event['source'] ) || ( $two_way && 'native' === $event['source'] ) ) {
                        continue;
                    }
                    $key = Dendrila_Calendar_Bridge::identity( $event );
                    $old = $map[ $key ] ?? null;
                    if ( 'native' === $event['source'] && isset( $links[ (int) substr( $event['id'], 7 ) ] ) ) {
                        $old['remote_id'] = $links[ (int) substr( $event['id'], 7 ) ]['remote'];
                    }
                    if ( Dendrila_Calendar_Bridge::status_is_cancelled( $event['status'] ) ) {
                        if ( $old && ! empty( $old['remote_id'] ) ) {
                            $deleted = self::api( $item['provider'], $access, 'DELETE', self::calendar_url( $item, $old['remote_id'] ) );
                            if ( $deleted && in_array( $deleted['status'], array( 200, 202, 204, 404 ), true ) ) {
                                unset( $map[ $key ] );
                            } else {
                                ++$failures;
                            }
                        }
                        continue;
                    }
                    $fingerprint = Dendrila_Calendar_Bridge::fingerprint( $event );
                    if ( $old && ( $old['fingerprint'] ?? '' ) === $fingerprint ) {
                        continue;
                    }
                    $body = self::format_event( $item['provider'], $event );
                    $remote_id = $old['remote_id'] ?? '';
                    if ( '' === $remote_id ) {
                        if ( 'google' === $item['provider'] ) {
                            $body['id'] = 'dc' . substr( $key, 0, 36 );
                        } else {
                            $body['transactionId'] = substr( $key, 0, 36 );
                        }
                    }
                    $url = self::calendar_url( $item, $remote_id );
                    $result = self::api( $item['provider'], $access, '' === $remote_id ? 'POST' : 'PATCH', $url, $body );
                    if ( $result && 'google' === $item['provider'] && 409 === $result['status'] && '' === $remote_id ) {
                        $remote_id = $body['id'];
                        unset( $body['id'] );
                        $result = self::api( $item['provider'], $access, 'PATCH', self::calendar_url( $item, $remote_id ), $body );
                    }
                    if ( $result && in_array( $result['status'], array( 200, 201 ), true ) ) {
                        $remote_id = $result['data']['id'] ?? $remote_id;
                        if ( is_string( $remote_id ) && '' !== $remote_id ) {
                            $map[ $key ] = array( 'remote_id' => $remote_id, 'fingerprint' => $fingerprint );
                            continue;
                        }
                    }
                    ++$failures;
                }
                $item['map'] = $map;
                unset( $item['last_diagnostic'] );
                $item['last_error'] = $failures ? __( 'Some calendar events could not be updated.', 'dendrila-calendar' ) : '';
                if ( $two_way ) {
                    try {
                        $report = Dendrila_Calendar_Two_Way::sync( $id, $item, $access, $events, $now->modify( '-7 days' )->format( DATE_ATOM ), $now->modify( '+85 days' )->format( DATE_ATOM ) );
                        if ( $report['errors'] ) { $item['last_error'] = __( 'Some calendar events could not be updated.', 'dendrila-calendar' ); }
                        if ( $report['conflicts'] ) {
                            $item['last_error'] = trim( $item['last_error'] . ' ' . __( 'Both copies changed. Match their titles and dates in both calendars, then synchronize again. Local event IDs:', 'dendrila-calendar' ) . ' ' . implode( ', ', array_slice( $report['conflicts'], 0, 20 ) ) );
                        }
                    } catch ( Throwable $error ) {
                        // Persist only fixed diagnostic identifiers. Raw exception messages could
                        // contain calendar IDs, private event content or other sensitive values.
                        $reason = $error->getMessage();
                        $codes = array(
                            'inventory_incomplete' => 'PAGINATION_LIMIT',
                            'calendar_bad_response' => 'RESPONSE_FORMAT',
                            'calendar_pagination' => 'PAGINATION_FORMAT',
                            'Connection changed; synchronization stopped' => 'CONNECTION_CHANGED',
                        );
                        if ( 'calendar_http' === $reason ) {
                            $status = (int) $error->getCode();
                            $code = in_array( $status, array( 400, 401, 403, 404, 408, 409, 429, 500, 502, 503, 504 ), true )
                                ? 'HTTP_' . $status : ( 0 === $status ? 'NETWORK' : 'HTTP_OTHER' );
                        } else {
                            $code = $codes[ $reason ] ?? 'INTERNAL';
                        }
                        $item['last_diagnostic'] = array( 'stage' => 'two_way', 'code' => $code, 'at' => gmdate( 'Y-m-d H:i:s' ) );
                        $item['last_error'] = __( 'Calendar synchronization was interrupted. Diagnostic code:', 'dendrila-calendar' ) . ' ' . $code;
                    }
                }
                if ( $limited ) {
                    $item['last_error'] = trim( $item['last_error'] . ' ' . __( 'The limit of 500 source events was reached. Some new events may not be exported; existing two-way links are still checked.', 'dendrila-calendar' ) );
                } elseif ( $source_warnings ) {
                    $item['last_error'] = trim( $item['last_error'] . ' ' . __( 'Some sources could not be read completely. Review the source diagnostics in the calendar.', 'dendrila-calendar' ) );
                }
            }
            $item['last_run'] = gmdate( 'Y-m-d H:i:s' );
            $latest = self::connections();
            if ( isset( $latest[ $id ] ) && $latest[ $id ] === $connections[ $id ] ) {
                $latest[ $id ] = $item;
                self::store( $latest );
            }
        } catch ( Throwable $error ) {
            // Never expose provider credentials, OAuth codes or remote payloads.
        } finally {
            delete_transient( $lock );
            WP_Upgrader::release_lock( $lock );
        }
    }


    /**
     * Read UTC busy windows without caching event details. Use calendarView on
     * Microsoft because getSchedule does not support personal Outlook accounts.
     * On any unavailable provider, return false (never interpret as free).
     */
    public static function busy_intervals( $from, $to, $ignore_exports = true ) {
        $begin = strtotime( $from ); $finish = strtotime( $to );
        if ( ! $begin || ! $finish || $finish <= $begin || $finish - $begin > 48 * 3600 ||
            ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $from ) ||
            ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $to ) ) { return false; }
        $connections = self::connections();
        if ( ! $connections ) { return false; }
        $busy = array();
        foreach ( $connections as $key => $item ) {
            $provider = $item['provider'] ?? '';
            if ( ! in_array( $provider, array( 'google', 'microsoft' ), true ) || empty( $item['calendar'] ) ) { return false; }
            $token = self::refresh_access( $item );
            if ( ! $token ) { return false; }
            $connections[ $key ]['access'] = $item['access'];
            $connections[ $key ]['refresh'] = $item['refresh'];
            $connections[ $key ]['expires'] = $item['expires'];
            $google = 'google' === $provider;
            $ignore = array();
            foreach ( $ignore_exports ? (array) ( $item['map'] ?? array() ) : array() as $mapped ) {
                if ( is_array( $mapped ) && ! empty( $mapped['remote_id'] ) ) { $ignore[ $mapped['remote_id'] ] = true; }
            }
            $base = $google ?
                'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode( $item['calendar'] ) . '/events' :
                'https://graph.microsoft.com/v1.0/me/calendars/' . rawurlencode( $item['calendar'] ) . '/calendarView';
            $params = $google ?
                array( 'timeMin'=>$from, 'timeMax'=>$to, 'singleEvents'=>'true', 'showDeleted'=>'false', 'timeZone'=>'UTC', 'maxResults'=>250 ) :
                array( 'startDateTime'=>$from, 'endDateTime'=>$to, '$top'=>250, '$select'=>'id,start,end,showAs,isCancelled' );
            $next = $base . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
            for ( $page=0; $next && $page<6; ++$page ) {
                $answer = self::api( $provider, $token, 'GET', $next );
                if ( ! $answer || 200 !== $answer['status'] || ! is_array( $answer['data'] ) ) { return false; }
                $events = $google ? ( $answer['data']['items'] ?? array() ) : ( $answer['data']['value'] ?? array() );
                if ( ! is_array( $events ) ) { return false; }
                foreach ( $events as $event ) {
                    if ( ! is_array( $event ) || isset( $ignore[ $event['id'] ?? '' ] ) ||
                        ( $google && ( ( $event['status'] ?? '' ) === 'cancelled' || ( $event['transparency'] ?? '' ) === 'transparent' ) ) ||
                        ( ! $google && ( ! empty( $event['isCancelled'] ) || ( $event['showAs'] ?? '' ) === 'free' ) ) ) { continue; }
                    $s = $event['start'] ?? null; $e = $event['end'] ?? null;
                    if ( ! is_array( $s ) || ! is_array( $e ) ) { return false; }
                    $sv = $google ? ( $s['dateTime'] ?? ( $s['date'] ?? '' ) ) : ( $s['dateTime'] ?? '' );
                    $ev = $google ? ( $e['dateTime'] ?? ( $e['date'] ?? '' ) ) : ( $e['dateTime'] ?? '' );
                    if ( ! is_string( $sv ) || ! is_string( $ev ) || !$sv || !$ev ) { return false; }
                    try {
                        $stz = $google && strlen($sv) === 10 ? wp_timezone() : new DateTimeZone('UTC');
                        $etz = $google && strlen($ev) === 10 ? wp_timezone() : new DateTimeZone('UTC');
                        $left = (new DateTimeImmutable($sv,$stz))->getTimestamp();
                        $right = (new DateTimeImmutable($ev,$etz))->getTimestamp();
                    } catch ( Exception $ex ) { return false; }
                    if ( $left >= $right ) { return false; }
                    if ( $left < $finish && $right > $begin ) { $busy[] = array($left,$right); }
                }
                $next = '';
                if ( $google && ! empty( $answer['data']['nextPageToken'] ) ) {
                    $params['pageToken'] = (string) $answer['data']['nextPageToken'];
                    $next = $base . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
                } elseif ( ! $google && ! empty( $answer['data']['@odata.nextLink'] ) ) {
                    $link = $answer['data']['@odata.nextLink'];
                    if ( ! is_string( $link ) || 0 !== strpos( $link, $base . '?' ) ) { return false; }
                    $next = $link;
                }
            }
            if ( $next ) { return false; }
        }
        // update_option() returns false on unchanged values: not an error.
        // Persist token rotation without overwriting the latest event maps.
        $latest = self::connections();
        $changed = false;
        foreach ( $connections as $id => $candidate ) {
            if ( ! isset( $latest[ $id ] ) ) { continue; }
            foreach ( array( 'access', 'refresh', 'expires' ) as $field ) {
                if ( isset( $candidate[ $field ] ) && ( $latest[ $id ][ $field ] ?? null ) !== $candidate[ $field ] ) {
                    $latest[ $id ][ $field ] = $candidate[ $field ];
                    $changed = true;
                }
            }
        }
        if ( $changed && ! self::store( $latest ) ) { return false; }
        return $busy;
    }

    public static function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $pending = get_transient( self::pending_key() );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only status message.
        $status = isset( $_GET['oauth_status'] ) && is_string( $_GET['oauth_status'] ) ? sanitize_key( wp_unslash( $_GET['oauth_status'] ) ) : '';
        echo '<section class="dendrila-oauth" aria-labelledby="dendrila-oauth-heading"><h2 id="dendrila-oauth-heading">' . esc_html__( 'Connect your calendar account', 'dendrila-calendar' ) . '</h2>';
        echo '<p>' . esc_html__( 'Choose Google or Microsoft to authorize Dendrila Calendar, then select the calendar that will receive your appointments.', 'dendrila-calendar' ) . '</p>';
        $statuses = array(
            'failed' => __( 'The connection could not be completed. Try again.', 'dendrila-calendar' ),
            'cancelled' => __( 'Account authorization was cancelled.', 'dendrila-calendar' ),
            'unavailable' => __( 'Account authorization is not configured for this site.', 'dendrila-calendar' ),
            'expired' => __( 'The connection expired. Try again.', 'dendrila-calendar' ),
            'invalid' => __( 'Choose a calendar and at least one source.', 'dendrila-calendar' ),
            'probe' => __( 'The provider did not confirm that events can be created and removed. No connection was saved.', 'dendrila-calendar' ),
            'limit' => __( 'Too many connected calendars.', 'dendrila-calendar' ),
            'connected' => __( 'Your calendar has been connected and synchronization started.', 'dendrila-calendar' ),
            'updated' => __( 'This calendar was already connected. Authorization and selected sources were updated without creating a duplicate.', 'dendrila-calendar' ),
            'deduped' => __( 'Connections grouped. No calendar events were deleted.', 'dendrila-calendar' ),
            'synced' => __( 'A synchronization attempt has finished. Review its status below.', 'dendrila-calendar' ),
            'disconnected' => __( 'Calendar disconnected. Existing remote events were not removed.', 'dendrila-calendar' ),
            'no_calendars' => __( 'No writable calendar was found in this account.', 'dendrila-calendar' ),
            'credentials_saved' => __( 'Application credentials saved. You can now authorize your account using the button below.', 'dendrila-calendar' ),
            'invalid_credentials' => __( 'Enter a valid client ID and secret. No credentials were changed.', 'dendrila-calendar' ),
            'mode_saved' => __( 'Synchronization direction saved. Review the result below.', 'dendrila-calendar' ),
            'busy' => __( 'Synchronization is running. Try again in a moment.', 'dendrila-calendar' ),
            'duplicate_conflict' => __( 'These connections have conflicting event links. Keep them separate until the event copies have been reviewed.', 'dendrila-calendar' ),
        );
        if ( isset( $statuses[ $status ] ) ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html( $statuses[ $status ] ) . '</p></div>';
        }
        if ( is_array( $pending ) && ! empty( $pending['calendars'] ) ) {
            $initial_calendar = (string) array_key_first( $pending['calendars'] );
            $initial_two_way = self::saved_destination_mode( $pending['provider'], $initial_calendar ) ?? true;
            echo '<div class="dendrila-oauth-stage"><span class="dendrila-stage-count">2 / 3</span><strong>' . esc_html__( 'Account authorized: choose your calendar', 'dendrila-calendar' ) . '</strong><p>' . esc_html__( 'The provider has granted access. Select the destination calendar and start synchronization.', 'dendrila-calendar' ) . '</p></div>';
            echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="dendrila-connect-card dendrila-oauth-step">';
            echo '<input type="hidden" name="action" value="dendrila_calendar_oauth_confirm">';
            wp_nonce_field( 'dendrila_calendar_oauth_confirm' );
            echo '<div class="dendrila-step-header"><span class="dendrila-step-number">3</span><h3>' . esc_html__( 'Choose your destination calendar', 'dendrila-calendar' ) . '</h3></div>';
            echo '<p><strong>' . esc_html( 'microsoft' === ( $pending['provider'] ?? '' ) ? 'Microsoft Outlook' : 'Google Calendar' ) . '</strong> · ' . esc_html__( 'Account authorization successful', 'dendrila-calendar' ) . '</p>';
            echo '<label for="dendrila-oauth-calendar">' . esc_html__( 'Calendar', 'dendrila-calendar' ) . '</label>';
            echo '<select name="calendar" id="dendrila-oauth-calendar" required>';
            foreach ( $pending['calendars'] as $id => $name ) {
                $two_way = self::saved_destination_mode( $pending['provider'], (string) $id ) ?? true;
                echo '<option value="' . esc_attr( $id ) . '" data-two-way="' . ( $two_way ? '1' : '0' ) . '">' . esc_html( $name ) . '</option>';
            }
            echo '</select><fieldset><legend>' . esc_html__( 'Which appointments should be sent?', 'dendrila-calendar' ) . '</legend>';
            foreach ( Dendrila_Calendar_Sources::available() as $source => $label ) {
                echo '<label class="dendrila-source-option"><input type="checkbox" name="sources[]" value="' . esc_attr( $source ) . '" checked> ' . esc_html( $label ) . '</label>';
            }
            echo '</fieldset><input type="hidden" name="two_way_calendar" id="dendrila-oauth-mode-calendar" value="' . esc_attr( $initial_calendar ) . '">';
            echo '<p><label><input type="checkbox" name="two_way" id="dendrila-oauth-two-way" value="1"' . ( $initial_two_way ? ' checked' : '' ) . '> ' . esc_html__( 'Synchronize local events in both directions', 'dendrila-calendar' ) . '</label></p>';
            echo '<p class="description">' . esc_html__( 'Copy titles and dates between this calendar and WordPress. Deletions propagate. New single events are found from 7 days ago to 85 days ahead. Recurring events and invitations are skipped. Other booking sources are export only.', 'dendrila-calendar' ) . '</p>';
            echo '<noscript><p class="description">' . esc_html__( 'If you select another previously connected calendar, its saved synchronization direction will be kept. You can change it after connecting.', 'dendrila-calendar' ) . '</p></noscript>';
            echo '<p>' . esc_html__( 'A test event is created then removed before automatic synchronization begins.', 'dendrila-calendar' ) . '</p><p><button type="submit" class="button button-primary dendrila-main-action">' . esc_html__( 'Connect and synchronize this calendar', 'dendrila-calendar' ) . '</button></p></form>';
        } else {
            // Optional provider hint from the calendar chooser; display preference only.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only interface selection.
            $preferred = isset( $_GET['oauth_provider'] ) && is_string( $_GET['oauth_provider'] ) ? sanitize_key( wp_unslash( $_GET['oauth_provider'] ) ) : '';
            $providers = array( 'google' => 'Google Calendar', 'microsoft' => 'Microsoft Outlook' );
            if ( isset( $providers[ $preferred ] ) ) {
                $providers = array( $preferred => $providers[ $preferred ] ) + $providers;
            }
            foreach ( $providers as $provider => $label ) {
                $configured = self::ready( $provider );
                $names = 'google' === $provider ?
                    array( 'DENDRILA_CALENDAR_GOOGLE_CLIENT_ID', 'DENDRILA_CALENDAR_GOOGLE_CLIENT_SECRET' ) :
                    array( 'DENDRILA_CALENDAR_MICROSOFT_CLIENT_ID', 'DENDRILA_CALENDAR_MICROSOFT_CLIENT_SECRET' );
                $locked = defined( $names[0] ) || defined( $names[1] );
                $saved = get_option( self::CREDENTIALS_OPTION, array() );
                $entry = is_array( $saved ) && isset( $saved[ $provider ] ) && is_array( $saved[ $provider ] ) ? $saved[ $provider ] : array();
                echo '<div class="dendrila-connect-card dendrila-oauth-app-setup"><h3>' . esc_html( $label ) . ' <span class="dendrila-connection-badge ' . ( $configured ? 'is-ready' : '' ) . '">' . esc_html( $configured ? __( 'Ready to connect', 'dendrila-calendar' ) : __( 'Setup required', 'dendrila-calendar' ) ) . '</span></h3>';
                if ( ! $locked ) {
                    echo '<details' . ( $configured ? '' : ' open' ) . '><summary>' . esc_html__( 'Configure application credentials (administrator)', 'dendrila-calendar' ) . '</summary>';
                    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" autocomplete="off">';
                    echo '<input type="hidden" name="action" value="dendrila_calendar_oauth_save_credentials">';
                    echo '<input type="hidden" name="provider" value="' . esc_attr( $provider ) . '">';
                    wp_nonce_field( 'dendrila_calendar_oauth_save_credentials' );
                    echo '<p><label for="dendrila-' . esc_attr( $provider ) . '-client-id">' . esc_html__( 'OAuth client ID', 'dendrila-calendar' ) . '</label>';
                    echo '<input type="text" id="dendrila-' . esc_attr( $provider ) . '-client-id" name="client_id" value="' . esc_attr( $entry['id'] ?? '' ) . '" autocomplete="off" spellcheck="false" placeholder="' . esc_attr__( 'Paste your client ID', 'dendrila-calendar' ) . '"></p>';
                    echo '<p><label for="dendrila-' . esc_attr( $provider ) . '-client-secret">' . esc_html__( 'OAuth client secret', 'dendrila-calendar' ) . '</label>';
                    echo '<input type="password" id="dendrila-' . esc_attr( $provider ) . '-client-secret" name="client_secret" value="" autocomplete="new-password" spellcheck="false" placeholder="' . esc_attr__( 'Paste your secret (never displayed again)', 'dendrila-calendar' ) . '"></p>';
                    echo '<p class="description">' . esc_html__( 'Saved securely in the WordPress database, encrypted with this site’s keys. Leave a field empty to keep its saved value. Never paste credentials into chat.', 'dendrila-calendar' ) . '</p>';
                    echo '<p><button class="button" type="submit">' . esc_html__( 'Save application credentials', 'dendrila-calendar' ) . '</button></p></form></details>';
                } else {
                    echo '<p class="description">' . esc_html__( 'Application credentials are managed in wp-config.php.', 'dendrila-calendar' ) . '</p>';
                }
                echo '<p class="description">' . esc_html__( 'Redirect URI:', 'dendrila-calendar' ) . ' <code>' . esc_html( self::callback_url() ) . '</code></p>';
                echo '<form class="dendrila-oauth-provider" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="dendrila_calendar_oauth_start">';
                echo '<input type="hidden" name="provider" value="' . esc_attr( $provider ) . '">';
                wp_nonce_field( 'dendrila_calendar_oauth_start' );
                echo '<button class="button button-primary" type="submit" ' . disabled( ! self::ready( $provider ), true, false ) . '>' .
                    esc_html( sprintf( /* translators: %s: calendar provider name, such as Google Calendar. */ __( 'Connect with %s', 'dendrila-calendar' ), $label ) ) . '</button>';
                if ( ! self::ready( $provider ) ) {
                    echo '<p class="description">' . esc_html__( 'Direct authorization requires the publisher to configure the Dendrila OAuth application once. Users do not need developer credentials.', 'dendrila-calendar' ) . '</p>';
                }
                echo '</form></div>';
            }
            echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-sync&dendrila_method=subscription' ) ) . '">' .
                esc_html__( 'Use a read-only calendar subscription instead', 'dendrila-calendar' ) . '</a></p>';
        }
        $already_shown = array();
        foreach ( self::connections() as $id => $item ) {
            $duplicate = false;
            foreach ( $already_shown as $previous ) {
                if ( self::same_destination( $previous, $item['provider'], $item['calendar'] ) ) {
                    $duplicate = true;
                    break;
                }
            }
            $already_shown[] = $item;
            echo '<div class="dendrila-bridge-connection dendrila-status-card"><h3><span class="dendrila-status-dot" aria-hidden="true"></span>' . esc_html( $item['label'] ) . ' (' . esc_html( $item['provider'] ) . ')</h3>';
            echo '<p>' . esc_html( ! empty( $item['two_way'] ) ? __( 'Two-way local events', 'dendrila-calendar' ) : __( 'Export to calendar', 'dendrila-calendar' ) ) . '</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            echo '<input type="hidden" name="action" value="dendrila_calendar_oauth_mode"><input type="hidden" name="connection_id" value="' . esc_attr( $id ) . '">';
            wp_nonce_field( 'dendrila_calendar_oauth_mode' );
            echo '<p><label><input type="checkbox" name="two_way" value="1"' . ( ! empty( $item['two_way'] ) ? ' checked' : '' ) . '> ' . esc_html__( 'Synchronize local events in both directions', 'dendrila-calendar' ) . '</label></p>';
            echo '<p class="description">' . esc_html__( 'Copy titles and dates between this calendar and WordPress. Deletions propagate. New single events are found from 7 days ago to 85 days ahead. Recurring events and invitations are skipped. Other booking sources are export only.', 'dendrila-calendar' ) . '</p>';
            echo '<p><button class="button" type="submit">' . esc_html__( 'Save synchronization direction', 'dendrila-calendar' ) . '</button></p></form>';
            echo '<p>' . esc_html__( 'Last synchronization:', 'dendrila-calendar' ) . ' ' . esc_html( $item['last_run'] ?: __( 'Never synchronized', 'dendrila-calendar' ) ) . '</p>';
            if ( ! empty( $item['last_error'] ) ) {
                echo '<p class="notice notice-warning inline">' . esc_html( $item['last_error'] ) . '</p>';
                if ( isset( $item['last_diagnostic']['code'] ) ) {
                    $safe_code = sanitize_key( (string) $item['last_diagnostic']['code'] );
                    echo '<p class="description">' . esc_html__( 'Diagnostic:', 'dendrila-calendar' ) . ' <code>' . esc_html( strtoupper( $safe_code ) ) . '</code> · ' . esc_html( $item['last_diagnostic']['at'] ?? '' ) . ' UTC</p>';
                }
            }
            if ( $duplicate ) {
                echo '<p class="notice notice-warning inline">' .
                    esc_html__( 'This calendar is connected more than once. Group the connections without deleting calendar events.', 'dendrila-calendar' ) . '</p>';
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="dendrila_calendar_oauth_dedupe">';
                echo '<input type="hidden" name="connection_id" value="' . esc_attr( $id ) . '">';
                wp_nonce_field( 'dendrila_calendar_oauth_dedupe' );
                echo '<button class="button" type="submit">' . esc_html__( 'Group', 'dendrila-calendar' ) . '</button></form>';
            }
            foreach ( array(
                'sync' => __( 'Synchronize now', 'dendrila-calendar' ),
                'disconnect' => __( 'Disconnect', 'dendrila-calendar' ),
            ) as $action => $title ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:0.5em">';
                echo '<input type="hidden" name="action" value="dendrila_calendar_oauth_' . esc_attr( $action ) . '">';
                echo '<input type="hidden" name="connection_id" value="' . esc_attr( $id ) . '">';
                wp_nonce_field( 'dendrila_calendar_oauth_' . $action );
                echo '<button class="button" type="submit">' . esc_html( $title ) . '</button></form>';
            }
            echo '</div>';
        }
        echo '</section>';
    }
}
