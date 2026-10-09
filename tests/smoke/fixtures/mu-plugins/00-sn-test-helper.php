<?php
/* Plugin Name: Safety Net smoke-test helper */

const SN_TEST_OUT = '/out';

// Safety Net's install guard reads these at the same point in the load, before the install finishes.
$GLOBALS['sn_test_at_load'] = array(
	'installing' => wp_installing(),
	'installed'  => is_blog_installed(),
);

function sn_test_json( $data ) {
	return function_exists( 'wp_json_encode' ) ? wp_json_encode( $data ) : json_encode( $data ); // phpcs:ignore
}

// The log checks fail when this marker is missing, so they cannot pass on a log that was never written.
error_log( 'SN_TEST boot ' . ( $_SERVER['REQUEST_URI'] ?? ( defined( 'WP_CLI' ) && WP_CLI ? 'wp-cli' : '' ) ) ); // phpcs:ignore

// Core reports these from wp-includes, so the file path in the PHP notice never names the plugin that caused them.
foreach ( array( 'doing_it_wrong_run', 'deprecated_function_run', 'deprecated_argument_run', 'deprecated_hook_run', 'deprecated_file_included', 'deprecated_class_run', 'deprecated_constructor_run', 'wp_trigger_error_run' ) as $sn_test_hook ) {
	add_action(
		$sn_test_hook,
		static function ( $name ) use ( $sn_test_hook ) {
			$trace = wp_debug_backtrace_summary( null, 0, false );
			$trace = implode( ', ', array_slice( $trace, 0, 40 ) );
			// Since WP 6.4, _doing_it_wrong() and the _deprecated_*() helpers report through wp_trigger_error(); their own hook already attributed them.
			if ( 'wp_trigger_error_run' === $sn_test_hook && preg_match( '/\b_(doing_it_wrong|deprecated_\w+)\b/', $trace ) ) {
				return;
			}
			if ( false !== strpos( $trace, 'SafetyNet\\' ) || false !== strpos( $trace, '/safety-net/' ) ) {
				error_log( "SN_TEST attributed: $sn_test_hook $name $trace" ); // phpcs:ignore
			}
		},
		10,
		1
	);
}
unset( $sn_test_hook );

// Action Scheduler builds its store once per request, so the last filter to run sees the class that request used.
add_filter(
	'action_scheduler_store_class',
	static function ( $store_class ) {
		$GLOBALS['sn_test_as_store'] = $store_class;
		return $store_class;
	},
	PHP_INT_MAX
);

register_shutdown_function(
	static function () {
		$error = error_get_last();
		$fatal = $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true );
		$runs  = array();
		foreach ( array( 'safety_net_loaded', 'safety_net_scrub_options', 'safety_net_deactivate_plugins', 'safety_net_deactivate_gateway_plugins', 'safety_net_delete_data', 'safety_net_keep_data', 'safety_net_delete_transients', 'safety_net_disable_webhooks' ) as $hook ) {
			$runs[ $hook ] = function_exists( 'did_action' ) ? did_action( $hook ) : null;
		}
		$line = array(
			'time'       => microtime( true ),
			'uri'        => $_SERVER['REQUEST_URI'] ?? '', // phpcs:ignore
			'method'     => $_SERVER['REQUEST_METHOD'] ?? '', // phpcs:ignore
			'php_run'    => ! empty( $_SERVER['SN_TEST_PHP_RUN'] ),
			'wp_cli'     => defined( 'WP_CLI' ) && WP_CLI,
			'code'       => http_response_code(),
			'blog_id'    => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : null,
			'env'        => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : null,
			'installing' => function_exists( 'wp_installing' ) ? wp_installing() : null,
			'installed'  => function_exists( 'is_blog_installed' ) ? is_blog_installed() : null,
			'at_load'    => $GLOBALS['sn_test_at_load'] ?? null,
			'sn_path'    => defined( 'SAFETY_NET_PATH' ) ? SAFETY_NET_PATH : null,
			'runs'       => $runs,
			'as_store'   => $GLOBALS['sn_test_as_store'] ?? null,
			'fatal'      => $fatal ? $error : null,
		);
		@file_put_contents( SN_TEST_OUT . '/probe.jsonl', sn_test_json( $line ) . "\n", FILE_APPEND ); // phpcs:ignore
	}
);

add_filter(
	'safety_net_options_to_clear',
	static function ( $options ) {
		$options[] = 'sn_custom_secret';
		return $options;
	}
);

add_filter(
	'safety_net_denylisted_plugins',
	static function ( $plugins ) {
		$plugins[] = 'zz-extra-denied';
		return $plugins;
	}
);

add_filter(
	'safety_net_payment_gateway_plugins',
	static function ( $plugins ) {
		return array_values( array_diff( $plugins, array( 'zz-keep-gateway/zz-keep-gateway.php' ) ) );
	}
);

