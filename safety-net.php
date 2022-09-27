<?php
/*
 * Plugin Name: Safety Net
 * Description: For Team51 Development Sites. Anonymizes user data and more!
 * Version: 1.0.0-beta.3
 * Author: WordPress.com Special Projects
 * Author URI: https://wpspecialprojects.wordpress.com
 * Text Domain: safety-net
 * License: GPLv3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Only run on development environments, and protect against more than one copy of the plugin being activated
if ( 
	// Make sure Site Health has loaded
	! class_exists( 'WP_Site_Health' ) ||
	// Make sure we're not executing this twice
	defined( 'SAFETY_NET_PATH' ) ||
	// Make sure we're on a development environment
	! WP_Site_Health::is_development_environment() ) {
		error_log( 'Skipping Safety Net plugin' );
	return;
}

error_log( 'Safety Net is active' );

define( 'SAFETY_NET_PATH', plugin_dir_path( __FILE__ ) );
define( 'SAFETY_NET_URL', plugin_dir_url( __FILE__ ) );
define( 'SAFETY_NET_BASENAME', plugin_basename( __FILE__ ) );

require_once __DIR__ . '/includes/library/wp-background-processing/wp-background-processing.php';
require_once __DIR__ . '/includes/anonymize.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/common.php';
require_once __DIR__ . '/includes/delete.php';
require_once __DIR__ . '/includes/utilities.php';
require_once __DIR__ . '/includes/deactivate-plugins.php';
require_once __DIR__ . '/includes/classes/background-anonymize-customer.php';
require_once __DIR__ . '/includes/classes/background-anonymize-order.php';
require_once __DIR__ . '/includes/classes/background-anonymize-user.php';
require_once __DIR__ . '/includes/classes/class-stop-emails-phpmailer.php';
require_once __DIR__ . '/includes/classes/class-dummy.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/cli/anonymize.php';
}

// Fire a hook now that the plugin is ready.
do_action( 'safety_net_loaded' );
