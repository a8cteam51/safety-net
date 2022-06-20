<?php

namespace SafetyNet\Admin;

use function SafetyNet\Anonymize\anonymize_data;
use function SafetyNet\Delete\delete_users;

add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_scripts' );
add_action( 'admin_menu', __NAMESPACE__ . '\create_options_menu' );
add_action( 'admin_init', __NAMESPACE__ . '\settings_init' );
add_action( 'wp_ajax_safety_net_anonymize_users', __NAMESPACE__ . '\handle_ajax_anonymize_users' );
add_action( 'wp_ajax_safety_net_delete_users', __NAMESPACE__ . '\handle_ajax_delete_users' );

/**
 * Enqueues the JavaScript for the settings page.
 *
 * @param string $hook_suffix The current admin page.
 *
 * @return void
 */
function enqueue_scripts( string $hook_suffix ) {
	if ( 'settings_page_safety_net_options' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_script( 'safety-net-admin', SAFETY_NET_URL . 'js/safety-net-admin.js', [ 'jquery' ], '1.0', true );

	wp_localize_script(
		'safety-net-admin',
		'safety_net_params',
		[
			'ajax_url' => admin_url( 'admin-ajax.php' ),
		]
	);
}

/**
 * Adds the options page under Settings > Safety Net.
 *
 * @return void
 */
function create_options_menu() {
	add_options_page(
		esc_html__( 'Safety Net - Settings', 'safety-net' ),
		esc_html__( 'Safety Net', 'safety-net' ),
		'manage_options',
		'safety_net_options',
		__NAMESPACE__ . '\render_options_html'
	);
}

/**
 * Registers the settings fields.
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

	add_settings_field(
		'safety_net_anonymize_users',
		esc_html__( 'Anonymize All Users', 'safety-net' ),
		__NAMESPACE__ . '\render_field',
		'safety_net_options',
		'safety_net_option',
		[
			'type' => 'button',
			'id' => 'safety-net-anonymize-users',
			'button_text' => esc_html__( 'Anonymize Users', 'safety-net' ),
			'description' => esc_html__( 'Replaces all non-admin user data with random fake data.', 'safety-net' ),
		]
	);

	add_settings_field(
		'safety_net_delete_users',
		esc_html__( 'Delete All Users', 'safety-net' ),
		__NAMESPACE__ . '\render_field',
		'safety_net_options',
		'safety_net_option',
		[
			'type' => 'button',
			'id' => 'safety-net-delete-users',
			'button_text' => esc_html__( 'Delete Users', 'safety-net' ),
			'description' => esc_html__( 'Deletes all non-admin users.', 'safety-net' ),
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
		<button type="button" id="<?php echo esc_attr( $args['id'] ); ?>" data-nonce="<?php echo wp_create_nonce( $args['id'] ); ?>">
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
		<form action="options.php" method="post">
			<?php
			settings_fields( 'safety-net' );
			do_settings_sections( 'safety_net_options' );
			?>
		</form>
	</div>
	<?php
}

/**
 * Handles the AJAX request for anonymizing all users.
 *
 * @return void
 */
function handle_ajax_anonymize_users() {
	// Permissions and security checks.
	check_the_permissions();
	check_the_nonce( $_POST['nonce'],'safety-net-anonymize-users' );

	// Checks passed. Anonymize the data.
	anonymize_data();

	// Send the AJAX response.
	echo json_encode(
		[
			'success' => true,
			'message' => esc_html__( 'Users have been successfully anonymized!' ),
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
	// Permissions and security checks.
	check_the_permissions();
	check_the_nonce( $_POST['nonce'], 'safety-net-delete-users' );

	// Checks passed. Delete the users.
	delete_users();

	echo json_encode(
		[
			'success' => true,
			'message' => esc_html__( 'Users have been successfully deleted!' ),
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
