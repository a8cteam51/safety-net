import assert from 'node:assert/strict';

export const HPOS_TABLES = [ 'wc_orders', 'wc_order_addresses', 'wc_orders_meta', 'wc_order_operational_data' ];

export const ALWAYS_SEEDED = [ 'woocommerce_order_items', 'woocommerce_order_itemmeta', 'order_notes', 'wc_customer_lookup', 'wc_order_stats', 'wc_order_product_lookup', 'woocommerce_api_keys', 'woocommerce_payment_tokens', 'woocommerce_payment_tokenmeta', 'wc_webhooks', 'subscription_posts', 'subscription_meta', 'membership_posts', 'membership_meta', 'renewal_actions', 'renewal_action_logs', 'keep_actions', 'woocommerce_log', 'woocommerce_sessions', 'woocommerce_downloadable_product_permissions', 'wc_download_log' ];

// Fails early when a table came out empty, since its deletion could then not be tested.
export async function seedWooCommerceSite( site, { hpos = true, path = '/', customer } = {} ) {
	const base = customer ? null : await site.php( 'return sn_test_seed_base();', { label: 'seeding the site', path } );
	const configured = await site.php( `return sn_test_configure_woocommerce( ${ hpos } );`, { label: 'choosing the order storage', path } );
	assert.equal( configured.orders_table_exists, true );
	const woo = await site.php( `return sn_test_seed_woocommerce( ${ customer ?? base.users.customer1 } );`, { label: 'seeding WooCommerce data', path } );
	assert.equal( woo.hpos, hpos, 'WooCommerce did not use the requested order storage' );
	const seeded = [ ...ALWAYS_SEEDED, ...( hpos ? [ ...HPOS_TABLES, 'placeholder_posts' ] : [ 'shop_order_posts', 'shop_order_postmeta', 'refund_posts', 'refund_postmeta' ] ) ];
	const empty = seeded.filter( ( table ) => ! ( woo.counts[ table ] > 0 ) );
	assert.deepEqual( empty, [], `Seeding left these empty, so their deletion could not be tested: ${ empty.join( ', ' ) }` );
	return { base, woo };
}
