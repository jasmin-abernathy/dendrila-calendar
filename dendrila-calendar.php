<?php
/**
 * Plugin Name: Dendrila Calendar
 * Description: Private unified calendar for WordPress events and compatible booking plugins.
 * Version: 0.2.0-dev
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Le Verger du Numérique
 * License: GPL-2.0-or-later
 * Text Domain: dendrila-calendar
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-dendrila-calendar-integrations.php';
require_once __DIR__ . '/includes/class-dendrila-calendar-sources.php';
require_once __DIR__ . '/includes/class-dendrila-calendar.php';

add_action( 'plugins_loaded', array( 'Dendrila_Calendar', 'boot' ) );
