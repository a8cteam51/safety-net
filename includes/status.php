<?php
/**
 * Status panel: what Safety Net does and does not protect on this site, at the top of Tools > Safety Net.
 *
 * @package SafetyNet
 */

namespace SafetyNet\Status;

use function SafetyNet\Admin\are_renewals_paused;
use function SafetyNet\Admin\get_active_denylisted_plugins;
use function SafetyNet\EmailLog\count_entries;
use function SafetyNet\EmailLog\is_cleanup_enabled;
use function SafetyNet\EmailLog\is_logging_enabled;
use function SafetyNet\EmailLog\page_url;
use function SafetyNet\EmailLog\table_exists;
use function SafetyNet\EmailLog\verify_table;
use function SafetyNet\Integrations\get_integrations;
use function SafetyNet\Integrations\plugin_patterns;
use function SafetyNet\Utilities\are_emails_enabled;
use function SafetyNet\Utilities\get_environment_type;
use function SafetyNet\Utilities\get_keep_config;
use function SafetyNet\Utilities\get_admin_user_ids;
use function SafetyNet\Utilities\get_payment_gateway_plugins;
use function SafetyNet\Utilities\get_send_emails_elsewhere_note;
use function SafetyNet\Utilities\is_data_deletion_disabled;
use function SafetyNet\Utilities\is_denylisted_plugin;
use function SafetyNet\Utilities\is_send_emails_switch_on;
use const SafetyNet\EmailLog\SETTINGS_SLUG;

/**
 * Returns one row per protection, in plain text: its state ('ok', 'warning' or 'danger'), the state in a few words, one line on what that means and, where it is changed, a link.
 *
 * @return array<int, array{id: string, label: string, state: string, status: string, details: string, link: ?array{url: string, text: string}}>
 */
function get_status_rows(): array {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$active = get_active_denylisted_plugins();

	return array_values(
		array_filter(
			array(
				environment_row(),
				emails_row(),
				email_log_row(),
				data_row(),
				options_row(),
				plugins_row( $active ),
				gateways_row( $active ),
				renewals_row(),
				webhooks_row(),
				api_keys_row(),
				search_engines_row(),
				transients_row(),
			)
		)
	);
}

/**
 * Builds one row of the status panel.
 *
 * @param string      $id        What the row is about, used in its class.
 * @param string      $label     What is protected.
 * @param string      $state     'ok', 'warning' or 'danger'.
 * @param string      $status    The state in a few words.
 * @param string      $details   One line on what the state means.
 * @param string|null $url       Where it is changed, if anywhere.
 * @param string      $link_text The text of the link to $url.
 *
 * @return array{id: string, label: string, state: string, status: string, details: string, link: ?array{url: string, text: string}}
 */
function row( string $id, string $label, string $state, string $status, string $details, ?string $url = null, string $link_text = '' ): array {
	return array(
		'id'      => $id,
		'label'   => $label,
		'state'   => $state,
		'status'  => $status,
		'details' => $details,
		'link'    => null === $url ? null : array(
			'url'  => $url,
			'text' => $link_text,
		),
	);
}

/**
 * Returns the URL of a tool on the Tools page.
 *
 * @param string $id The tool's element ID.
 *
 * @return string
 */
function tools_url( string $id ): string {
	return admin_url( 'tools.php?page=safety_net_options' ) . '#' . $id;
}

/**
 * Whether a table exists, without a database error when it does not.
 *
 * @param string $table The table name, with its prefix.
 *
 * @return bool
 */
