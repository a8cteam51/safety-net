<?php

namespace Anonymizer\Admin;

use function Anonymizer\Anonymize\anonymize_data;
use function Anonymizer\Delete\delete_users;

add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_scripts' );
add_action( 'admin_menu', __NAMESPACE__ . '\create_options_menu' );
add_action( 'admin_init', __NAMESPACE__ . '\settings_init' );
add_action( 'wp_ajax_anonymizer_anonymize_users', __NAMESPACE__ . '\handle_ajax_anonymize_users' );
add_action( 'wp_ajax_anonymizer_delete_users', __NAMESPACE__ . '\handle_ajax_delete_users' );

/**
 * Enqueues the JavaScript for the settings page.
 *
 * @param string $hook_suffix The current admin page.
 *
 * @return void
 */
function enqueue_scripts( string $hook_suffix ) {
	if ( 'settings_page_anonymizer_options' !== $hook_suffix ) {
		return;
	}

	wp_enqueue_script( 'anonymizer-admin', ANONYMIZER_URL . 'js/anonymizer-admin.js', [ 'jquery' ], '1.0', true );

	wp_localize_script(
		'anonymizer-admin',
		'anonymizer_params',
		[
			'ajax_url' => admin_url( 'admin-ajax.php' ),
		]
	);
}

/**
 * Adds the options page under Settings > Anonymizer.
 *
 * @return void
 */
function create_options_menu() {
	add_options_page(
		esc_html__( 'Anonymizer - Settings', 'anonymizer' ),
		esc_html__( 'Anonymizer', 'anonymizer' ),
		'manage_options',
		'anonymizer_options',
		__NAMESPACE__ . '\render_options_html'
	);
}

/**
 * Registers the settings fields.
 *
 * @return void
 */
function settings_init() {
	// Register settings for Anonymizer
	register_setting( 'anonymizer', 'anonymizer_' );
	register_setting( 'anonymizer', 'anonymizer_dismiss_bar' );
	register_setting( 'anonymizer', 'anonymizer_display_content' );

	// Register section for the settings
	add_settings_section(
		'anonymizer_option',
		'',
		null,
		'anonymizer_options'
	);

	add_settings_field(
		'anonymizer_anonymize_users',
		esc_html__( 'Anonymize All Users', 'anonymizer' ),
		__NAMESPACE__ . '\render_field',
		'anonymizer_options',
		'anonymizer_option',
		[
			'type' => 'button',
			'id' => 'anonymizer-anonymize-users',
			'button_text' => esc_html__( 'Anonymize Users', 'anonymizer' ),
			'description' => esc_html__( 'Replaces real user data with random fake data.', 'anonymizer' ),
		]
	);

	add_settings_field(
		'anonymizer_delete_users',
		esc_html__( 'Delete All Users', 'anonymizer' ),
		__NAMESPACE__ . '\render_field',
		'anonymizer_options',
		'anonymizer_option',
		[
			'type' => 'button',
			'id' => 'anonymizer-delete-users',
			'button_text' => esc_html__( 'Delete Users', 'anonymizer' ),
			'description' => esc_html__( 'Removes all users and their data, except administrators.', 'anonymizer' ),
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
		<h1 id="anonymizer-settings-title"><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'anonymizer' );
			do_settings_sections( 'anonymizer_options' );
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
	check_the_nonce( $_POST['nonce'],'anonymizer-anonymize-users' );

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
	check_the_nonce( $_POST['nonce'], 'anonymizer-delete-users' );

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