<?php
/** Safe CalDAV calendar discovery for non-technical WordPress administrators. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar_Discovery {
    const NONCE_ACTION = 'dendrila_calendar_discover';
    const MAX_RESULTS = 30;
    const RESPONSE_BYTES = 196608;
    const TOKEN_LIFETIME = 600;

    public static function boot() {
        add_action( 'wp_ajax_dendrila_calendar_discover', array( __CLASS__, 'ajax_discover' ) );
    }

    public static function initial_url( $provider, $address ) {
        if ( 'icloud' === $provider ) {
            return 'https://caldav.icloud.com/';
        }
        if ( ! is_string( $address ) || ! $address || ! in_array( $provider, array( 'nextcloud', 'caldav' ), true ) ) {
            return false;
        }
        $address = trim( $address );
        // A bare domain is enough. HTTPS and the full DAV path are filled automatically.
        if ( ! preg_match( '~^https?://~i', $address ) ) {
            $address = 'https://' . $address;
        }
        $parts = wp_parse_url( $address );
        if ( ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) ||
             empty( $parts['host'] ) || ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) ||
             isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return false;
        }
        if ( ! Dendrila_Calendar_Bridge::validate_endpoint( $address, false ) ) {
            return false;
        }
        $base = trailingslashit( $address );
        if ( 'nextcloud' === $provider && false === strpos( $parts['path'] ?? '', '/remote.php/dav' ) ) {
            return $base . 'remote.php/dav/';
        }
        return $base;
    }

    /** Prevent credentials from travelling to a new origin or a private IP. */
    public static function trusted_url( $candidate, $origin, $provider ) {
        if ( ! Dendrila_Calendar_Bridge::validate_endpoint( $candidate, false ) ) {
            return false;
        }
        $target = wp_parse_url( $candidate );
        $source = wp_parse_url( $origin );
        if ( ! is_array( $target ) || ! is_array( $source ) ) {
            return false;
        }
        if ( 'icloud' === $provider ) {
            $host = strtolower( $target['host'] ?? '' );
            return 443 === (int) ( $target['port'] ?? 443 ) &&
                ( 'caldav.icloud.com' === $host || (bool) preg_match( '/^p[0-9]+-caldav\.icloud\.com$/D', $host ) );
        }
        return strtolower( $target['host'] ?? '' ) === strtolower( $source['host'] ?? '' ) &&
            (int) ( $target['port'] ?? 443 ) === (int) ( $source['port'] ?? 443 );
    }

    /** Resolve DAV hrefs; validate every resolved URL before sending authentication. */
    public static function resolve_href( $href, $base, $origin, $provider ) {
        $href = is_string( $href ) ? $href : '';
        if ( '' === $href || strlen( $href ) > 1500 || 0 === strpos( $href, '//' ) || preg_match( '/[\x00-\x20\x7f]/', $href ) || false !== strpos( $href, chr( 92 ) ) ) {
            return false;
        }
        if ( preg_match( '~^https://~i', $href ) ) {
            $url = $href;
        } elseif ( 0 === strpos( $href, '/' ) ) {
            $parts = wp_parse_url( $base );
            $authority = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
            $url = 'https://' . $authority . $href;
        } elseif ( false === strpos( $href, ':' ) && false === strpos( $href, '?' ) && false === strpos( $href, '#' ) ) {
            $url = substr( $base, 0, strrpos( $base, '/' ) + 1 ) . $href;
        } else {
            return false;
        }
        return self::trusted_url( $url, $origin, $provider ) ? $url : false;
    }

    /** Extract DAV XML without resolving external entities or treating untrusted hrefs as URLs yet. */
    public static function parse_dav( $xml ) {
        if ( ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) || ! is_string( $xml ) ||
             '' === trim( $xml ) || strlen( $xml ) > self::RESPONSE_BYTES || false !== stripos( $xml, '<!DOCTYPE' ) ) {
            return false;
        }
        $doc = new DOMDocument();
        $old = libxml_use_internal_errors( true );
        try {
            $valid = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
            if ( ! $valid || $doc->doctype ) {
                return false;
            }
            $xp = new DOMXPath( $doc );
            $xp->registerNamespace( 'd', 'DAV:' );
            $xp->registerNamespace( 'c', 'urn:ietf:params:xml:ns:caldav' );
            if ( ! $xp->evaluate( 'boolean(/d:multistatus)' ) ) {
                return false;
            }
            $principal = '';
            $home = '';
            $calendars = array();
            foreach ( $xp->query( '/d:multistatus/d:response' ) as $response ) {
                $properties = 'd:propstat[substring(normalize-space(d:status), 10, 3) = "200"]/d:prop';
                if ( '' === $principal ) {
                    $principal = trim( $xp->evaluate( 'string(' . $properties . '/d:current-user-principal/d:href)', $response ) );
                }
                if ( '' === $home ) {
                    $home = trim( $xp->evaluate( 'string(' . $properties . '/c:calendar-home-set/d:href)', $response ) );
                }
                if ( ! $xp->evaluate( 'boolean(' . $properties . '/d:resourcetype/c:calendar)', $response ) ) {
                    continue;
                }
                $href = trim( $xp->evaluate( 'string(d:href)', $response ) );
                $label = trim( $xp->evaluate( 'string(' . $properties . '/d:displayname)', $response ) );
                if ( '' !== $href ) {
                    $calendars[] = array( 'href' => $href, 'name' => $label );
                }
            }
            return array( 'principal' => $principal, 'home' => $home, 'calendars' => $calendars );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors( $old );
        }
    }

    private static function propfind( $url, $origin, $provider, $user, $password, $depth, $elements ) {
        for ( $attempt = 0; $attempt < 3; ++$attempt ) {
            if ( ! self::trusted_url( $url, $origin, $provider ) ) {
                return new WP_Error( 'dendrila_unsafe_dav', __( 'The calendar server redirected to an unsafe address.', 'dendrila-calendar' ) );
            }
            $body = '<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop>' . $elements . '</d:prop></d:propfind>';
            $response = wp_safe_remote_request( $url, array(
                'method' => 'PROPFIND',
                'timeout' => 15,
                'redirection' => 0,
                'sslverify' => true,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $user . ':' . $password ),
                    'Depth' => (string) $depth,
                    'Content-Type' => 'application/xml; charset=UTF-8',
                    'User-Agent' => 'Dendrila Calendar 0.0.1',
                ),
                'body' => $body,
                'limit_response_size' => self::RESPONSE_BYTES,
            ) );
            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'dendrila_dav_transport', __( 'The calendar service could not be reached securely.', 'dendrila-calendar' ) );
            }
            $status = (int) wp_remote_retrieve_response_code( $response );
            if ( in_array( $status, array( 301, 302, 307, 308 ), true ) ) {
                $location = wp_remote_retrieve_header( $response, 'location' );
                $url = self::resolve_href( $location, $url, $origin, $provider );
                if ( ! $url ) {
                    return new WP_Error( 'dendrila_untrusted_dav_redirect', __( 'Calendar discovery was redirected to another service.', 'dendrila-calendar' ) );
                }
                continue;
            }
            if ( 207 !== $status ) {
                return new WP_Error( 'dendrila_dav_http', __( 'The calendar service did not accept the connection (', 'dendrila-calendar' ) . $status . ').' );
            }
            $parsed = self::parse_dav( wp_remote_retrieve_body( $response ) );
            if ( false === $parsed ) {
                return new WP_Error( 'dendrila_dav_xml', __( 'The calendar service returned an unreadable response. PHP DOM/XML may be unavailable.', 'dendrila-calendar' ) );
            }
            $parsed['response_url'] = $url;
            return $parsed;
        }
        return new WP_Error( 'dendrila_dav_redirects', __( 'Too many calendar redirects.', 'dendrila-calendar' ) );
    }

    public static function discover( $provider, $address, $user, $password ) {
        $origin = self::initial_url( $provider, $address );
        if ( ! $origin ) {
            return new WP_Error( 'dendrila_dav_address', __( 'Enter a valid HTTPS address for your calendar service.', 'dendrila-calendar' ) );
        }
        if ( ! is_string( $user ) || ! is_string( $password ) || '' === trim( $user ) || '' === $password || strlen( $password ) > 4096 ) {
            return new WP_Error( 'dendrila_dav_credentials', __( 'Enter your calendar account and application password.', 'dendrila-calendar' ) );
        }
        $principal_tags = '<d:current-user-principal/><d:resourcetype/>';
        $home_tags = '<c:calendar-home-set/><d:resourcetype/>';
        $collections = '<d:displayname/><d:resourcetype/><d:current-user-privilege-set/>';
        $first = self::propfind( $origin, $origin, $provider, $user, $password, 0, $principal_tags );
        if ( is_wp_error( $first ) ) {
            return $first;
        }
        $principal = self::resolve_href( $first['principal'], $first['response_url'], $origin, $provider );
        if ( ! $principal ) {
            return new WP_Error( 'dendrila_dav_principal', __( 'This server did not provide a calendar account. Check your service address.', 'dendrila-calendar' ) );
        }
        $person = self::propfind( $principal, $origin, $provider, $user, $password, 0, $home_tags );
        if ( is_wp_error( $person ) ) {
            return $person;
        }
        $home = self::resolve_href( $person['home'], $person['response_url'], $origin, $provider );
        if ( ! $home ) {
            return new WP_Error( 'dendrila_dav_home', __( 'This account did not expose a calendar collection.', 'dendrila-calendar' ) );
        }
        $listed = self::propfind( $home, $origin, $provider, $user, $password, 1, $collections );
        if ( is_wp_error( $listed ) ) {
            return $listed;
        }
        $calendars = array();
        foreach ( $listed['calendars'] as $entry ) {
            $url = self::resolve_href( $entry['href'], $listed['response_url'], $origin, $provider );
            if ( ! $url ) {
                continue;
            }
            $label = sanitize_text_field( $entry['name'] );
            if ( '' === $label ) {
                $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );
                $label = sanitize_text_field( rawurldecode( basename( $path ) ) );
            }
            $url = trailingslashit( $url );
            $calendars[ $url ] = array( 'url' => $url, 'name' => $label );
            if ( count( $calendars ) >= self::MAX_RESULTS ) {
                break;
            }
        }
        if ( ! $calendars ) {
            return new WP_Error( 'dendrila_dav_empty', __( 'No calendar was found in this account.', 'dendrila-calendar' ) );
        }
        return array_values( $calendars );
    }

    private static function transient_key( $token ) {
        return 'dendrila_dav_' . get_current_user_id() . '_' . substr( hash( 'sha256', $token ), 0, 30 );
    }

    public static function verified_choice( $token, $url, $provider, $username, $password ) {
        if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
            return false;
        }
        $data = get_transient( self::transient_key( $token ) );
        return is_array( $data ) && ( $data['provider'] ?? '' ) === $provider &&
            hash_equals( (string) ( $data['username'] ?? '' ), (string) $username ) &&
            hash_equals( (string) ( $data['proof'] ?? '' ), hash_hmac( 'sha256', $password, wp_salt( 'auth' ) . $token ) ) &&
            in_array( $url, $data['urls'] ?? array(), true );
    }

    public static function consume_choice( $token ) {
        delete_transient( self::transient_key( $token ) );
    }

    public static function ajax_discover() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Not authorized.', 'dendrila-calendar' ) ), 403 );
        }
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        $provider = isset( $_POST['provider'] ) && is_string( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'nextcloud';
        $address = isset( $_POST['server_url'] ) && is_string( $_POST['server_url'] ) ? sanitize_text_field( wp_unslash( $_POST['server_url'] ) ) : '';
        $username = isset( $_POST['username'] ) && is_string( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- App password is a secret; sanitization would corrupt it. The connector enforces type/length, HTTPS and never returns/stores plaintext.
        $password = isset( $_POST['app_password'] ) && is_string( $_POST['app_password'] ) ? wp_unslash( $_POST['app_password'] ) : '';
        if ( ! in_array( $provider, array( 'nextcloud', 'caldav', 'icloud' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Unsupported calendar service.', 'dendrila-calendar' ) ), 400 );
        }
        $key = 'dendrila_dav_requests_' . get_current_user_id();
        $attempts = (int) get_transient( $key );
        if ( $attempts >= 8 ) {
            wp_send_json_error( array( 'message' => __( 'Too many connection attempts. Please try again later.', 'dendrila-calendar' ) ), 429 );
        }
        set_transient( $key, $attempts + 1, 10 * MINUTE_IN_SECONDS );
        $calendars = self::discover( $provider, $address, $username, $password );
        if ( is_wp_error( $calendars ) ) {
            wp_send_json_error( array( 'message' => $calendars->get_error_message() ), 400 );
        }
        try {
            $token = bin2hex( random_bytes( 16 ) );
        } catch ( Throwable $error ) {
            wp_send_json_error( array( 'message' => __( 'Unable to prepare calendar selection.', 'dendrila-calendar' ) ), 500 );
        }
        set_transient( self::transient_key( $token ), array(
            'provider' => $provider,
            'username' => $username,
            'proof' => hash_hmac( 'sha256', $password, wp_salt( 'auth' ) . $token ),
            'urls' => array_column( $calendars, 'url' ),
        ), self::TOKEN_LIFETIME );
        wp_send_json_success( array( 'calendars' => $calendars, 'token' => $token ) );
    }
}
