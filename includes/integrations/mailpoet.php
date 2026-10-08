<?php
/**
 * MailPoet: deletes its subscribers and their activity and scrubs its credentials, keeping its forms, lists, emails, templates and automations
 *
 * @package SafetyNet
 */

namespace SafetyNet\Integrations\MailPoet;

use SafetyNet\Integrations\Integration;

add_filter(
	'safety_net/integrations',
	static function ( $integrations ) {
		$integrations[] = new Integration(
			slug: 'mailpoet',
			label: 'MailPoet',
			plugins: array( 'mailpoet' ),
			// MailPoet's automation storage ignores the mailpoet_db_prefix filter, so its tables always start with {$wpdb->prefix}mailpoet_.
			tables: array(
				'mailpoet_subscribers',
				'mailpoet_subscriber_segment',
				'mailpoet_subscriber_custom_field',
				'mailpoet_subscriber_tag',
				'mailpoet_subscriber_ips',
				'mailpoet_statistics_newsletters',
				'mailpoet_statistics_opens',
				'mailpoet_statistics_clicks',
				'mailpoet_statistics_bounces',
				'mailpoet_statistics_unsubscribes',
				'mailpoet_statistics_forms',
				'mailpoet_statistics_woocommerce_purchases',
				'mailpoet_user_agents',
				'mailpoet_scheduled_tasks',
				'mailpoet_scheduled_task_subscribers',
				'mailpoet_sending_queues',
				'mailpoet_stats_notifications',
				'mailpoet_newsletter_links',
				'mailpoet_automation_runs',
				'mailpoet_automation_run_subjects',
				'mailpoet_automation_run_logs',
				'mailpoet_log',
			),
			// Each step action points at an automation run deleted with the tables.
			action_scheduler_hooks: array( 'mailpoet/automation/step' ),
			// MailPoet keeps exports for up to a week; older versions wrote them directly into uploads/mailpoet.
			upload_globs: array(
				'mailpoet/MailPoet_export_*.*',
				'mailpoet/MailPoet_stats_export_*.*',
				'mailpoet/exports/MailPoet_export_*.*',
				'mailpoet/exports/MailPoet_stats_export_*.*',
			),
			scrub: scrub_settings( ... ),
			delete: unschedule_newsletters( ... ),
		);

		return $integrations;
	}
);

/**
 * Scrubs MailPoet's service keys, mail credentials and email addresses, including each email's sender, keeping each setting's structure.
 *
 * @return void
 */
function scrub_settings() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct access bypasses MailPoet; the table name comes from $wpdb.
	$table_name = $wpdb->prefix . 'mailpoet_settings';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) !== $table_name ) {
		return;
	}

	$keys_to_scrub = array(
		// MailPoet trusts a stored "valid" key state without rechecking, so states go back to a fresh install's null.
		'mta'                           => array(
			'mailpoet_api_key'       => '',
			'mailpoet_api_key_state' => null,
			'login'                  => '',
			'password'               => '',
			'api_key'                => '',
			'access_key'             => '',
			'secret_key'             => '',
		),
		'premium'                       => array(
			'premium_key'       => '',
			'premium_key_state' => null,
		),
		'captcha'                       => array(
			'recaptcha_secret_token'           => '',
			'recaptcha_invisible_secret_token' => '',
			'turnstile_secret_token'           => '',
		),
		're_captcha'                    => array( 'secret_token' => '' ),
		// MailPoet sends without wp_mail(), so without these it cannot send as the live site or notify its owner.
		'sender'                        => array( 'address' => '' ),
		'reply_to'                      => array( 'address' => '' ),
		'bounce'                        => array( 'address' => '' ),
		'stats_notifications'           => array( 'address' => '' ),
		'subscriber_email_notification' => array( 'address' => '' ),
		// The last sending error, whose message can name a subscriber.
		'mta_log'                       => array( 'error' => null ),
	);

	foreach ( $keys_to_scrub as $name => $keys ) {
		$setting = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$table_name} WHERE name = %s", $name ) ) );
		if ( ! is_array( $setting ) ) {
			continue;
		}

		$scrubbed = array_replace( $setting, array_intersect_key( $keys, $setting ) );
		// Without their secret keys, reCAPTCHA and Turnstile would reject every signup, so forms use MailPoet's own captcha.
		if ( 'captcha' === $name && in_array( $setting['type'] ?? null, array( 'recaptcha', 'recaptcha-invisible', 'turnstile' ), true ) ) {
			$scrubbed['type'] = 'built-in';
		}
		if ( $scrubbed !== $setting ) {
			$wpdb->update( $table_name, array( 'value' => maybe_serialize( $scrubbed ) ), array( 'name' => $name ) );
		}
	}

	// The Sending Service's last check of the scrubbed sender addresses; MailPoet clears it with null.
	$wpdb->update( $table_name, array( 'value' => null ), array( 'name' => 'authorized_emails_addresses_check' ) );

	// Each email has its own sender, which MailPoet uses instead of the default one.
	$table_name = $wpdb->prefix . 'mailpoet_newsletters';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
		$wpdb->query( "UPDATE {$table_name} SET sender_address = '', reply_to_address = '' WHERE sender_address != '' OR reply_to_address != ''" );
	}
	// phpcs:enable
}

/**
 * Gives the emails that were scheduled or sending the status MailPoet's own unschedule and cleanup give them.
 *
 * @return void
 */
function unschedule_newsletters() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct access bypasses MailPoet; the table name comes from $wpdb.
	// MailPoet cannot unschedule a newsletter whose sending queue is gone.
	$table_name = $wpdb->prefix . 'mailpoet_newsletters';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
		$wpdb->query( "UPDATE {$table_name} SET status = 'draft' WHERE type = 'standard' AND status IN ( 'scheduled', 'sending' )" );
		$wpdb->query( "UPDATE {$table_name} SET status = 'sent' WHERE type = 'notification_history' AND status IN ( 'scheduled', 'sending' )" );
	}
	// phpcs:enable
}
