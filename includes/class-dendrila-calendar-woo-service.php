<?php
/** A paid service is edited with its WooCommerce product; the booking record is an internal projection. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Calendar_Woo_Service {
    const SERVICE_KEY = '_dendrila_calendar_service_id';
    const ENABLED_KEY = '_dendrila_calendar_booking_product';
    private static $pending = array();
    private static $saving = false;

    public static function boot() {
        add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'tab' ) );
        add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'panel' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'prepare' ) );
        add_action( 'woocommerce_after_product_object_save', array( __CLASS__, 'saved' ), 20, 2 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_filter( 'get_edit_post_link', array( __CLASS__, 'edit_link' ), 20, 3 );
        add_action( 'wp', array( __CLASS__, 'product_page' ) );
        add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_button' ), 20, 2 );
        add_filter( 'render_block', array( __CLASS__, 'product_block' ), 20, 3 );
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'guard_cart' ), 20, 2 );
        add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'guard_store_cart' ), 20, 2 );
        add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart' ) );
        add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'check_store_cart' ), 20, 2 );
    }

    public static function service_id( $product_id ) {
        if ( absint( $product_id ) < 1 ) { return 0; }
        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
        $id = $product ? absint( $product->get_meta( self::SERVICE_KEY, true ) ) : 0;
        if ( $id && 'dendrila_service' === get_post_type( $id ) ) {
            // Verify the actual product association, not a submitted service ID.
            if ( (int) get_post_meta( $id, '_dendrila_woo_product_id', true ) === (int) $product_id ) { return $id; }
        }
        $posts = get_posts( array( 'post_type' => Dendrila_Calendar_Native::SERVICE, 'post_status' => array( 'publish', 'draft', 'private', 'pending' ),
            'numberposts' => 2, 'meta_key' => '_dendrila_woo_product_id', 'meta_value' => absint( $product_id ) ) );
        return 1 === count( $posts ) ? (int) $posts[0]->ID : 0;
    }

    public static function tab( $tabs ) {
        if ( current_user_can( 'manage_options' ) ) {
            $tabs['dendrila_booking'] = array( 'label' => __( 'Appointment booking', 'dendrila-calendar' ), 'target' => 'dendrila-product-booking', 'class' => array( 'show_if_simple' ), 'priority' => 65 );
        }
        return $tabs;
    }

    public static function enqueue() {
        $screen = get_current_screen();
        if ( ! $screen || 'product' !== $screen->post_type || ! current_user_can( 'manage_options' ) ) { return; }
        $base = dirname( __DIR__ ); $url = plugin_dir_url( $base . '/dendrila-calendar.php' );
        wp_enqueue_style( 'dendrila-calendar-schedule-admin', $url . 'assets/calendar-schedule-admin.css', array(), (string) filemtime( $base . '/assets/calendar-schedule-admin.css' ) );
        wp_enqueue_script( 'dendrila-calendar-schedule-admin', $url . 'assets/calendar-schedule-admin.js', array(), (string) filemtime( $base . '/assets/calendar-schedule-admin.js' ), true );
        wp_add_inline_style( 'dendrila-calendar-schedule-admin', '#dendrila-product-booking{padding:16px;box-sizing:border-box}#dendrila-product-booking>p{padding:0!important}#dendrila-product-booking label{float:none;width:auto;margin:0}#dendrila-product-booking input[type=number],#dendrila-product-booking input[type=date],#dendrila-product-booking select{float:none;width:100%;max-width:24rem}#dendrila-product-booking .dendrila-product-basics{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:16px;margin:16px 0}#dendrila-product-booking .dendrila-product-basics label{display:block;margin-bottom:6px}' );
    }

    public static function panel() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $product_object, $post;
        $product_id = $product_object ? $product_object->get_id() : ( $post->ID ?? 0 );
        $service_id = self::service_id( $product_id );
        $enabled = $service_id > 0 && ( ! $product_object || 'no' !== $product_object->get_meta( self::ENABLED_KEY, true ) );
        echo '<div id="dendrila-product-booking" class="panel woocommerce_options_panel hidden">';
        wp_nonce_field( 'dendrila_product_booking_' . $product_id, 'dendrila_product_booking_nonce' );
        echo '<p><input type="hidden" name="dendrila_product_booking" value="0"><label><input type="checkbox" name="dendrila_product_booking" value="1"' . checked( $enabled, true, false ) . '> <strong>' . esc_html__( 'Use this product as an appointment service', 'dendrila-calendar' ) . '</strong></label></p>';
        echo '<p>' . esc_html__( 'Keep the name, description and price in the normal WooCommerce fields. Dendrila adds the appointment settings here and creates the linked service automatically. Appointment products are virtual.', 'dendrila-calendar' ) . '</p>';
        $profile_id = absint( get_post_meta( $service_id, Dendrila_Calendar_Native::PROFILE_KEY, true ) );
        echo '<div class="dendrila-product-basics"><div><label for="dendrila-product-profile">' . esc_html__( 'Responsible profile', 'dendrila-calendar' ) . '</label><select id="dendrila-product-profile" name="dendrila_product_profile"><option value="0">' . esc_html__( 'Select a profile', 'dendrila-calendar' ) . '</option>';
        foreach ( Dendrila_Calendar_Native::profiles() as $profile ) { echo '<option value="' . esc_attr( $profile->ID ) . '"' . selected( $profile_id, (int) $profile->ID, false ) . '>' . esc_html( $profile->post_title ) . '</option>'; }
        echo '</select></div>';
        foreach ( array( 'duration' => array( __( 'Duration in minutes', 'dendrila-calendar' ), '_dendrila_duration_minutes', 30, 5 ),
            'before' => array( __( 'Preparation time before (minutes)', 'dendrila-calendar' ), '_dendrila_buffer_before', 0, 0 ),
            'after' => array( __( 'Recovery time after (minutes)', 'dendrila-calendar' ), '_dendrila_buffer_after', 0, 0 ) ) as $key => $field ) {
            $saved = $service_id ? get_post_meta( $service_id, $field[1], true ) : '';
            $value = '' === $saved ? $field[2] : absint( $saved );
            echo '<div><label for="dendrila-product-' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label><input id="dendrila-product-' . esc_attr( $key ) . '" name="dendrila_product_' . esc_attr( $key ) . '" type="number" min="' . esc_attr( $field[3] ) . '" max="1440" step="1" value="' . esc_attr( $value ) . '"></div>';
        }
        echo '</div><p><a href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-profiles' ) ) . '">' . esc_html__( 'Manage profiles and weekly hours', 'dendrila-calendar' ) . '</a></p>';
        Dendrila_Calendar_Schedule_Admin::render_rules( (object) array( 'ID' => $service_id ), $service_id > 0, false );
        if ( ! $service_id ) { echo '<p class="description">' . esc_html__( 'Save this product to generate its booking shortcode.', 'dendrila-calendar' ) . '</p>'; }
        echo '</div>';
    }

    private static function error( $message ) {
        if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) { WC_Admin_Meta_Boxes::add_error( $message ); }
    }

    /** Validate every field before mutating either a product association or its booking rules. */
    public static function prepare( $product ) {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['dendrila_product_booking_nonce'] ) || ! is_string( $_POST['dendrila_product_booking_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dendrila_product_booking_nonce'] ) ), 'dendrila_product_booking_' . $product->get_id() ) ) { return; }
        $flag = $_POST['dendrila_product_booking'] ?? null;
        if ( ! is_string( $flag ) || ! in_array( $flag, array( '0', '1' ), true ) ) { self::error( __( 'Check the appointment fields. Previous booking settings were kept.', 'dendrila-calendar' ) ); return; }
        if ( '0' === $flag ) {
            self::$pending[ $product->get_id() ] = array( 'enabled' => false );
            $product->update_meta_data( self::ENABLED_KEY, 'no' ); return;
        }
        $profile_raw = $_POST['dendrila_product_profile'] ?? '';
        $profile_id = is_scalar( $profile_raw ) ? absint( $profile_raw ) : 0;
        if ( ! $product->is_type( 'simple' ) || ! Dendrila_Calendar_Native::valid_profile( $profile_id ) ) {
            self::error( __( 'Choose a simple product and a published responsible profile before enabling appointment booking.', 'dendrila-calendar' ) ); return;
        }
        $config = array( 'enabled' => true, 'profile_id' => $profile_id );
        foreach ( array( 'duration' => 5, 'before' => 0, 'after' => 0 ) as $key => $min ) {
            $raw = $_POST[ 'dendrila_product_' . $key ] ?? null;
            if ( ! is_scalar( $raw ) || ! preg_match( '/^\d+$/D', (string) $raw ) || (int) $raw < $min || (int) $raw > 1440 ) {
                self::error( __( 'Enter a valid appointment duration and preparation/recovery times.', 'dendrila-calendar' ) ); return;
            }
            $config[ $key ] = (int) $raw;
        }
        $raw = isset( $_POST['dendrila_schedule_rules'] ) && is_array( $_POST['dendrila_schedule_rules'] ) ? wp_unslash( $_POST['dendrila_schedule_rules'] ) : null;
        if ( ! is_array( $raw ) || ! isset( $raw['enabled'] ) || ! is_scalar( $raw['enabled'] ) || ! in_array( (string) $raw['enabled'], array( '0', '1' ), true ) ) {
            self::error( __( 'Check the appointment fields. Previous booking settings were kept.', 'dendrila-calendar' ) ); return;
        }
        $config['rules'] = Dendrila_Calendar_Schedule::normalize_rules( $raw );
        if ( is_wp_error( $config['rules'] ) ) { self::error( $config['rules']->get_error_message() ); return; }
        self::$pending[ $product->get_id() ] = $config;
        $product->set_virtual( true );
        $product->update_meta_data( self::ENABLED_KEY, 'yes' );
    }

    public static function saved( $product, $data_store = null ) {
        if ( self::$saving || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) { return; }
        $product_id = $product->get_id();
        $config = self::$pending[ $product_id ] ?? null;
        unset( self::$pending[ $product_id ] );
        $service_id = self::service_id( $product_id );
        if ( ! $service_id && ( ! $config || empty( $config['enabled'] ) ) ) { return; }
        self::$saving = true;
        try {
            if ( $config && empty( $config['enabled'] ) ) {
                if ( $service_id ) { $rules = Dendrila_Calendar_Schedule::rules( $service_id ); $rules['enabled'] = false; update_post_meta( $service_id, Dendrila_Calendar_Schedule::RULES_META, $rules ); }
                return;
            }
            $meta = array( '_dendrila_woo_product_id' => $product_id, '_dendrila_price_cents' => 0 );
            if ( $config ) {
                $meta += array( Dendrila_Calendar_Native::PROFILE_KEY => $config['profile_id'], '_dendrila_duration_minutes' => $config['duration'],
                    '_dendrila_buffer_before' => $config['before'], '_dendrila_buffer_after' => $config['after'], Dendrila_Calendar_Schedule::RULES_META => $config['rules'] );
            }
            $record = array( 'post_type' => Dendrila_Calendar_Native::SERVICE, 'post_status' => 'publish' === $product->get_status() ? 'publish' : 'draft',
                'post_title' => $product->get_name(), 'post_content' => $product->get_description(), 'post_excerpt' => $product->get_short_description(), 'meta_input' => $meta );
            if ( $service_id ) { $record['ID'] = $service_id; }
            $saved = wp_insert_post( wp_slash( $record ), true );
            if ( is_wp_error( $saved ) || ! $saved ) { self::error( __( 'The linked appointment service could not be saved. Please save the product again.', 'dendrila-calendar' ) ); return; }
            if ( (int) $product->get_meta( self::SERVICE_KEY, true ) !== (int) $saved ) {
                $product->update_meta_data( self::SERVICE_KEY, (int) $saved );
                $product->save_meta_data();
            }
        } finally { self::$saving = false; }
    }

    /** The product owns paid-service content and fields; all edit links lead back to that one editor. */
    public static function edit_link( $link, $post_id, $context = 'display' ) {
        if ( Dendrila_Calendar_Native::SERVICE !== get_post_type( $post_id ) ) { return $link; }
        $product_id = absint( get_post_meta( $post_id, '_dendrila_woo_product_id', true ) );
        if ( $product_id && 'product' === get_post_type( $product_id ) ) { return get_edit_post_link( $product_id, $context ); }
        return $link;
    }

    /** A linked appointment must always go through a selected time, including old carts. */
    public static function is_appointment( $product_id ) {
        return self::service_id( $product_id ) > 0;
    }

    public static function product_page() {
        if ( ! function_exists( 'is_product' ) || ! is_product() || ! self::is_appointment( get_queried_object_id() ) ) { return; }
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
        add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'product_form' ), 30 );
    }

    public static function product_form() {
        $service_id = self::service_id( get_queried_object_id() );
        if ( $service_id ) { echo Dendrila_Calendar_Booking_Form::render( array( 'service' => $service_id ) ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Form escapes each field.
    }

    private static function select_link( $product_id ) {
        return '<a class="button dendrila-booking-product-link" href="' . esc_url( get_permalink( $product_id ) ) . '">' . esc_html__( 'Choose an appointment time', 'dendrila-calendar' ) . '</a>';
    }

    public static function loop_button( $html, $product ) {
        return self::is_appointment( $product->get_id() ) ? self::select_link( $product->get_id() ) : $html;
    }

    public static function product_block( $html, $block, $instance ) {
        $name = $block['blockName'] ?? '';
        if ( ! in_array( $name, array( 'woocommerce/add-to-cart-form', 'woocommerce/add-to-cart-with-options', 'woocommerce/product-button' ), true ) ) { return $html; }
        $product_id = absint( $instance->context['postId'] ?? $block['attrs']['productId'] ?? 0 );
        $service_id = $product_id ? self::service_id( $product_id ) : 0;
        if ( ! $service_id ) { return $html; }
        if ( 'woocommerce/product-button' === $name ) { return self::select_link( $product_id ); }
        return Dendrila_Calendar_Booking_Form::render( array( 'service' => $service_id ) );
    }

    private static function cart_message() {
        return __( 'Choose an available appointment time on the product page before paying for this service.', 'dendrila-calendar' );
    }

    public static function guard_cart( $valid, $product_id ) {
        if ( self::is_appointment( $product_id ) ) { wc_add_notice( self::cart_message(), 'error' ); return false; }
        return $valid;
    }

    public static function guard_store_cart( $product, $request ) {
        if ( self::is_appointment( $product->get_id() ) || ( $product->get_parent_id() && self::is_appointment( $product->get_parent_id() ) ) ) {
            throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'dendrila_appointment_time_required', self::cart_message(), 400 );
        }
    }

    public static function check_cart() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) { return; }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( self::is_appointment( $item['product_id'] ?? 0 ) ) { wc_add_notice( self::cart_message(), 'error' ); return; }
        }
    }

    public static function check_store_cart( $errors, $cart ) {
        foreach ( $cart->get_cart() as $item ) {
            if ( self::is_appointment( $item['product_id'] ?? 0 ) ) { $errors->add( 'dendrila_appointment_time_required', self::cart_message() ); return; }
        }
    }
}
