<?php

namespace SafetyNet\Utilities;

/**
 * Return an array of user IDs of site admins, or on multisite, of every site's admins and the super admins.
 *
 * @return array
 */
function get_admin_user_ids(): array {
	global $wpdb;

	$capability_keys = array( $wpdb->prefix . 'capabilities' );

	// The users table is shared by the whole network, so a run on one site must keep every site's admins.
	if ( is_multisite() ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $site_id ) {
			$capability_keys[] = $wpdb->get_blog_prefix( $site_id ) . 'capabilities';
		}
		$capability_keys = array_values( array_unique( $capability_keys ) );
	}

	$placeholders = implode( ',', array_fill( 0, count( $capability_keys ), '%s' ) );
	$admin_ids    = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT u.ID FROM $wpdb->users u INNER JOIN $wpdb->usermeta m ON m.user_id = u.ID WHERE m.meta_key IN ($placeholders) AND m.meta_value LIKE %s ORDER BY u.ID", ...array_merge( $capability_keys, array( '%administrator%' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	$super_admins = array();
	if ( is_multisite() ) {
		// get_super_admins() only covers the current network, but the users table is shared by every network.
		$super_admins = get_super_admins();
		foreach ( $wpdb->get_col( "SELECT meta_value FROM $wpdb->sitemeta WHERE meta_key = 'site_admins'" ) as $network_super_admins ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$super_admins = array_merge( $super_admins, (array) maybe_unserialize( $network_super_admins ) );
		}
		$super_admins = array_values( array_unique( array_filter( $super_admins, 'is_string' ) ) );
	}

	if ( $super_admins ) {
		// Not get_user_by(): pluggable functions aren't loaded yet when the automatic run fires.
		$placeholders = implode( ',', array_fill( 0, count( $super_admins ), '%s' ) );
		$super_ids    = $wpdb->get_results( $wpdb->prepare( "SELECT ID, user_login FROM $wpdb->users WHERE user_login IN ($placeholders)", ...$super_admins ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$ids_by_login = array_column( $super_ids, 'ID', 'user_login' );

		// Super admins first, current network's first, so get_admin_id() prefers them when a site has no administrator of its own.
		$admin_ids = array_merge( array_values( array_intersect_key( array_replace( array_flip( $super_admins ), $ids_by_login ), $ids_by_login ) ), $admin_ids );
	}

	return array_values( array_unique( array_map( 'intval', $admin_ids ) ) );
}

/**
 * The function @{wp_get_environment_type()} from WP Core will default to 'production' if the environment type is set
 * to anything other than 'staging', 'development', or 'local'. However, some hosts like Pressable and tools like
 * WPCOM Studio set an unsupported environment type via the constant `WP_ENVIRONMENT_TYPE` (in both cases, `sandbox`).
 *
 * This function tries to reconcile that.
 *
 * @return string
 */
function get_environment_type(): string {
	$current_env = wp_get_environment_type();

	if ( 'production' === $current_env ) { // Either true production or fallback production due to an unsupported environment type.
		$other_supported_envs = array( 'sandbox', 'dev', 'develop' );

		if ( function_exists( 'getenv' ) ) {
			$env = getenv( 'WP_ENVIRONMENT_TYPE' );
			if ( in_array( $env, $other_supported_envs, true ) ) {
				$current_env = $env;
			}
		}

		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && in_array( WP_ENVIRONMENT_TYPE, $other_supported_envs, true ) ) {
			$current_env = WP_ENVIRONMENT_TYPE;
		}
	}

	return $current_env;
}

/**
 * Returns true if plugin is running on production.
 *
 * @return boolean
 */
function is_production() {
	return 'production' === get_environment_type();
}

/**
 * Whether this run should change a network-wide setting on multisite, which automatic runs do once per network.
 *
 * @param string $automatic_hook The action that fires the automatic run.
 * @param string $flag           The network option that records the change was made.
 * @param string $capability     The capability needed to make the change from the Tools page.
 *
 * @return bool
 */
function should_change_network( string $automatic_hook, string $flag, string $capability ): bool {
	if ( ! is_multisite() ) {
		return false;
	}

	if ( doing_action( $automatic_hook ) ) {
		return ! get_site_option( $flag );
	}

	return ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( $capability );
}

/**
 * Reads the plugin or options denylist txt files, and returns an array for use
 *
 * @param string $denylist_type Type of denylist. Accepts 'options' or 'plugins'.
 *
 * @return array
 */