function has_table( string $table ): bool {
	global $wpdb;

	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

/**
 * Returns the environment type row.
 *
 * @return array
 */
function environment_row(): array {
	return row( 'environment', __( 'Environment type', 'safety-net' ), 'ok', get_environment_type(), __( 'Safety Net runs on staging, development and local sites, and does nothing on production.', 'safety-net' ) );
}

/**
 * Returns the emails row: switched on, not blocked because Safety Net's filter is gone, wp_mail() replaced, blocked, or blocked under the rules for kept data.
 *
 * @return array
 */
function emails_row(): array {
	$label    = __( 'Emails', 'safety-net' );
	$settings = admin_url( 'admin.php?page=' . SETTINGS_SLUG );
	$link     = __( 'Email Log Settings', 'safety-net' );

	if ( are_emails_enabled() ) {
		return row( 'emails', $label, 'danger', __( 'Switched on: sent to real addresses', 'safety-net' ), __( 'Send emails is on, so every email sent through wp_mail() reaches its recipients, customers included.', 'safety-net' ), $settings, $link );
	}

	if ( false === has_filter( 'pre_wp_mail', 'SafetyNet\Admin\stop_emails' ) ) {
		return row( 'emails', $label, 'danger', __( 'Not blocked', 'safety-net' ), __( 'Something removed Safety Net\'s mail filter, so emails sent through wp_mail() reach their recipients and are not logged.', 'safety-net' ) );
	}

	$file = get_replaced_wp_mail_file();
	if ( null !== $file ) {
		/* translators: %s: the file that defines wp_mail(), relative to the WordPress folder */
		$details = sprintf( __( 'wp_mail() is defined in %s instead of WordPress. Safety Net blocks and logs its emails only if it applies the pre_wp_mail filter.', 'safety-net' ), $file );

		return row( 'emails', $label, 'warning', __( 'wp_mail() is replaced', 'safety-net' ), $details );
	}

	$elsewhere = get_send_emails_elsewhere_note();

	if ( is_data_deletion_disabled() ) {
		$details = __( 'SAFETY_NET_DELETE_DATA keeps customer data, so only password resets sent to administrators alone go out.', 'safety-net' );
		if ( is_send_emails_switch_on() ) {
			$details .= ' ' . __( 'Send emails is on but held off while data is kept.', 'safety-net' );
		} elseif ( null !== $elsewhere ) {
			$details .= ' ' . $elsewhere;
		}

		return row( 'emails', $label, 'ok', __( 'Blocked; password resets only to administrators', 'safety-net' ), $details, $settings, $link );
	}

	$details = __( 'Emails sent through wp_mail() are blocked. Plugins that send email another way are neither blocked nor logged.', 'safety-net' );
	if ( null !== $elsewhere ) {
		$details .= ' ' . $elsewhere;
	}

	return row( 'emails', $label, 'ok', __( 'Blocked, except password resets', 'safety-net' ), $details, $settings, $link );
}

/**
 * Returns the file that defines wp_mail(), relative to the WordPress folder, when it is not WordPress's own pluggable.php.
 *
 * @return string|null
 */
function get_replaced_wp_mail_file(): ?string {
	if ( ! function_exists( 'wp_mail' ) ) {
		return null;
	}

	$real = static function ( string $path ): string {
		$resolved = realpath( $path );

		return wp_normalize_path( false === $resolved ? $path : $resolved );
	};

	$file = $real( (string) ( new \ReflectionFunction( 'wp_mail' ) )->getFileName() );
	if ( $real( ABSPATH . WPINC . '/pluggable.php' ) === $file ) {
		return null;
	}

	$root = trailingslashit( $real( ABSPATH ) );

	return str_starts_with( $file, $root ) ? substr( $file, strlen( $root ) ) : $file;
}

/**
 * Returns the email log row: logging and cleanup on or off, and how many entries the log holds.
 *
 * @return array
 */
function email_log_row(): array {
	$entries = table_exists() && verify_table() ? count_entries() : 0;
	$details = array(
		sprintf(
			/* translators: %s: number of log entries */
			_n( 'The log holds %s entry.', 'The log holds %s entries.', $entries, 'safety-net' ),
			number_format_i18n( $entries )
		),
		is_cleanup_enabled() ? __( 'Entries older than 6 months are deleted once a day.', 'safety-net' ) : __( 'Old entries are not deleted.', 'safety-net' ),
	);

	if ( is_logging_enabled() ) {
		return row( 'email_log', __( 'Email log', 'safety-net' ), 'ok', __( 'On', 'safety-net' ), implode( ' ', $details ), page_url(), __( 'Email Log', 'safety-net' ) );
	}

	array_unshift( $details, __( 'New emails are not logged.', 'safety-net' ) );

	return row( 'email_log', __( 'Email log', 'safety-net' ), 'warning', __( 'Off', 'safety-net' ), implode( ' ', $details ), admin_url( 'admin.php?page=' . SETTINGS_SLUG ), __( 'Email Log Settings', 'safety-net' ) );
}

/**
 * Returns the users, orders and subscriptions row: deleted, kept by SAFETY_NET_DELETE_DATA, or not deleted yet.
 *
 * @return array
 */
function data_row(): array {
	$label  = __( 'Users, orders and subscriptions', 'safety-net' );
	$config = get_keep_config();

	if ( get_option( 'safety_net_data_deleted' ) ) {
		return row( 'data', $label, 'ok', __( 'Deleted', 'safety-net' ), __( 'Users other than administrators, WooCommerce orders and subscriptions, and the personal data some plugins keep were deleted.', 'safety-net' ) );
	}

	if ( $config['disabled'] ) {
		if ( $config['until'] ) {
			/* translators: %s: the SAFETY_NET_KEEP_UNTIL date, e.g. 2026-11-15 */
			$status = sprintf( __( 'Kept until %s', 'safety-net' ), $config['until'] );
		} else {
			$status = __( 'Kept, with no expiry date', 'safety-net' );
		}

		return row( 'data', $label, 'warning', $status, __( 'SAFETY_NET_DELETE_DATA is false, so this site keeps the live site\'s customers. Removing the constant deletes them on the next page load.', 'safety-net' ) );
	}

	return row( 'data', $label, 'warning', __( 'Not deleted yet', 'safety-net' ), __( 'They are deleted automatically once options are scrubbed and plugins deactivated, or with Delete below.', 'safety-net' ), tools_url( 'safety-net-delete-users' ), __( 'Delete', 'safety-net' ) );
}

/**
 * Returns the denylisted options row: scrubbed or not yet.
 *
 * @return array
 */
function options_row(): array {
	$label = __( 'Denylisted options', 'safety-net' );

	if ( get_option( 'safety_net_options_scrubbed' ) ) {
		return row( 'options', $label, 'ok', __( 'Scrubbed', 'safety-net' ), __( 'API keys and other secrets in denylisted options were cleared.', 'safety-net' ) );
	}

	return row( 'options', $label, 'warning', __( 'Not scrubbed yet', 'safety-net' ), __( 'API keys and other secrets copied from the live site stay until Scrub Options runs, automatically or below.', 'safety-net' ), tools_url( 'safety-net-scrub-options' ), __( 'Scrub Options', 'safety-net' ) );
}

/**
 * Returns the denylisted plugins row: deactivated, active again, or not deactivated yet.
 *
 * @param string[] $active The names of active plugins that Safety Net deactivates, keyed by plugin file.
 *
 * @return array
 */
function plugins_row( array $active ): array {
	$label = __( 'Denylisted plugins', 'safety-net' );

	if ( ! get_option( 'safety_net_plugins_deactivated' ) ) {
		$details = __( 'Plugins that send email, sync data or take payments stay active until Deactivate Plugins runs, automatically or below.', 'safety-net' );
		if ( $active ) {
			/* translators: %s: comma-separated plugin names */
			$details .= ' ' . sprintf( __( 'Active: %s.', 'safety-net' ), implode( ', ', $active ) );
		}

		return row( 'plugins', $label, 'warning', __( 'Not deactivated yet', 'safety-net' ), $details, tools_url( 'safety-net-deactivate-plugins' ), __( 'Deactivate Plugins', 'safety-net' ) );
	}

	// Until the gateway pass has run, Safety Net deactivated no plugin only for registering a gateway; the gateways row names those.
	if ( ! get_option( 'safety_net_gateway_plugins_deactivated' ) ) {
		$patterns = apply_filters( 'safety_net_denylisted_plugins', plugin_patterns() );
		foreach ( array_intersect_key( $active, array_flip( get_payment_gateway_plugins() ) ) as $file => $name ) {
			if ( ! is_denylisted_plugin( $file, $patterns ) ) {
				unset( $active[ $file ] );
			}
		}
	}

	if ( $active ) {
		/* translators: %s: comma-separated plugin names */
		$details = sprintf( __( 'Safety Net deactivated these plugins, but they are active again and may send email or sync data outside wp_mail(): %s.', 'safety-net' ), implode( ', ', $active ) );

		return active_again_row( 'plugins', $label, $active, $details );
	}

	return row( 'plugins', $label, 'ok', __( 'Deactivated', 'safety-net' ), __( 'Plugins that send email, sync data or take payments were deactivated.', 'safety-net' ) );
}

/**
 * Builds the danger row for plugins that are active again, with a link to where they are deactivated: the Plugins page, or the network admin's for network-activated ones.
 *
 * @param string   $id      What the row is about.
 * @param string   $label   What is protected.
 * @param string[] $active  The names of the plugins, keyed by plugin file.
 * @param string   $details One line on what the state means.
 *
 * @return array
 */
function active_again_row( string $id, string $label, array $active, string $details ): array {
	$status = __( 'Active again', 'safety-net' );

	if ( ! is_multisite() || ! array_intersect_key( $active, (array) get_site_option( 'active_sitewide_plugins', array() ) ) ) {
		return row( $id, $label, 'danger', $status, $details, admin_url( 'plugins.php' ), __( 'Plugins', 'safety-net' ) );
	}

	// A site's Plugins page cannot deactivate network-activated plugins, and hides them from users who cannot manage network plugins.
	if ( current_user_can( 'manage_network_plugins' ) ) {
		return row( $id, $label, 'danger', $status, $details, network_admin_url( 'plugins.php' ), __( 'Network Plugins', 'safety-net' ) );
	}

	return row( $id, $label, 'danger', $status, $details . ' ' . __( 'A network administrator must deactivate the network-activated ones.', 'safety-net' ) );
}

/**
 * Returns the payment gateway plugins row: deactivated, active again, waiting for WooCommerce, no WooCommerce, or not deactivated yet.
 *
 * @param string[] $active The names of active plugins that Safety Net deactivates, keyed by plugin file.
 *
 * @return array
 */
function gateways_row( array $active ): array {
	$label   = __( 'Payment gateway plugins', 'safety-net' );
	$active  = array_intersect_key( $active, array_flip( get_payment_gateway_plugins() ) );
	$plugins = implode( ', ', $active );

	if ( get_option( 'safety_net_gateway_plugins_deactivated' ) ) {
		if ( $active ) {
			/* translators: %s: comma-separated plugin names */
			$details = sprintf( __( 'These plugins register a WooCommerce payment gateway and are active again, so the store can take real payments: %s.', 'safety-net' ), $plugins );

			return active_again_row( 'gateways', $label, $active, $details );
		}

		return row( 'gateways', $label, 'ok', __( 'Deactivated', 'safety-net' ), __( 'Plugins that register a WooCommerce payment gateway were deactivated. WooCommerce\'s own gateways and offline ones stay.', 'safety-net' ) );
	}

	if ( ! class_exists( 'WooCommerce' ) ) {
		if ( get_option( 'safety_net_gateway_plugins_pending' ) ) {
			return row( 'gateways', $label, 'ok', __( 'Waiting for WooCommerce', 'safety-net' ), __( 'WooCommerce is not active, so there are no payment gateways yet. Plugins that register one are deactivated once it is.', 'safety-net' ) );
		}

		return row( 'gateways', $label, 'ok', __( 'No WooCommerce', 'safety-net' ), __( 'WooCommerce is not active, so there are no payment gateways to deactivate.', 'safety-net' ) );
	}

	// Only the deactivate step sets the pending flag, so without it no automatic gateway pass will come.
	if ( get_option( 'safety_net_plugins_deactivated' ) && ! get_option( 'safety_net_gateway_plugins_pending' ) ) {
		$details = __( 'Safety Net ran before it handled payment gateway plugins, so plugins that register a WooCommerce payment gateway stay active until Deactivate Plugins runs below.', 'safety-net' );
	} else {
		$details = __( 'Plugins that register a WooCommerce payment gateway stay active until Deactivate Plugins runs, automatically or below.', 'safety-net' );
	}
	if ( $active ) {
		/* translators: %s: comma-separated plugin names */
		$details .= ' ' . sprintf( __( 'Active: %s.', 'safety-net' ), $plugins );
	}

	return row( 'gateways', $label, 'warning', __( 'Not deactivated yet', 'safety-net' ), $details, tools_url( 'safety-net-deactivate-plugins' ), __( 'Deactivate Plugins', 'safety-net' ) );
}

/**
 * Whether Action Scheduler holds pending actions for the hooks the WooCommerce Subscriptions integration deletes and pauses.
 *
 * @return bool
 */
function has_pending_renewals(): bool {
	global $wpdb;

	$hooks = ( get_integrations()['woocommerce-subscriptions'] ?? null )?->action_scheduler_hooks;
	$table = $wpdb->prefix . 'actionscheduler_actions';
	if ( ! $hooks || ! has_table( $table ) ) {
		return false;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $hooks ), '%s' ) );

	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE status = 'pending' AND hook IN ({$placeholders}) LIMIT 1", ...$hooks ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
}