// Registered the way plugins add their own AI connectors, so core names or keeps their settings and the settings endpoint accepts them.
add_action(
	'wp_connectors_init',
	static function ( $registry ) {
		$registry->register(
			'sn-fixture',
			array(
				'name'           => 'SN Fixture',
				'type'           => 'ai_provider',
				'authentication' => array( 'method' => 'api_key' ),
			)
		);
		// mw-local-ai-connector registers its connector with the plugin's own option as the setting.
		$registry->register(
			'sn-fixture-custom',
			array(
				'name'           => 'SN Fixture Custom',
				'type'           => 'ai_provider',
				'authentication' => array(
					'method'       => 'api_key',
					'setting_name' => 'mwlai_actual_computer_api_key',
				),
			)
		);
		// WordPress before 7.1 rejects this method with a notice.
		if ( function_exists( 'wp_connectors_get_application_password_credentials' ) ) {
			$registry->register(
				'sn-fixture-app',
				array(
					'name'           => 'SN Fixture App',
					'type'           => 'ai_provider',
					'authentication' => array( 'method' => 'application_password' ),
				)
			);
		}
	}
);

// A site's own integrations, declared before Safety Net loads: ones it must act on, and invalid ones it must log and skip.
if ( get_option( 'sn_test_extra_integration' ) || get_option( 'sn_test_keep_integration' ) || get_option( 'sn_test_bad_integration' ) ) {
	add_filter(
		'safety_net/integrations',
		static function ( $integrations ) {
			if ( get_option( 'sn_test_extra_integration' ) ) {
				$integrations[] = new SafetyNet\Integrations\Integration(
					slug: 'sn-test-extra',
					label: 'SN test extra',
					plugins: array( 'zz-single-file' ),
					options: array( 'sn_test_extra_secret' ),
					partial_options: array( 'sn_test_extra_settings' => array( 'api_key', 'missing_key', 'mode' => 'test' ) ),
					option_values: array( 'sn_test_extra_env' => 'sandbox' ),
					delete_options: array( 'sn_test_extra_token' ),
					delete_option_prefixes: array( 'sn_test_extra_key_' => array( '_secret' ) ),
					tables: array( 'sn_test_extra' ),
					network_tables: array( 'sn_test_extra_network' ),
					post_types: array( 'sn_test_extra' ),
					comment_types: array( 'sn_test_extra' ),
					usermeta: array( 'sn_test_extra_meta%' ),
					upload_globs: array( 'sn-test-extra/export-*.csv' ),
					scrub: static function () {
						global $wpdb;
						error_log( 'SN_TEST integration phase scrub, option ' . sn_test_json( $wpdb->get_row( "SELECT option_value FROM $wpdb->options WHERE option_name = 'sn_test_extra_secret'" )->option_value ?? null ) ); // phpcs:ignore
					},
					delete: static function () {
						global $wpdb;
						error_log( 'SN_TEST integration phase delete, rows ' . $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}sn_test_extra" ) ); // phpcs:ignore
					},
					keep: static function () {
						error_log( 'SN_TEST integration phase keep' ); // phpcs:ignore
					},
					hooks: static function () {
						error_log( 'SN_TEST integration phase hooks' ); // phpcs:ignore
					},
					late: static function () {
						error_log( 'SN_TEST integration phase late' ); // phpcs:ignore
					},
				);
			}
			if ( get_option( 'sn_test_keep_integration' ) ) {
				$integrations[] = new SafetyNet\Integrations\Integration(
					slug: 'sn-test-keep',
					label: 'SN test keep',
					keep: static function () {
						error_log( 'SN_TEST integration phase keep' ); // phpcs:ignore
					},
				);
			}
			if ( get_option( 'sn_test_bad_integration' ) ) {
				$integrations[] = new SafetyNet\Integrations\Integration( slug: 'SN Test Bad', label: 'Invalid slug', options: array( 'sn_test_bad_secret' ), tables: array( 'sn_test_bad' ) );
				$integrations[] = new SafetyNet\Integrations\Integration( slug: 'sn-test-extra', label: 'Duplicate slug', options: array( 'sn_test_bad_secret' ), tables: array( 'sn_test_bad' ) );
				$integrations[] = new SafetyNet\Integrations\Integration( slug: 'sn-test-claimed', label: 'Declares an option another integration declares', options: array( 'sn_test_bad_secret', 'sn_test_extra_secret' ), tables: array( 'sn_test_bad' ) );
				$integrations[] = new SafetyNet\Integrations\Integration( slug: 'sn-test-bad-partial', label: 'Maps an option to one key instead of a list', delete_partial_options: array( 'sn_test_bad_secret' => 'api_key' ), tables: array( 'sn_test_bad' ) );
				$integrations[] = new SafetyNet\Integrations\Integration( slug: 'sn-test-ai-engine', label: 'Declares a plugin pattern the AI integration declares', plugins: array( 'ai-engine' ), tables: array( 'sn_test_bad' ) );
				$integrations[] = new SafetyNet\Integrations\Integration( slug: 'sn-test-ai-key', label: 'Declares an option the AI integration deletes by prefix', options: array( 'koneek_api_key_openai' ), tables: array( 'sn_test_bad' ) );
				$integrations[] = 'sn-test-not-an-integration';
			}
			return $integrations;
		}
	);
}