function get_denylist_array( $denylist_type ): array {
	global $wp_filesystem;

	if ( ! $wp_filesystem ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}

	$denylist_array = array();
	$filename       = 'options' === $denylist_type ? 'option_scrublist.txt' : 'plugin_denylist.txt';
	$file_path      = SAFETY_NET_PATH . '/assets/data/' . $filename;

	if ( ! $wp_filesystem->exists( $file_path ) ) {
		return $denylist_array;
	}

	$file_contents = $wp_filesystem->get_contents( $file_path );
	if ( false === $file_contents ) {
		return $denylist_array;
	}

	$rows = explode( "\n", $file_contents );

	foreach ( $rows as $row ) {
		$data = str_getcsv( $row, ',', '"', '\\' );
		foreach ( $data as $item ) {
			$denylist_array[] = trim( (string) $item );
		}
	}

	return array_filter( $denylist_array );
}

/**
 * Returns the active plugins that register a WooCommerce payment gateway, other than WooCommerce itself.
 *
 * @return string[] Plugin basenames, e.g. 'woocommerce-gateway-dummy/woocommerce-gateway-dummy.php'.
 */
function get_payment_gateway_plugins(): array {
	// Third-party gateways register on plugins_loaded, and WooCommerce caches the gateway list on first use.
	if ( ! class_exists( 'WooCommerce' ) || ! did_action( 'plugins_loaded' ) ) {
		return array();
	}

	// These gateways (and their subclasses) never contact a payment processor, so they shouldn't take the rest of their plugin down with them.
	$offline_gateway_classes = array(
		'WC_Gateway_BACS',
		'WC_Gateway_Cheque',
		'WC_Gateway_COD',
		'WC_Pre_Orders_Gateway_Pay_Later',
		'WC_Bookings_Gateway',
		'WC_Gateway_Account_Funds',
		'Kestrel\\Account_Funds\\Gateway',
		'WC_GZD_Gateway_Invoice',
		'WC_GZD_Gateway_Direct_Debit',
		'WCPOS\\WooCommercePOS\\Gateways\\Card',
		'WCPOS\\WooCommercePOS\\Gateways\\Cash',
	);

	$woocommerce_path = wp_normalize_path( dirname( WC_PLUGIN_FILE ) );

	$gateway_files = array();
	foreach ( WC()->payment_gateways->payment_gateways() as $gateway ) {
		$files = array();

		// Concrete parents count too, since a subclass can replace a processor's gateway; abstract bases may come from another plugin's bundled framework.
		for ( $class = new \ReflectionClass( $gateway ); $class; $class = $class->getParentClass() ) {
			if ( in_array( $class->getName(), $offline_gateway_classes, true ) ) {
				$files = array();
				break;
			}

			$file = $class->getFileName();
			if ( ! $file || 0 === strpos( wp_normalize_path( $file ), $woocommerce_path . '/' ) ) {
				break;
			}

			if ( ! $class->isAbstract() ) {
				$files[] = wp_normalize_path( $file );
			}
		}

		$gateway_files = array_merge( $gateway_files, $files );
	}

	$skipped_paths = array(
		$woocommerce_path,
		untrailingslashit( wp_normalize_path( SAFETY_NET_PATH ) ),
	);

	$active_plugins = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active_plugins = array_merge( $active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	$gateway_plugins = array();
	foreach ( array_unique( $active_plugins ) as $plugin ) {
		$plugin_path = realpath( WP_PLUGIN_DIR . '/' . ( '.' === dirname( $plugin ) ? $plugin : dirname( $plugin ) ) );
		if ( false === $plugin_path ) {
			continue;
		}

		$plugin_path = wp_normalize_path( $plugin_path );
		if ( in_array( $plugin_path, $skipped_paths, true ) ) {
			continue;
		}

		foreach ( $gateway_files as $file ) {
			if ( $file === $plugin_path || 0 === strpos( $file, $plugin_path . '/' ) ) {
				$gateway_plugins[] = $plugin;
				break;
			}
		}
	}

	return (array) apply_filters( 'safety_net_payment_gateway_plugins', $gateway_plugins );
}

/**
 * Renders an admin notice, if the plugin is running on production
 *
 * @filter safety_net_show_production_notice
 *
 * @return void
 */
function show_production_notice() {
	// If not production, return.
	if ( ! is_production() ) {
		return;
	}

	// Check the if the user has the capability to manage options.
	$allowed = current_user_can( 'manage_options' );
	// Filter for third-party plugins to add their own capability check.
	$allowed = apply_filters( 'safety_net_show_production_notice', $allowed );

	if ( ! $allowed ) {
		return;
	}

	// Check if the constant starts as an mu plugin.
	$is_mu = defined( 'WPMU_PLUGIN_DIR' ) && \str_starts_with( SAFETY_NET_PATH, WPMU_PLUGIN_DIR );
	?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo esc_html(
					sprintf(
						// translators: %s: Is plugin or mu-plugin.
						__( 'Safety Net is active on a production site, which restricts certain processes from running. To proceed, either remove the %s or switch the site to a staging or development environment.', 'safety-net' ),
						$is_mu ? __( 'mu-plugin', 'safety-net' ) : __( 'plugin', 'safety-net' )
					)
				);
				?>
			</p>
		</div>
		<?php
}
