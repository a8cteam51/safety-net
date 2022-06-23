<?php
/*
 * Plugin Name: Safety Net - for Team 51 Development Sites
 * Description: Helps protect development sites by anonymizing user data and more!
 * Version: 1.0.0
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

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/anonymize.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/common.php';
require_once __DIR__ . '/includes/delete.php';
require_once __DIR__ . '/includes/utilities.php';
require_once __DIR__ . '/includes/stop-emails.php';
require_once __DIR__ . '/includes/deactivate-plugins.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/cli/anonymize.php';
}

add_action( 'admin_init', function(){
	if ( isset( $_GET['delete_all_users' ] ) ) {
		\SafetyNet\Delete\delete_users();
	}
});

/*
*	Deactivagte plugins and clear options as soon as Safety Net plugin is activated.
* You can find these in includes/deactivate-plugins.php
*/
function run_at_activation() {
  \SafetyNet\DeactivatePlugins\scrub_options();
  \SafetyNet\DeactivatePlugins\deactivate_plugins();
}
register_activation_hook( __FILE__, 'run_at_activation' );
