<?php
/** Administration, local events and the calendar endpoint. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar {
    const VERSION = '0.0.1';
    const POST_TYPE = 'dendrila_event';
    const START_KEY = '_dendrila_calendar_start_utc';
    const END_KEY = '_dendrila_calendar_end_utc';

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'register_events' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_event_box' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_event' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_notices', array( __CLASS__, 'local_events_notice' ) );
        add_action( 'wp_ajax_dendrila_calendar_events', array( __CLASS__, 'ajax_events' ) );
    }

    public static function register_events() {
        load_plugin_textdomain( 'dendrila-calendar', false, 'dendrila-calendar/languages' );
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name' => __( 'Local events', 'dendrila-calendar' ),
                'menu_name' => __( 'Local events', 'dendrila-calendar' ),
                'all_items' => __( 'Local events', 'dendrila-calendar' ),
                'singular_name' => __( 'Calendar event', 'dendrila-calendar' ),
                'add_new_item' => __( 'Add calendar event', 'dendrila-calendar' ),
                'edit_item' => __( 'Edit calendar event', 'dendrila-calendar' ),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'dendrila-calendar',
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

    public static function admin_menu() {
        add_menu_page(
            __( 'Dendrila Calendar', 'dendrila-calendar' ),
            __( 'Dendrila Calendar', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar',
            array( __CLASS__, 'render' ),
            'dashicons-calendar-alt',
            27
        );
        // Explicitly name the first submenu so the source-specific post list is not
        // mistaken for the unified calendar's month/week/list views.
        add_submenu_page(
            'dendrila-calendar',
            __( 'Unified calendar', 'dendrila-calendar' ),
            __( 'Calendar view', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar-view',
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
            'This list contains only events created in Dendrila Calendar. Appointments imported from Easy Appointments and other plugins appear in the unified calendar.',
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
        update_post_meta( $post_id, self::START_KEY, $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
        update_post_meta( $post_id, self::END_KEY, $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) );
    }

    public static function enqueue( $hook ) {
        $calendar_screen = in_array( $hook, array( 'toplevel_page_dendrila-calendar', 'dendrila-calendar_page_dendrila-calendar-view' ), true );
        $sync_screen = 'dendrila-calendar_page_dendrila-calendar-sync' === $hook;
        $setup_screen = 'dendrila-calendar_page_dendrila-calendar-setup' === $hook;
        if ( ! $calendar_screen && ! $sync_screen && ! $setup_screen ) {
            return;
        }
        $url = plugin_dir_url( dirname( __DIR__ ) . '/dendrila-calendar.php' );
        // The plugin stays at 0.0.1 during internal testing, but assets must refresh on every change.
        $asset_version = static function ( $file ) {
            $path = dirname( __DIR__ ) . '/assets/' . $file;
            return is_file( $path ) ? (string) filemtime( $path ) : self::VERSION;
        };
        wp_enqueue_style( 'dendrila-calendar-admin', $url . 'assets/calendar.css', array(), $asset_version( 'calendar.css' ) );
        if ( $calendar_screen ) {
            wp_enqueue_script( 'dendrila-calendar-admin', $url . 'assets/calendar.js', array(), $asset_version( 'calendar.js' ), true );
        }
        if ( $sync_screen || $setup_screen ) {
            wp_enqueue_script( 'dendrila-calendar-connect', $url . 'assets/calendar-connect.js', array(), $asset_version( 'calendar-connect.js' ), true );
            wp_localize_script( 'dendrila-calendar-connect', 'dendrilaCalendarConnect', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'initialService' => class_exists( 'Dendrila_Calendar_Setup' ) ? ( Dendrila_Calendar_Setup::settings()['calendar_service'] ?? 'nextcloud' ) : 'nextcloud',
                'nonce' => wp_create_nonce( Dendrila_Calendar_Discovery::NONCE_ACTION ),
                'testing' => __( 'Looking for your calendars…', 'dendrila-calendar' ),
                'sourcesRequired' => __( 'Select at least one appointment source.', 'dendrila-calendar' ),
                'failed' => __( 'Unable to find calendars. Check your account details and try again.', 'dendrila-calendar' ),
                'nextcloud' => __( 'Enter the web address of your Nextcloud, then your login details.', 'dendrila-calendar' ),
                'icloud' => __( 'Use your Apple Account email and an app-specific password. Connection may not work on every iCloud account yet.', 'dendrila-calendar' ),
                'caldav' => __( 'Enter your calendar service address and account details.', 'dendrila-calendar' ),
                'oauth' => __( 'Account connection is not yet available for this service. You can use a calendar subscription instead.', 'dendrila-calendar' ),
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
            'i18n' => array(
                'today' => __( 'Today', 'dendrila-calendar' ),
                'month' => __( 'Month', 'dendrila-calendar' ),
                'week' => __( 'Week', 'dendrila-calendar' ),
                'list' => __( 'List', 'dendrila-calendar' ),
                'previous' => __( 'Previous', 'dendrila-calendar' ),
                'next' => __( 'Next', 'dendrila-calendar' ),
                'loading' => __( 'Loading events…', 'dendrila-calendar' ),
                'empty' => __( 'No events for this period.', 'dendrila-calendar' ),
                'failed' => __( 'Unable to load events. Please try again.', 'dendrila-calendar' ),
                'allDay' => __( 'All day', 'dendrila-calendar' ),
                'sources' => __( 'Sources', 'dendrila-calendar' ),
            ),
        ) );
        }
    }

    public static function render_sync_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to view this calendar.', 'dendrila-calendar' ) );
        }
        echo '<div class="wrap dendrila-calendar-wrap"><h1>' . esc_html__( 'Automatic calendar synchronization', 'dendrila-calendar' ) . '</h1>';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-view' ) ) . '" class="button">' . esc_html__( 'Back to calendar', 'dendrila-calendar' ) . '</a></p>';
        if ( class_exists( 'Dendrila_Calendar_Setup' ) && ! Dendrila_Calendar_Setup::complete() ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Your external calendar is not connected. Selecting an application in the list below is not enough; complete a CalDAV connection or create and subscribe to a private URL.', 'dendrila-calendar' ) . '</p></div>';
        }
        $method = isset( $_GET['dendrila_method'] ) ? sanitize_key( wp_unslash( $_GET['dendrila_method'] ) ) : 'direct';
        echo '<nav class="dendrila-connection-methods" aria-label="' . esc_attr__( 'Connection method', 'dendrila-calendar' ) . '">';
        echo '<a class="button ' . ( 'direct' === $method ? 'button-primary' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-sync' ) ) . '">' . esc_html__( 'Connect an existing calendar', 'dendrila-calendar' ) . '</a> ';
        echo '<a class="button ' . ( 'subscription' === $method ? 'button-primary' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-sync&dendrila_method=subscription' ) ) . '">' . esc_html__( 'Read-only subscription', 'dendrila-calendar' ) . '</a></nav>';
        if ( 'subscription' === $method ) {
            Dendrila_Calendar_Subscriptions::render_settings();
        } else {
            Dendrila_Calendar_Bridge::render_settings();
        }
        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-setup' ) ) . '">' . esc_html__( 'Change source and destination settings', 'dendrila-calendar' ) . '</a></p>';
        echo '</div>';
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to view this calendar.', 'dendrila-calendar' ) );
        }
        echo '<div class="wrap dendrila-calendar-wrap"><h1>' . esc_html__( 'Dendrila Calendar', 'dendrila-calendar' ) . '</h1>';
        echo '<p>' . esc_html__( 'Your appointments from other plugins are shown here. The Local events submenu lists only events created in Dendrila Calendar.', 'dendrila-calendar' ) . '</p>';
        echo '<div class="dendrila-calendar-intro-actions">';
        echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-sync' ) ) . '">' . esc_html__( 'Synchronize appointments with your external calendar', 'dendrila-calendar' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ) . '">' . esc_html__( 'Add an event', 'dendrila-calendar' ) . '</a> ';
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-setup' ) ) . '">' . esc_html__( 'Configure sources and destination', 'dendrila-calendar' ) . '</a>';
        echo '</div>';
        echo '<div class="dendrila-calendar-toolbar">';
        echo '<button type="button" class="button" id="dendrila-prev">' . esc_html__( 'Previous', 'dendrila-calendar' ) . '</button>';
        echo '<button type="button" class="button" id="dendrila-today">' . esc_html__( 'Today', 'dendrila-calendar' ) . '</button>';
        echo '<button type="button" class="button" id="dendrila-next">' . esc_html__( 'Next', 'dendrila-calendar' ) . '</button>';
        echo '<h2 id="dendrila-period" aria-live="polite"></h2>';
        echo '<label for="dendrila-month">' . esc_html__( 'Choose month', 'dendrila-calendar' ) . '</label> ';
        echo '<input type="month" id="dendrila-month" class="dendrila-month-picker" autocomplete="off">';
        echo '<label for="dendrila-view">' . esc_html__( 'View', 'dendrila-calendar' ) . '</label> <select id="dendrila-view">';
        echo '<option value="month">' . esc_html__( 'Month', 'dendrila-calendar' ) . '</option><option value="week">' . esc_html__( 'Week', 'dendrila-calendar' ) . '</option><option value="list">' . esc_html__( 'List', 'dendrila-calendar' ) . '</option></select></div>';
        echo '<fieldset class="dendrila-calendar-sources"><legend>' . esc_html__( 'Sources', 'dendrila-calendar' ) . '</legend>';
        echo '<div class="dendrila-source-actions"><button type="button" class="button button-small" id="dendrila-sources-all">' . esc_html__( 'Select all', 'dendrila-calendar' ) . '</button> ';
        echo '<button type="button" class="button button-small" id="dendrila-sources-none">' . esc_html__( 'Select none', 'dendrila-calendar' ) . '</button></div>';
        echo '<div id="dendrila-source-toggles"></div></fieldset>';
        echo '<div class="dendrila-calendar-tools">';
        echo '<label for="dendrila-search">' . esc_html__( 'Find events', 'dendrila-calendar' ) . '</label> ';
        echo '<input id="dendrila-search" type="search" placeholder="' . esc_attr__( 'Search title or status', 'dendrila-calendar' ) . '">';
        echo '</div>';
        echo '<div id="dendrila-message" role="status" aria-live="polite"></div><div id="dendrila-grid" aria-label="' . esc_attr__( 'Unified calendar', 'dendrila-calendar' ) . '"></div>';
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
        $events = Dendrila_Calendar_Sources::events( $start, $end, array_keys( $available ) );
        echo '<section class="dendrila-upcoming" aria-labelledby="dendrila-upcoming-heading">';
        echo '<h2 id="dendrila-upcoming-heading">' . esc_html__( 'Upcoming appointments (server view)', 'dendrila-calendar' ) . '</h2>';
        echo '<p class="description">' . esc_html__( 'This list is loaded directly by WordPress and remains visible when the interactive calendar is unavailable.', 'dendrila-calendar' ) . '</p>';
        if ( ! $events ) {
            echo '<p class="notice notice-warning inline">' . esc_html__( 'No events returned by the active source connectors for the next 60 days. Check the source diagnostics below.', 'dendrila-calendar' ) . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date and time', 'dendrila-calendar' ) . '</th><th>' . esc_html__( 'Source', 'dendrila-calendar' ) . '</th><th>' . esc_html__( 'Event', 'dendrila-calendar' ) . '</th><th>' . esc_html__( 'Status', 'dendrila-calendar' ) . '</th></tr></thead><tbody>';
            foreach ( array_slice( $events, 0, 35 ) as $event ) {
                $start_date = new DateTimeImmutable( $event['start'] );
                $date = $start_date->setTimezone( wp_timezone() )->format( 'd/m/Y H:i' );
                $source = isset( $available[ $event['source'] ] ) ? $available[ $event['source'] ] : $event['source'];
                echo '<tr><td>' . esc_html( $date ) . '</td><td>' . esc_html( $source ) . '</td><td>' . esc_html( $event['title'] ) . '</td><td>' . esc_html( $event['status'] ) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        $diagnostics = Dendrila_Calendar_Sources::diagnostics();
        foreach ( $diagnostics as $source => $reason ) {
            echo '<p class="notice notice-warning inline">' . esc_html( $source . ': ' . $reason ) . '</p>';
        }
        echo '</section>';
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
        $events = Dendrila_Calendar_Sources::events( $start_utc, $end_utc, $requested );
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
