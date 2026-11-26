<?php
/** Administration, local events and the calendar endpoint. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar {
    const VERSION = '0.0.6';
    const POST_TYPE = 'dendrila_event';
    const START_KEY = '_dendrila_calendar_start_utc';
    const END_KEY = '_dendrila_calendar_end_utc';

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'register_events' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_event_box' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_event' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_menu_icon_style' ) );
        add_action( 'admin_footer', array( __CLASS__, 'render_support_footer' ) );
        add_action( 'admin_notices', array( __CLASS__, 'local_events_notice' ) );
        add_action( 'wp_ajax_dendrila_calendar_events', array( __CLASS__, 'ajax_events' ) );
    }

    public static function register_events() {
        load_plugin_textdomain( 'dendrila-calendar', false, 'dendrila-calendar/languages' );
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name' => __( 'Personal events', 'dendrila-calendar' ),
                'menu_name' => __( 'Personal events', 'dendrila-calendar' ),
                'all_items' => __( 'Personal events', 'dendrila-calendar' ),
                'singular_name' => __( 'Calendar event', 'dendrila-calendar' ),
                'add_new_item' => __( 'Add calendar event', 'dendrila-calendar' ),
                'edit_item' => __( 'Edit calendar event', 'dendrila-calendar' ),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => array( 'title' ),
            'capabilities' => array(
                'edit_post' => 'manage_options',
                'read_post' => 'manage_options',
                'delete_post' => 'manage_options',
                'edit_posts' => 'manage_options',
                'edit_others_posts' => 'manage_options',
                'publish_posts' => 'manage_options',
                'read_private_posts' => 'manage_options',
                'delete_posts' => 'manage_options',
                'delete_private_posts' => 'manage_options',
                'delete_published_posts' => 'manage_options',
                'delete_others_posts' => 'manage_options',
                'edit_private_posts' => 'manage_options',
                'edit_published_posts' => 'manage_options',
                'create_posts' => 'manage_options',
            ),
            'map_meta_cap' => false,
        ) );
    }


    /**
     * WordPress native sync icon, slightly larger and more legible in the admin
     * sidebar. Keep the standard WordPress menu icon box (no custom giant SVG).
     */
    public static function enqueue_menu_icon_style() {
        wp_enqueue_style( 'dashicons' );
        wp_add_inline_style(
            'dashicons',
            '#adminmenu #toplevel_page_dendrila-calendar .wp-menu-image:before { color: #f0f6fc; font-size: 22px; }' .
            '.dendrila-calendar-support{margin:26px 0 16px;font-size:13px;color:#64748b}' .
            '.dendrila-calendar-support a{display:inline-flex;align-items:center;gap:6px;color:#526174;text-decoration:none}' .
            '.dendrila-calendar-support a:hover,.dendrila-calendar-support a:focus-visible{color:#1d4ed8;text-decoration:underline}' .
            '.dendrila-calendar-support .dashicons{font-size:16px;width:16px;height:16px}'
        );
    }

    /** Unobtrusive, optional support link matching Dendrila Simple Edit. */
    public static function render_support_footer() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] )
            ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $type = $screen && isset( $screen->post_type ) ? $screen->post_type : '';
        if ( 0 !== strpos( $page, 'dendrila-calendar' ) &&
             ! in_array( $type, array( 'dendrila_profile', 'dendrila_service', 'dendrila_booking', self::POST_TYPE ), true ) ) {
            return;
        }
        echo '<footer class="dendrila-calendar-support" aria-label="' .
            esc_attr__( 'Support development', 'dendrila-calendar' ) . '">';
        echo '<a href="https://app.lepotager.org/soutenir/" target="_blank" rel="noopener noreferrer">' .
            '<span class="dashicons dashicons-coffee" aria-hidden="true"></span>' .
            esc_html__( 'Offer the developer a coffee', 'dendrila-calendar' ) .
            '<span class="screen-reader-text"> (' . esc_html__( 'opens in a new tab', 'dendrila-calendar' ) .
            ')</span></a></footer>';
    }

    public static function admin_menu() {
        add_menu_page(
            __( 'Dendrila Calendar', 'dendrila-calendar' ),
            __( 'Dendrila Calendar', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar',
            array( __CLASS__, 'render' ),
            'dashicons-update',
            27
        );
        // First submenu uses the top-level slug: clicking Dendrila always opens
        // the unified month/week/list view, never the native post list.
        add_submenu_page(
            'dendrila-calendar',
            __( 'Unified calendar', 'dendrila-calendar' ),
            __( 'Calendar view', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar',
            array( __CLASS__, 'render' )
        );
        add_submenu_page(
            'dendrila-calendar',
            __( 'Automatic synchronization', 'dendrila-calendar' ),
            __( 'Synchronization', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar-sync',
            array( __CLASS__, 'render_sync_page' )
        );
    }

    public static function local_events_notice() {
        if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || 'edit-' . self::POST_TYPE !== $screen->id ) {
            return;
        }
        echo '<div class="notice notice-info"><p>' . esc_html__(
            'This list contains manually created events and events imported from connected calendars. Customer appointments are shown under Your appointments. Other plugins are optional.',
            'dendrila-calendar'
        ) . '</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar' ) ) . '">' . esc_html__(
            'Open unified calendar', 'dendrila-calendar'
        ) . '</a></p></div>';
    }

    public static function add_event_box() {
        add_meta_box(
            'dendrila-calendar-date',
            __( 'Date and time', 'dendrila-calendar' ),
            array( __CLASS__, 'event_box' ),
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    private static function local_input( $utc ) {
        if ( ! $utc ) {
            return '';
        }
        try {
            return ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )
                ->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
        } catch ( Exception $error ) {
            return '';
        }
    }

    public static function event_box( $post ) {
        wp_nonce_field( 'dendrila_calendar_save', 'dendrila_calendar_nonce' );
        $start = self::local_input( get_post_meta( $post->ID, self::START_KEY, true ) );
        $end = self::local_input( get_post_meta( $post->ID, self::END_KEY, true ) );
        echo '<p><label for="dendrila-calendar-start">' . esc_html__( 'Start', 'dendrila-calendar' ) . '</label> ';
        echo '<input id="dendrila-calendar-start" type="datetime-local" name="dendrila_calendar_start" value="' . esc_attr( $start ) . '" required></p>';
        echo '<p><label for="dendrila-calendar-end">' . esc_html__( 'End', 'dendrila-calendar' ) . '</label> ';
        echo '<input id="dendrila-calendar-end" type="datetime-local" name="dendrila_calendar_end" value="' . esc_attr( $end ) . '" required></p>';
        echo '<p><label><input type="checkbox" name="dendrila_calendar_all_day" value="1"' . ( get_post_meta( $post->ID, '_dendrila_calendar_all_day', true ) ? ' checked' : '' ) . '> ' . esc_html__( 'All-day event', 'dendrila-calendar' ) . '</label></p>';
        echo '<p class="description">' . esc_html__( 'For all-day events, use midnight for both times. The end date is the first day after the event.', 'dendrila-calendar' ) . '</p>';
        echo '<p class="notice notice-info inline">' . esc_html__( 'Local events can be sent to connected external calendars. They do not yet block booking availability in third-party booking plugins. Do not use this form to prevent reservations.', 'dendrila-calendar' ) . '</p>';
        echo '<p class="description">' . esc_html( sprintf(
            /* translators: %s: WordPress site timezone. */
            __( 'Times use the site timezone: %s. The event is visible only to administrators.', 'dendrila-calendar' ),
            wp_timezone_string()
        ) ) . '</p>';
    }

    private static function parse_local( $input ) {
        if ( ! is_string( $input ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $input ) ) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $input, wp_timezone() );
        $errors = DateTimeImmutable::getLastErrors();
        if ( ! $date || ( is_array( $errors ) && ( $errors['error_count'] || $errors['warning_count'] ) ) ) {
            return null;
        }
        return $date->format( 'Y-m-d\TH:i' ) === $input ? $date : null;
    }

    public static function save_event( $post_id ) {
        if ( ! isset( $_POST['dendrila_calendar_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dendrila_calendar_nonce'] ) ), 'dendrila_calendar_save' ) ||
            ! current_user_can( 'manage_options' ) ||
            ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
            return;
        }
        $start = self::parse_local( isset( $_POST['dendrila_calendar_start'] ) ? sanitize_text_field( wp_unslash( $_POST['dendrila_calendar_start'] ) ) : '' );
        $end = self::parse_local( isset( $_POST['dendrila_calendar_end'] ) ? sanitize_text_field( wp_unslash( $_POST['dendrila_calendar_end'] ) ) : '' );
        if ( ! $start || ! $end || $end <= $start ) {
            // Do not destroy previously valid dates on malformed input.
            return;
        }
        $all_day = isset( $_POST['dendrila_calendar_all_day'] ) && '1' === $_POST['dendrila_calendar_all_day'] &&
            '00:00' === $start->format( 'H:i' ) && '00:00' === $end->format( 'H:i' );
        update_post_meta( $post_id, '_dendrila_calendar_all_day', (int) $all_day );
        update_post_meta( $post_id, self::START_KEY, $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
        update_post_meta( $post_id, self::END_KEY, $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
    }

    public static function enqueue( $hook ) {
        $calendar_screen = in_array( $hook, array( 'toplevel_page_dendrila-calendar', 'dendrila-calendar_page_dendrila-calendar' ), true );
        $sync_screen = 'dendrila-calendar_page_dendrila-calendar-sync' === $hook;
        $setup_screen = 'dendrila-calendar_page_dendrila-calendar-setup' === $hook;
        $native_screen = in_array( $hook, array( 'dendrila-calendar_page_dendrila-calendar-services', 'dendrila-calendar_page_dendrila-calendar-profiles', 'dendrila-calendar_page_dendrila-calendar-bookings' ), true );
        if ( ! $calendar_screen && ! $sync_screen && ! $setup_screen && ! $native_screen ) {
            return;
        }
        $url = plugin_dir_url( dirname( __DIR__ ) . '/dendrila-calendar.php' );
        // Version assets independently of the plugin test version.
        $asset_version = static function ( $file ) {
            $path = dirname( __DIR__ ) . '/assets/' . $file;
            return is_file( $path ) ? (string) filemtime( $path ) : self::VERSION;
        };
        wp_enqueue_style( 'dendrila-calendar-admin', $url . 'assets/calendar.css', array(), $asset_version( 'calendar.css' ) );
        if ( $native_screen ) { return; }
        if ( $calendar_screen ) {
            wp_enqueue_script( 'dendrila-calendar-admin', $url . 'assets/calendar.js', array(), $asset_version( 'calendar.js' ), true );
        }
        if ( $sync_screen || $setup_screen ) {
            wp_enqueue_script( 'dendrila-calendar-connect', $url . 'assets/calendar-connect.js', array(), $asset_version( 'calendar-connect.js' ), true );
            wp_localize_script( 'dendrila-calendar-connect', 'dendrilaCalendarConnect', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'oauthUrl' => admin_url( 'admin.php?page=dendrila-calendar-sync&dendrila_method=oauth' ),
                'initialService' => class_exists( 'Dendrila_Calendar_Setup' ) ? ( Dendrila_Calendar_Setup::settings()['calendar_service'] ?? 'nextcloud' ) : 'nextcloud',
                'nonce' => wp_create_nonce( Dendrila_Calendar_Discovery::NONCE_ACTION ),
                'testing' => __( 'Looking for your calendars…', 'dendrila-calendar' ),
                'sourcesRequired' => __( 'Select at least one appointment source.', 'dendrila-calendar' ),
                'failed' => __( 'Unable to find calendars. Check your account details and try again.', 'dendrila-calendar' ),
                'nextcloud' => __( 'Enter the web address of your Nextcloud, then your login details.', 'dendrila-calendar' ),
                'icloud' => __( 'Use your Apple Account email and an app-specific password. Connection may not work on every iCloud account yet.', 'dendrila-calendar' ),
                'caldav' => __( 'Enter your calendar service address and account details.', 'dendrila-calendar' ),
                'oauth' => __( 'Continue to your provider’s secure account authorization. No calendar password is entered in WordPress.', 'dendrila-calendar' ),
            ) );
        }
        if ( $setup_screen ) {
            return;
        }
        wp_enqueue_script( 'dendrila-calendar-subscriptions', $url . 'assets/calendar-subscriptions.js', array(), $asset_version( 'calendar-subscriptions.js' ), true );
        wp_localize_script( 'dendrila-calendar-subscriptions', 'dendrilaCalendarSubscriptions', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'dendrila_calendar_subscription' ),
            'revokeConfirm' => __( 'Revoke this subscription? Connected calendars will stop updating.', 'dendrila-calendar' ),
            'created' => __( 'Subscription created. Copy the URL now; it will not be shown again.', 'dendrila-calendar' ),
            'copy' => __( 'Copy link', 'dendrila-calendar' ),
            'copied' => __( 'Link copied.', 'dendrila-calendar' ),
            'copyFailed' => __( 'Select and copy the link manually.', 'dendrila-calendar' ),
            'revoked' => __( 'Subscription revoked.', 'dendrila-calendar' ),
            'failed' => __( 'Unable to update the subscription.', 'dendrila-calendar' ),
            'revoke' => __( 'Revoke link', 'dendrila-calendar' ),
            'appleLink' => __( 'Open Apple Calendar subscription', 'dendrila-calendar' ),
            'steps' => array(
                'google' => __( 'On a computer, open Google Calendar settings, choose Add calendar > From URL, paste the private HTTPS link, and add the calendar. Google decides when to refresh it. The mobile app cannot add URL subscriptions directly.', 'dendrila-calendar' ),
                'apple' => __( 'On iPhone: Calendar > Calendars > Add Calendar > Add Subscription Calendar. Paste the URL. On Mac: File > New Calendar Subscription. You can also try Open Apple Calendar subscription after creating a link.', 'dendrila-calendar' ),
                'outlook' => __( 'In Outlook on the web, open Calendar > Add calendar > Subscribe from web. Paste the link and save. Updates can take more than 24 hours.', 'dendrila-calendar' ),
                'nextcloud' => __( 'In Nextcloud Calendar, choose New calendar > New subscription from link and paste the private URL. It will appear as a read-only subscribed calendar.', 'dendrila-calendar' ),
                'other' => __( 'Find Subscribe by URL, Internet calendar, or iCalendar subscription in your calendar application. Paste the link rather than importing a one-time ICS file.', 'dendrila-calendar' ),
            ),
        ) );
        if ( $calendar_screen ) {
            wp_localize_script( 'dendrila-calendar-admin', 'dendrilaCalendar', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'dendrila_calendar_events' ),
            'locale' => get_user_locale(),
            'timezone' => wp_timezone_string(),
            'weekStartsOn' => (int) get_option( 'start_of_week', 1 ),
            'sources' => Dendrila_Calendar_Sources::available(),
            'primarySources' => array( 'native', 'bookings' ),
            'savedSources' => class_exists( 'Dendrila_Calendar_Setup' ) ? Dendrila_Calendar_Setup::selected_sources() : null,
            'i18n' => array(
                'today' => __( 'Today', 'dendrila-calendar' ),
                'month' => __( 'Month', 'dendrila-calendar' ),
                'week' => __( 'Week', 'dendrila-calendar' ),
                'list' => __( 'List', 'dendrila-calendar' ),
                'previousMonth' => __( 'Previous month', 'dendrila-calendar' ),
                'nextMonth' => __( 'Next month', 'dendrila-calendar' ),
                'previousWeek' => __( 'Previous week', 'dendrila-calendar' ),
                'nextWeek' => __( 'Next week', 'dendrila-calendar' ),
                'previous' => __( 'Previous', 'dendrila-calendar' ),
                'next' => __( 'Next', 'dendrila-calendar' ),
                'loading' => __( 'Loading events…', 'dendrila-calendar' ),
                'empty' => __( 'No events for this period.', 'dendrila-calendar' ),
                'emptyDay' => __( 'No events for this day.', 'dendrila-calendar' ),
                'failed' => __( 'Unable to load events. Please try again.', 'dendrila-calendar' ),
                'allDay' => __( 'All day', 'dendrila-calendar' ),
                'sources' => __( 'Sources', 'dendrila-calendar' ),
                'optionalSources' => __( 'Other booking plugins (optional)', 'dendrila-calendar' ),
                'optionalSourceHelp' => __( 'These integrations are only for sites that already use another booking plugin. Dendrila works without them.', 'dendrila-calendar' ),
            ),
        ) );
        }
    }

    public static function render_sync_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to view this calendar.', 'dendrila-calendar' ) );
        }
        echo '<div class="wrap dendrila-calendar-wrap dendrila-settings-page"><h1>' . esc_html__( 'Automatic calendar synchronization', 'dendrila-calendar' ) . '</h1>';
        echo '<p class="dendrila-settings-lead">' . esc_html__( 'Connect your calendars, choose the bookings to send and protect your availability.', 'dendrila-calendar' ) . '</p>';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar' ) ) . '" class="button">' . esc_html__( 'Back to calendar', 'dendrila-calendar' ) . '</a></p>';
        if ( class_exists( 'Dendrila_Calendar_Setup' ) && ! Dendrila_Calendar_Setup::complete() ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Finish connecting an agenda below. Google and Outlook use account authorization; Nextcloud and iCloud use CalDAV.', 'dendrila-calendar' ) . '</p></div>';
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection; no state change.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view switch, no mutation.
        $method = isset( $_GET['dendrila_method'] ) ? sanitize_key( wp_unslash( $_GET['dendrila_method'] ) ) : 'choice';
        if ( ! in_array( $method, array( 'choice', 'oauth', 'direct', 'subscription' ), true ) ) {
            $method = 'choice';
        }
        echo '<nav class="dendrila-connection-methods" aria-label="' . esc_attr__( 'Connection method', 'dendrila-calendar' ) . '">';
        $links = array(
            'choice' => __( 'Choose an agenda', 'dendrila-calendar' ),
            'oauth' => __( 'Google / Outlook', 'dendrila-calendar' ),
            'direct' => __( 'Nextcloud / iCloud', 'dendrila-calendar' ),
            'subscription' => __( 'iCalendar subscription', 'dendrila-calendar' ),
        );
        foreach ( $links as $id => $label ) {
            $url = add_query_arg( 'dendrila_method', $id, admin_url( 'admin.php?page=dendrila-calendar-sync' ) );
            echo '<a class="button ' . ( $id === $method ? 'button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> ';
        }
        echo '</nav>';
        if ( 'oauth' === $method ) {
            Dendrila_Calendar_OAuth::render_settings();
            Dendrila_Calendar_Availability::render_settings();
        } elseif ( 'subscription' === $method ) {
            Dendrila_Calendar_Subscriptions::render_settings();
        } elseif ( 'direct' === $method ) {
            Dendrila_Calendar_Bridge::render_settings();
        } else {
            echo '<section class="dendrila-agenda-chooser"><h2>' . esc_html__( 'Which calendar do you use?', 'dendrila-calendar' ) . '</h2>';
            echo '<p>' . esc_html__( 'Pick your everyday calendar. We will guide you without technical setup.', 'dendrila-calendar' ) . '</p>';
            $choices = array(
                array( 'Google Calendar', 'oauth', __( 'Connect your Google account securely. Authorization requires a configured Dendrila OAuth application.', 'dendrila-calendar' ) ),
                array( 'Microsoft Outlook', 'oauth', __( 'Connect your Microsoft account securely. Authorization requires a configured Dendrila OAuth application.', 'dendrila-calendar' ) ),
                array( 'Apple Calendar / iCloud', 'direct', __( 'Use an Apple app password to connect your iCloud calendar.', 'dendrila-calendar' ) ),
                array( 'Nextcloud / CalDAV', 'direct', __( 'Enter your cloud address and choose an agenda.', 'dendrila-calendar' ) ),
                array( 'Other iCalendar / ICS', 'subscription', __( 'Create a private read-only subscription link.', 'dendrila-calendar' ) ),
            );
            echo '<div class="dendrila-agenda-options">';
            foreach ( $choices as $choice ) {
                $provider = 'Google Calendar' === $choice[0] ? 'google' : 'microsoft';
                $url = add_query_arg( 'dendrila_method', $choice[1], admin_url( 'admin.php?page=dendrila-calendar-sync' ) );
                echo '<article class="dendrila-agenda-option"><h3>' . esc_html( $choice[0] ) . '</h3><p>' . esc_html( $choice[2] ) . '</p>';
                if ( 'oauth' === $choice[1] && Dendrila_Calendar_OAuth::ready( $provider ) ) {
                    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dendrila_calendar_oauth_start"><input type="hidden" name="provider" value="' . esc_attr( $provider ) . '">';
                    wp_nonce_field( 'dendrila_calendar_oauth_start' );
                    /* translators: %s: calendar provider name. */
                    echo '<button class="button button-primary">' . esc_html( sprintf( __( 'Connect with %s', 'dendrila-calendar' ), $choice[0] ) ) . '</button></form>';
                } else {
                    if ( 'oauth' === $choice[1] ) { $url = add_query_arg( 'oauth_provider', $provider, $url ); }
                    echo '<a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Continue', 'dendrila-calendar' ) . '</a>';
                }
                echo '</article>';
            }
            echo '</div></section>';
        }
        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-setup' ) ) . '">' . esc_html__( 'Change source and destination settings', 'dendrila-calendar' ) . '</a></p>';
        echo '</div>';
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to view this calendar.', 'dendrila-calendar' ) );
        }
        echo '<div class="wrap dendrila-calendar-wrap dendrila-calendar-overview">';
        echo '<header class="dendrila-overview-header"><h1>' . esc_html__( 'Dendrila Calendar', 'dendrila-calendar' ) . '</h1>';
        echo '<p>' . esc_html__( 'Your Dendrila appointments and personal events appear here. Other booking plugins are optional and can be shown separately.', 'dendrila-calendar' ) . '</p></header>';
        echo '<section class="dendrila-calendar-panel" aria-labelledby="dendrila-period">';
        echo '<div class="dendrila-calendar-toolbar">';
        echo '<div class="dendrila-calendar-navigation">';
        echo '<button type="button" class="button" id="dendrila-prev" aria-label="' . esc_attr__( 'Previous month', 'dendrila-calendar' ) . '"><span aria-hidden="true">‹</span></button>';
        echo '<h2 id="dendrila-period" aria-live="polite"></h2>';
        echo '<button type="button" class="button" id="dendrila-next" aria-label="' . esc_attr__( 'Next month', 'dendrila-calendar' ) . '"><span aria-hidden="true">›</span></button></div>';
        echo '<div class="dendrila-calendar-selectors"><button type="button" class="button" id="dendrila-today">' . esc_html__( 'Today', 'dendrila-calendar' ) . '</button>';
        echo '<label for="dendrila-month">' . esc_html__( 'Choose month', 'dendrila-calendar' ) . '</label> ';
        echo '<input type="month" id="dendrila-month" class="dendrila-month-picker" autocomplete="off">';
        echo '<label for="dendrila-view">' . esc_html__( 'View', 'dendrila-calendar' ) . '</label> <select id="dendrila-view">';
        echo '<option value="month">' . esc_html__( 'Month', 'dendrila-calendar' ) . '</option><option value="week">' . esc_html__( 'Week', 'dendrila-calendar' ) . '</option><option value="list">' . esc_html__( 'List', 'dendrila-calendar' ) . '</option></select></div></div>';
        echo '<details class="dendrila-calendar-filters"><summary>' . esc_html__( 'Sources', 'dendrila-calendar' ) . ' <span id="dendrila-source-count"></span></summary><fieldset class="dendrila-calendar-sources"><legend class="screen-reader-text">' . esc_html__( 'Sources', 'dendrila-calendar' ) . '</legend>';
        echo '<div class="dendrila-source-actions"><button type="button" class="button button-small" id="dendrila-sources-all">' . esc_html__( 'Select all', 'dendrila-calendar' ) . '</button> ';
        echo '<button type="button" class="button button-small" id="dendrila-sources-none">' . esc_html__( 'Select none', 'dendrila-calendar' ) . '</button></div>';
        echo '<div id="dendrila-source-toggles"></div></fieldset></details>';
        echo '<div class="dendrila-calendar-tools">';
        echo '<label for="dendrila-search">' . esc_html__( 'Find events', 'dendrila-calendar' ) . '</label> ';
        echo '<input id="dendrila-search" type="search" placeholder="' . esc_attr__( 'Search title or status', 'dendrila-calendar' ) . '">';
        echo '</div>';
        echo '<div id="dendrila-message" role="status" aria-live="polite"></div>';
        echo '<div id="dendrila-grid" aria-label="' . esc_attr__( 'Unified calendar', 'dendrila-calendar' ) . '"></div>';
        echo '<section id="dendrila-day-details" class="dendrila-day-details" aria-live="polite" aria-label="' . esc_attr__( 'Selected day appointments', 'dendrila-calendar' ) . '"></section>';
        echo '</section>';
        // Secondary administration actions must not push the calendar below the fold.
        echo '<nav class="dendrila-calendar-intro-actions" aria-label="' . esc_attr__( 'Calendar actions', 'dendrila-calendar' ) . '">';
        echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-sync' ) ) . '">' . esc_html__( 'Synchronize appointments with your external calendar', 'dendrila-calendar' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ) . '">' . esc_html__( 'Add an event', 'dendrila-calendar' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ) . '">' . esc_html__( 'Manage local events', 'dendrila-calendar' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-setup' ) ) . '">' . esc_html__( 'Configure sources and destination', 'dendrila-calendar' ) . '</a>';
        if ( class_exists( 'Dendrila_Calendar_Native' ) ) {
            echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-profiles' ) ) . '">' .
                esc_html__( 'Booking profiles', 'dendrila-calendar' ) . '</a>';
            echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-services' ) ) . '">' .
                esc_html__( 'Services', 'dendrila-calendar' ) . '</a>';
        }
        echo '</nav>';
        self::render_upcoming();
        echo '</div>';
    }

    /** Server-side list stays visible even if browser JavaScript or AJAX fails. */
    public static function render_upcoming() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
        $start = $now->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
        $end = $now->modify( '+60 days' )->format( 'Y-m-d H:i:s' );
        $available = Dendrila_Calendar_Sources::available();
        $events = Dendrila_Calendar_Sources::events( $start, $end, array_keys( $available ), true );
        echo '<details class="dendrila-upcoming" aria-labelledby="dendrila-upcoming-heading"><summary id="dendrila-upcoming-heading">' . esc_html__( 'Upcoming appointments (server view)', 'dendrila-calendar' ) . '</summary>';
        echo '<p class="description">' . esc_html__( 'Dendrila appointments and personal events are shown here. Events from other booking plugins can be displayed on request; their records remain managed by their original extension.', 'dendrila-calendar' ) . '</p>';
        if ( ! $events ) {
            echo '<p class="notice notice-warning inline">' . esc_html__( 'No events returned by the active source connectors for the next 60 days. Check the source diagnostics below.', 'dendrila-calendar' ) . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date and time', 'dendrila-calendar' ) . '</th><th>' . esc_html__( 'Source', 'dendrila-calendar' ) . '</th><th>' . esc_html__( 'Event', 'dendrila-calendar' ) . '</th><th>' . esc_html__( 'Status', 'dendrila-calendar' ) . '</th></tr></thead><tbody>';
            foreach ( array_slice( $events, 0, 35 ) as $event ) {
                $start_date = new DateTimeImmutable( $event['start'] );
                $date = $start_date->setTimezone( wp_timezone() )->format( 'd/m/Y H:i' );
                $source = isset( $available[ $event['source'] ] ) ? $available[ $event['source'] ] : $event['source'];
                $title = esc_html( $event['title'] );
                $edit_url = isset( $event['url'] ) ? (string) $event['url'] : '';
                if ( $edit_url && 0 === strpos( $edit_url, admin_url() ) ) {
                    $title = '<a href="' . esc_url( $edit_url ) . '">' . $title . '</a>';
                }
                echo '<tr><td>' . esc_html( $date ) . '</td><td>' . esc_html( $source ) . '</td><td>' . $title . '</td><td>' . esc_html( $event['status'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped title and validated same-origin admin URL.
            }
            echo '</tbody></table>';
        }
        $diagnostics = Dendrila_Calendar_Sources::diagnostics();
        foreach ( $diagnostics as $source => $reason ) {
            echo '<p class="notice notice-warning inline">' . esc_html( $source . ': ' . $reason ) . '</p>';
        }
        echo '</details>';
    }

    public static function ajax_events() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Forbidden', 'dendrila-calendar' ) ), 403 );
        }
        check_ajax_referer( 'dendrila_calendar_events', 'nonce' );
        $start = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : '';
        $end = isset( $_GET['end'] ) ? sanitize_text_field( wp_unslash( $_GET['end'] ) ) : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $end ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid date range', 'dendrila-calendar' ) ), 400 );
        }
        try {
            $start_date = new DateTimeImmutable( $start . ' 00:00:00', wp_timezone() );
            $end_date = new DateTimeImmutable( $end . ' 00:00:00', wp_timezone() );
        } catch ( Exception $exception ) {
            wp_send_json_error( array( 'message' => __( 'Invalid date range', 'dendrila-calendar' ) ), 400 );
        }
        if ( $end_date <= $start_date || $end_date->getTimestamp() - $start_date->getTimestamp() > 93 * DAY_IN_SECONDS ) {
            wp_send_json_error( array( 'message' => __( 'Date range is too large', 'dendrila-calendar' ) ), 400 );
        }
        $allowed = array_keys( Dendrila_Calendar_Sources::available() );
        $requested = isset( $_GET['sources'] ) && is_string( $_GET['sources'] )
            ? explode( ',', sanitize_text_field( wp_unslash( $_GET['sources'] ) ) ) : $allowed;
        $requested = array_values( array_intersect( $allowed, $requested ) );
        $start_utc = $start_date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        $end_utc = $end_date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        $events = Dendrila_Calendar_Sources::events( $start_utc, $end_utc, $requested, true );
        // Aggregated source counts help diagnose missing bookings without exposing personal data.
        $source_counts = array_fill_keys( $requested, 0 );
        foreach ( $events as $event ) {
            if ( isset( $event['source'], $source_counts[ $event['source'] ] ) ) {
                ++$source_counts[ $event['source'] ];
            }
        }
        wp_send_json_success( array( 'events' => $events, 'source_counts' => $source_counts, 'source_warnings' => Dendrila_Calendar_Sources::diagnostics() ) );
    }
}
