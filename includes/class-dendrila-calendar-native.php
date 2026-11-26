<?php
/**
 * First-party booking domain: mandatory profiles, services and private bookings.
 *
 * Profiles and services supply the native availability and locked booking engine.
 * Existing read-only connectors remain optional.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dendrila_Calendar_Native {
    const PROFILE = 'dendrila_profile';
    const SERVICE = 'dendrila_service';
    const BOOKING = 'dendrila_booking';

    const PROFILE_KEY = '_dendrila_booking_profile_id';
    const SERVICE_KEY = '_dendrila_booking_service_id';
    const START_KEY = '_dendrila_booking_start_utc';
    const END_KEY = '_dendrila_booking_end_utc';
    const STATUS_KEY = '_dendrila_booking_status';

    public static function boot() {
        add_action( 'init', array( __CLASS__, 'register_types' ) );
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 25 );
        add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
        add_action( 'save_post_' . self::SERVICE, array( __CLASS__, 'save_service' ) );
        add_action( 'admin_notices', array( __CLASS__, 'profile_notice' ) );
    }

    private static function caps() {
        $all = array(
            'edit_post', 'read_post', 'delete_post', 'edit_posts', 'edit_others_posts',
            'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts',
            'delete_published_posts', 'delete_others_posts', 'edit_private_posts',
            'edit_published_posts', 'create_posts',
        );
        return array_fill_keys( $all, 'manage_options' );
    }

    public static function register_types() {
        register_post_type( self::PROFILE, array(
            'labels' => array(
                'name' => __( 'Booking profiles', 'dendrila-calendar' ),
                'singular_name' => __( 'Booking profile', 'dendrila-calendar' ),
                'add_new_item' => __( 'Add booking profile', 'dendrila-calendar' ),
                'edit_item' => __( 'Edit booking profile', 'dendrila-calendar' ),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => array( 'title', 'editor', 'thumbnail' ),
            'capabilities' => self::caps(),
            'map_meta_cap' => false,
        ) );
        register_post_type( self::SERVICE, array(
            'labels' => array(
                'name' => __( 'Services', 'dendrila-calendar' ),
                'singular_name' => __( 'Service', 'dendrila-calendar' ),
                'add_new_item' => __( 'Add service', 'dendrila-calendar' ),
                'edit_item' => __( 'Edit service', 'dendrila-calendar' ),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => array( 'title', 'editor', 'excerpt' ),
            'capabilities' => self::caps(),
            'map_meta_cap' => false,
        ) );
        register_post_type( self::BOOKING, array(
            'labels' => array(
                'name' => __( 'Native bookings', 'dendrila-calendar' ),
                'singular_name' => __( 'Native booking', 'dendrila-calendar' ),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_rest' => false,
            'supports' => array( 'title' ),
            'capabilities' => self::caps(),
            'map_meta_cap' => false,
        ) );
    }

    public static function menu() {
        add_submenu_page(
            'dendrila-calendar',
            __( 'Booking profiles', 'dendrila-calendar' ),
            __( 'Profiles', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar-profiles',
            array( __CLASS__, 'render_profiles' )
        );
        add_submenu_page(
            'dendrila-calendar',
            __( 'Services', 'dendrila-calendar' ),
            __( 'Services', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar-services',
            array( __CLASS__, 'render_services' )
        );
        add_submenu_page(
            'dendrila-calendar',
            __( 'Native bookings', 'dendrila-calendar' ),
            __( 'Bookings', 'dendrila-calendar' ),
            'manage_options',
            'dendrila-calendar-bookings',
            array( __CLASS__, 'render_bookings' )
        );
    }

    public static function profiles() {
        return get_posts( array(
            'post_type' => self::PROFILE,
            'post_status' => 'publish',
            'numberposts' => 200,
            'orderby' => 'title',
            'order' => 'ASC',
        ) );
    }

    /** At least one published profile is mandatory before bookings can open. */
    public static function has_profile() {
        return ! empty( self::profiles() );
    }

    public static function valid_profile( $profile_id ) {
        if ( absint( $profile_id ) < 1 ) { return false; }
        $profile = get_post( absint( $profile_id ) );
        return $profile && self::PROFILE === $profile->post_type && 'publish' === $profile->post_status;
    }

    public static function service_profile_id( $service_id ) {
        if ( absint( $service_id ) < 1 ) { return 0; }
        $post = get_post( absint( $service_id ) );
        if ( ! $post || self::SERVICE !== $post->post_type || 'publish' !== $post->post_status ) {
            return 0;
        }
        $profile_id = absint( get_post_meta( $post->ID, self::PROFILE_KEY, true ) );
        return self::valid_profile( $profile_id ) ? $profile_id : 0;
    }

    /** Only complete, owned, published services may be booked. */
    public static function bookable_service( $service_id ) {
        $profile_id = self::service_profile_id( $service_id );
        $duration = absint( get_post_meta( absint( $service_id ), '_dendrila_duration_minutes', true ) );
        return 0 !== $profile_id && $duration >= 5 && $duration <= 1440;
    }

    public static function service_data( $service_id ) {
        if ( ! self::bookable_service( $service_id ) ) {
            return false;
        }
        $service_id = absint( $service_id );
        return array(
            'service_id' => $service_id,
            'profile_id' => self::service_profile_id( $service_id ),
            'duration_minutes' => absint( get_post_meta( $service_id, '_dendrila_duration_minutes', true ) ),
            'buffer_before' => absint( get_post_meta( $service_id, '_dendrila_buffer_before', true ) ),
            'buffer_after' => absint( get_post_meta( $service_id, '_dendrila_buffer_after', true ) ),
            'price_cents' => absint( get_post_meta( $service_id, '_dendrila_price_cents', true ) ),
            'woo_product_id' => absint( get_post_meta( $service_id, '_dendrila_woo_product_id', true ) ),
        );
    }

    public static function meta_boxes() {
        add_meta_box(
            'dendrila-service-settings',
            __( 'Duration, profile and price', 'dendrila-calendar' ),
            array( __CLASS__, 'render_service_fields' ),
            self::SERVICE,
            'normal',
            'high'
        );
    }

    public static function render_service_fields( $post ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_nonce_field( 'dendrila_calendar_service_save', 'dendrila_calendar_service_nonce' );
        $profiles = self::profiles();
        $selected = absint( get_post_meta( $post->ID, self::PROFILE_KEY, true ) );
        echo '<p><label for="dendrila-profile-id"><strong>' . esc_html__( 'Responsible profile (required)', 'dendrila-calendar' ) . '</strong></label></p>';
        echo '<select id="dendrila-profile-id" name="dendrila_profile_id" required>';
        echo '<option value="">' . esc_html__( 'Select a profile', 'dendrila-calendar' ) . '</option>';
        foreach ( $profiles as $profile ) {
            echo '<option value="' . esc_attr( $profile->ID ) . '"' . selected( $selected, (int) $profile->ID, false ) . '>' . esc_html( $profile->post_title ) . '</option>';
        }
        echo '</select>';
        if ( ! $profiles ) {
            echo '<p class="notice notice-warning inline">' . esc_html__( 'Create and publish at least one booking profile before offering a service.', 'dendrila-calendar' ) . '</p>';
            echo '<p><a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::PROFILE ) ) . '">' .
                esc_html__( 'Create a profile', 'dendrila-calendar' ) . '</a></p>';
        }
        $fields = array(
            'duration' => array( __( 'Duration in minutes', 'dendrila-calendar' ), '_dendrila_duration_minutes', 30, 5, 1440 ),
            'before' => array( __( 'Preparation time before (minutes)', 'dendrila-calendar' ), '_dendrila_buffer_before', 0, 0, 1440 ),
            'after' => array( __( 'Recovery time after (minutes)', 'dendrila-calendar' ), '_dendrila_buffer_after', 0, 0, 1440 ),
            'price' => array( __( 'Price in cents (0 = free)', 'dendrila-calendar' ), '_dendrila_price_cents', 0, 0, 100000000 ),
            'product' => array( __( 'WooCommerce product ID (optional)', 'dendrila-calendar' ), '_dendrila_woo_product_id', 0, 0, 2147483647 ),
        );
        foreach ( $fields as $key => $field ) {
            $saved = get_post_meta( $post->ID, $field[1], true );
            $value = '' === (string) $saved ? $field[2] : absint( $saved );
            if ( 'product' === $key && class_exists( 'Dendrila_Calendar_Woo' ) ) {
                Dendrila_Calendar_Woo::render_product_field( $post->ID, $value );
                continue;
            }
            echo '<p><label for="dendrila-service-' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label> ';
            echo '<input id="dendrila-service-' . esc_attr( $key ) . '" type="number" step="1" min="' .
                esc_attr( $field[3] ) . '" max="' . esc_attr( $field[4] ) . '" name="dendrila_service_' . esc_attr( $key ) .
                '" value="' . esc_attr( $value ) . '"></p>';
        }
        echo '<p class="description">' .
            esc_html__( 'For paid appointments, select a published, purchasable simple virtual WooCommerce product. Its current price and taxes apply. A nonzero price without a valid product keeps online booking closed.', 'dendrila-calendar' ) .
            '</p>';
    }

    public static function save_service( $post_id ) {
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
             ! current_user_can( 'manage_options' ) ||
             ! isset( $_POST['dendrila_calendar_service_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dendrila_calendar_service_nonce'] ) ), 'dendrila_calendar_service_save' ) ) {
            return;
        }
        $profile_id = isset( $_POST['dendrila_profile_id'] ) ? absint( wp_unslash( $_POST['dendrila_profile_id'] ) ) : 0;
        if ( self::valid_profile( $profile_id ) ) {
            update_post_meta( $post_id, self::PROFILE_KEY, $profile_id );
        } else {
            // An unassigned service is never bookable; don't fall back to another profile.
            delete_post_meta( $post_id, self::PROFILE_KEY );
        }
        $fields = array(
            'duration' => array( '_dendrila_duration_minutes', 30, 5, 1440 ),
            'before' => array( '_dendrila_buffer_before', 0, 0, 1440 ),
            'after' => array( '_dendrila_buffer_after', 0, 0, 1440 ),
            'price' => array( '_dendrila_price_cents', 0, 0, 100000000 ),
            'product' => array( '_dendrila_woo_product_id', 0, 0, 2147483647 ),
        );
        foreach ( $fields as $key => $definition ) {
            $field = 'dendrila_service_' . $key;
            $value = isset( $_POST[ $field ] ) ? absint( wp_unslash( $_POST[ $field ] ) ) : $definition[1];
            // Never silently accept a duration of zero or extreme buffer size.
            $value = min( $definition[3], max( $definition[2], $value ) );
            update_post_meta( $post_id, $definition[0], $value );
        }
    }

    /**
     * Validate the identity tuple before any future reservation is saved.
     * This is called independently of the public interface and payment provider.
     */
    public static function validate_booking( $service_id, $profile_id, $start_utc, $end_utc ) {
        $service = self::service_data( $service_id );
        if ( ! $service || ! self::has_profile() ) {
            return new WP_Error( 'dendrila_missing_profile', __( 'Create a profile and assign it to a published service before accepting bookings.', 'dendrila-calendar' ) );
        }
        if ( $service['profile_id'] !== absint( $profile_id ) ) {
            return new WP_Error( 'dendrila_profile_mismatch', __( 'The selected profile does not provide this service.', 'dendrila-calendar' ) );
        }
        try {
            $start = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $start_utc, new DateTimeZone( 'UTC' ) );
            $end = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $end_utc, new DateTimeZone( 'UTC' ) );
            if ( ! $start || ! $end || $start->format( 'Y-m-d H:i:s' ) !== $start_utc ||
                 $end->format( 'Y-m-d H:i:s' ) !== $end_utc || $end <= $start ) {
                throw new Exception( 'Invalid date' );
            }
        } catch ( Exception $exception ) {
            return new WP_Error( 'dendrila_invalid_slot', __( 'This reservation has an invalid time period.', 'dendrila-calendar' ) );
        }
        if ( $end->getTimestamp() - $start->getTimestamp() !== $service['duration_minutes'] * 60 ) {
            return new WP_Error( 'dendrila_wrong_duration', __( 'The reservation duration must match its service.', 'dendrila-calendar' ) );
        }
        return true;
    }

    /** Draft storage contract. Public confirmation must use Dendrila_Calendar_Booking::create(). */
    public static function booking_record( $service_id, $profile_id, $start_utc, $end_utc ) {
        $valid = self::validate_booking( $service_id, $profile_id, $start_utc, $end_utc );
        if ( true !== $valid ) {
            return $valid;
        }
        return array(
            'post_type' => self::BOOKING,
            'post_status' => 'private',
            'meta_input' => array(
                self::SERVICE_KEY => absint( $service_id ),
                self::PROFILE_KEY => absint( $profile_id ),
                self::START_KEY => $start_utc,
                self::END_KEY => $end_utc,
                self::STATUS_KEY => 'draft',
            ),
        );
    }

    public static function profile_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // Only on Dendrila screens, never across the entire WordPress admin.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
        $page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $type = $screen && isset( $screen->post_type ) ? $screen->post_type : '';
        if ( 0 !== strpos( $page, 'dendrila-calendar' ) && ! in_array( $type, array( self::PROFILE, self::SERVICE, self::BOOKING ), true ) ) {
            return;
        }
        if ( self::has_profile() ) { return; }
        echo '<div class="notice notice-info"><p>' .
            esc_html__( 'Native booking setup: create at least one profile before offering services or taking reservations.', 'dendrila-calendar' ) .
            ' <a href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::PROFILE ) ) . '">' .
            esc_html__( 'Create the first profile', 'dendrila-calendar' ) . '</a></p></div>';
    }

    private static function head( $heading, $intro ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You cannot manage Dendrila booking profiles.', 'dendrila-calendar' ) );
        }
        echo '<div class="wrap dendrila-native"><h1>' . esc_html( $heading ) . '</h1><p>' . esc_html( $intro ) . '</p>';
    }

    public static function render_profiles() {
        self::head(
            __( 'Booking profiles', 'dendrila-calendar' ),
            __( 'Each reservation belongs to a profile. Create one per professional or resource, then set its hours and date exceptions.', 'dendrila-calendar' )
        );
        echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::PROFILE ) ) . '">' .
            esc_html__( 'Add a profile', 'dendrila-calendar' ) . '</a> <a class="button" href="' .
            esc_url( admin_url( 'edit.php?post_type=' . self::PROFILE ) ) . '">' . esc_html__( 'Manage profiles', 'dendrila-calendar' ) . '</a></p>';
        $profiles = self::profiles();
        if ( ! $profiles ) {
            echo '<p class="notice notice-warning inline">' . esc_html__( 'No active profile yet. Bookings cannot be offered.', 'dendrila-calendar' ) . '</p>';
        } else {
            echo '<ul>';
            foreach ( $profiles as $profile ) {
                echo '<li><a href="' . esc_url( get_edit_post_link( $profile->ID, '' ) ) . '">' . esc_html( $profile->post_title ) . '</a>';
                if ( class_exists( 'Dendrila_Calendar_Schedule' ) ) {
                    $schedule = Dendrila_Calendar_Schedule::profile( $profile->ID );
                    echo '<br><span class="description">' . esc_html( $schedule['timezone'] . ' — ' . ( $schedule['enabled'] ? __( 'Availability enabled', 'dendrila-calendar' ) : __( 'Availability closed', 'dendrila-calendar' ) ) ) . '</span>';
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    public static function render_services() {
        self::head(
            __( 'Services', 'dendrila-calendar' ),
            __( 'Create a WooCommerce product for a paid appointment and use its Appointment booking tab. You can also create a free service directly here. Every service needs a responsible profile.', 'dendrila-calendar' )
        );
        if ( ! self::has_profile() ) {
            echo '<p class="notice notice-warning inline">' .
                esc_html__( 'A profile is required before a service can be offered for booking.', 'dendrila-calendar' ) .
                ' <a href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::PROFILE ) ) . '">' .
                esc_html__( 'Create a profile', 'dendrila-calendar' ) . '</a></p>';
        } else {
            if ( class_exists( 'Dendrila_Calendar_Woo' ) && Dendrila_Calendar_Woo::available() ) {
                echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=product' ) ) . '">' . esc_html__( 'Add a WooCommerce appointment product', 'dendrila-calendar' ) . '</a></p>';
            }
            echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'post-new.php?post_type=' . self::SERVICE ) ) . '">' .
                esc_html__( 'Add a service', 'dendrila-calendar' ) . '</a></p>';
        }
        echo '<p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . self::SERVICE ) ) . '">' .
            esc_html__( 'Manage services', 'dendrila-calendar' ) . '</a></p>';
        echo '</div>';
    }

    public static function render_bookings() {
        self::head(
            __( 'Native bookings', 'dendrila-calendar' ),
            __( 'Reservations are linked to a service and its profile. Free bookings are confirmed immediately; paid bookings follow their WooCommerce order. Add [dendrila_booking] to a page to offer your enabled services.', 'dendrila-calendar' )
        );
        echo '<p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . self::BOOKING ) ) . '">' .
            esc_html__( 'View private booking records', 'dendrila-calendar' ) . '</a></p></div>';
    }
}
