<?php

namespace SafetyNet\Admin;

use function SafetyNet\Anonymize\anonymize_data;
use function SafetyNet\DeactivatePlugins\scrub_options;
use function SafetyNet\DeactivatePlugins\deactivate_plugins;
use function SafetyNet\Delete\delete_users_and_orders;
use function SafetyNet\Utilities\is_production;

add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_scripts' );
add_action( 'admin_menu', __NAMESPACE__ . '\create_options_menu' );
add_action( 'admin_init', __NAMESPACE__ . '\settings_init' );
add_action( 'wp_ajax_safety_net_anonymize_users', __NAMESPACE__ . '\handle_ajax_anonymize_users' );
add_action( 'wp_ajax_safety_net_scrub_options', __NAMESPACE__ . '\handle_ajax_scrub_options' );
add_action( 'wp_ajax_safety_net_deactivate_plugins', __NAMESPACE__ . '\handle_ajax_deactivate_plugins' );
add_action( 'wp_ajax_safety_net_delete_users', __NAMESPACE__ . '\handle_ajax_delete_users' );
add_action( 'init', __NAMESPACE__ . '\pause_scheduled_actions', 10 );
add_action( 'admin_notices', __NAMESPACE__ . '\show_warning' );
add_filter( 'plugin_action_links_' . SAFETY_NET_BASENAME, __NAMESPACE__ . '\add_action_links' );

/**
 * Enqueues the JavaScript for the tools page.
 *
 * @param string $hook_suffix The current admin page.
 *
 * @return void
 */
