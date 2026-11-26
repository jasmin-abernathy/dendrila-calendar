<?php
/** Accessible profile schedules and service booking policies. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Calendar_Schedule_Admin {
    const PROFILE_FIELD = 'dendrila_schedule_profile';
    const RULES_FIELD = 'dendrila_schedule_rules';

    public static function boot() {
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
        add_action( 'save_post_dendrila_profile', array( __CLASS__, 'save_profile' ) );
        add_action( 'save_post_dendrila_service', array( __CLASS__, 'save_rules' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
    }

    public static function meta_boxes() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        add_meta_box( 'dendrila-profile-schedule', __( 'Availability', 'dendrila-calendar' ), array( __CLASS__, 'render_profile' ), 'dendrila_profile', 'normal', 'high' );
        add_meta_box( 'dendrila-service-rules', __( 'Booking rules', 'dendrila-calendar' ), array( __CLASS__, 'render_rules' ), 'dendrila_service', 'normal', 'default' );
    }

    public static function enqueue( $hook ) {
        if ( ! current_user_can( 'manage_options' ) || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, array( 'dendrila_profile', 'dendrila_service' ), true ) ) { return; }
        $base = dirname( __DIR__ );
        $url = plugin_dir_url( $base . '/dendrila-calendar.php' );
        wp_enqueue_style( 'dendrila-calendar-schedule-admin', $url . 'assets/calendar-schedule-admin.css', array(), (string) filemtime( $base . '/assets/calendar-schedule-admin.css' ) );
        wp_enqueue_script( 'dendrila-calendar-schedule-admin', $url . 'assets/calendar-schedule-admin.js', array(), (string) filemtime( $base . '/assets/calendar-schedule-admin.js' ), true );
        wp_localize_script( 'dendrila-calendar-schedule-admin', 'dendrilaScheduleAdmin', array(
            /* translators: %d: position of this date exception. */
            'exceptionTitle' => __( 'Date exception %d', 'dendrila-calendar' ),
            'rangeAdded' => __( 'Time range added.', 'dendrila-calendar' ),
            'rangeRemoved' => __( 'Time range removed.', 'dendrila-calendar' ),
            'dayClosed' => __( 'No time ranges remain. This day is closed.', 'dendrila-calendar' ),
            'dateAdded' => __( 'Date exception added. Choose a date.', 'dendrila-calendar' ),
            'dateRemoved' => __( 'Date exception removed.', 'dendrila-calendar' ),
        ) );
    }

    private static function days() {
        return array(
            1 => __( 'Monday', 'dendrila-calendar' ), 2 => __( 'Tuesday', 'dendrila-calendar' ),
            3 => __( 'Wednesday', 'dendrila-calendar' ), 4 => __( 'Thursday', 'dendrila-calendar' ),
            5 => __( 'Friday', 'dendrila-calendar' ), 6 => __( 'Saturday', 'dendrila-calendar' ),
            7 => __( 'Sunday', 'dendrila-calendar' ),
        );
    }

    private static function checkbox( $id, $name, $checked, $label, $closed_toggle = false ) {
        echo '<label class="dendrila-schedule-toggle" for="' . esc_attr( $id ) . '">';
        echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"' . ( $closed_toggle ? ' data-closed-value' : '' ) . '>';
        echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . ( $checked ? ' checked' : '' ) . ( $closed_toggle ? ' data-schedule-closed' : '' ) . '> ' . esc_html( $label ) . '</label>';
    }

    private static function range_fields( $name, $id, $range = array() ) {
        $start = isset( $range['start'] ) ? $range['start'] : '';
        $end = isset( $range['end'] ) ? $range['end'] : '';
        if ( '24:00' === $end ) { $end = '00:00'; }
        echo '<div class="dendrila-schedule-range" data-time-range>';
        foreach ( array( 'start' => __( 'Start', 'dendrila-calendar' ), 'end' => __( 'End', 'dendrila-calendar' ) ) as $part => $label ) {
            echo '<div class="dendrila-schedule-time"><label data-range-label="' . esc_attr( $part ) . '" for="' . esc_attr( $id . '-' . $part ) . '">' . esc_html( $label ) . '</label>';
            echo '<input type="time" step="60" data-range-' . esc_attr( $part ) . ' id="' . esc_attr( $id . '-' . $part ) . '" name="' . esc_attr( $name . '[' . $part . ']' ) . '" value="' . esc_attr( 'start' === $part ? $start : $end ) . '"></div>';
        }
        echo '<button type="button" class="button dendrila-schedule-remove" data-remove-range data-schedule-js hidden>' . esc_html__( 'Remove range', 'dendrila-calendar' ) . '</button></div>';
    }

    private static function exception_fields( $index, $date = '', $ranges = array(), $fallback_help = true ) {
        $prefix = 'dendrila-profile-exception-' . $index;
        $name = self::PROFILE_FIELD . '[exceptions][' . $index . ']';
        /* translators: %d: position of this date exception. */
        echo '<fieldset class="dendrila-schedule-exception" data-schedule-group="exception"><legend data-exception-title>' . esc_html( sprintf( __( 'Date exception %d', 'dendrila-calendar' ), (int) $index + 1 ) ) . '</legend>';
        echo '<div class="dendrila-schedule-exception-heading"><div><label data-exception-date-label for="' . esc_attr( $prefix . '-date' ) . '">' . esc_html__( 'Date', 'dendrila-calendar' ) . '</label>';
        echo '<input type="date" data-exception-date id="' . esc_attr( $prefix . '-date' ) . '" name="' . esc_attr( $name . '[date]' ) . '" value="' . esc_attr( $date ) . '"></div>';
        self::checkbox( $prefix . '-closed', $name . '[closed]', empty( $ranges ), __( 'Closed all day', 'dendrila-calendar' ), true );
        echo '<button type="button" class="button dendrila-schedule-remove" data-remove-exception data-schedule-js hidden>' . esc_html__( 'Remove date', 'dendrila-calendar' ) . '</button></div>';
        echo '<div data-time-ranges>';
        foreach ( $ranges ? array_values( $ranges ) : array( array() ) as $i => $range ) {
            self::range_fields( $name . '[slots][' . $i . ']', $prefix . '-range-' . $i, $range );
        }
        echo '</div><button type="button" class="button" data-add-range data-schedule-js hidden>' . esc_html__( 'Add a time range', 'dendrila-calendar' ) . '</button>';
        if ( $fallback_help ) {
            echo '<noscript><p>' . esc_html__( 'For a closed date, leave Closed all day checked. To remove an exception, clear its date and times.', 'dendrila-calendar' ) . '</p></noscript>';
        }
        echo '</fieldset>';
    }

    private static function number_field( $name, $id, $label, $value, $min = 0, $max = '' ) {
        echo '<div class="dendrila-schedule-number"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
        echo '<input type="number" step="1" min="' . esc_attr( $min ) . '"' . ( '' !== (string) $max ? ' max="' . esc_attr( $max ) . '"' : '' ) . ' id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></div>';
    }

    private static function quotas( $field, $prefix, $settings, $shared ) {
        echo '<h3>' . esc_html( $shared ? __( 'Shared booking limits', 'dendrila-calendar' ) : __( 'Service booking limits', 'dendrila-calendar' ) ) . '</h3>';
        echo '<p class="description">' . esc_html( $shared ? __( 'These limits cover all services assigned to this profile. 0 means no limit.', 'dendrila-calendar' ) : __( 'These limits apply to this service in addition to the profile limits. 0 means no limit.', 'dendrila-calendar' ) ) . '</p><div class="dendrila-schedule-numbers">';
        foreach ( array( 'max_per_day' => __( 'Bookings per day', 'dendrila-calendar' ), 'max_per_week' => __( 'Bookings per week', 'dendrila-calendar' ), 'max_per_month' => __( 'Bookings per month', 'dendrila-calendar' ) ) as $key => $label ) {
            self::number_field( $field . '[' . $key . ']', $prefix . '-' . $key, $label, $settings[ $key ], 0, 10000 );
        }
        echo '</div>';
    }

    public static function render_profile( $post ) {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $settings = Dendrila_Calendar_Schedule::profile( $post->ID );
        wp_nonce_field( 'dendrila_calendar_schedule_profile_' . $post->ID, 'dendrila_calendar_schedule_profile_nonce' );
        echo '<div class="dendrila-schedule-admin" data-dendrila-schedule="profile">';
        self::checkbox( 'dendrila-profile-enabled', self::PROFILE_FIELD . '[enabled]', $settings['enabled'], __( 'Enable availability for this profile', 'dendrila-calendar' ) );
        echo '<p class="description">' . esc_html__( 'Set the hours when this person or resource can accept bookings. Services use these hours and their own booking rules.', 'dendrila-calendar' ) . '</p>';
        echo '<p><label for="dendrila-profile-timezone"><strong>' . esc_html__( 'Time zone', 'dendrila-calendar' ) . '</strong></label><br><select id="dendrila-profile-timezone" name="' . esc_attr( self::PROFILE_FIELD . '[timezone]' ) . '">';
        if ( function_exists( 'wp_timezone_choice' ) ) {
            echo wp_timezone_choice( $settings['timezone'], get_user_locale() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress-generated option markup.
        } else {
            $zones = DateTimeZone::listIdentifiers();
            if ( ! in_array( $settings['timezone'], $zones, true ) ) { array_unshift( $zones, $settings['timezone'] ); }
            foreach ( $zones as $zone ) { echo '<option value="' . esc_attr( $zone ) . '"' . ( $zone === $settings['timezone'] ? ' selected' : '' ) . '>' . esc_html( $zone ) . '</option>'; }
        }
        echo '</select></p><h3>' . esc_html__( 'Weekly hours', 'dendrila-calendar' ) . '</h3>';
        echo '<p class="description">' . esc_html__( 'Add separate time ranges for a lunch break. 00:00 as an end time means midnight at the end of the day.', 'dendrila-calendar' ) . '</p><div class="dendrila-schedule-week">';
        foreach ( self::days() as $day => $label ) {
            $ranges = isset( $settings['weekly'][ $day ] ) ? $settings['weekly'][ $day ] : array();
            $prefix = 'dendrila-profile-day-' . $day;
            $name = self::PROFILE_FIELD . '[weekly][' . $day . ']';
            echo '<fieldset class="dendrila-schedule-day" data-schedule-group="day" data-day="' . esc_attr( $day ) . '"><legend>' . esc_html( $label ) . '</legend>';
            self::checkbox( $prefix . '-closed', self::PROFILE_FIELD . '[closed][' . $day . ']', empty( $ranges ), __( 'Closed', 'dendrila-calendar' ), true );
            echo '<div data-time-ranges>';
            foreach ( $ranges ? array_values( $ranges ) : array( array() ) as $i => $range ) { self::range_fields( $name . '[' . $i . ']', $prefix . '-range-' . $i, $range ); }
            echo '</div><button type="button" class="button" data-add-range data-schedule-js hidden>' . esc_html__( 'Add a time range', 'dendrila-calendar' ) . '</button>';
            echo '<noscript><p>' . esc_html__( 'Uncheck Closed to use these hours. Leave both times empty to remove a range.', 'dendrila-calendar' ) . '</p>';
            self::range_fields( $name . '[' . max( 1, count( $ranges ) ) . ']', $prefix . '-extra' );
            echo '</noscript></fieldset>';
        }
        echo '</div><h3>' . esc_html__( 'Date exceptions', 'dendrila-calendar' ) . '</h3><p class="description">' . esc_html__( 'A date exception replaces the weekly hours for that date. Close the whole day or enter different time ranges.', 'dendrila-calendar' ) . '</p><div data-exceptions>';
        $index = 0;
        foreach ( $settings['overrides'] as $date => $ranges ) { self::exception_fields( $index++, $date, $ranges ); }
        echo '</div><button type="button" class="button" data-add-exception data-schedule-js hidden>' . esc_html__( 'Add a date exception', 'dendrila-calendar' ) . '</button>';
        echo '<noscript><h4>' . esc_html__( 'Add a date exception', 'dendrila-calendar' ) . '</h4>';
        self::exception_fields( $index, '', array(), false );
        echo '<p>' . esc_html__( 'Save this date before adding another exception.', 'dendrila-calendar' ) . '</p></noscript>';
        self::quotas( self::PROFILE_FIELD, 'dendrila-profile', $settings, true );
        echo '<h3>' . esc_html__( 'Connected calendars', 'dendrila-calendar' ) . '</h3>';
        self::checkbox( 'dendrila-profile-external', self::PROFILE_FIELD . '[check_external]', $settings['check_external'], __( 'Check busy times in all connected Google and Outlook calendars', 'dendrila-calendar' ) );
        echo '<p class="description">' . esc_html__( 'This includes every connected Google and Outlook destination, not only a calendar named after this profile.', 'dendrila-calendar' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-sync&dendrila_method=oauth' ) ) . '">' . esc_html__( 'Manage connected calendars', 'dendrila-calendar' ) . '</a></p>';
        echo '<p class="description">' . esc_html__( 'Only the appointment itself must fit inside these hours. Preparation and recovery time may extend outside them, but must remain free.', 'dendrila-calendar' ) . '</p>';
        echo '<template data-range-template>';
        self::range_fields( '', 'dendrila-range-template' );
        echo '</template><template data-exception-template>';
        self::exception_fields( 0 );
        echo '</template><p class="screen-reader-text" role="status" aria-live="polite" data-schedule-status></p></div>';
    }

    public static function render_rules( $post, $show_shortcodes = true, $include_nonce = true, $prefill_enabled = false ) {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $settings = Dendrila_Calendar_Schedule::rules( $post->ID );
        if ( $include_nonce ) {
            wp_nonce_field( 'dendrila_calendar_schedule_rules_' . $post->ID, 'dendrila_calendar_schedule_rules_nonce' );
        }
        echo '<div class="dendrila-schedule-admin" data-dendrila-schedule="rules">';
        self::checkbox( 'dendrila-rules-enabled', self::RULES_FIELD . '[enabled]', $settings['enabled'] || $prefill_enabled, __( 'Enable booking for this service', 'dendrila-calendar' ) );
        $profile_id = absint( get_post_meta( $post->ID, '_dendrila_booking_profile_id', true ) );
        echo '<p>' . esc_html__( 'This service uses its responsible profile’s weekly hours, date exceptions and time zone.', 'dendrila-calendar' );
        if ( $profile_id && 'dendrila_profile' === get_post_type( $profile_id ) ) {
            echo ' <a href="' . esc_url( get_edit_post_link( $profile_id, '' ) ) . '">' . esc_html__( 'Edit profile availability', 'dendrila-calendar' ) . '</a>';
        } else {
            echo ' ' . esc_html__( 'Choose a responsible profile in the service settings.', 'dendrila-calendar' ) . ' <a href="' . esc_url( admin_url( 'post-new.php?post_type=dendrila_profile' ) ) . '">' . esc_html__( 'Create a profile', 'dendrila-calendar' ) . '</a>';
        }
        echo '</p><div class="dendrila-schedule-numbers">';
        self::number_field( self::RULES_FIELD . '[notice_minutes]', 'dendrila-rules-notice', __( 'Minimum notice (minutes)', 'dendrila-calendar' ), $settings['notice_minutes'], 0, 525600 );
        self::number_field( self::RULES_FIELD . '[horizon_days]', 'dendrila-rules-horizon', __( 'Book up to this many days ahead', 'dendrila-calendar' ), $settings['horizon_days'], 0, 3650 );
        self::number_field( self::RULES_FIELD . '[slot_step]', 'dendrila-rules-step', __( 'Time between offered start times (minutes)', 'dendrila-calendar' ), $settings['slot_step'], 5, 240 );
        echo '</div><p class="description">' . esc_html__( 'A horizon of 0 days removes the rolling limit. Any fixed date limits still apply.', 'dendrila-calendar' ) . '</p><h3>' . esc_html__( 'Optional date limits', 'dendrila-calendar' ) . '</h3><p class="description">' . esc_html__( 'Leave either date empty for no fixed limit. These dates can only narrow the booking horizon above.', 'dendrila-calendar' ) . '</p><div class="dendrila-schedule-numbers">';
        foreach ( array( 'date_from' => __( 'First bookable date', 'dendrila-calendar' ), 'date_to' => __( 'Last bookable date', 'dendrila-calendar' ) ) as $key => $label ) {
            echo '<div><label for="dendrila-rules-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label><input type="date" id="dendrila-rules-' . esc_attr( $key ) . '" name="' . esc_attr( self::RULES_FIELD . '[' . $key . ']' ) . '" value="' . esc_attr( $settings[ $key ] ) . '"></div>';
        }
        echo '</div>';
        self::quotas( self::RULES_FIELD, 'dendrila-rules', $settings, false );
        echo '<p class="description">' . esc_html__( 'Duration, preparation time and recovery time are set in the other service settings. The appointment must fit within the profile hours; its preparation and recovery time must also be free.', 'dendrila-calendar' ) . '</p>';
        $price = absint( get_post_meta( $post->ID, '_dendrila_price_cents', true ) );
        $product = absint( get_post_meta( $post->ID, '_dendrila_woo_product_id', true ) );
        if ( $price > 0 && ! $product ) {
            echo '<p class="notice notice-warning inline">' . esc_html__( 'This service has a price but no WooCommerce product. Link a product in the service settings before accepting paid bookings.', 'dendrila-calendar' ) . '</p>';
        }
        if ( $show_shortcodes ) {
            echo '<h3>' . esc_html__( 'Add booking to a page', 'dendrila-calendar' ) . '</h3><p class="description">' . esc_html__( 'Paste a shortcode into a page. Free services can be booked directly; paid bookings use the linked WooCommerce product and its price.', 'dendrila-calendar' ) . '</p>';
            foreach ( array( 'service' => array( __( 'This service', 'dendrila-calendar' ), '[dendrila_booking service="' . absint( $post->ID ) . '"]' ), 'all' => array( __( 'Service selection', 'dendrila-calendar' ), '[dendrila_booking]' ) ) as $key => $item ) {
                echo '<p><label for="dendrila-booking-shortcode-' . esc_attr( $key ) . '">' . esc_html( $item[0] ) . '</label><input class="dendrila-schedule-shortcode" type="text" readonly data-select-shortcode id="dendrila-booking-shortcode-' . esc_attr( $key ) . '" value="' . esc_attr( $item[1] ) . '"></p>';
            }
        }
        echo '</div>';
    }

    private static function allowed_save( $post_id, $type, $part ) {
        $field = 'dendrila_calendar_schedule_' . $part . '_nonce';
        return ! ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) &&
            ! ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) &&
            ! ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) &&
            current_user_can( 'manage_options' ) && $type === get_post_type( $post_id ) &&
            isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ), 'dendrila_calendar_schedule_' . $part . '_' . $post_id );
    }

    private static function invalid_form() {
        return new WP_Error( 'dendrila_schedule_invalid_form', __( 'Check the availability fields and try again.', 'dendrila-calendar' ) );
    }

    private static function flag( $value ) {
        if ( in_array( $value, array( true, 1, '1' ), true ) ) { return true; }
        if ( in_array( $value, array( false, 0, '0' ), true ) ) { return false; }
        return null;
    }

    private static function ranges_input( $rows ) {
        if ( ! is_array( $rows ) ) { return self::invalid_form(); }
        $ranges = array();
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || ! isset( $row['start'], $row['end'] ) || ! is_string( $row['start'] ) || ! is_string( $row['end'] ) ) { return self::invalid_form(); }
            $start = trim( $row['start'] );
            $end = trim( $row['end'] );
            if ( '' === $start && '' === $end ) { continue; }
            if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $start ) || ! preg_match( '/^(?:(?:[01]\d|2[0-3]):[0-5]\d|24:00)$/D', $end ) ) { return self::invalid_form(); }
            $ranges[] = array( 'start' => $start, 'end' => '00:00' === $end ? '24:00' : $end );
        }
        return $ranges;
    }

    private static function profile_input( $input ) {
        if ( ! is_array( $input ) ) { return self::invalid_form(); }
        foreach ( array( 'enabled', 'timezone', 'max_per_day', 'max_per_week', 'max_per_month', 'check_external' ) as $key ) {
            if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[ $key ] ) ) { return self::invalid_form(); }
        }
        $enabled = self::flag( $input['enabled'] );
        $external = self::flag( $input['check_external'] );
        $closed = $input['closed'] ?? null;
        $weekly = $input['weekly'] ?? array();
        $exceptions = $input['exceptions'] ?? array();
        if ( null === $enabled || null === $external || ! is_array( $closed ) || ! is_array( $weekly ) || ! is_array( $exceptions ) ) { return self::invalid_form(); }
        foreach ( array( $closed, $weekly ) as $days ) {
            foreach ( array_keys( $days ) as $day ) {
                if ( ! in_array( (string) $day, array( '1', '2', '3', '4', '5', '6', '7' ), true ) ) { return self::invalid_form(); }
            }
        }
        $raw = array( 'enabled' => $enabled, 'timezone' => $input['timezone'], 'weekly' => array(), 'overrides' => array(), 'check_external' => $external );
        foreach ( self::days() as $day => $label ) {
            if ( ! array_key_exists( $day, $closed ) || null === self::flag( $closed[ $day ] ) ) { return self::invalid_form(); }
            $ranges = self::ranges_input( $weekly[ $day ] ?? array() );
            if ( is_wp_error( $ranges ) ) { return $ranges; }
            if ( ! self::flag( $closed[ $day ] ) && ! $ranges ) { return new WP_Error( 'dendrila_schedule_empty_day', __( 'Each open day needs a time range. Otherwise, mark it Closed.', 'dendrila-calendar' ) ); }
            $raw['weekly'][ $day ] = self::flag( $closed[ $day ] ) ? array() : $ranges;
        }
        foreach ( $exceptions as $exception ) {
            if ( ! is_array( $exception ) || ! isset( $exception['date'], $exception['closed'] ) || ! is_string( $exception['date'] ) || null === self::flag( $exception['closed'] ) ) { return self::invalid_form(); }
            $date = trim( $exception['date'] );
            $ranges = self::ranges_input( $exception['slots'] ?? array() );
            if ( is_wp_error( $ranges ) ) { return $ranges; }
            if ( '' === $date && ! $ranges ) { continue; }
            if ( '' === $date || array_key_exists( $date, $raw['overrides'] ) ) { return new WP_Error( 'dendrila_schedule_duplicate_date', __( 'Give each date exception a valid, unique date.', 'dendrila-calendar' ) ); }
            if ( ! self::flag( $exception['closed'] ) && ! $ranges ) { return new WP_Error( 'dendrila_schedule_empty_exception', __( 'Enter hours for the date exception or select Closed all day.', 'dendrila-calendar' ) ); }
            $raw['overrides'][ $date ] = self::flag( $exception['closed'] ) ? array() : $ranges;
        }
        foreach ( array( 'max_per_day', 'max_per_week', 'max_per_month' ) as $key ) { $raw[ $key ] = $input[ $key ]; }
        return Dendrila_Calendar_Schedule::normalize_profile( $raw );
    }

    private static function rules_input( $input ) {
        if ( ! is_array( $input ) ) { return self::invalid_form(); }
        $raw = array();
        foreach ( array( 'enabled', 'notice_minutes', 'horizon_days', 'slot_step', 'date_from', 'date_to', 'max_per_day', 'max_per_week', 'max_per_month' ) as $key ) {
            if ( ! array_key_exists( $key, $input ) || ! is_scalar( $input[ $key ] ) ) { return self::invalid_form(); }
            $raw[ $key ] = $input[ $key ];
        }
        if ( null === self::flag( $raw['enabled'] ) ) { return self::invalid_form(); }
        $raw['enabled'] = self::flag( $raw['enabled'] );
        return Dendrila_Calendar_Schedule::normalize_rules( $raw );
    }

    private static function notice_key( $post_id ) {
        return 'dendrila_schedule_error_' . get_current_user_id() . '_' . absint( $post_id );
    }

    private static function persist( $post_id, $meta_key, $result ) {
        $notice = self::notice_key( $post_id );
        if ( is_wp_error( $result ) ) {
            set_transient( $notice, $result->get_error_message(), 600 );
            return;
        }
        update_post_meta( $post_id, $meta_key, $result );
        delete_transient( $notice );
    }

    public static function save_profile( $post_id ) {
        if ( ! self::allowed_save( $post_id, 'dendrila_profile', 'profile' ) ) { return; }
        $input = isset( $_POST[ self::PROFILE_FIELD ] ) ? wp_unslash( $_POST[ self::PROFILE_FIELD ] ) : null;
        self::persist( $post_id, Dendrila_Calendar_Schedule::PROFILE_META, self::profile_input( $input ) );
    }

    public static function save_rules( $post_id ) {
        if ( ! self::allowed_save( $post_id, 'dendrila_service', 'rules' ) ) { return; }
        $input = isset( $_POST[ self::RULES_FIELD ] ) ? wp_unslash( $_POST[ self::RULES_FIELD ] ) : null;
        self::persist( $post_id, Dendrila_Calendar_Schedule::RULES_META, self::rules_input( $input ) );
    }

    public static function admin_notices() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, array( 'dendrila_profile', 'dendrila_service' ), true ) ) { return; }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selection of the current administrator's notice.
        $post_id = isset( $_GET['post'] ) && is_scalar( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        if ( ! $post_id ) { return; }
        $key = self::notice_key( $post_id );
        $message = get_transient( $key );
        if ( ! is_string( $message ) || '' === $message ) { return; }
        delete_transient( $key );
        echo '<div class="notice notice-error" role="alert"><p><strong>' . esc_html__( 'Availability settings were not saved. Previous settings were kept.', 'dendrila-calendar' ) . '</strong> ' . esc_html( $message ) . '</p></div>';
    }
}
