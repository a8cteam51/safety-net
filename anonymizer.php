<?php
/*
 * Plugin Name: Anonymizer
 * Description: Modifies personally identifiable information, resulting in anonymized data.
 * Version: 1.0.0
 * Author: WordPress.com Special Projects
 * Author URI: https://wpspecialprojects.wordpress.com
 * Text Domain: anonymizer
 * License: GPLv3
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'ANONYMIZER_PATH', plugin_dir_path( __FILE__ ) );
define( 'ANONYMIZER_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/includes/anonymize.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/common.php';
require_once __DIR__ . '/includes/delete.php';
require_once __DIR__ . '/includes/utilities.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/includes/cli/anonymize.php';
}

add_action( 'admin_init', function(){
	if ( isset( $_GET['delete_all_users' ] ) ) {
		\Anonymizer\Delete\delete_users();
	}
});