function enqueue_scripts( string $hook_suffix ) {
	if ( 'tools_page_safety_net_options' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_script( 'safety-net-admin', SAFETY_NET_URL . 'assets/js/safety-net-admin.js', [ 'jquery' ], '1.0', true );

	wp_localize_script(
		'safety-net-admin',
		'safety_net_params',
		[
			'ajax_url' => admin_url( 'admin-ajax.php' ),
		]
	);

	wp_enqueue_style( 'safety-net-admin-style', SAFETY_NET_URL . 'assets/css/admin.css', array(), '0.0' );
}

/**
 * Adds the options page under Tools > Safety Net.
 *
 * @return void
 */
function create_options_menu() {
	add_submenu_page(
		'tools.php',
		esc_html__( 'Safety Net', 'safety-net' ),
		esc_html__( 'Safety Net', 'safety-net' ),
		'manage_options',
		'safety_net_options',
		__NAMESPACE__ . '\render_options_html'
	);
}

/**
 * Registers the fields on the Tools page.
 *
 * @return void
 */
function settings_init() {
	// Register settings for Safety Net
	register_setting( 'safety-net', 'safety_net_' );
	register_setting( 'safety-net', 'safety_net_dismiss_bar' );
	register_setting( 'safety-net', 'safety_net_display_content' );

	// Register section for the settings
	add_settings_section(
		'safety_net_option',
		'',
		null,
		'safety_net_options'
	);

	// Register section for the settings
	add_settings_section(
		'safety_net_option',
		'',
		null,
		'safety_net_advanced_options'
	);

	add_settings_field(
		'safety_net_scrub_options',
		esc_html__( '1. Scrub Options', 'safety-net' ),
		__NAMESPACE__ . '\render_field',
		'safety_net_options',
		'safety_net_option',
		[
			'type' => 'button',
			'id' => 'safety-net-scrub-options',
			'button_text' => esc_html__( 'Scrub Options', 'safety-net' ),
			'description' => esc_html__( 'Clears specific denylisted options, such as API keys, which could cause problems on a development site.', 'safety-net' ),
		]
	);

	add_settings_field(
		'safety_net_deactivate_plugins',
		esc_html__( '2. Deactivate Plugins', 'safety-net' ),
		__NAMESPACE__ . '\render_field',
		'safety_net_options',
		'safety_net_option',
		[
			'type' => 'button',
			'id' => 'safety-net-deactivate-plugins',
			'button_text' => esc_html__( 'Deactivate Plugins', 'safety-net' ),
			'description' => esc_html__( 'Deactivates a handful of denylisted plugins. Also, runs through installed Woo payment gateways and deactivates them (deactivates the actual plugin, not from the checkout settings).', 'safety-net' ),
		]
	);

	add_settings_field(
		'safety_net_anonymize_users',
		esc_html__( 'Anonymize User Data', 'safety-net' ),
		__NAMESPACE__ . '\render_field',
		'safety_net_advanced_options',
		'safety_net_option',
		[
			'type' => 'button',
			'id' => 'safety-net-anonymize-users',
			'button_text' => esc_html__( 'Anonymize', 'safety-net' ),
			'description' => esc_html__( 'Replaces all non-admin user data with random fake data. This anonymizes users, Woo orders and Woo subscriptions. Will also disconnect Woo subscriptions from their payment method.', 'safety-net' ),
		]
	);

	add_settings_field(
		'safety_net_delete_users',
		esc_html__( 'Delete All Users, Orders, and Subscriptions', 'safety-net' ),
		__NAMESPACE__ . '\render_field',
		'safety_net_advanced_options',
		'safety_net_option',
		[
			'type' => 'button',
			'id' => 'safety-net-delete-users',
			'button_text' => esc_html__( 'Delete', 'safety-net' ),
			'description' => esc_html__( 'Deletes all non-admin users, as well as WooCommerce orders and subscriptions.', 'safety-net' ),
		]
	);
}

/**
 * Renders the HTML for the settings.
 *
 * @param array $args Arguments passed to the fields.
 *
 * @return void
 */
function render_field( array $args = [] ) {
	if ( ! isset( $args['type'] ) ) {
		return;
	} ?>

	<?php if ( 'button' === $args['type'] ) : ?>
		<button type="button" id="<?php echo esc_attr( $args['id'] ); ?>" class="button button-large" data-nonce="<?php echo wp_create_nonce( $args['id'] ); ?>">
			<?php echo esc_html( $args['button_text' ] ) ?>
		</button>
	<?php endif; ?>

	<?php if ( isset( $args['description' ] ) ) : ?>
		<p class="description" id="tagline-description"><?php echo esc_html( $args['description' ] ); ?></p>
	<?php endif; ?>
	<?php
}

/**
 * Renders the HTML for the options page.
 *
 * @return void
 */
function render_options_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1 id="safety-net-settings-title"><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p>This plugin is intended for use on Team51 Development sites, to scrub sensitive API keys, delete user data, and deactivate sensitive plugins. The first time it is activated on a non-production site, it will automatically scrub, deactivate, and delete. If you need to run these functions again, you can use the tools below.</p>
		<p>It is a work in progress. Read more about it or create issues/suggestions in the <a href="https://github.com/a8cteam51/safety-net">Safety Net repository</a>.</p>
		<hr/>
		<?php if ( is_production() ) { ?>
			<p class="info"><strong>It appears that you are are viewing this page on a production site.</strong><br>
			For Safety Net to run - and to access the tools on this page - the environment type needs to be set as staging, development, or local. <a href="https://github.com/a8cteam51/safety-net/#plugin-not-running">More info in the README</a>.</p>
		<?php } else { ?>
		<h3>Tools</h3>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'safety-net' );
			do_settings_sections( 'safety_net_options' ); ?>
			<h4>Run both of the above, <em>then</em> choose one of the below.</h4>
			<?php
			do_settings_sections( 'safety_net_advanced_options' );
			?>
		</form>
	</div>
	<div class="loading-overlay"></div>
	<?php }
}

/**
 * Handles the AJAX request for anonymizing all users.
 *
 * @return void
 */
function handle_ajax_anonymize_users() {

	// If we're not on staging, development, or a local environment, die with a warning.
	if ( is_production() ) {
		// Send an AJAX warning.
		echo json_encode(
			[
				'warning' => true,
				'message' => esc_html__( 'You can not run these tools on a production site. Please set the environment type correctly.' ),
			]
		);
		die();
	}

	// Permissions and security checks.
	check_the_permissions();
	check_the_nonce( $_POST['nonce'],'safety-net-anonymize-users' );

	// Checks passed. Anonymize the data.
	anonymize_data();

	// Send the AJAX response.
	echo json_encode(
		[
			'success' => true,
			'message' => esc_html__( 'Users have been scheduled to be anonymized in the background.' ),
		]
	);

	die();
}

/**
 * Handles the AJAX request for scrubbing options.
 *
 * @return void
 */
function handle_ajax_scrub_options() {

	// If we're not on staging, development, or a local environment, die with a warning.
	if ( is_production() ) {
		// Send an AJAX warning.
		echo json_encode(
			[
				'warning' => true,
				'message' => esc_html__( 'You can not run these tools on a production site. Please set the environment type correctly.' ),
			]
		);
		die();
	}

	// Permissions and security checks.
	check_the_permissions();
	check_the_nonce( $_POST['nonce'],'safety-net-scrub-options' );

	// Checks passed. Scrub the options.
	scrub_options();

	// Send the AJAX response.
	echo json_encode(
		[
			'success' => true,
			'message' => esc_html__( 'Options have been scrubbed.' ),
		]
	);

	die();
}

