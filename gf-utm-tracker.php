<?php
/**
 * Plugin Name:       UTM Tracker for Gravity Forms
 * Description:       Captures UTM parameters, click IDs, landing page and referrer when a visitor arrives, keeps them across pages, and saves them with every Gravity Forms entry. Values are available as merge tags in notifications and confirmations and shown on the entry screen.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Tring Web Design
 * License:           GPL-2.0-or-later
 * Text Domain:       gf-utm-tracker
 */

defined( 'ABSPATH' ) || exit;

define( 'GFUT_VERSION', '1.0.0' );
define( 'GFUT_FILE', __FILE__ );
define( 'GFUT_DIR', plugin_dir_path( __FILE__ ) );
define( 'GFUT_URL', plugin_dir_url( __FILE__ ) );

add_action( 'gform_loaded', array( 'GFUT_Bootstrap', 'load' ), 5 );

class GFUT_Bootstrap {

	public static function load() {
		if ( ! method_exists( 'GFForms', 'include_addon_framework' ) ) {
			return;
		}

		GFForms::include_addon_framework();

		require_once GFUT_DIR . 'includes/class-gfut-tracker.php';

		GFAddOn::register( 'GFUT_Tracker' );
	}
}

/**
 * Accessor for the add-on instance.
 *
 * @return GFUT_Tracker|null
 */
function gfut() {
	return class_exists( 'GFUT_Tracker' ) ? GFUT_Tracker::get_instance() : null;
}
