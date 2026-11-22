<?php
/** Administration, local events and the calendar endpoint. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar {
    const VERSION = '0.1.0';
    const POST_TYPE = 'dendrila_event';
    const START_KEY = '_dendrila_calendar_start_utc';
    const END_KEY = '_dendrila_calendar_end_utc';

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'register_events' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_event_box' ) );
        add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_event' ) );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'wp_ajax_dendrila_calendar_events', array( __CLASS__, 'ajax_events' ) );
    }

    public static function register_events() {
        register_post_type( self::POST_TYPE, array(
            'labels' => array(
                'name' => __( 'Calendar events', 'dendrila-calendar' ),
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
        if ( 'toplevel_page_dendrila-calendar' !== $hook ) {
            return;
        }
        $url = plugin_dir_url( dirname( __DIR__ ) . '/dendrila-calendar.php' );
        wp_enqueue_style( 'dendrila-calendar-admin', $url . 'assets/calendar.css', array(), self::VERSION );
        wp_enqueue_script( 'dendrila-calendar-admin', $url . 'assets/calendar.js', array(), self::VERSION, true );
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

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to view this calendar.', 'dendrila-calendar' ) );
        }
        echo '<div class="wrap dendrila-calendar-wrap"><h1>' . esc_html__( 'Dendrila Calendar', 'dendrila-calendar' ) . '</h1>';
        echo '<p>' . esc_html__( 'One private calendar for your WordPress events and compatible booking tools. Imported sources are read-only.', 'dendrila-calendar' ) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::POST_TYPE ) ) . '">' . esc_html__( 'Add an event', 'dendrila-calendar' ) . '</a></p>';
        echo '<div class="dendrila-calendar-toolbar">';
        echo '<button type="button" class="button" id="dendrila-prev">' . esc_html__( 'Previous', 'dendrila-calendar' ) . '</button>';
        echo '<button type="button" class="button" id="dendrila-today">' . esc_html__( 'Today', 'dendrila-calendar' ) . '</button>';
        echo '<button type="button" class="button" id="dendrila-next">' . esc_html__( 'Next', 'dendrila-calendar' ) . '</button>';
        echo '<h2 id="dendrila-period" aria-live="polite"></h2>';
        echo '<label for="dendrila-view">' . esc_html__( 'View', 'dendrila-calendar' ) . '</label> <select id="dendrila-view">';
        echo '<option value="month">' . esc_html__( 'Month', 'dendrila-calendar' ) . '</option><option value="week">' . esc_html__( 'Week', 'dendrila-calendar' ) . '</option><option value="list">' . esc_html__( 'List', 'dendrila-calendar' ) . '</option></select></div>';
        echo '<fieldset class="dendrila-calendar-sources"><legend>' . esc_html__( 'Sources', 'dendrila-calendar' ) . '</legend><div id="dendrila-source-toggles"></div></fieldset>';
        echo '<div id="dendrila-message" role="status" aria-live="polite"></div><div id="dendrila-grid" aria-label="' . esc_attr__( 'Unified calendar', 'dendrila-calendar' ) . '"></div></div>';
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
        wp_send_json_success( array( 'events' => $events ) );
    }
}
