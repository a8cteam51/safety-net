<?php
/*
 * Plugin Name: Safety Net
 * Plugin URI: https://specialprojects.automattic.com/tools/safety-net/
 * Description: For Team51 Development Sites. Deletes user data and more!
 * Version: 1.5.7
 * Author: WordPress.com Special Projects
 * Author URI: https://specialprojects.automattic.com
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

// Allow access to the basic utility functions.
require_once __DIR__ . '/includes/utilities.php';

// If the site is production, bail.
if ( SafetyNet\Utilities\is_production() ) {
	// Show the production notice.
	add_action( 'admin_notices', 'SafetyNet\Utilities\show_production_notice' );
	return;
}

require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/common.php';
require_once __DIR__ . '/includes/delete.php';
require_once __DIR__ . '/includes/delete-transients.php';
require_once __DIR__ . '/includes/deactivate-plugins.php';
require_once __DIR__ . '/includes/scrub-options.php';
require_once __DIR__ . '/includes/disable-webhooks.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/classes/cli/class-safetynet-cli.php';
}

// Fire a hook now that the plugin is ready.
do_action( 'safety_net_loaded' );