/**
 * Returns the WooCommerce Subscriptions renewal actions row, or null unless Action Scheduler runs them and there is something to renew: WooCommerce Subscriptions (or WooPayments' subscriptions) or pending renewal actions.
 *
 * @return array|null
 */
function renewals_row(): ?array {
	if ( ! class_exists( 'ActionScheduler', false ) || ! ( function_exists( 'wcs_get_subscriptions' ) || has_pending_renewals() ) ) {
		return null;
	}

	$label  = __( 'WooCommerce Subscriptions renewal actions', 'safety-net' );
	$toggle = tools_url( 'safety_net_pause_renewal_actions_toggle' );
	$link   = __( 'Pause renewal actions', 'safety-net' );

	if ( are_renewals_paused() ) {
		if ( is_data_deletion_disabled() ) {
			return row( 'renewals', $label, 'ok', __( 'Held paused while data is kept', 'safety-net' ), __( 'Renewal payments, payment retries and end of prepaid term actions do not run, whatever Pause renewal actions says, because SAFETY_NET_DELETE_DATA keeps customer data.', 'safety-net' ) );
		}

		return row( 'renewals', $label, 'ok', __( 'Paused', 'safety-net' ), __( 'Renewal payments, payment retries and end of prepaid term actions do not run. Other scheduled actions do.', 'safety-net' ), $toggle, $link );
	}

	if ( is_data_deletion_disabled() ) {
		return row( 'renewals', $label, 'warning', __( 'Not paused', 'safety-net' ), __( 'Renewal actions must stay paused while SAFETY_NET_DELETE_DATA keeps customer data, but the WooCommerce Subscriptions integration that pauses them is not loaded.', 'safety-net' ) );
	}

	if ( 'on' === get_option( 'safety_net_pause_renewal_actions_toggle' ) ) {
		return row( 'renewals', $label, 'warning', __( 'Not paused', 'safety-net' ), __( 'Pause renewal actions is on, but the WooCommerce Subscriptions integration that pauses them is not loaded.', 'safety-net' ) );
	}

	return row( 'renewals', $label, 'warning', __( 'Not paused', 'safety-net' ), __( 'Pause renewal actions is off, so renewal payments, payment retries and end of prepaid term actions run and can charge customers.', 'safety-net' ), $toggle, $link );
}

