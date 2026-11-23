<?php
/** Push selected WordPress appointments into an existing CalDAV calendar. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar_Bridge {
    const OPTION = 'dendrila_calendar_direct_connections';
    const CRON_HOOK = 'dendrila_calendar_push_connections';
    const MAX_CONNECTIONS = 5;
    const MAX_ITEMS = 500;

    public static function boot() {
        add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) );
        add_action( 'init', array( __CLASS__, 'ensure_cron' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_all' ) );
        add_action( 'admin_post_dendrila_calendar_bridge_connect', array( __CLASS__, 'connect' ) );
        add_action( 'admin_post_dendrila_calendar_bridge_remove', array( __CLASS__, 'remove' ) );
        add_action( 'admin_post_dendrila_calendar_bridge_sync', array( __CLASS__, 'sync_now' ) );
    }

    public static function schedules( $schedules ) {
        $schedules['dendrila_calendar_5_minutes'] = array( 'interval' => 300, 'display' => 'Every 5 minutes' );
        return $schedules;
    }

    public static function ensure_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 60, 'dendrila_calendar_5_minutes', self::CRON_HOOK );
        }
    }

    public static function connections() {
        $items = get_option( self::OPTION, array() );
        return is_array( $items ) ? $items : array();
    }

    private static function save( $items ) {
        return update_option( self::OPTION, $items, false );
    }

    private static function key() {
        return hash( 'sha256', wp_salt( 'auth' ), true );
    }

    public static function protect( $secret ) {
        if ( ! is_string( $secret ) || '' === $secret ) {
            return false;
        }
        try {
            if ( function_exists( 'sodium_crypto_secretbox' ) ) {
                $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                return 's:' . base64_encode( $nonce . sodium_crypto_secretbox( $secret, $nonce, self::key() ) );
            }
            if ( function_exists( 'openssl_encrypt' ) ) {
                $nonce = random_bytes( 12 );
                $tag = '';
                $encrypted = openssl_encrypt( $secret, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag );
                if ( false !== $encrypted ) {
                    return 'o:' . base64_encode( $nonce . $tag . $encrypted );
                }
            }
        } catch ( Throwable $error ) {
            return false;
        }
        return false; // Never fall back to plaintext.
    }

    public static function reveal( $value ) {
        if ( ! is_string( $value ) || strlen( $value ) > 20000 ) {
            return false;
        }
        $binary = base64_decode( substr( $value, 2 ), true );
        if ( false === $binary ) {
            return false;
        }
        if ( 0 === strpos( $value, 's:' ) && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $binary ) > 40 ) {
            return sodium_crypto_secretbox_open(
                substr( $binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
                substr( $binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
                self::key()
            );
        }
        if ( 0 === strpos( $value, 'o:' ) && function_exists( 'openssl_decrypt' ) && strlen( $binary ) >= 29 ) {
            return openssl_decrypt( substr( $binary, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $binary, 0, 12 ), substr( $binary, 12, 16 ) );
        }
        return false;
    }

    public static function validate_endpoint( $url, $collection = true ) {
        if ( ! is_string( $url ) || strlen( $url ) > 1000 ) {
            return false;
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ||
            isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return false;
        }
        // WordPress' safe HTTP transport validates DNS and rejects private destinations.
        if ( ! function_exists( 'wp_http_validate_url' ) || ! wp_http_validate_url( $url ) ) {
            return false;
        }
        return $collection ? trailingslashit( $url ) : $url;
    }

    private static function request( $url, $user, $password, $method, $body = '', $headers = array() ) {
        $safe = self::validate_endpoint( $url, false );
        if ( ! $safe ) {
            return new WP_Error( 'dendrila_calendar_invalid_destination', 'Invalid destination.' );
        }
        $args = array(
            'method' => $method,
            'timeout' => 12,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array_merge( array(
                'Authorization' => 'Basic ' . base64_encode( $user . ':' . $password ),
                'User-Agent' => 'Dendrila Calendar 0.0.1',
            ), $headers ),
            'body' => $body,
            'limit_response_size' => 10000,
        );
        return wp_safe_remote_request( $url, $args );
    }

    private static function destination_request( $connection, $method, $body = '', $suffix = '', $headers = array() ) {
        $password = self::reveal( $connection['password'] ?? '' );
        if ( false === $password ) {
            return new WP_Error( 'dendrila_calendar_credentials', 'Credentials unavailable.' );
        }
        $url = $connection['url'] . $suffix;
        return self::request( $url, $connection['username'], $password, $method, $body, $headers );
    }

    public static function probe( $url, $user, $password ) {
        $body = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>';
        $result = self::request( $url, $user, $password, 'PROPFIND', $body, array( 'Depth' => '0', 'Content-Type' => 'application/xml; charset=utf-8' ) );
        // A successful CalDAV calendar collection responds with 207 Multi-Status.
        return ! is_wp_error( $result ) && 207 === (int) wp_remote_retrieve_response_code( $result ) &&
            false !== stripos( (string) wp_remote_retrieve_body( $result ), 'calendar' );
    }

    private static function permitted() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to manage calendar connections.', 'dendrila-calendar' ) );
        }
        check_admin_referer( 'dendrila_calendar_direct_connection' );
    }

    private static function redirect( $status ) {
        wp_safe_redirect( add_query_arg( 'bridge_status', $status, admin_url( 'admin.php?page=dendrila-calendar-sync' ) ) );
        exit;
    }

    public static function connect() {
        self::permitted();
        $items = self::connections();
        if ( count( $items ) >= self::MAX_CONNECTIONS ) {
            self::redirect( 'limit' );
        }
        $url = isset( $_POST['calendar_url'] ) && is_string( $_POST['calendar_url'] ) ? self::validate_endpoint( esc_url_raw( wp_unslash( $_POST['calendar_url'] ) ) ) : false;
        $username = isset( $_POST['username'] ) && is_string( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
        $password = isset( $_POST['app_password'] ) && is_string( $_POST['app_password'] ) ? wp_unslash( $_POST['app_password'] ) : '';
        $label = isset( $_POST['label'] ) && is_string( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : 'CalDAV';
        $provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'caldav';
        $token = isset( $_POST['discovery_token'] ) && is_string( $_POST['discovery_token'] ) ? sanitize_text_field( wp_unslash( $_POST['discovery_token'] ) ) : '';
        if ( ! class_exists( 'Dendrila_Calendar_Discovery' ) ||
            ! in_array( $provider, array( 'nextcloud', 'caldav', 'icloud' ), true ) ||
            ! Dendrila_Calendar_Discovery::verified_choice( $token, $url, $provider, $username ) ) {
            self::redirect( 'invalid' );
        }
        $selected = isset( $_POST['sources'] ) && is_array( $_POST['sources'] ) ? wp_unslash( $_POST['sources'] ) : array();
        $sources = Dendrila_Calendar_Subscriptions::allowed_sources( $selected, Dendrila_Calendar_Sources::available() );
        if ( ! $url || ! $sources || '' === trim( $username ) || '' === $password || strlen( $password ) > 4096 ) {
            self::redirect( 'invalid' );
        }
        $encrypted = self::protect( $password );
        if ( false === $encrypted ) {
            self::redirect( 'crypto' );
        }
        if ( ! self::probe( $url, $username, $password ) ) {
            self::redirect( 'probe' );
        }
        $id = bin2hex( random_bytes( 8 ) );
        $items[ $id ] = array(
            'label' => wp_html_excerpt( $label ?: 'CalDAV', 60, '' ),
            'provider' => $provider,
            'url' => $url,
            'username' => $username,
            'password' => $encrypted,
            'sources' => $sources,
            'map' => array(),
            'last_run' => '',
            'last_error' => '',
        );
        self::save( $items );
        Dendrila_Calendar_Discovery::consume_choice( $token );
        // Run once immediately so a successful connection has meaningful status.
        self::run_one( $id );
        if ( class_exists( 'Dendrila_Calendar_Setup' ) ) {
            Dendrila_Calendar_Setup::destination_connected();
        }
        self::redirect( 'connected' );
    }

    public static function remove() {
        self::permitted();
        $id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
        $items = self::connections();
        if ( isset( $items[ $id ] ) ) {
            unset( $items[ $id ] );
            self::save( $items );
        }
        // Existing remote events are deliberately left untouched on disconnect.
        self::redirect( 'removed' );
    }

    public static function sync_now() {
        self::permitted();
        $id = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
        if ( ! isset( self::connections()[ $id ] ) ) {
            self::redirect( 'invalid' );
        }
        self::run_one( $id );
        self::redirect( 'synced' );
    }

    public static function status_is_cancelled( $status ) {
        return in_array( strtolower( (string) $status ), array( 'cancelled', 'canceled', 'rejected', 'refunded', 'deleted' ), true );
    }

    public static function identity( $event ) {
        return hash( 'sha256', home_url( '/' ) . '|' . $event['source'] . '|' . $event['id'] );
    }

    public static function event_filename( $event ) {
        return 'dc-' . self::identity( $event ) . '.ics';
    }

    public static function fingerprint( $event ) {
        return hash( 'sha256', wp_json_encode( array(
            $event['source'], $event['id'], $event['title'], $event['start'],
            $event['end'], $event['status'], $event['all_day'],
        ) ) );
    }

    public static function run_all() {
        foreach ( array_keys( self::connections() ) as $id ) {
            self::run_one( $id );
        }
    }

    public static function run_one( $id ) {
        $lock_key = 'dendrila_calendar_sync_' . $id;
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, 4 * MINUTE_IN_SECONDS );
        try {
            self::perform( $id );
        } finally {
            delete_transient( $lock_key );
        }
    }

    private static function perform( $id ) {
        $all = self::connections();
        if ( empty( $all[ $id ] ) ) {
            return;
        }
        $item = $all[ $id ];
        $selected = Dendrila_Calendar_Subscriptions::allowed_sources( $item['sources'], Dendrila_Calendar_Sources::available() );
        $stats = array( 'added' => 0, 'updated' => 0, 'cancelled' => 0, 'failed' => 0 );
        $error = '';
        if ( $selected ) {
            $now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
            $from = $now->modify( '-7 days' )->format( 'Y-m-d H:i:s' );
            $until = $now->modify( '+85 days' )->format( 'Y-m-d H:i:s' );
            // Import adapters are read-only. Only the destination is written.
            $events = array_slice( Dendrila_Calendar_Sources::events( $from, $until, $selected ), 0, self::MAX_ITEMS );
            $map = isset( $item['map'] ) && is_array( $item['map'] ) ? $item['map'] : array();
            foreach ( $events as $event ) {
                if ( empty( $event['id'] ) || empty( $event['source'] ) ) {
                    continue;
                }
                $key = self::identity( $event );
                $old = isset( $map[ $key ] ) ? $map[ $key ] : null;
                $filename = self::event_filename( $event );
                if ( self::status_is_cancelled( $event['status'] ) ) {
                    if ( $old ) {
                        $result = self::destination_request( $item, 'DELETE', '', $filename );
                        $status = is_wp_error( $result ) ? 0 : (int) wp_remote_retrieve_response_code( $result );
                        if ( in_array( $status, array( 200, 202, 204, 404 ), true ) ) {
                            unset( $map[ $key ] );
                            ++$stats['cancelled'];
                        } else {
                            ++$stats['failed'];
                        }
                    }
                    continue;
                }
                $fingerprint = self::fingerprint( $event );
                if ( $old && isset( $old['fingerprint'] ) && hash_equals( $old['fingerprint'], $fingerprint ) ) {
                    continue;
                }
                $ical = Dendrila_Calendar_Subscriptions::build_ical( array( $event ), array( $event['source'] => $event['source'] ), home_url( '/' ), wp_timezone() );
                $result = self::destination_request( $item, 'PUT', $ical, $filename, array( 'Content-Type' => 'text/calendar; charset=utf-8' ) );
                $status = is_wp_error( $result ) ? 0 : (int) wp_remote_retrieve_response_code( $result );
                if ( in_array( $status, array( 200, 201, 204 ), true ) ) {
                    $map[ $key ] = array( 'filename' => $filename, 'fingerprint' => $fingerprint, 'source' => $event['source'] );
                    if ( $old ) {
                        ++$stats['updated'];
                    } else {
                        ++$stats['added'];
                    }
                } else {
                    ++$stats['failed'];
                }
            }
            $item['map'] = $map;
        } else {
            $error = 'No active sources';
        }
        if ( $stats['failed'] ) {
            $error = 'Some remote operations failed';
        }
        $item['last_run'] = gmdate( 'Y-m-d H:i:s' );
        $item['last_error'] = $error;
        $item['last_stats'] = $stats;
        // Reload to avoid dropping a concurrently added/removed connection.
        $latest = self::connections();
        if ( isset( $latest[ $id ] ) ) {
            $latest[ $id ] = $item;
            self::save( $latest );
        }
    }

    public static function render_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $sources = Dendrila_Calendar_Sources::available();
        $status = isset( $_GET['bridge_status'] ) ? sanitize_key( wp_unslash( $_GET['bridge_status'] ) ) : '';
        $labels = array(
            'connected' => __( 'Calendar connected. Automatic direct synchronization is enabled.', 'dendrila-calendar' ),
            'synced' => __( 'Synchronization attempt finished. Review the status below.', 'dendrila-calendar' ),
            'removed' => __( 'Calendar disconnected. Previously created external events were not deleted.', 'dendrila-calendar' ),
            'invalid' => __( 'Check the calendar address, credentials and selected sources.', 'dendrila-calendar' ),
            'probe' => __( 'Cannot connect to that CalDAV calendar. Verify its calendar URL and application password.', 'dendrila-calendar' ),
            'crypto' => __( 'The server cannot securely protect the calendar password.', 'dendrila-calendar' ),
            'limit' => __( 'Too many connected calendars.', 'dendrila-calendar' ),
        );
        echo '<section class="dendrila-calendar-direct-sync" aria-labelledby="dendrila-calendar-direct-heading">';
        echo '<h2 id="dendrila-calendar-direct-heading">' . esc_html__( 'Direct synchronization into an existing calendar', 'dendrila-calendar' ) . '</h2>';
        echo '<p>' . esc_html__( 'Connect a CalDAV calendar such as Nextcloud. New and changed WordPress bookings are pushed automatically approximately every five minutes while WordPress cron runs. This is one-way; the destination is never polled for changes.', 'dendrila-calendar' ) . '</p>';
        if ( isset( $labels[ $status ] ) ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html( $labels[ $status ] ) . '</p></div>';
        }
        echo '<div class="dendrila-connect-card"><h3>' . esc_html__( '1. Choose your calendar service', 'dendrila-calendar' ) . '</h3>';
        echo '<p>' . esc_html__( 'We will guide you through the connection. You do not need an API key or a technical calendar address.', 'dendrila-calendar' ) . '</p>';
        echo '<label for="dendrila-connect-provider">' . esc_html__( 'Calendar service', 'dendrila-calendar' ) . '</label> ';
        echo '<select id="dendrila-connect-provider"><option value="nextcloud">Nextcloud</option><option value="icloud">Apple iCloud</option><option value="caldav">' . esc_html__( 'Other CalDAV service', 'dendrila-calendar' ) . '</option><option value="google">Google Calendar</option><option value="microsoft">Microsoft Outlook</option></select>';
        echo '<p id="dendrila-connect-provider-help" class="description"></p>';
        echo '</div>';
        echo '<form method="post" id="dendrila-direct-connect-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="dendrila_calendar_bridge_connect">';
        echo '<input type="hidden" name="provider" id="dendrila-direct-provider" value="nextcloud">';
        echo '<input type="hidden" name="calendar_url" id="dendrila-bridge-url" value="">';
        echo '<input type="hidden" name="discovery_token" id="dendrila-discovery-token" value="">';
        wp_nonce_field( 'dendrila_calendar_direct_connection' );
        echo '<div class="dendrila-connect-card" id="dendrila-connect-account">';
        echo '<h3>' . esc_html__( '2. Connect your account', 'dendrila-calendar' ) . '</h3>';
        echo '<p id="dendrila-server-field"><label for="dendrila-bridge-server">' . esc_html__( 'Your cloud address', 'dendrila-calendar' ) . '</label> ';
        echo '<input id="dendrila-bridge-server" type="text" inputmode="url" autocomplete="url" placeholder="cloud.example.org"></p>';
        echo '<p><label for="dendrila-bridge-user">' . esc_html__( 'Account username or email', 'dendrila-calendar' ) . '</label> ';
        echo '<input id="dendrila-bridge-user" name="username" autocomplete="username" required></p>';
        echo '<p><label for="dendrila-bridge-password">' . esc_html__( 'Application password', 'dendrila-calendar' ) . '</label> ';
        echo '<input id="dendrila-bridge-password" type="password" name="app_password" autocomplete="new-password" required></p>';
        echo '<p class="description">' . esc_html__( 'Use an application-specific password if your service requires one. Your password is encrypted before it is saved.', 'dendrila-calendar' ) . '</p>';
        echo '<p><button class="button button-primary" type="button" id="dendrila-discover-calendars">' . esc_html__( 'Find my calendars', 'dendrila-calendar' ) . '</button></p>';
        echo '<p id="dendrila-discovery-feedback" role="status" aria-live="polite"></p>';
        echo '</div>';
        echo '<div class="dendrila-connect-card" id="dendrila-calendar-choice" hidden>';
        echo '<h3>' . esc_html__( '3. Choose where to add appointments', 'dendrila-calendar' ) . '</h3>';
        echo '<p><label for="dendrila-discovered-calendar">' . esc_html__( 'Calendar', 'dendrila-calendar' ) . '</label> ';
        echo '<select id="dendrila-discovered-calendar"></select></p>';
        echo '<p><label for="dendrila-bridge-label">' . esc_html__( 'Connection name', 'dendrila-calendar' ) . '</label> ';
        echo '<input id="dendrila-bridge-label" name="label" maxlength="60" value="Nextcloud"></p>';
        echo '<fieldset><legend>' . esc_html__( 'Appointments to send', 'dendrila-calendar' ) . '</legend>';
        $initial = class_exists( 'Dendrila_Calendar_Setup' ) ? Dendrila_Calendar_Setup::selected_sources() : null;
        foreach ( $sources as $name => $source_label ) {
            $selected = null === $initial || in_array( $name, $initial, true );
            echo '<label class="dendrila-source-option"><input type="checkbox" name="sources[]" value="' . esc_attr( $name ) . '" ' . checked( $selected, true, false ) . '> ' . esc_html( $source_label ) . '</label> ';
        }
        echo '</fieldset>';
        echo '<p><button class="button button-primary" type="submit" id="dendrila-confirm-calendar">' . esc_html__( 'Connect this calendar', 'dendrila-calendar' ) . '</button></p>';
        echo '</div></form>';
        echo '<div id="dendrila-oauth-unavailable" class="dendrila-connect-card" hidden>';
        echo '<h3>' . esc_html__( 'Connect your account', 'dendrila-calendar' ) . '</h3>';
        echo '<p>' . esc_html__( 'Direct account authorization is not available yet for this service. Dendrila cannot connect to your account just by selecting its name.', 'dendrila-calendar' ) . '</p>';
        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-sync&dendrila_method=subscription' ) ) . '">' . esc_html__( 'Use a read-only calendar subscription instead', 'dendrila-calendar' ) . '</a></p></div>';
        foreach ( self::connections() as $id => $item ) {
            echo '<div class="dendrila-bridge-connection"><h3>' . esc_html( $item['label'] ) . '</h3>';
            echo '<p>' . esc_html( implode( ', ', $item['sources'] ) ) . ' — ' . esc_html( $item['last_run'] ?: __( 'Never synchronized', 'dendrila-calendar' ) ) . ' UTC</p>';
            if ( ! empty( $item['last_error'] ) ) {
                echo '<p class="notice notice-warning inline">' . esc_html( $item['last_error'] ) . '</p>';
            }
            if ( ! empty( $item['last_stats'] ) ) {
                echo '<p>' . esc_html( sprintf( __( '%d created, %d updated, %d cancelled, %d failed', 'dendrila-calendar' ), $item['last_stats']['added'], $item['last_stats']['updated'], $item['last_stats']['cancelled'], $item['last_stats']['failed'] ) ) . '</p>';
            }
            foreach ( array( 'sync' => __( 'Synchronize now', 'dendrila-calendar' ), 'remove' => __( 'Disconnect', 'dendrila-calendar' ) ) as $action => $button ) {
                echo '<form method="post" style="display:inline-block;margin-right:1em" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
                echo '<input type="hidden" name="action" value="dendrila_calendar_bridge_' . esc_attr( $action ) . '">';
                echo '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
                wp_nonce_field( 'dendrila_calendar_direct_connection' );
                echo '<button class="button" type="submit">' . esc_html( $button ) . '</button></form>';
            }
            echo '</div>';
        }
        echo '<p class="description">' . esc_html__( 'Google Calendar and Outlook need OAuth account authorization and are not connected by this CalDAV form. Existing URL subscriptions remain available separately.', 'dendrila-calendar' ) . '</p>';
        echo '</section>';
    }
}