if ( get_option( 'sn_test_hide_admin' ) ) {
	add_filter( 'safety_net_hide_admin', '__return_true' );
}

if ( get_option( 'sn_test_hide_production_notice' ) ) {
	add_filter( 'safety_net_show_production_notice', '__return_false' );
}

// Core offers application passwords only over HTTPS or on a local site, and test sites are neither.
if ( get_option( 'sn_test_application_passwords' ) ) {
	add_filter( 'wp_is_application_passwords_available', '__return_true' );
}

// Hosts can set the environment type as an environment variable instead of a constant.
if ( get_option( 'sn_test_env' ) ) {
	putenv( 'WP_ENVIRONMENT_TYPE=' . get_option( 'sn_test_env' ) );
}

// Constants that Safety Net reads, set per scenario; every request is a new PHP process.
foreach ( (array) get_option( 'sn_test_constants', array() ) as $sn_test_name => $sn_test_value ) {
	if ( ! defined( $sn_test_name ) ) {
		define( $sn_test_name, $sn_test_value );
	}
}
unset( $sn_test_name, $sn_test_value );

// The README's way to stop the automatic run: drop the safety_net_loaded callbacks.
if ( get_option( 'sn_test_manual_mode' ) ) {
	add_action(
		'safety_net_loaded',
		static function () {
			foreach ( array( 'maybe_scrub_options', 'maybe_deactivate_plugins', 'maybe_delete_data' ) as $callback ) {
				remove_action( 'safety_net_loaded', 'SafetyNet\\Bootstrap\\' . $callback );
			}
		},
		0
	);
}

// Plugins can send mail on plugins_loaded, before init; the capture keeps Safety Net's verdict and sends nothing.
if ( get_option( 'sn_test_early_mail' ) ) {
	add_action(
		'plugins_loaded',
		static function () {
			$GLOBALS['sn_test_early_mail'] = array();
			$capture                       = static function ( $verdict, $atts ) {
				$GLOBALS['sn_test_early_mail'][ $atts['subject'] ] = $verdict;
				return false;
			};
			add_filter( 'pre_wp_mail', $capture, 11, 2 );
			wp_mail( 'someone@example.com', 'Early order receipt', 'body' );
			wp_mail( 'someone@example.com', 'Early Password Reset', 'body' );
			remove_filter( 'pre_wp_mail', $capture, 11 );
		}
	);
}

// One write of this step flag reports failure and stores nothing, as update_option() does when the database refuses it.
if ( get_option( 'sn_test_lost_flag' ) ) {
	add_filter(
		'pre_update_option_' . get_option( 'sn_test_lost_flag' ),
		static function ( $value, $old_value ) {
			delete_option( 'sn_test_lost_flag' );
			return $old_value;
		},
		10,
		2
	);
}

if ( get_option( 'sn_test_atomic' ) && ! function_exists( 'jetpack_is_atomic_site' ) ) {
	function jetpack_is_atomic_site() {
		return true;
	}
}

// Tests must never reach the network, and the self-updater needs scripted GitHub answers.
add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		@file_put_contents( SN_TEST_OUT . '/http.jsonl', sn_test_json( array( 'url' => $url ) ) . "\n", FILE_APPEND ); // phpcs:ignore

		$mock_file = SN_TEST_OUT . '/http-mock.json';
		$mocks     = file_exists( $mock_file ) ? (array) json_decode( (string) file_get_contents( $mock_file ), true ) : array(); // phpcs:ignore
		$mocks    += array(
			'https://api.wordpress.org/plugins/update-check/' => array( 'body' => array( 'plugins' => array(), 'translations' => array(), 'no_update' => array() ) ),
			'https://api.wordpress.org/themes/update-check/'  => array( 'body' => array( 'themes' => array(), 'translations' => array(), 'no_update' => array() ) ),
			'https://api.wordpress.org/core/version-check/'   => array( 'body' => array( 'offers' => array(), 'translations' => array() ) ),
		);

		foreach ( $mocks as $prefix => $mock ) {
			if ( 0 === strpos( preg_replace( '#^http://#', 'https://', $url ), $prefix ) ) {
				$body = $mock['body'] ?? '';
				return array(
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => is_string( $body ) ? $body : sn_test_json( $body ),
					'response' => array(
						'code'    => (int) ( $mock['code'] ?? 200 ),
						'message' => 'Mocked',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}

		return new WP_Error( 'sn_test_offline', 'Outgoing HTTP is blocked in Safety Net tests: ' . $url );
	},
	1,
	3
);