/**
 * Returns the WooCommerce webhooks row, or null without WooCommerce's webhooks table.
 *
 * @return array|null
 */
function webhooks_row(): ?array {
	global $wpdb;

	$table = $wpdb->prefix . 'wc_webhooks';
	if ( ! has_table( $table ) ) {
		return null;
	}

	$label = __( 'WooCommerce webhooks', 'safety-net' );

	if ( ! get_option( 'safety_net_webhooks_disabled' ) ) {
		return row( 'webhooks', $label, 'warning', __( 'Not disabled yet', 'safety-net' ), __( 'Webhooks copied from the live site send store data, such as orders and customers, to other services until Disable Webhooks runs, automatically or below.', 'safety-net' ), tools_url( 'safety-net-disable-webhooks' ), __( 'Disable Webhooks', 'safety-net' ) );
	}

	$active = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $active ) {
		/* translators: %s: number of active webhooks */
		$status = sprintf( _n( '%s active again', '%s active again', $active, 'safety-net' ), number_format_i18n( $active ) );

		return row( 'webhooks', $label, 'warning', $status, __( 'Active webhooks send store data, such as orders and customers, to other services.', 'safety-net' ), admin_url( 'admin.php?page=wc-settings&tab=advanced&section=webhooks' ), __( 'Webhooks', 'safety-net' ) );
	}

	return row( 'webhooks', $label, 'ok', __( 'Disabled', 'safety-net' ), __( 'No webhook sends store data to other services.', 'safety-net' ) );
}

