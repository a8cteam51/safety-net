<?php
/**
 * WPForms: deletes its form entries, which hold what visitors submitted
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\WPForms;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'wpforms',
			label: 'WPForms',
			tables: array(
				'wpforms_entries',
				'wpforms_entry_meta',
				'wpforms_entry_fields',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
