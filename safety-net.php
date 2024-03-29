<?php
/*
 * Plugin Name: Safety Net
 * Description: For Team51 Development Sites. Deletes user data and more!
 * Version: 1.4.15
 * Author: WordPress.com Special Projects
 * Author URI: https://wpspecialprojects.wordpress.com
 * Text Domain: safety-net
 * License: GPLv3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( defined( 'SAFETY_NET_PATH' ) ) {
	return; // Return if another copy of the plugin is activated
}

define( 'SAFETY_NET_PATH', plugin_dir_path( __FILE__ ) );
define( 'SAFETY_NET_URL', plugin_dir_url( __FILE__ ) );
define( 'SAFETY_NET_BASENAME', plugin_basename( __FILE__ ) );

require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/common.php';
require_once __DIR__ . '/includes/delete.php';
require_once __DIR__ . '/includes/utilities.php';
require_once __DIR__ . '/includes/deactivate-plugins.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/classes/cli/class-safetynet-cli.php';
}

// Fire a hook now that the plugin is ready.
do_action( 'safety_net_loaded' );