/**
 * Counts the administrators who can use the REST API on this site with an application password: users in get_admin_user_ids() whose application passwords hold at least one password, on multisite only those who belong to this site or are super admins. 0 while WordPress does not offer application passwords.
 *
 * @return int
 */
function count_admins_with_application_passwords(): int {
	global $wpdb;

	$ids = get_admin_user_ids();
	if ( ! $ids || ! wp_is_application_passwords_available() ) {
		return 0;
	}

	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND user_id IN ({$placeholders})", ...array_merge( array( \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS ), $ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	$admins = array();
	foreach ( (array) $rows as $row ) {
		$passwords = maybe_unserialize( $row->meta_value );
		if ( ! is_array( $passwords ) ) {
			continue;
		}

		foreach ( $passwords as $password ) {
			if ( is_array( $password ) && isset( $password['password'] ) && is_string( $password['password'] ) && '' !== $password['password'] ) {
				$admins[ (int) $row->user_id ] = true;
				break;
			}
		}
	}

	$count = 0;
	foreach ( array_keys( $admins ) as $id ) {
		$user = get_userdata( $id );
		if ( ! $user || ! wp_is_application_passwords_available_for_user( $user ) ) {
			continue;
		}

		if ( is_multisite() && ! is_super_admin( $id ) && ! is_user_member_of_blog( $id ) ) {
			continue;
		}

		++$count;
	}

	return $count;
}

