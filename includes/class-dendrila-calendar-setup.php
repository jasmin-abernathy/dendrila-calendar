<?php
/** Mandatory first-run configuration and rate-limited connector compatibility reports. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar_Setup {
    const OPTION = 'dendrila_calendar_setup';
    const REDIRECT_OPTION = 'dendrila_calendar_setup_redirect';
    const REPORTS_OPTION = 'dendrila_calendar_connector_reports';

    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
        add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
        add_action( 'admin_post_dendrila_calendar_setup_save', array( __CLASS__, 'save' ) );
        add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
    }

    public static function settings() {
        $stored = get_option( self::OPTION, array() );
        return is_array( $stored ) ? $stored : array();
    }

    public static function activate() {
        if ( false === get_option( self::OPTION, false ) ) {
            add_option( self::OPTION, array( 'completed' => false, 'sources' => array(), 'mode' => '' ), '', false );
        }
        if ( ! self::complete() ) {
            update_option( self::REDIRECT_OPTION, 1, false );
        }
    }

    /** A saved destination choice is not an established calendar connection. */
    public static function destination_ready( $settings ) {
        if ( ! is_array( $settings ) || empty( $settings['sources'] ) ) {
            return false;
        }
        $mode = isset( $settings['mode'] ) ? $settings['mode'] : '';
        if ( 'local' === $mode ) {
            return true;
        }
        // The destination must actually contain every source chosen in setup.
        if ( 'caldav' === $mode && class_exists( 'Dendrila_Calendar_Bridge' ) ) {
            $connections = Dendrila_Calendar_Bridge::connections();
        } elseif ( 'subscription' === $mode && class_exists( 'Dendrila_Calendar_Subscriptions' ) ) {
            $connections = Dendrila_Calendar_Subscriptions::subscriptions();
        } elseif ( 'oauth' === $mode && class_exists( 'Dendrila_Calendar_OAuth' ) ) {
            $connections = Dendrila_Calendar_OAuth::connections();
        } else {
            return false;
        }
        foreach ( $connections as $connection ) {
            if ( ! is_array( $connection ) || empty( $connection['sources'] ) ) {
                continue;
            }
            // A Google account cannot silently complete a Microsoft setup, or vice versa.
            if ( 'oauth' === $mode && ( $connection['provider'] ?? '' ) !== ( $settings['calendar_service'] ?? '' ) ) {
                continue;
            }
            if ( ! array_diff( $settings['sources'], $connection['sources'] ) ) {
                return true;
            }
        }
        return false;
    }

    public static function complete() {
        $settings = self::settings();
        return ! empty( $settings['completed'] ) && self::destination_ready( $settings );
    }

    /** Null means no source selection has been saved yet; empty means all were disabled. */
    public static function selected_sources() {
        $settings = self::settings();
        return isset( $settings['sources'] ) && is_array( $settings['sources'] ) && ! empty( $settings['sources'] )
            ? array_values( $settings['sources'] )
            : null;
    }

    public static function menu() {
        add_submenu_page(
            'dendrila-calendar',
            __( 'Dendrila Calendar setup', 'dendrila-calendar' ),
            __( 'Configuration', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar-setup',
            array( __CLASS__, 'render' )
        );
    }

    /** Redirect only the plugin's administrator pages; never trap the whole WP admin. */
    public static function maybe_redirect() {
        if ( ! current_user_can( 'manage_options' ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
            return;
        }
        global $pagenow;
        if ( in_array( $pagenow, array( 'admin-post.php', 'admin-ajax.php' ), true ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin-only navigation slug, sanitized and used only for routing/notices.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'dendrila-calendar-setup' === $page ) {
            delete_option( self::REDIRECT_OPTION );
            return;
        }
        $pending = (bool) get_option( self::REDIRECT_OPTION, false );
        // Offer the wizard on first activation only. Do not hide the calendar or
        // synchronization pages if a remote service has not been configured.
        if ( $pending ) {
            delete_option( self::REDIRECT_OPTION );
            if ( ! self::complete() && 0 !== strpos( $page, 'dendrila-calendar' ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=dendrila-calendar-setup' ) );
                exit;
            }
        }
    }

    public static function notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin-only navigation slug, sanitized and used only for routing/notices.
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'dendrila-calendar-sync' === $page && ! self::complete() ) {
            echo '<div class="notice notice-info"><p>' . esc_html__( 'Finish by connecting your calendar account or creating an optional private calendar subscription.', 'dendrila-calendar' ) . '</p></div>';
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only report counter; absint() sanitized.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Informational notice only; absint() sanitized.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only count from the redirect; absint() enforced.
        $reports_sent = isset( $_GET['reports_sent'] ) ? absint( $_GET['reports_sent'] ) : 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only report counter; absint() sanitized.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Informational notice only; absint() sanitized.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only count from the redirect; absint() enforced.
        $reports_failed = isset( $_GET['reports_failed'] ) ? absint( $_GET['reports_failed'] ) : 0;
            if ( $reports_sent ) {
                echo '<div class="notice notice-info"><p>' . esc_html__( 'A connector compatibility request was submitted for review.', 'dendrila-calendar' ) . '</p></div>';
            }
            if ( $reports_failed ) {
                echo '<div class="notice notice-warning"><p>' . esc_html__( 'A compatibility request could not be submitted. Check your WordPress email delivery settings.', 'dendrila-calendar' ) . '</p></div>';
            }
            return;
        }
        if ( self::complete() ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( $screen && 'dendrila-calendar_page_dendrila-calendar-setup' === $screen->id ) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__( 'Finish the Dendrila Calendar setup to enable your chosen appointment sources and destination.', 'dendrila-calendar' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-setup' ) ) . '">' . esc_html__( 'Open configuration assistant', 'dendrila-calendar' ) . '</a></p></div>';
    }

    /** Only source identifiers whose adapters are ready can be configured. */
    public static function ready_sources( $detected ) {
        $ready = array( 'native' );
        foreach ( $detected as $id => $details ) {
            if ( ! empty( $details['ready'] ) ) {
                $ready[] = $id;
            }
        }
        return $ready;
    }

    public static function technical_report( $details, $wp_version, $php_version, $site_url ) {
        $fields = array(
            'Plugin' => $details['label'] ?? '',
            'Plugin entrypoint' => $details['plugin_file'] ?? '',
            'Plugin version' => $details['version'] ?? 'unknown',
            'Source identifier' => $details['source_id'] ?? '',
            'Status' => $details['state'] ?? '',
            'Problem' => $details['reason'] ?? '',
            'WordPress' => $wp_version,
            'PHP' => $php_version,
            'Site' => $site_url,
            'Dendrila Calendar' => '0.0.1',
        );
        $lines = array( 'Dendrila Calendar: request to review a connector.', '' );
        foreach ( $fields as $label => $value ) {
            $clean = trim( sanitize_text_field( (string) $value ) );
            $lines[] = $label . ': ' . substr( $clean, 0, 250 );
        }
        $lines[] = '';
        $lines[] = 'Automatically detected during the administrator setup. No bookings, customer details or credentials are included.';
        return implode( "\n", $lines );
    }

    /** Send a request only for an active unusable connector; dedupe by plugin, version and reason. */
    public static function report_unusable( $detected ) {
        $reports = get_option( self::REPORTS_OPTION, array() );
        $reports = is_array( $reports ) ? $reports : array();
        $sent = 0;
        $failed = 0;
        foreach ( $detected as $details ) {
            if ( empty( $details['active'] ) || ! in_array( $details['state'] ?? '', array( 'unsupported', 'unavailable' ), true ) ) {
                continue;
            }
            $key = hash( 'sha256', ( $details['plugin_file'] ?? '' ) . '|' . ( $details['version'] ?? '' ) . '|' . ( $details['state'] ?? '' ) . '|' . ( $details['reason'] ?? '' ) );
            if ( isset( $reports[ $key ] ) ) {
                if ( ! empty( $reports[ $key ]['sent'] ) || ( time() - (int) $reports[ $key ]['attempted'] ) < DAY_IN_SECONDS ) {
                    continue;
                }
            }
            $body = self::technical_report( $details, get_bloginfo( 'version' ), PHP_VERSION, home_url( '/' ) );
            $subject = '[Dendrila Calendar] Connector request: ' . substr( sanitize_text_field( $details['label'] ), 0, 90 );
            // This address is a server-side support destination; never expose it in HTML/JS.
            try {
                $accepted = (bool) wp_mail( 'jasmin@lepotager.org', $subject, $body );
            } catch ( Throwable $error ) {
                $accepted = false;
            }
            $reports[ $key ] = array( 'sent' => $accepted, 'attempted' => time() );
            $accepted ? ++$sent : ++$failed;
        }
        // Avoid autoloading support diagnostics on every public page request.
        update_option( self::REPORTS_OPTION, $reports, false );
        return array( 'sent' => $sent, 'failed' => $failed );
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to configure Dendrila Calendar.', 'dendrila-calendar' ) );
        }
        check_admin_referer( 'dendrila_calendar_setup' );
        $detected = Dendrila_Calendar_Detector::discover();
        $ready = self::ready_sources( $detected );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each submitted source is sanitize_key()'d and intersected with installed, ready adapters.
        $submitted = isset( $_POST['sources'] ) && is_array( $_POST['sources'] ) ? wp_unslash( $_POST['sources'] ) : array();
        $requested = array_map( 'sanitize_key', array_filter( $submitted, 'is_string' ) );
        $selected = array_values( array_unique( array_intersect( $requested, $ready ) ) );
        if ( ! $selected ) {
            wp_safe_redirect( admin_url( 'admin.php?page=dendrila-calendar-setup&setup_status=source_required' ) );
            exit;
        }
        // Existing WordPress setups may still submit the old destination field.
        $provider = isset( $_POST['calendar_service'] ) ? sanitize_key( wp_unslash( $_POST['calendar_service'] ) ) : '';
        $destination = isset( $_POST['destination'] ) ? sanitize_key( wp_unslash( $_POST['destination'] ) ) : 'caldav';
        $modes = array(
            'nextcloud' => 'caldav', 'icloud' => 'caldav', 'caldav' => 'caldav',
            'google' => class_exists( 'Dendrila_Calendar_OAuth' ) && Dendrila_Calendar_OAuth::ready( 'google' ) ? 'oauth' : 'subscription',
            'microsoft' => class_exists( 'Dendrila_Calendar_OAuth' ) && Dendrila_Calendar_OAuth::ready( 'microsoft' ) ? 'oauth' : 'subscription',
            'local' => 'local',
        );
        if ( $provider ) {
            if ( ! isset( $modes[ $provider ] ) ) {
                wp_safe_redirect( admin_url( 'admin.php?page=dendrila-calendar-setup&setup_status=destination_invalid' ) );
                exit;
            }
            $destination = $modes[ $provider ];
        } elseif ( ! in_array( $destination, array( 'local', 'caldav', 'subscription' ), true ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=dendrila-calendar-setup&setup_status=destination_invalid' ) );
            exit;
        }
        $settings = array( 'completed' => false, 'sources' => $selected, 'mode' => $destination, 'calendar_service' => $provider );
        $complete = self::destination_ready( $settings );
        $settings['completed'] = $complete;
        update_option( self::OPTION, $settings, false );
        $report = self::report_unusable( $detected );
        // The wizard intentionally never reveals the support email destination.
        $params = array(
            'page' => $complete ? 'dendrila-calendar' : 'dendrila-calendar-sync',
            'setup_status' => $complete ? 'saved' : 'connect_destination',
            'dendrila_method' => in_array( $destination, array( 'subscription', 'oauth' ), true ) ? $destination : 'direct',
            'reports_sent' => (int) $report['sent'],
            'reports_failed' => (int) $report['failed'],
        );
        wp_safe_redirect( add_query_arg( $params, admin_url( 'admin.php' ) ) );
        exit;
    }

    /** Called by the actual destination connector after successful creation. */
    public static function destination_connected() {
        $settings = self::settings();
        if ( self::destination_ready( $settings ) ) {
            $settings['completed'] = true;
            update_option( self::OPTION, $settings, false );
        }
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to configure Dendrila Calendar.', 'dendrila-calendar' ) );
        }
        $detected = Dendrila_Calendar_Detector::discover();
        $saved = self::settings();
        $ready = self::ready_sources( $detected );
        $selected = ! empty( $saved['sources'] ) ? $saved['sources'] : $ready;
        $mode = ! empty( $saved['mode'] ) ? $saved['mode'] : 'caldav';
        $calendar_service = ! empty( $saved['calendar_service'] ) ? $saved['calendar_service'] : ( 'subscription' === $mode ? 'google' : ( 'local' === $mode ? 'local' : 'nextcloud' ) );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only setup notice, sanitized and checked against known values.
        $status = isset( $_GET['setup_status'] ) ? sanitize_key( wp_unslash( $_GET['setup_status'] ) ) : '';
        echo '<div class="wrap dendrila-calendar-setup"><h1>' . esc_html__( 'Configure Dendrila Calendar', 'dendrila-calendar' ) . '</h1>';
        echo '<p>' . esc_html__( 'This assistant detects active appointment plugins and guides external synchronization. You can view the local calendar before connecting an external destination.', 'dendrila-calendar' ) . '</p>';
        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar' ) ) . '">' . esc_html__( 'Open calendar view', 'dendrila-calendar' ) . '</a></p>';
        // The native booking path requires a profile; legacy calendar sources
        // remain available while their administrators migrate at their pace.
        if ( class_exists( 'Dendrila_Calendar_Native' ) ) {
            $has_profile = Dendrila_Calendar_Native::has_profile();
            echo '<section class="dendrila-setup-profile-step" aria-labelledby="dendrila-setup-profile-title">';
            echo '<h2 id="dendrila-setup-profile-title">' . esc_html__( 'Native booking setup — create a profile first', 'dendrila-calendar' ) . '</h2>';
            echo '<p>' . esc_html__( 'Every new native reservation must belong to a profile. Add at least one professional or resource before creating bookable services.', 'dendrila-calendar' ) . '</p>';
            if ( $has_profile ) {
                echo '<p class="notice notice-success inline">' . esc_html__( 'At least one active profile is ready.', 'dendrila-calendar' ) . '</p>';
            } else {
                echo '<p class="notice notice-warning inline">' . esc_html__( 'Required for native bookings: no active profile has been created.', 'dendrila-calendar' ) . '</p>';
            }
            echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . Dendrila_Calendar_Native::PROFILE ) ) . '">' .
                esc_html__( 'Create a profile', 'dendrila-calendar' ) . '</a> <a class="button" href="' .
                esc_url( admin_url( 'admin.php?page=dendrila-calendar-profiles' ) ) . '">' .
                esc_html__( 'Manage profiles', 'dendrila-calendar' ) . '</a></p></section>';
        }
        if ( 'saved' === $status ) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Configuration saved.', 'dendrila-calendar' ) . '</p></div>';
        } elseif ( in_array( $status, array( 'source_required', 'destination_invalid' ), true ) ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Select at least one available source and a valid destination.', 'dendrila-calendar' ) . '</p></div>';
        }
        $reports_sent = isset( $_GET['reports_sent'] ) ? absint( $_GET['reports_sent'] ) : 0;
        $reports_failed = isset( $_GET['reports_failed'] ) ? absint( $_GET['reports_failed'] ) : 0;
        if ( $reports_sent ) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__( 'A connector compatibility request was submitted for review.', 'dendrila-calendar' ) . '</p></div>';
        }
        if ( $reports_failed ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A compatibility request could not be submitted. Check your WordPress email delivery settings.', 'dendrila-calendar' ) . '</p></div>';
        }
        echo '<form method="post" id="dendrila-calendar-setup-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="dendrila_calendar_setup_save">';
        wp_nonce_field( 'dendrila_calendar_setup' );
        echo '<section id="dendrila-setup-sources">';
        echo '<h2>' . esc_html__( 'Step 1 — Detected appointment sources', 'dendrila-calendar' ) . '</h2>';
        echo '<p>' . esc_html__( 'Compatible active plugins are preselected. You may deselect them. Inactive or unsupported plugins cannot be synchronized.', 'dendrila-calendar' ) . '</p>';
        echo '<fieldset><label><input type="checkbox" name="sources[]" value="native" ' . checked( in_array( 'native', $selected, true ), true, false ) . '> ' . esc_html__( 'Dendrila local events', 'dendrila-calendar' ) . '</label></fieldset>';
        if ( ! $detected ) {
            echo '<p>' . esc_html__( 'No supported appointment plugin was detected. You can start with local events and return to setup later.', 'dendrila-calendar' ) . '</p>';
        }
        foreach ( $detected as $id => $details ) {
            $name = $details['label'];
            $version = $details['version'];
            $state = $details['state'];
            $usable = ! empty( $details['ready'] );
            echo '<div class="dendrila-setup-source" style="margin:0.75em 0;padding:0.75em;border:1px solid #ccd0d4;border-radius:5px">';
            echo '<label><input type="checkbox" name="sources[]" value="' . esc_attr( $id ) . '" ' . ( $usable ? checked( in_array( $id, $selected, true ), true, false ) : 'disabled' ) . '> <strong>' . esc_html( $name ) . '</strong></label>';
            echo ' <span class="description">' . esc_html( 'v' . $version ) . ' — ' . esc_html( $state ) . '</span>';
            if ( ! $usable ) {
                echo '<p class="description">' . esc_html( $details['reason'] ) . '</p>';
            }
            echo '</div>';
        }
        echo '<p><button type="button" class="button button-primary" id="dendrila-setup-next" hidden>' . esc_html__( 'Continue', 'dendrila-calendar' ) . '</button></p>';
        echo '<p id="dendrila-setup-feedback" role="status"></p></section>';
        echo '<section id="dendrila-setup-destination">';
        echo '<h2>' . esc_html__( 'Step 2 — Your destination calendar', 'dendrila-calendar' ) . '</h2>';
        echo '<p>' . esc_html__( 'Choose how appointments reach the calendar you already use.', 'dendrila-calendar' ) . '</p>';
        $choices = array(
            'nextcloud' => __( 'Nextcloud (direct connection)', 'dendrila-calendar' ),
            'icloud' => __( 'Apple iCloud (app-specific password)', 'dendrila-calendar' ),
            'google' => __( 'Google Calendar', 'dendrila-calendar' ),
            'microsoft' => __( 'Outlook / Microsoft 365', 'dendrila-calendar' ),
            'caldav' => __( 'Another calendar service', 'dendrila-calendar' ),
            'local' => __( 'Keep my appointments inside WordPress only', 'dendrila-calendar' ),
        );
        echo '<fieldset class="dendrila-calendar-services"><legend>' . esc_html__( 'Where should your appointments appear?', 'dendrila-calendar' ) . '</legend>';
        foreach ( $choices as $id => $label ) {
            echo '<p><label><input type="radio" name="calendar_service" value="' . esc_attr( $id ) . '" ' . checked( $calendar_service, $id, false ) . '> ' . esc_html( $label ) . '</label></p>';
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__( 'Google and Outlook can authorize access when the Dendrila OAuth application is enabled by the publisher. Otherwise a read-only calendar subscription remains available.', 'dendrila-calendar' ) . '</p>';
        echo '<p class="description">' . esc_html__( 'If an active booking plugin is detected but its connector is unusable, saving sends a technical compatibility request for review. This includes only the plugin name, entrypoint, version, reason, WordPress/PHP versions and site URL — never appointments, customer data or passwords. Duplicate requests are suppressed.', 'dendrila-calendar' ) . '</p>';
        submit_button( __( 'Save settings and continue', 'dendrila-calendar' ) );
        echo '<p><button type="button" class="button" id="dendrila-setup-back" hidden>' . esc_html__( 'Back', 'dendrila-calendar' ) . '</button></p>';
        echo '</section></form>';
        if ( ! self::complete() && ! empty( $saved['mode'] ) && 'local' !== $saved['mode'] ) {
            echo '<p class="notice notice-warning inline">' . esc_html__( 'No external destination connected yet. Complete the connection on the Synchronization page.', 'dendrila-calendar' ) . '</p>';
            echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-sync' ) ) . '" class="button button-primary">' . esc_html__( 'Finish connecting my calendar', 'dendrila-calendar' ) . '</a></p>';
        }
        echo '</div>';
    }
}