/**
 * Handles the AJAX request for deactivating plugins.
 *
 * @return void
 */
function handle_ajax_deactivate_plugins() {

	// If we're not on staging, development, or a local environment, die with a warning.
	if ( is_production() ) {
		// Send an AJAX warning.
		echo json_encode(
			[
				'warning' => true,
				'message' => esc_html__( 'You can not run these tools on a production site. Please set the environment type correctly.' ),
			]
		);
		die();
	}

	// Permissions and security checks.
	check_the_permissions();
	check_the_nonce( $_POST['nonce'],'safety-net-deactivate-plugins' );

	// Checks passed. Scrub the options.
	deactivate_plugins();

	// Send the AJAX response.
	echo json_encode(
		[
			'success' => true,
			'message' => esc_html__( 'Plugins have been deactivated.' ),
		]
	);

	die();
}

/**
 * Handles the AJAX request for deleting all users.
 *
 * @return void
 */
function handle_ajax_delete_users() {

	// If we're not on staging, development, or a local environment, die with a warning.
	if ( is_production() ) {
		// Send an AJAX warning.
		echo json_encode(
			[
				'warning' => true,
				'message' => esc_html__( 'You can not run these tools on a production site. Please set the environment type correctly.' ),
			]
		);
		die();
	}

	// Permissions and security checks.
	check_the_permissions();
	check_the_nonce( $_POST['nonce'], 'safety-net-delete-users' );

	// Checks passed. Delete the users.
	delete_users_and_orders();

	echo json_encode(
		[
			'success' => true,
			'message' => esc_html__( 'Users, orders, and subscriptions have been successfully deleted!' ),
		]
	);

	die();
}

/**
 * Checks if the user has the correct permissions, and sends the AJAX response if they don't.
 *
 * @return void
 */
function check_the_permissions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		echo json_encode(
			[
				'success' => false,
				'message' => esc_html__( 'You do not have permission to do that.' ),
			]
		);

		die();
	}
}

/**
 * Checks if the nonce passed is correct, and sends the AJAX response if it doesn't.
 *
 * @param string $nonce  The nonce to check.
 * @param string $action The action the nonce was created from.
 *
 * @return void
 */
function check_the_nonce( string $nonce, $action ) {
	if ( ! wp_verify_nonce( $nonce, $action ) ) {
		echo json_encode(
			[
				'success' => false,
				'message' => esc_html__( 'Security check failed. Refresh the page and try again.' ),
			]
		);

		die();
	}
}

/**
 * Adds the action link on plugins page
 *
 * @return array
 */

function add_action_links( $actions ) {
	$links = array(
		'<a href="' . admin_url( 'tools.php?page=safety_net_options' ) . '">Tools</a>',
	);

	return array_merge( $actions, $links );
}

/**
 * Pause WooCommerce Subscriptions renewal and failed payment retry scheduled actions
 *
 */

function pause_scheduled_actions() {
	if ( class_exists( '\ActionScheduler' ) ) {
		add_action( 'action_scheduler_pre_init', function() {
				require_once __DIR__ . '/classes/class-actionscheduler-custom-dbstore.php';
				add_filter( 'action_scheduler_store_class', function( $class ) {
					return 'ActionScheduler_Custom_DBStore';
				}, 101, 1 );
		});
	}
}

/**
 * Display Warning that Safety Net is activated.
 *
 */
function show_warning() {
	// If we're not on staging, development, or a local environment, return.
	if ( is_production() ) {
		return;
	}
	echo "\n<div class='notice notice-info'><p>";
	echo '<strong>';
		esc_html_e( 'Safety Net Activated', 'safety-net' );
	echo ': ';
	echo '</strong>';
	esc_html_e( 'The Safety Net plugin is currently active, which will prevent any emails from being sent, and prevents WooCommerce Subscriptions renewal actions from running.  ', 'safety-net' );
	esc_html_e( 'To send emails or enable renewals, deactivate the Safety Net plugin.  ', 'safety-net' );
	echo 'This site\'s environment type is set to "' . wp_get_environment_type() . '".';
	echo '</p></div>';
}