/**
 * Returns one line on how many administrators have application passwords.
 *
 * @param int  $admins      The number of administrators with application passwords.
 * @param bool $woocommerce Whether to say that WooCommerce's REST API is open to them too.
 *
 * @return string
 */
function application_passwords_note( int $admins, bool $woocommerce ): string {
	if ( $woocommerce ) {
		/* translators: %s: number of administrators */
		$note = _n( '%s administrator has application passwords that still let apps use the REST API, including WooCommerce\'s.', '%s administrators have application passwords that still let apps use the REST API, including WooCommerce\'s.', $admins, 'safety-net' );
	} else {
		/* translators: %s: number of administrators */
		$note = _n( '%s administrator has application passwords that still let apps use the REST API.', '%s administrators have application passwords that still let apps use the REST API.', $admins, 'safety-net' );
	}

	return sprintf( $note, number_format_i18n( $admins ) );
}

/**
 * Returns the WooCommerce REST API keys row, with the administrators' application passwords; without WooCommerce's API keys table, the application passwords row, or null while WordPress does not offer application passwords.
 *
 * @return array|null
 */
function api_keys_row(): ?array {
	global $wpdb;

	$table  = $wpdb->prefix . 'woocommerce_api_keys';
	$admins = count_admins_with_application_passwords();
	$users  = admin_url( 'users.php' );

	if ( ! has_table( $table ) ) {
		if ( ! wp_is_application_passwords_available() ) {
			return null;
		}

		$label = __( 'Application passwords', 'safety-net' );
		if ( ! $admins ) {
			return row( 'app_passwords', $label, 'ok', __( 'None', 'safety-net' ), __( 'No administrator has an application password.', 'safety-net' ) );
		}

		/* translators: %s: number of administrators */
		$status = sprintf( _n( '%s administrator', '%s administrators', $admins, 'safety-net' ), number_format_i18n( $admins ) );

		return row( 'app_passwords', $label, 'warning', $status, application_passwords_note( $admins, false ), $users, __( 'Users', 'safety-net' ) );
	}

	$label = __( 'WooCommerce REST API keys', 'safety-net' );
	$keys  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$note  = $admins ? ' ' . application_passwords_note( $admins, true ) : '';

	if ( ! $keys ) {
		$details = __( 'There are no WooCommerce REST API keys.', 'safety-net' );

		if ( $admins ) {
			/* translators: %s: number of administrators */
			$status = sprintf( _n( 'No keys; %s administrator has application passwords', 'No keys; %s administrators have application passwords', $admins, 'safety-net' ), number_format_i18n( $admins ) );

			return row( 'api_keys', $label, 'warning', $status, $details . $note, $users, __( 'Users', 'safety-net' ) );
		}

		if ( wp_is_application_passwords_available() ) {
			$details .= ' ' . __( 'No administrator has an application password.', 'safety-net' );
		}

		return row( 'api_keys', $label, 'ok', __( 'None', 'safety-net' ), $details );
	}

	$url  = admin_url( 'admin.php?page=wc-settings&tab=advanced&section=keys' );
	$link = __( 'REST API keys', 'safety-net' );

	// The delete and keep steps both empty the table, so any key left was added after them.
	if ( get_option( 'safety_net_data_deleted' ) || get_option( 'safety_net_data_kept' ) ) {
		/* translators: %s: number of REST API keys */
		$status = sprintf( _n( '%s key created after Safety Net ran', '%s keys created after Safety Net ran', $keys, 'safety-net' ), number_format_i18n( $keys ) );

		return row( 'api_keys', $label, 'warning', $status, __( 'Apps and 3PLs using them can read and change orders.', 'safety-net' ) . $note, $url, $link );
	}

	/* translators: %s: number of REST API keys */
	$status = sprintf( _n( '%s key, not deleted yet', '%s keys, not deleted yet', $keys, 'safety-net' ), number_format_i18n( $keys ) );

	if ( is_data_deletion_disabled() ) {
		$details = __( 'Keys copied from the live site let apps and 3PLs read and change orders until the keep step deletes them; users and orders are kept.', 'safety-net' );
	} else {
		$details = __( 'Keys copied from the live site let apps and 3PLs read and change orders until they are deleted with the users and orders.', 'safety-net' );
	}

	return row( 'api_keys', $label, 'warning', $status, $details . $note, $url, $link );
}

