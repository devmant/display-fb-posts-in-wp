<?php
/**
 * Plugin Name:       FB Posts Feed
 * Description:       Automatically shows the latest posts from your Facebook Page on any WordPress page. Use the shortcode [fb_posts].
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Galiu Daugiau
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gd-fb-feed
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GDFF_VERSION', '1.0.0' );
define( 'GDFF_FILE', __FILE__ );
define( 'GDFF_DIR', plugin_dir_path( __FILE__ ) );
define( 'GDFF_URL', plugin_dir_url( __FILE__ ) );
define( 'GDFF_OPTION', 'gdff_settings' );
define( 'GDFF_CACHE_OPTION', 'gdff_cache' );
define( 'GDFF_ERROR_OPTION', 'gdff_last_error' );

require_once GDFF_DIR . 'includes/class-gdff-settings.php';
require_once GDFF_DIR . 'includes/class-gdff-api.php';
require_once GDFF_DIR . 'includes/class-gdff-feed.php';
require_once GDFF_DIR . 'includes/class-gdff-admin.php';

add_action(
	'plugins_loaded',
	function () {
		GDFF_Feed::init();
		if ( is_admin() ) {
			GDFF_Admin::init();
		}
	}
);

register_deactivation_hook( __FILE__, array( 'GDFF_Feed', 'deactivate' ) );
