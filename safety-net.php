<?php
/*
 * Plugin Name: Safety Net
 * Description: For Team51 Development Sites. Anonymizes user data and more!
 * Version: 1.0.0-beta.1
 * Author: WordPress.com Special Projects
 * Author URI: https://wpspecialprojects.wordpress.com
 * Text Domain: safety-net
 * License: GPLv3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'SAFETY_NET_PATH', plugin_dir_path( __FILE__ ) );
define( 'SAFETY_NET_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/library/wp-background-processing/wp-background-processing.php';
require_once __DIR__ . '/includes/anonymize.php';
require_once __DIR__ . '/includes/admin.php';
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

add_action('plugins_loaded', function() {
	new \SafetyNet\Background_Anonymize_User();
	new \SafetyNet\Background_Anonymize_Order();
	new \SafetyNet\Background_Anonymize_Customer();
});


/**
 * Adds the action link on plugins page
 *
 * @return void
 */

function add_action_links( $actions ) {
	$mylinks = array(
		'<a href="' . admin_url( 'tools.php?page=safety_net_options' ) . '">Tools</a>',
	);
	$actions = array_merge( $actions, $mylinks );
	return $actions;
}
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'add_action_links' );