/**
 * Returns the search engines row: discouraged, not discouraged, or discouraged with a static robots.txt the web server serves instead of Safety Net's.
 *
 * @return array
 */
function search_engines_row(): array {
	$label = __( 'Search engines', 'safety-net' );

	if ( '0' !== (string) get_option( 'blog_public' ) || false === has_filter( 'robots_txt', 'SafetyNet\Common\disallow_all_user_agents' ) ) {
		return row( 'search_engines', $label, 'warning', __( 'Not discouraged', 'safety-net' ), __( 'Something changed the "Discourage search engines" setting or removed Safety Net\'s robots.txt filter, so search engines may index this site.', 'safety-net' ) );
	}

	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	if ( file_exists( get_home_path() . 'robots.txt' ) ) {
		return row( 'search_engines', $label, 'warning', __( 'Static robots.txt', 'safety-net' ), __( 'The web server serves the robots.txt file in the site\'s folder instead of Safety Net\'s, though pages still ask search engines not to index them.', 'safety-net' ) );
	}

	return row( 'search_engines', $label, 'ok', __( 'Discouraged', 'safety-net' ), __( 'The "Discourage search engines" setting is forced on, and robots.txt disallows every user agent.', 'safety-net' ) );
}

/**
 * Returns the transients row: deleted or not yet.
 *
 * @return array
 */
function transients_row(): array {
	$label = __( 'Transients', 'safety-net' );

	if ( get_option( 'safety_net_transients_deleted' ) ) {
		return row( 'transients', $label, 'ok', __( 'Deleted', 'safety-net' ), __( 'Transients copied from the live site, such as cached API responses, were deleted.', 'safety-net' ) );
	}

	return row( 'transients', $label, 'warning', __( 'Not deleted yet', 'safety-net' ), __( 'Transients copied from the live site, such as cached API responses, stay until Delete Transients runs, automatically or below.', 'safety-net' ), tools_url( 'safety-net-delete-transients' ), __( 'Delete Transients', 'safety-net' ) );
}

/**
 * Renders the status panel: one row per protection, its state as an icon and text, what it means and where it is changed.
 *
 * @return void
 */
function render_status_panel(): void {
	$states = array(
		'ok'      => array( 'yes-alt', __( 'OK:', 'safety-net' ) ),
		'warning' => array( 'warning', __( 'Warning:', 'safety-net' ) ),
		'danger'  => array( 'dismiss', __( 'Danger:', 'safety-net' ) ),
	);
	?>
	<div class="safety-net-status">
		<h3><?php esc_html_e( 'Status', 'safety-net' ); ?></h3>
		<table class="widefat striped safety-net-status-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Protection', 'safety-net' ); ?></th>
					<th scope="col"><?php esc_html_e( 'State', 'safety-net' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Details', 'safety-net' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( get_status_rows() as $row ) {
					list( $icon, $severity ) = $states[ $row['state'] ];
					printf(
						'<tr class="%1$s"><th scope="row">%2$s</th><td class="safety-net-status-state"><span class="dashicons dashicons-%3$s" aria-hidden="true"></span><span class="screen-reader-text">%4$s</span> %5$s</td><td class="safety-net-status-details">%6$s%7$s</td></tr>',
						esc_attr( "safety-net-status-row safety-net-status-{$row['id']} safety-net-status-{$row['state']}" ),
						esc_html( $row['label'] ),
						esc_attr( $icon ),
						esc_html( $severity ),
						esc_html( $row['status'] ),
						esc_html( $row['details'] ),
						$row['link'] ? ' <a href="' . esc_url( $row['link']['url'] ) . '">' . esc_html( $row['link']['text'] ) . '</a>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped here.
					);
				}
				?>
			</tbody>
		</table>
	</div>
	<?php
}
