<?php
/**
 * Plugin Name: Dendrila Calendar
 * Plugin URI: https://github.com/jasmin-abernathy/dendrila-calendar
 * Description: Unified private calendar for WordPress events and bookings from optional, read-only integrations.
 * Version: 0.0.1
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
require_once __DIR__ . '/includes/class-dendrila-calendar-setup.php';

add_action( 'plugins_loaded', array( 'Dendrila_Calendar', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Subscriptions', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Bridge', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Discovery', 'boot' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_OAuth', 'boot' ) );

register_activation_hook( __FILE__, array( 'Dendrila_Calendar_Setup', 'activate' ) );
add_action( 'plugins_loaded', array( 'Dendrila_Calendar_Setup', 'boot' ) );
