<?php
/**
 * Google and Microsoft account authorization and one-way event synchronization.
 * OAuth application IDs/secrets belong to the plugin publisher, never to users.
 * This connector is deliberately unavailable until publisher credentials exist.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar_OAuth {
    const OPTION = 'dendrila_calendar_oauth_connections';
    const MAX_CONNECTIONS = 5;

    public static function boot() {
        add_action( 'admin_post_dendrila_calendar_oauth_start', array( __CLASS__, 'start' ) );
        add_action( 'admin_post_dendrila_calendar_oauth_callback', array( __CLASS__, 'callback' ) );
        add_action( 'admin_post_dendrila_calendar_oauth_confirm', array( __CLASS__, 'confirm' ) );
        add_action( 'admin_post_dendrila_calendar_oauth_disconnect', array( __CLASS__, 'disconnect' ) );
        add_action( 'admin_post_dendrila_calendar_oauth_sync', array( __CLASS__, 'sync_now' ) );
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
        if ( ! defined( $id_name ) || ! defined( $secret_name ) ||
             ! is_string( constant( $id_name ) ) || ! is_string( constant( $secret_name ) ) ||
             '' === trim( constant( $id_name ) ) || '' === trim( constant( $secret_name ) ) ) {
            return array();
        }
        $google = 'google' === $provider;
        return array(
            'id' => constant( $id_name ),
            'secret' => constant( $secret_name ),
            'authorize' => $google ? 'https://accounts.google.com/o/oauth2/v2/auth' : 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token' => $google ? 'https://oauth2.googleapis.com/token' : 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'scope' => $google ?
                'https://www.googleapis.com/auth/calendar.calendarlist.readonly https://www.googleapis.com/auth/calendar.events' :
                'offline_access Calendars.ReadWrite',
        );
    }

    public static function ready( $provider ) {
        return (bool) self::config( $provider );
    }

    public static function connections() {
        $saved = get_option( self::OPTION, array() );
        return is_array( $saved ) ? $saved : array();
    }

    private static function store( $items ) {
        return update_option( self::OPTION, $items, false );
    }

    private static function callback_url() {
        return admin_url( 'admin-post.php?action=dendrila_calendar_oauth_callback' );
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
        // Only a provider endpoint fixed in config() can receive this redirect.
        wp_redirect( add_query_arg( $query, $config['authorize'] ), 302, 'Dendrila Calendar' );
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

    private static function api( $provider, $access, $method, $url, $payload = null ) {
        // Never accept arbitrary hostnames or redirects when sending bearer tokens.
        $hosts = array( 'google' => 'www.googleapis.com', 'microsoft' => 'graph.microsoft.com' );
        $parts = wp_parse_url( $url );
        if ( ! isset( $hosts[ $provider ] ) || ! is_array( $parts ) ||
            'https' !== ( $parts['scheme'] ?? '' ) || $hosts[ $provider ] !== ( $parts['host'] ?? '' ) ) {
            return false;
        }
        $args = array(
            'method' => $method,
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array( 'Authorization' => 'Bearer ' . $access, 'Accept' => 'application/json' ),
            'limit_response_size' => 100000,
        );
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

    private static function calendar_url( $item, $event_id = '' ) {
        $calendar = rawurlencode( $item['calendar'] );
        if ( 'google' === $item['provider'] ) {
            $url = 'https://www.googleapis.com/calendar/v3/calendars/' . $calendar . '/events';
        } else {
            $url = 'https://graph.microsoft.com/v1.0/me/calendars/' . $calendar . '/events';
        }
        return $url . ( '' === $event_id ? '' : '/' . rawurlencode( $event_id ) );
    }

    private static function format_event( $provider, $event ) {
        $start = new DateTimeImmutable( $event['start'] );
        $end = new DateTimeImmutable( $event['end'] );
        if ( 'google' === $provider ) {
            $dates = ! empty( $event['all_day'] ) ?
                array( 'start' => array( 'date' => $start->setTimezone( wp_timezone() )->format( 'Y-m-d' ) ), 'end' => array( 'date' => $end->setTimezone( wp_timezone() )->format( 'Y-m-d' ) ) ) :
                array( 'start' => array( 'dateTime' => $start->format( 'Y-m-d\TH:i:s\Z' ) ), 'end' => array( 'dateTime' => $end->format( 'Y-m-d\TH:i:s\Z' ) ) );
            return array_merge( array( 'summary' => $event['title'] ), $dates );
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
        if ( ! $created || 201 !== $created['status'] || empty( $created['data']['id'] ) ) {
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
        $submitted = isset( $_POST['sources'] ) && is_array( $_POST['sources'] ) ? wp_unslash( $_POST['sources'] ) : array();
        $requested = array_map( 'sanitize_key', array_filter( $submitted, 'is_string' ) );
        $sources = Dendrila_Calendar_Subscriptions::allowed_sources( $requested, Dendrila_Calendar_Sources::available() );
        if ( ! isset( $pending['calendars'][ $calendar ] ) || ! $sources ) {
            self::redirect( 'invalid', $provider );
        }
        $connections = self::connections();
        if ( count( $connections ) >= self::MAX_CONNECTIONS ) {
            self::redirect( 'limit', $provider );
        }
        $item = array(
            'provider' => $provider,
            'calendar' => $calendar,
            'label' => $pending['calendars'][ $calendar ],
            'access' => $pending['access'],
            'refresh' => $pending['refresh'],
            'expires' => $pending['expires'],
            'sources' => $sources,
            'map' => array(),
            'last_error' => '',
            'last_run' => '',
        );
        $access = Dendrila_Calendar_Bridge::reveal( $item['access'] );
        if ( ! $access || ! self::probe( $item, $access ) ) {
            self::redirect( 'probe', $provider );
        }
        $id = substr( hash( 'sha256', self::random_urlsafe() ), 0, 16 );
        $connections[ $id ] = $item;
        if ( ! self::store( $connections ) ) {
            self::redirect( 'failed', $provider );
        }
        delete_transient( self::pending_key() );
        if ( class_exists( 'Dendrila_Calendar_Setup' ) ) {
            Dendrila_Calendar_Setup::destination_connected();
        }
        self::run_one( $id );
        self::redirect( 'connected', $provider );
    }

    public static function disconnect() {
        self::permitted();
        check_admin_referer( 'dendrila_calendar_oauth_disconnect' );
        $id = isset( $_POST['connection_id'] ) && is_string( $_POST['connection_id'] ) ? sanitize_key( wp_unslash( $_POST['connection_id'] ) ) : '';
        $connections = self::connections();
        if ( isset( $connections[ $id ] ) ) {
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
        set_transient( $lock, 1, 4 * MINUTE_IN_SECONDS );
        try {
            $item = $connections[ $id ];
            $access = self::refresh_access( $item );
            if ( ! $access ) {
                $item['last_error'] = 'Reconnect this calendar to renew authorization.';
            } else {
                $sources = Dendrila_Calendar_Subscriptions::allowed_sources( $item['sources'], Dendrila_Calendar_Sources::available() );
                $now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
                $events = array_slice( Dendrila_Calendar_Sources::events( $now->modify( '-7 days' )->format( 'Y-m-d H:i:s' ), $now->modify( '+85 days' )->format( 'Y-m-d H:i:s' ), $sources ), 0, 500 );
                $map = isset( $item['map'] ) && is_array( $item['map'] ) ? $item['map'] : array();
                $failures = 0;
                foreach ( $events as $event ) {
                    if ( empty( $event['id'] ) || empty( $event['source'] ) ) {
                        continue;
                    }
                    $key = Dendrila_Calendar_Bridge::identity( $event );
                    $old = $map[ $key ] ?? null;
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
                $item['last_error'] = $failures ? 'Some calendar events could not be updated.' : '';
            }
            $item['last_run'] = gmdate( 'Y-m-d H:i:s' );
            $latest = self::connections();
            if ( isset( $latest[ $id ] ) ) {
                $latest[ $id ] = $item;
                self::store( $latest );
            }
        } catch ( Throwable $error ) {
            // Never expose provider credentials, OAuth codes or remote payloads.
        } finally {
            delete_transient( $lock );
        }
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
            'synced' => __( 'A synchronization attempt has finished. Review its status below.', 'dendrila-calendar' ),
            'disconnected' => __( 'Calendar disconnected. Existing remote events were not removed.', 'dendrila-calendar' ),
            'no_calendars' => __( 'No writable calendar was found in this account.', 'dendrila-calendar' ),
        );
        if ( isset( $statuses[ $status ] ) ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html( $statuses[ $status ] ) . '</p></div>';
        }
        if ( is_array( $pending ) && ! empty( $pending['calendars'] ) ) {
            echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post" class="dendrila-connect-card">';
            echo '<input type="hidden" name="action" value="dendrila_calendar_oauth_confirm">';
            wp_nonce_field( 'dendrila_calendar_oauth_confirm' );
            echo '<h3>' . esc_html__( 'Choose your destination calendar', 'dendrila-calendar' ) . '</h3>';
            echo '<label for="dendrila-oauth-calendar">' . esc_html__( 'Calendar', 'dendrila-calendar' ) . '</label>';
            echo '<select name="calendar" id="dendrila-oauth-calendar" required>';
            foreach ( $pending['calendars'] as $id => $name ) {
                echo '<option value="' . esc_attr( $id ) . '">' . esc_html( $name ) . '</option>';
            }
            echo '</select><fieldset><legend>' . esc_html__( 'Which appointments should be sent?', 'dendrila-calendar' ) . '</legend>';
            foreach ( Dendrila_Calendar_Sources::available() as $source => $label ) {
                echo '<label class="dendrila-source-option"><input type="checkbox" name="sources[]" value="' . esc_attr( $source ) . '" checked> ' . esc_html( $label ) . '</label>';
            }
            echo '</fieldset><p><button type="submit" class="button button-primary">' . esc_html__( 'Test and connect this calendar', 'dendrila-calendar' ) . '</button></p></form>';
        } else {
            foreach ( array( 'google' => 'Google Calendar', 'microsoft' => 'Microsoft Outlook' ) as $provider => $label ) {
                echo '<form class="dendrila-oauth-provider" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="dendrila_calendar_oauth_start">';
                echo '<input type="hidden" name="provider" value="' . esc_attr( $provider ) . '">';
                wp_nonce_field( 'dendrila_calendar_oauth_start' );
                echo '<button class="button button-primary" type="submit" ' . disabled( ! self::ready( $provider ), true, false ) . '>' .
                    esc_html( sprintf( __( 'Connect with %s', 'dendrila-calendar' ), $label ) ) . '</button>';
                if ( ! self::ready( $provider ) ) {
                    echo '<p class="description">' . esc_html__( 'Direct authorization requires the publisher to configure the Dendrila OAuth application once. Users do not need developer credentials.', 'dendrila-calendar' ) . '</p>';
                }
                echo '</form>';
            }
            echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-sync&dendrila_method=subscription' ) ) . '">' .
                esc_html__( 'Use a read-only calendar subscription instead', 'dendrila-calendar' ) . '</a></p>';
        }
        foreach ( self::connections() as $id => $item ) {
            echo '<div class="dendrila-bridge-connection"><h3>' . esc_html( $item['label'] ) . ' (' . esc_html( $item['provider'] ) . ')</h3>';
            echo '<p>' . esc_html( $item['last_run'] ?: __( 'Never synchronized', 'dendrila-calendar' ) ) . '</p>';
            if ( ! empty( $item['last_error'] ) ) {
                echo '<p class="notice notice-warning inline">' . esc_html( $item['last_error'] ) . '</p>';
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
