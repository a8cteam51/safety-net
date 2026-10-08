<?php
/**
 * Jetpack CRM: deletes its contacts, companies, quotes, invoices, transactions, line items, events, logs and tags
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\JetpackCRM;

use SafetyNet\Integrations\Integration;

use const SafetyNet\Integrations\BUILT_IN_PRIORITY;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'jetpack-crm',
			label: 'Jetpack CRM',
			tables: array(
				'zbs_contacts',
				'zbs_contactmeta',
				'zbs_companies',
				'zbs_companymeta',
				'zbs_quotes',
				'zbs_quotemeta',
				'zbs_invoices',
				'zbs_invoicemeta',
				'zbs_transactions',
				'zbs_transactionmeta',
				'zbs_lineitems',
				'zbs_events',
				'zbs_eventmeta',
				'zbs_logs',
				'zbs_mail',
				'zbs_lists',
				'zbs_tags',
				'zbs_tagmeta',
				'zbs_aliases',
				'zbs_objlinks',
			),
		);

		return $integrations;
	},
	BUILT_IN_PRIORITY
);
