<?php
/** Booking presentation and a permanent, first-party WooCommerce setup guide. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Dendrila_Calendar_Appearance {
    const OPTION = 'dendrila_calendar_booking_style';
    const COLORS_OPTION = 'dendrila_calendar_booking_palettes';

    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 28 );
        add_action( 'admin_post_dendrila_calendar_style_save', array( __CLASS__, 'save' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function choices() {
        return array(
            'classic' => array( __( 'Classic', 'dendrila-calendar' ), __( 'A familiar, balanced booking form.', 'dendrila-calendar' ) ),
            'cards' => array( __( 'Cards', 'dendrila-calendar' ), __( 'Soft background and prominent appointment choices.', 'dendrila-calendar' ) ),
            'minimal' => array( __( 'Minimal', 'dendrila-calendar' ), __( 'A compact design that blends into your site.', 'dendrila-calendar' ) ),
            'contrast' => array( __( 'High contrast', 'dendrila-calendar' ), __( 'Stronger outlines and clearer selection states.', 'dendrila-calendar' ) ),
        );
    }

    public static function normalize( $style ) {
        return is_string( $style ) && array_key_exists( $style, self::choices() ) ? $style : 'classic';
    }

    public static function selected() {
        return self::normalize( get_option( self::OPTION, 'classic' ) );
    }

    /** Each model keeps its own palette. Strict CSS hex values only. */
    public static function defaults( $style ) {
        $palettes = array(
            'classic' => array( 'accent' => '#185b45', 'ink' => '#18352e', 'surface' => '#ffffff', 'soft' => '#f2f7f4' ),
            'cards' => array( 'accent' => '#28528c', 'ink' => '#203148', 'surface' => '#f6faff', 'soft' => '#eaf1fc' ),
            'minimal' => array( 'accent' => '#234639', 'ink' => '#252c2a', 'surface' => '#ffffff', 'soft' => '#f3f6f4' ),
            'contrast' => array( 'accent' => '#003d88', 'ink' => '#141414', 'surface' => '#ffffff', 'soft' => '#e8f1ff' ),
        );
        return $palettes[ self::normalize( $style ) ];
    }

    private static function valid_color( $color ) {
        return is_string( $color ) && 1 === preg_match( '/^#[0-9a-f]{6}$/iD', $color );
    }

    public static function palette( $style ) {
        $style = self::normalize( $style );
        $colors = self::defaults( $style );
        $stored = get_option( self::COLORS_OPTION, array() );
        if ( ! is_array( $stored ) || ! isset( $stored[ $style ] ) || ! is_array( $stored[ $style ] ) ) { return $colors; }
        foreach ( $colors as $name => $default ) {
            if ( self::valid_color( $stored[ $style ][ $name ] ?? null ) ) {
                $colors[ $name ] = strtolower( $stored[ $style ][ $name ] );
            }
        }
        return $colors;
    }

    private static function luminance( $color ) {
        $values = array();
        foreach ( array( 1, 3, 5 ) as $offset ) {
            $part = hexdec( substr( $color, $offset, 2 ) ) / 255;
            $values[] = $part <= 0.04045 ? $part / 12.92 : pow( ( $part + 0.055 ) / 1.055, 2.4 );
        }
        return $values[0] * 0.2126 + $values[1] * 0.7152 + $values[2] * 0.0722;
    }

    public static function contrast_ratio( $first, $second ) {
        if ( ! self::valid_color( $first ) || ! self::valid_color( $second ) ) { return 0;
        }
        $a = self::luminance( $first );
        $b = self::luminance( $second );
        return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
    }

    private static function accent_text( $accent ) {
        return self::contrast_ratio( $accent, '#ffffff' ) >= self::contrast_ratio( $accent, '#000000' ) ? '#ffffff' : '#000000';
    }

    /** Every value is validated before it reaches an inline style attribute. */
    public static function variables( $style, $colors = null ) {
        $colors = is_array( $colors ) ? $colors : self::palette( $style );
        $safe = self::defaults( $style );
        foreach ( $safe as $name => $default ) {
            if ( self::valid_color( $colors[ $name ] ?? null ) ) { $safe[ $name ] = strtolower( $colors[ $name ] ); }
        }
        return '--dc-accent:' . $safe['accent'] . ';--dc-ink:' . $safe['ink'] .
            ';--dc-surface:' . $safe['surface'] . ';--dc-soft:' . $safe['soft'] .
            ';--dc-accent-text:' . self::accent_text( $safe['accent'] ) . ';';
    }

    public static function menu() {
        add_submenu_page( 'dendrila-calendar', __( 'Booking appearance', 'dendrila-calendar' ),
            __( 'Appearance', 'dendrila-calendar' ), 'manage_options', 'dendrila-calendar-appearance', array( __CLASS__, 'render_appearance' ) );
        add_submenu_page( 'dendrila-calendar', __( 'Booking setup guide', 'dendrila-calendar' ),
            __( 'Booking guide', 'dendrila-calendar' ), 'manage_options', 'dendrila-calendar-guide', array( __CLASS__, 'render_guide' ) );
    }

    public static function enqueue( $hook ) {
        if ( ! current_user_can( 'manage_options' ) || ! in_array( $hook, array( 'dendrila-calendar_page_dendrila-calendar-appearance', 'dendrila-calendar_page_dendrila-calendar-guide' ), true ) ) { return; }
        $base = dirname( __DIR__ );
        $url = plugin_dir_url( $base . '/dendrila-calendar.php' );
        if ( 'dendrila-calendar_page_dendrila-calendar-appearance' === $hook ) {
            wp_enqueue_style( 'dendrila-calendar-booking', $url . 'assets/calendar-booking.css', array(), (string) filemtime( $base . '/assets/calendar-booking.css' ) );
        }
        wp_enqueue_style( 'dendrila-calendar-appearance', $url . 'assets/calendar-appearance-admin.css', array(), (string) filemtime( $base . '/assets/calendar-appearance-admin.css' ) );
        if ( 'dendrila-calendar_page_dendrila-calendar-appearance' === $hook ) {
            wp_enqueue_script( 'dendrila-calendar-appearance', $url . 'assets/calendar-appearance-admin.js', array(), (string) filemtime( $base . '/assets/calendar-appearance-admin.js' ), true );
        }
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You cannot edit booking appearance.', 'dendrila-calendar' ) );
        }
        check_admin_referer( 'dendrila_calendar_style_save' );
        $raw = isset( $_POST['booking_style'] ) && is_string( $_POST['booking_style'] ) ? sanitize_key( wp_unslash( $_POST['booking_style'] ) ) : '';
        if ( ! array_key_exists( $raw, self::choices() ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=dendrila-calendar-appearance&style_status=invalid' ) );
            exit;
        }
        // No JavaScript fallback: do not accidentally assign a previous model's palette
        // to a different model if the radio was switched without the editor script.
        $palette_style = isset( $_POST['booking_colors_style'] ) && is_string( $_POST['booking_colors_style'] ) ? sanitize_key( wp_unslash( $_POST['booking_colors_style'] ) ) : '';
        if ( $raw === $palette_style ) {
            $submitted = isset( $_POST['booking_colors'] ) && is_array( $_POST['booking_colors'] ) ? wp_unslash( $_POST['booking_colors'] ) : array();
            $colors = array();
            foreach ( self::defaults( $raw ) as $name => $default ) {
                $value = $submitted[ $name ] ?? null;
                if ( ! self::valid_color( $value ) ) {
                    wp_safe_redirect( admin_url( 'admin.php?page=dendrila-calendar-appearance&style_status=invalid' ) );
                    exit;
                }
                $colors[ $name ] = strtolower( $value );
            }
            if ( self::contrast_ratio( $colors['ink'], $colors['surface'] ) < 4.5 ||
                self::contrast_ratio( $colors['ink'], $colors['soft'] ) < 4.5 ||
                self::contrast_ratio( $colors['accent'], $colors['surface'] ) < 3.0 ) {
                wp_safe_redirect( admin_url( 'admin.php?page=dendrila-calendar-appearance&style_status=contrast' ) );
                exit;
            }
            $stored = get_option( self::COLORS_OPTION, array() );
            if ( ! is_array( $stored ) ) { $stored = array(); }
            $stored[ $raw ] = $colors;
            update_option( self::COLORS_OPTION, $stored, false );
        }
        update_option( self::OPTION, $raw, false );
        wp_safe_redirect( admin_url( 'admin.php?page=dendrila-calendar-appearance&style_status=saved' ) );
        exit;
    }

    public static function render_appearance() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $current = self::selected();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only feedback code, not a state-changing action.
        $status = isset( $_GET['style_status'] ) && is_string( $_GET['style_status'] ) ? sanitize_key( wp_unslash( $_GET['style_status'] ) ) : '';
        echo '<div class="wrap dc-appearance-settings" data-contrast-warning="' . esc_attr__( 'The selected colors need more contrast. Adjust text, buttons or background.', 'dendrila-calendar' ) . '"><h1>' . esc_html__( 'Booking calendar appearance', 'dendrila-calendar' ) . '</h1>';
        echo '<p>' . esc_html__( 'Choose the design shown on product pages and in the booking shortcode. This does not change schedules, appointments or payments.', 'dendrila-calendar' ) . '</p>';
        if ( 'saved' === $status ) { echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Booking appearance saved.', 'dendrila-calendar' ) . '</p></div>'; }
        if ( 'invalid' === $status ) { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Choose a valid booking design.', 'dendrila-calendar' ) . '</p></div>'; }
        if ( 'contrast' === $status ) { echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Choose colors with sufficient contrast between text, background and buttons.', 'dendrila-calendar' ) . '</p></div>'; }
        echo '<form action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" method="post"><input type="hidden" name="action" value="dendrila_calendar_style_save">';
        wp_nonce_field( 'dendrila_calendar_style_save' );
        echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Booking design', 'dendrila-calendar' ) . '</legend><div class="dc-appearance-options">';
        foreach ( self::choices() as $style => $details ) {
            echo '<label class="dc-appearance-option"><span class="dc-appearance-label"><input type="radio" name="booking_style" value="' . esc_attr( $style ) . '" data-palette="' . esc_attr( wp_json_encode( self::palette( $style ) ) ) . '" data-defaults="' . esc_attr( wp_json_encode( self::defaults( $style ) ) ) . '"' . checked( $style, $current, false ) . '><strong>' . esc_html( $details[0] ) . '</strong><small>' . esc_html( $details[1] ) . '</small></span>';
            echo '<span class="dendrila-booking dendrila-booking--' . esc_attr( $style ) . ' dc-appearance-demo" style="' . esc_attr( self::variables( $style ) ) . '" aria-hidden="true">';
            echo '<span class="dc-booking-eyebrow">Dendrila Calendar</span>';
            echo '<span class="dc-appearance-demo-title">' . esc_html__( 'Choose an available time', 'dendrila-calendar' ) . '</span>';
            echo '<span class="dc-booking-slot-grid"><span class="dc-booking-slot-content">09:00</span><span class="dc-booking-slot-content dc-appearance-active">10:30</span><span class="dc-booking-slot-content">14:00</span></span></span></label>';
        }
        echo '</div></fieldset>';
        echo '<fieldset class="dc-appearance-colors"><legend>' . esc_html__( 'Customize the selected design colors', 'dendrila-calendar' ) . '</legend>';
        echo '<p class="description">' . esc_html__( 'Every design remembers its own colors. Changes appear immediately in the preview, and take effect on your site after saving.', 'dendrila-calendar' ) . '</p>';
        echo '<input type="hidden" name="booking_colors_style" data-dc-colors-style value="' . esc_attr( $current ) . '">';
        $labels = array(
            'accent' => __( 'Buttons and selected times', 'dendrila-calendar' ),
            'ink' => __( 'Text color', 'dendrila-calendar' ),
            'surface' => __( 'Calendar background', 'dendrila-calendar' ),
            'soft' => __( 'Soft highlight background', 'dendrila-calendar' ),
        );
        $current_colors = self::palette( $current );
        echo '<div class="dc-appearance-color-grid">';
        foreach ( $labels as $key => $label ) {
            echo '<label class="dc-appearance-color"><span>' . esc_html( $label ) . '</span><input type="color" name="booking_colors[' . esc_attr( $key ) . ']" data-dc-color="' . esc_attr( $key ) . '" value="' . esc_attr( $current_colors[ $key ] ) . '"><output data-dc-color-output="' . esc_attr( $key ) . '">' . esc_html( $current_colors[ $key ] ) . '</output></label>';
        }
        echo '</div><p class="dc-appearance-color-actions"><button type="button" class="button" data-dc-colors-reset>' . esc_html__( 'Restore the original colors for this design', 'dendrila-calendar' ) . '</button></p>';
        echo '<p data-dc-contrast-message aria-live="polite" class="dc-appearance-contrast-message"></p></fieldset>';
        echo '<p><button type="submit" class="button button-primary" data-dc-style-submit>' . esc_html__( 'Save design and colors', 'dendrila-calendar' ) . '</button></p></form>';
        echo '<p>' . esc_html__( 'You can override the selected design for a specific page with [dendrila_booking style="cards"].', 'dendrila-calendar' ) . '</p>';
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-guide' ) ) . '">' . esc_html__( 'Open the booking setup guide', 'dendrila-calendar' ) . '</a></p></div>';
    }

    public static function render_guide() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div class="wrap dc-booking-guide"><h1>' . esc_html__( 'Create a bookable WooCommerce service', 'dendrila-calendar' ) . '</h1>';
        echo '<p>' . esc_html__( 'Dendrila manages availability and reservations itself. A paid service is edited as a WooCommerce product for its price and payment; the linked Dendrila service is generated automatically. No other booking plugin is needed.', 'dendrila-calendar' ) . '</p>';
        if ( ! class_exists( 'WooCommerce' ) ) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Install and activate WooCommerce to sell paid appointments. Free bookings are available in Dendrila Calendar.', 'dendrila-calendar' ) . '</p></div>';
        }
        echo '<ol class="dc-guide-steps">';
        echo '<li><h2>' . esc_html__( 'Set your working hours', 'dendrila-calendar' ) . '</h2><p>' . esc_html__( 'In Dendrila Calendar > Profiles, create a person or resource, publish it, enable availability and set weekly hours and exceptions.', 'dendrila-calendar' ) . '</p><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-profiles' ) ) . '">' . esc_html__( 'Manage booking profiles', 'dendrila-calendar' ) . '</a></p></li>';
        echo '<li><h2>' . esc_html__( 'Create the WooCommerce product', 'dendrila-calendar' ) . '</h2><p>' . esc_html__( 'Use Products > Add a service. Give the simple product a name, description and regular price. You do not need a second service form.', 'dendrila-calendar' ) . '</p>';
        if ( class_exists( 'WooCommerce' ) ) {
            echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=dendrila-add-service' ) ) . '">' . esc_html__( 'Add a bookable service', 'dendrila-calendar' ) . '</a></p>';
        }
        echo '</li>';
        echo '<li><h2>' . esc_html__( 'Enable appointment booking', 'dendrila-calendar' ) . '</h2><p>' . esc_html__( 'Open the Product data box, select the Appointment booking tab, tick Use this product as an appointment service, choose a published responsible profile and set the duration.', 'dendrila-calendar' ) . '</p><p>' . esc_html__( 'Also tick Enable booking for this service, then publish or update the product. The Dendrila service is created automatically.', 'dendrila-calendar' ) . '</p></li>';
        echo '<li><h2>' . esc_html__( 'Show the calendar and receive payments', 'dendrila-calendar' ) . '</h2><p>' . esc_html__( 'The product page shows its booking calendar. Visitors select a time before continuing to WooCommerce payment. You can also embed [dendrila_booking] in a page.', 'dendrila-calendar' ) . '</p><p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'Open products', 'dendrila-calendar' ) . '</a></p></li>';
        echo '<li><h2>' . esc_html__( 'Personalize the booking calendar', 'dendrila-calendar' ) . '</h2><p>' . esc_html__( 'Choose a design and its colors in Dendrila Calendar > Appearance. Google and Outlook synchronization are optional.', 'dendrila-calendar' ) . '</p><p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=dendrila-calendar-appearance' ) ) . '">' . esc_html__( 'Choose a calendar design', 'dendrila-calendar' ) . '</a></p></li>';
        echo '</ol><h2>' . esc_html__( 'Already have a WooCommerce product?', 'dendrila-calendar' ) . '</h2>';
        echo '<p>' . esc_html__( 'Edit that product, select Simple product under Product data, then open Appointment booking and enable both the product association and the booking rules. Keep the existing product, price and orders.', 'dendrila-calendar' ) . '</p>';
        echo '<p>' . esc_html__( 'If the tab is hidden, open Screen Options, show Product data, then expand that box. If your site uses a custom product editor that hides WooCommerce product-data tabs, switch to the standard WooCommerce product editor.', 'dendrila-calendar' ) . '</p></div>';
    }
}
