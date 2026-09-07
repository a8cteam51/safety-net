<?php

namespace SafetyNet\ScrubOptions;

use WC_Data_Store;
use WC_Webhook;

use function SafetyNet\Utilities\get_denylist_array;

add_action( 'safety_net_scrub_options', __NAMESPACE__ . '\scrub_options' );

/*
* Clear options such as API keys so that plugins won't talk to 3rd parties
*/
function scrub_options() {
	global $wpdb;

	safety_net_update_option_direct( 'admin_email', 'safetynet@scrubbedthis.option' );

	$options_to_clear = array_merge( get_denylist_array( 'options' ), get_ai_options_to_clear() );
	$options_to_clear = apply_filters( 'safety_net_options_to_clear', $options_to_clear );

	// Check if it’s an Atomic site either via the Jetpack function or URL.
	$is_atomic_site = false;
	if ( function_exists( 'jetpack_is_atomic_site' ) && jetpack_is_atomic_site() ) {
		$is_atomic_site = true;
	} elseif ( str_ends_with( home_url(), 'wpcomstaging.com' ) ) {
		$is_atomic_site = true;
	}

	// Leave these options intact on Atomic, so that we don't disconnect Jetpack
	if ( $is_atomic_site ) {
		$unset_wpcom_options = array( 'jetpack_private_options', 'jetpack_secrets' );
		$options_to_clear    = array_diff( $options_to_clear, $unset_wpcom_options );
	}

	foreach ( $options_to_clear as $option ) {
		if ( is_ai_credential_option( $option ) ) {
			scrub_ai_credential_option( $option );
			continue;
		}

		$option_value = get_option( $option );
		if ( $option_value ) {

			update_option( $option . '_sn_backup', $option_value );

			if ( 'woocommerce_ppcp-gateway_settings' === $option || 'woocommerce-ppcp-settings' === $option || 'woocommerce_stripe_settings' === $option ) {
				// we need to more selectively wipe parts of these options, because the respective plugins will fatal if the entire options are blank
				$keys_to_scrub = array( 'enabled', 'client_secret_production', 'client_id_production', 'client_secret', 'client_id', 'merchant_id', 'merchant_email', 'merchant_id_production', 'merchant_email_production', 'publishable_key', 'secret_key', 'webhook_secret' );
				$option_array  = $option_value;
				foreach ( $keys_to_scrub as $key ) {
					if ( array_key_exists( $key, $option_array ) ) {
						$option_array[ $key ] = '';
					}
				}
				safety_net_update_option_direct( $option, $option_array );
			} elseif ( 'jetpack_active_modules' === $option ) {
				// Clear some Jetpack options to disable specific modules.
				$modules_to_disable = array( 'enhanced-distribution', 'publicize', 'subscriptions' );
				$modules_array      = array_filter(
					$option_value,
					function( $v ) use ( $modules_to_disable ) {
						return ! in_array( $v, $modules_to_disable, true );
					},
				);

				safety_net_update_option_direct( $option, $modules_array );
			} elseif ( 'wprus' === $option ) {
				// Clear some WP Remote Users Sync options to disable only keys needed for remote connections and keep the remaining settings intact.
				$keys_to_scrub = array(
					'encryption' => array(
						'aes_key',
						'hmac_key',
					),
				);
				$option_array  = $option_value;
				foreach ( $keys_to_scrub as $index => $keys ) {
					if ( array_key_exists( $index, $option_array ) ) {
						foreach ( $keys as $key ) {
							if ( array_key_exists( $key, $option_array[ $index ] ) ) {
								$option_array[ $index ][ $key ] = '';
							}
						}
					}
				}
				safety_net_update_option_direct( $option, $option_array );
			} elseif ( 'pmpro_gateway' === $option ) {
				safety_net_update_option_direct( $option, '' );
			} elseif ( 'pmpro_gateway_environment' === $option ) {
				safety_net_update_option_direct( $option, 'sandbox' );
			} elseif ( 'pmpro_last_known_url' === $option ) {
				safety_net_update_option_direct( $option, 'https://safetynetscrubbedthis.com' );
				if ( function_exists( 'pmpro_clear_crons' ) ) {
					pmpro_clear_crons();
				}
			} else if ( '_wp_convertkit_settings' === $option ) {
				$option_array  = $option_value;

				$keys_to_scrub = array( 'access_token', 'refresh_token', 'token_expires', 'api_key', 'api_secret' );
				foreach ( $keys_to_scrub as $key ) {
					if ( array_key_exists( $key, $option_array ) ) {
						$option_array[ $key ] = '';
					}
				}

				safety_net_update_option_direct( $option, $option_array );
			} elseif ( 'apple_news_settings' === $option ) {
				$keys_to_scrub = array( 'api_key', 'api_secret', 'api_channel', 'apple_news_admin_email' );

				$option_array = $option_value;
				foreach ( $keys_to_scrub as $key ) {
					if ( array_key_exists( $key, $option_array ) ) {
						$option_array[ $key ] = '';
					}
				}

				$option_array['api_autosync'] = 'no';
				$option_array['api_autosync_update']  = 'no';
				$option_array['api_autosync_trash'] = 'no';
				$option_array['api_autosync_delete']  = 'no';
				$option_array['api_autosync_unpublish'] = 'no';

				$option_array['apple_news_enable_debugging'] = 'no';

				safety_net_update_option_direct( $option, $option_array );
			} elseif ( 'default_pingback_flag' === $option ) {
				// Delete all _pingme postmeta to prevent pingbacks from being sent.
				$wpdb->delete(
					$wpdb->postmeta,
					array( 'meta_key' => '_pingme' )
				);

				safety_net_update_option_direct( $option, '' );
			} else {
				// Some plugins don't like it when options are deleted, so we will save their value as either an empty string or array, depending on which it already is.
				if ( is_array( get_option( $option ) ) ) {
					$empty_array = array();
					safety_net_update_option_direct( $option, $empty_array );
				} else {
					safety_net_update_option_direct( $option, '' );
				}
			}
		}
	}

	// Disable all Woo Webhooks
	if ( class_exists( 'WooCommerce' ) ) {
		$data_store = WC_Data_Store::load( 'webhook' );
		$webhooks   = $data_store->search_webhooks();

		if ( ! empty( $webhooks ) ) {
			foreach ( $webhooks as $webhook_id ) {
				$webhook = new WC_Webhook( $webhook_id );
				$webhook->set_status( 'disabled' );
				$webhook->save();
			}
		}
	}

	// Disable AutomateWoo workflows, clear the queue, and set scheduled actions to "done"
	$wpdb->query( "UPDATE $wpdb->posts SET post_status = 'aw-disabled' WHERE post_type = 'aw_workflow' AND post_status = 'publish'" );

	$table_name = $wpdb->prefix . 'automatewoo_queue';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "DELETE FROM {$wpdb->prefix}automatewoo_queue" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}automatewoo_queue_meta" );
	}

	$table_name = $wpdb->prefix . 'actionscheduler_actions';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
		$wpdb->query( "UPDATE {$wpdb->prefix}actionscheduler_actions SET status = 'done' WHERE status = 'pending' AND hook LIKE '%automatewoo%'" );
	}

	update_option( 'safety_net_options_scrubbed', true );

	// Clear object cache since the updates happen directly in the database.
	wp_cache_flush();
}

