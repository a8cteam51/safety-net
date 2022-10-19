<?php
/*
 * Plugin Name: Safety Net
 * Description: For Team51 Development Sites. Deletes user data and more!
 * Version: 1.0.0-beta.3
 * Author: WordPress.com Special Projects
 * Author URI: https://wpspecialprojects.wordpress.com
 * Text Domain: safety-net
 * License: GPLv3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Protect against more than one copy of the plugin being activated
if ( defined( 'SAFETY_NET_PATH' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	deactivate_plugins( plugin_basename( __FILE__ ) );

	function deactivation_admin_notice() {
		$class   = 'notice notice-error';
		$message = __( 'It looks like more than one copy of Safety Net is installed, so one has been deactivated.', 'safety-net' );
		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}
	add_action( 'admin_notices', 'deactivation_admin_notice' );

	return;
}

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
