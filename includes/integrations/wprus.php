<?php
/**
 * WP Remote Users Sync: scrubs the keys it signs and encrypts requests to remote sites with, and deactivates it
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\WPRUS;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'wprus',
			label: 'WP Remote Users Sync',
			plugins: array( 'wp-remote-users-sync' ),
			partial_options: array(
				'wprus' => blank_encryption_keys( ... ),
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);

/**
 * Blanks the encryption keys inside the settings and keeps the rest.
 *
 * @param array $settings The plugin's settings.
 * @return array
 */
function blank_encryption_keys( array $settings ): array {
	if ( ! array_key_exists( 'encryption', $settings ) || ! is_array( $settings['encryption'] ) ) {
		return $settings;
	}

	foreach ( array( 'aes_key', 'hmac_key' ) as $key ) {
		if ( array_key_exists( $key, $settings['encryption'] ) ) {
			$settings['encryption'][ $key ] = '';
		}
	}

	return $settings;
}
