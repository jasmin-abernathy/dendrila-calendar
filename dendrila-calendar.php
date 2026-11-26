<?php
/**
 * Plugin Name: Dendrila Calendar
 * Plugin URI: https://github.com/jasmin-abernathy/dendrila-calendar
 * Description: Calendar, appointment booking and Google/Outlook synchronization for WordPress, with optional WooCommerce payments.
 * Version: 0.0.6
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Le Potager du Web
 * License: GPL-2.0-or-later
 * Text Domain: dendrila-calendar
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-dendrila-calendar-detector.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-integrations.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-sources.php';
require_once __DIR__ . '/includes/class-dendrila-calendar.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-subscriptions.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-bridge.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-discovery.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-oauth.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-two-way.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-availability.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-setup.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-native.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-woo.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-schedule.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-schedule-admin.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-booking.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-booking-form.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-woo-service.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-appearance.php';

add_action( 'plugins_loaded', array( 'Dendrila_Calendar', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Subscriptions', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Bridge', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Discovery', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_OAuth', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Availability', 'boot' ) );

register_activation_hook( __FILE__, array( 'Dendrila_Calendar_Setup', 'activate' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Setup', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Native', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Schedule_Admin', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Booking', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Booking_Form', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Woo', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Woo_Service', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Appearance', 'boot' ) );
add_action( 'dendrila_calendar_reconcile_order', array( 'Dendrila_Calendar_Booking', 'reconcile_order' ) );