/**
 * Determines whether an option stores a WordPress AI provider credential.
 *
 * @param mixed $option Option name.
 * @return bool Whether the option stores an AI credential.
 */
function is_ai_credential_option( $option ): bool {
	if ( ! is_string( $option ) ) {
		return false;
	}

	return 'wp_ai_client_provider_credentials' === $option
		|| ( str_starts_with( $option, 'connectors_ai_' ) && str_ends_with( $option, '_api_key' ) )
		|| ( str_starts_with( $option, '_secret_ai/' ) && str_ends_with( $option, '_api_key' ) );
}

/**
 * Scrubs an AI provider credential without reading or backing up its value.
 *
 * @param string $option Option name.
 * @return void
 */
function scrub_ai_credential_option( string $option ): void {
	global $wpdb;

	$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- AI credentials must not be retained in backup options.
		$wpdb->options,
		array( 'option_name' => $option . '_sn_backup' )
	);

	if ( str_starts_with( $option, '_secret_ai/' ) ) {
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct access intentionally bypasses option hooks.
			$wpdb->options,
			array( 'option_name' => $option )
		);
		return;
	}

	if ( 'wp_ai_client_provider_credentials' === $option ) {
		safety_net_update_option_direct( $option, array() );
		return;
	}

	safety_net_update_option_direct( $option, '' );
}

/**
 * Gets database-backed WordPress AI connector credentials that should be scrubbed.
 *
 * WordPress 7.0+ stores AI provider API keys in options matching the
 * `connectors_ai_{provider}_api_key` pattern. The AI plugin can instead store
 * those keys as encrypted `_secret_ai/{provider}_api_key` options, and older
 * versions used one combined credentials option.
 *
 * @return array AI credential option names.
 */
function get_ai_options_to_clear(): array {
	global $wpdb;

	$plain_key_pattern     = $wpdb->esc_like( 'connectors_ai_' ) . '%';
	$encrypted_key_pattern = $wpdb->esc_like( '_secret_ai/' ) . '%';

	$stored_options = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scrubbing requires the stored option names, not their values.
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$plain_key_pattern,
			$encrypted_key_pattern
		)
	);

	$ai_options    = array();
	$backup_suffix = '_sn_backup';

	foreach ( $stored_options as $option ) {
		if ( str_ends_with( $option, $backup_suffix ) ) {
			$option = substr( $option, 0, -strlen( $backup_suffix ) );
		}

		if ( is_ai_credential_option( $option ) ) {
			$ai_options[] = $option;
		}
	}

	$ai_options[] = 'wp_ai_client_provider_credentials';

	return array_values( array_unique( $ai_options ) );
}

/**
 * Updates options directly in the database to prevent notifications from being sent.
 *
 * @param string $option_name The name of the option to update.
 * @param mixed $option_value The value to set the option to.
 *
 * @return void
 */
function safety_net_update_option_direct( $option_name, $option_value ) {
	global $wpdb;

	if ( is_array( $option_value ) ) {
		$option_value = serialize( $option_value );
	}

	$wpdb->update(
		$wpdb->options,
		array( 'option_value' => $option_value ),
		array( 'option_name' => $option_name ),
	);
}
