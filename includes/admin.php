<?php

namespace SafetyNet\Admin;

use function SafetyNet\Anonymize\anonymize_data;
use function SafetyNet\DeactivatePlugins\scrub_options;
use function SafetyNet\DeactivatePlugins\deactivate_plugins;
use function SafetyNet\Delete\delete_users_and_orders;
use function SafetyNet\Utilities\is_production;

add_filter( 'init', __NAMESPACE__ . '\add_admin_hooks' );

/**
 * Registers all the admin hooks.
 *
 * @return void
 */
function add_admin_hooks(){

	if ( true !== apply_filters( 'safety_net_hide_admin', false ) ) {
		// Skip the admin page and options if the `safety_net_hide_admin` filter returns true.
		add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_scripts' );
		add_action( 'admin_menu', __NAMESPACE__ . '\create_options_menu' );
		add_action( 'admin_init', __NAMESPACE__ . '\settings_init' );
		add_action( 'wp_ajax_safety_net_anonymize_users', __NAMESPACE__ . '\handle_ajax_anonymize_users' );
		add_action( 'wp_ajax_safety_net_scrub_options', __NAMESPACE__ . '\handle_ajax_scrub_options' );
		add_action( 'wp_ajax_safety_net_deactivate_plugins', __NAMESPACE__ . '\handle_ajax_deactivate_plugins' );
		add_action( 'wp_ajax_safety_net_delete_users', __NAMESPACE__ . '\handle_ajax_delete_users' );
		add_filter( 'plugin_action_links_' . SAFETY_NET_BASENAME, __NAMESPACE__ . '\add_action_links' );
	}
	add_action( 'action_scheduler_pre_init', __NAMESPACE__ . '\pause_renewal_actions' );
	add_action( 'admin_notices', __NAMESPACE__ . '\show_warning' );
	add_filter( 'wp_mail', __NAMESPACE__ . '\stop_emails', 10, 1 );
}

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
	register_setting( 'safety-net', 'safety_net_pause_renewal_actions_toggle' );

	// Register section for the settings
	add_settings_section(
		'safety_net_option',
		'',
		null,
		'safety_net_options'
	);

	add_settings_field(
		'safety_net_scrub_options',
		esc_html__( 'Scrub Options', 'safety-net' ),
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
		esc_html__( 'Deactivate Plugins', 'safety-net' ),
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
		'safety_net_delete_users',
		esc_html__( 'Delete All Users, Orders, and Subscriptions', 'safety-net' ),
		__NAMESPACE__ . '\render_field',
		'safety_net_options',
		'safety_net_option',
		[
			'type' => 'button',
			'id' => 'safety-net-delete-users',
			'button_text' => esc_html__( 'Delete', 'safety-net' ),
			'description' => esc_html__( 'Deletes all non-admin users, as well as WooCommerce orders and subscriptions.', 'safety-net' ),
		]
	);

	add_settings_field(
		'safety_net_pause_renewal_actions_toggle',
		esc_html__( 'Pause renewal actions', 'safety-net' ),
		__NAMESPACE__ . '\render_field',
		'safety_net_options',
		'safety_net_option',
		array(
			'type'      => 'checkbox',
			'name'      => 'safety_net_pause_renewal_actions_toggle',
			'class'     => 'safety-net-pause-renewal-actions-toggle',
			'label_for' => 'safety_net_pause_renewal_actions_toggle',
		)
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

	<?php if ( 'checkbox' === $args['type'] ) : 
		$checked = '';
		if ( 'on' === get_option( $args['name'] ) ) {
			$checked = ' checked="checked" '; 
		} ?>
		<input id="<?php echo esc_attr( $args['name'] ) ?>" class="<?php echo esc_attr( $args['class'] ) ?>" name="<?php echo esc_attr( $args['name'] ) ?>" type="checkbox" <?php echo $checked ?> />
	<?php endif; ?>

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
		<p>Read about Safety Net or create issues/suggestions in the <a href="https://github.com/a8cteam51/safety-net">Safety Net repository</a>.</p>
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
			<input name="Submit" type="submit" class="button button-primary safety-net-save" value="<?php esc_attr_e( 'Save Changes' ); ?>" />
		</form>
	</div>
	<div class="loading-overlay"></div>
	<?php }
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
function pause_renewal_actions() {
	if ( 'on' === get_option( 'safety_net_pause_renewal_actions_toggle' ) ) {
		require_once __DIR__ . '/classes/class-actionscheduler-custom-dbstore.php';
		add_filter( 'action_scheduler_store_class', function( $class ) {
			return 'SafetyNet\ActionScheduler_Custom_DBStore';
		}, 101, 1 );
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
	esc_html_e( 'The Safety Net plugin is currently active. ' . PHP_EOL, 'safety-net' );
	if ( 'on' === get_option( 'safety_net_pause_renewal_actions_toggle' ) ) {
		esc_html_e( 'WooCommerce Subscriptions scheduled actions are currently paused. ' . PHP_EOL, 'safety-net' );
	}
	echo 'This site\'s environment type is set to "' . wp_get_environment_type() . '".';
	echo '</p></div>';
}

/**
 * Stop all emails except password resets
 *
 */
function stop_emails( $return, $args ) {
	if ( ! strstr( $args['subject'], 'Password Reset' ) ) {
		error_log( "Email blocked: " . $args['subject'] ); // phpcs:ignore -- Logging is okay here.
		// returning false says "short-circuit the wp_mail() function and indicate we did not send the email"
		$return = false;
	} else {
		error_log( "Email sent: " . $args['subject'] ); // phpcs:ignore -- Logging is okay here.
		// returning null says "don't short circuit the wp_mail function"
		$return = null;
	}

	return $return;
}
