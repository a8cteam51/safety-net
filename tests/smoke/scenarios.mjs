// Each scenario is tests/smoke/scenarios/<name>.test.mjs and boots its own WordPress.
export const SCENARIOS = [
	{ name: 'staging-plugin', group: 'single-site', covers: 'Regular plugin on staging without WooCommerce: first load, every scrub step, tools page, AJAX (including refused requests), REST, robots, emails, filters, self-update offer' },
	{ name: 'staging-mu', group: 'single-site', covers: 'mu-plugin on a development site: first load, asset URLs from mu-plugins, AJAX, REST' },
	{ name: 'production', group: 'single-site', needs: [ 'wp-cli' ], covers: 'Production: nothing runs or changes, notice, endpoints absent, GitHub self-updater, WP-CLI commands absent' },
	{ name: 'production-mu-no-env', group: 'single-site', covers: 'mu-plugin with WP_ENVIRONMENT_TYPE undefined behaves as production, then "sandbox" set as an environment variable runs everything' },
	{ name: 'duplicate-copy', group: 'single-site', covers: 'mu-plugin and regular plugin both active, "sandbox" environment' },
	{ name: 'install-flow', group: 'single-site', covers: 'WordPress installs with Safety Net already in mu-plugins (#187)' },
	{ name: 'no-administrator', group: 'single-site', covers: 'A site without administrators keeps its users' },
	{ name: 'woocommerce-hpos', group: 'woocommerce', needs: [ 'woocommerce' ], covers: 'WooCommerce with HPOS: orders, customers, tokens, webhooks, subscriptions, AutomateWoo, gateway plugins, renewal pause, store and admin pages' },
	{ name: 'woocommerce-mu-legacy', group: 'woocommerce', needs: [ 'woocommerce' ], covers: 'mu-plugin with posts-based order storage' },
	{ name: 'woocommerce-production', group: 'woocommerce', needs: [ 'woocommerce' ], covers: 'Production WooCommerce store is left alone' },
	{ name: 'woocommerce-activated-later', group: 'woocommerce', needs: [ 'woocommerce' ], covers: 'Gateway pass waits until WooCommerce is activated' },
	{ name: 'woocommerce-preprocessed', group: 'woocommerce', needs: [ 'woocommerce' ], covers: 'Sites processed before the gateway pass existed are not changed retroactively' },
	{ name: 'wp-cli', group: 'woocommerce', needs: [ 'woocommerce', 'wp-cli' ], covers: 'wp safety-net commands' },
	{ name: 'multisite-main-first', group: 'multisite', covers: 'Network, main site first: per-site runs, surviving admins and super admins, network-wide changes made once and only re-applied by super admins, subsite tools, new sites' },
	{ name: 'multisite-subsite-first', group: 'multisite', covers: 'Network, subsite first: main site untouched, network admin email, network-wide BuddyPress tables, fallback and exact-role post author' },
	{ name: 'multisite-network-woocommerce', group: 'multisite', needs: [ 'woocommerce', 'wp-cli' ], covers: 'Network-activated Safety Net and WooCommerce: per-site order deletion, network-active plugins and the network admin email changed once per network, WP-CLI on a subsite' },
];

export const GROUPS = [ ...new Set( SCENARIOS.map( ( scenario ) => scenario.group ) ) ];
