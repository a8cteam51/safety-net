<?php

use MailPoet\API\API as MailPoetAPI;
use MailPoet\DI\ContainerWrapper;
use MailPoet\Entities\DynamicSegmentFilterData;
use MailPoet\Entities\DynamicSegmentFilterEntity;
use MailPoet\Entities\FormEntity;
use MailPoet\Entities\NewsletterEntity;
use MailPoet\Entities\NewsletterLinkEntity;
use MailPoet\Entities\NewsletterOptionEntity;
use MailPoet\Entities\NewsletterOptionFieldEntity;
use MailPoet\Entities\NewsletterPostEntity;
use MailPoet\Entities\NewsletterSegmentEntity;
use MailPoet\Entities\ScheduledTaskEntity;
use MailPoet\Entities\ScheduledTaskSubscriberEntity;
use MailPoet\Entities\SegmentEntity;
use MailPoet\Entities\SendingQueueEntity;
use MailPoet\Entities\StatisticsBounceEntity;
use MailPoet\Entities\StatisticsClickEntity;
use MailPoet\Entities\StatisticsFormEntity;
use MailPoet\Entities\StatisticsNewsletterEntity;
use MailPoet\Entities\StatisticsOpenEntity;
use MailPoet\Entities\StatisticsUnsubscribeEntity;
use MailPoet\Entities\StatisticsWooCommercePurchaseEntity;
use MailPoet\Entities\StatsNotificationEntity;
use MailPoet\Entities\SubscriberEntity;
use MailPoet\Entities\SubscriberIPEntity;
use MailPoet\Entities\UserAgentEntity;

const SN_TEST_MAILPOET_DELETED = array( 'subscribers', 'subscriber_segment', 'subscriber_custom_field', 'subscriber_tag', 'subscriber_ips', 'statistics_newsletters', 'statistics_opens', 'statistics_clicks', 'statistics_bounces', 'statistics_unsubscribes', 'statistics_forms', 'statistics_woocommerce_purchases', 'user_agents', 'scheduled_tasks', 'scheduled_task_subscribers', 'sending_queues', 'stats_notifications', 'newsletter_links', 'automation_runs', 'automation_run_subjects', 'automation_run_logs', 'log' );

// Newsletters and settings are left out: Safety Net changes some of their columns on purpose.
const SN_TEST_MAILPOET_KEPT = array( 'segments', 'dynamic_segment_filters', 'custom_fields', 'tags', 'forms', 'newsletter_templates', 'newsletter_option_fields', 'newsletter_option', 'newsletter_segment', 'newsletter_posts', 'automations', 'automation_versions', 'automation_triggers', 'feature_flags', 'user_flags', 'migrations' );

const SN_TEST_MAILPOET_PREMIUM = 'mailpoet-premium/mailpoet-premium.php';

// Every setting a scrub must reach, in the shape MailPoet's settings page saves: one serialized row per top-level key.
function sn_test_mailpoet_seed_settings(): void {
	$settings = ContainerWrapper::getInstance()->get( \MailPoet\Settings\SettingsController::class );
	// Set once the welcome wizard is done; without it every MailPoet admin page redirects to the landing page.
	$settings->set( 'version', MAILPOET_VERSION );
	$settings->set( 'mta_group', 'mailpoet' );
	$settings->set(
		'mta',
		array(
			'method'                     => 'MailPoet',
			'frequency'                  => array(
				'emails'   => 25,
				'interval' => 5,
			),
			'mailpoet_api_key'           => 'sn-mss-key-live',
			'mailpoet_api_key_state'     => array(
				'state' => 'valid',
				'code'  => 200,
				'data'  => array(
					'public_id'    => 'sn-public-id',
					'support_tier' => 'premium',
				),
			),
			'mailpoet_subscription_type' => 'MANUAL',
			'host'                       => 'smtp.client.example',
			'port'                       => '587',
			'authentication'             => '1',
			'login'                      => 'smtp-user@client.example',
			'password'                   => 'sn-smtp-password',
			'encryption'                 => 'tls',
			'region'                     => 'us-east-1',
			'access_key'                 => 'AKIASNSEEDACCESSKEY',
			'secret_key'                 => 'sn-ses-secret-key',
			'api_key'                    => 'SG.sn-sendgrid-key',
		)
	);
	$settings->set(
		'premium',
		array(
			'premium_key'       => 'sn-premium-key-live',
			'premium_key_state' => array(
				'state' => 'valid',
				'code'  => 200,
				'data'  => array( 'support_tier' => 'premium' ),
			),
		)
	);
	$settings->set(
		'sender',
		array(
			'name'    => 'Client Shop',
			'address' => 'news@client.example',
		)
	);
	$settings->set(
		'reply_to',
		array(
			'name'    => 'Client Shop',
			'address' => 'reply@client.example',
		)
	);
	$settings->set( 'bounce', array( 'address' => 'bounce@client.example' ) );
	$settings->set(
		'stats_notifications',
		array(
			'enabled'   => true,
			'automated' => true,
			'address'   => 'owner@client.example',
		)
	);
	$settings->set(
		'subscriber_email_notification',
		array(
			'enabled'   => true,
			'automated' => true,
			'address'   => 'owner@client.example',
		)
	);
	$settings->set(
		'captcha',
		array(
			'type'                             => 'recaptcha',
			'recaptcha_site_token'             => 'sn-recaptcha-site',
			'recaptcha_secret_token'           => 'sn-recaptcha-secret',
			'recaptcha_invisible_site_token'   => 'sn-invisible-site',
			'recaptcha_invisible_secret_token' => 'sn-invisible-secret',
			'turnstile_site_token'             => 'sn-turnstile-site',
			'turnstile_secret_token'           => 'sn-turnstile-secret',
		)
	);
	$settings->set(
		're_captcha',
		array(
			'enabled'      => false,
			'site_token'   => 'sn-legacy-site',
			'secret_token' => 'sn-legacy-secret',
		)
	);
	$settings->set(
		'mta_log',
		array(
			'sent'          => array(),
			'started'       => time(),
			'status'        => 'paused',
			'retry_attempt' => 1,
			'retry_at'      => time() + 120,
			'error'         => array(
				'operation'     => 'send',
				'error_message' => 'Sending to alice@example.com failed: mailbox unavailable',
			),
		)
	);
	$settings->set(
		'authorized_emails_addresses_check',
		array(
			'invalid_sender_address' => 'news@client.example',
			'invalid_senders_in_newsletters' => array(
				array(
					'newsletter_id'  => 1,
					'subject'        => 'SN seed',
					'sender_address' => 'news@client.example',
				),
			),
		)
	);
}

// Premium keeps no data of its own, so an empty plugin is enough to check that it is deactivated too.
function sn_test_mailpoet_install_premium_stub(): void {
	$dir = WP_PLUGIN_DIR . '/mailpoet-premium';
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0777, true ) ) {
		throw new RuntimeException( "Could not create $dir" );
	}
	if ( false === file_put_contents( "$dir/mailpoet-premium.php", "<?php\n/*\n * Plugin Name: MailPoet Premium (fixture)\n */\n" ) ) { // phpcs:ignore
		throw new RuntimeException( 'Could not write the MailPoet Premium stub.' );
	}
	sn_test_activate_plugins( array( SN_TEST_MAILPOET_PREMIUM ) );
}

function sn_test_seed_mailpoet(): array {
	global $wpdb;
	if ( ! class_exists( MailPoetAPI::class ) || ! defined( 'MAILPOET_INITIALIZED' ) ) {
		throw new RuntimeException( 'MailPoet is not active and initialized, so it cannot seed its own data.' );
	}
	$container = ContainerWrapper::getInstance();
	$em        = $container->get( \MailPoetVendor\Doctrine\ORM\EntityManager::class );
	$api       = MailPoetAPI::MP( 'v1' );
	$quiet     = array(
		'send_confirmation_email'      => false,
		'schedule_welcome_email'       => false,
		'skip_subscriber_notification' => true,
	);

	$list  = $api->addList(
		array(
			'name'        => 'SN seed list',
			'description' => 'Kept as an empty list',
		)
	);
	$field = $api->addSubscriberField(
		array(
			'name'   => 'SN phone',
			'type'   => 'text',
			'params' => array( 'required' => '' ),
		)
	);
	$tag   = $api->addTag( array( 'name' => 'SN seed tag' ) );

	$subscribers = array();
	foreach ( array( 'alice', 'bob', 'carol' ) as $i => $name ) {
		$added = $api->addSubscriber(
			array(
				'email'         => "$name@example.com",
				'first_name'    => ucfirst( $name ),
				'last_name'     => 'Seed',
				'subscribed_ip' => "203.0.113.1$i",
				$field['id']    => "555-010$i",
			),
			array( $list['id'] ),
			$quiet
		);
		$api->tagSubscriber( $added['id'], $tag['id'] );
		$entity = $em->find( SubscriberEntity::class, (int) $added['id'] );
		$entity->setStatus( SubscriberEntity::STATUS_SUBSCRIBED );
		$entity->setConfirmedIp( "203.0.113.1$i" );
		$subscribers[] = $entity;
	}
	$em->persist( new SubscriberIPEntity( '203.0.113.10' ) );
	$segment = $em->find( SegmentEntity::class, (int) $list['id'] );

	$dynamic = new SegmentEntity( 'SN dynamic segment', SegmentEntity::TYPE_DYNAMIC, 'Administrators' );
	$em->persist( $dynamic );
	$em->persist(
		new DynamicSegmentFilterEntity(
			$dynamic,
			new DynamicSegmentFilterData(
				DynamicSegmentFilterData::TYPE_USER_ROLE,
				'wordpressRole',
				array(
					'wordpressRole' => array( 'administrator' ),
					'operator'      => 'any',
					'connect'       => 'and',
				)
			)
		)
	);

	$form = new FormEntity( 'SN seed form' );
	$form->setBody(
		array(
			array(
				'type'   => 'text',
				'id'     => 'email',
				'name'   => 'Email',
				'params' => array(
					'label'    => 'Email',
					'required' => true,
				),
			),
			array(
				'type'   => 'submit',
				'id'     => 'submit',
				'name'   => 'Submit',
				'params' => array( 'label' => 'Subscribe' ),
			),
		)
	);
	$form->setSettings(
		array(
			'segments'        => array( (string) $list['id'] ),
			'on_success'      => 'message',
			'success_message' => 'Thanks',
		)
	);
	$em->persist( $form );
	$em->persist( new StatisticsFormEntity( $form, $subscribers[0] ) );

	$build = static function ( string $subject, string $type, string $status, ?DateTimeImmutable $sent_at, ?NewsletterEntity $parent = null ) use ( $em, $segment ): NewsletterEntity {
		$newsletter = new NewsletterEntity();
		$newsletter->setSubject( $subject );
		$newsletter->setType( $type );
		$newsletter->setStatus( $status );
		$newsletter->setSenderAddress( 'news@client.example' );
		$newsletter->setSenderName( 'Client Shop' );
		$newsletter->setReplyToAddress( 'reply@client.example' );
		$newsletter->setReplyToName( 'Client Shop' );
		$newsletter->setBody(
			array(
				'content' => array(
					'type'        => 'container',
					'orientation' => 'vertical',
					'blocks'      => array(),
				),
			)
		);
		$newsletter->setSentAt( $sent_at );
		$newsletter->setParent( $parent );
		$em->persist( $newsletter );
		$em->persist( new NewsletterSegmentEntity( $newsletter, $segment ) );
		return $newsletter;
	};
	$sent         = $build( 'SN seed newsletter (sent)', NewsletterEntity::TYPE_STANDARD, NewsletterEntity::STATUS_SENT, new DateTimeImmutable( '-1 day' ) );
	$scheduled    = $build( 'SN seed newsletter (scheduled)', NewsletterEntity::TYPE_STANDARD, NewsletterEntity::STATUS_SCHEDULED, null );
	$notification = $build( 'SN seed post notification', NewsletterEntity::TYPE_NOTIFICATION, NewsletterEntity::STATUS_ACTIVE, null );
	$history      = $build( 'SN seed post notification (sending)', NewsletterEntity::TYPE_NOTIFICATION_HISTORY, NewsletterEntity::STATUS_SENDING, null, $notification );
	$sending      = $build( 'SN seed newsletter (sending)', NewsletterEntity::TYPE_STANDARD, NewsletterEntity::STATUS_SENDING, null );

	$interval = $em->getRepository( NewsletterOptionFieldEntity::class )->findOneBy(
		array(
			'name'           => 'intervalType',
			'newsletterType' => NewsletterEntity::TYPE_NOTIFICATION,
		)
	);
	$option   = new NewsletterOptionEntity( $notification, $interval );
	$option->setValue( 'daily' );
	$em->persist( $option );
	$em->persist( new NewsletterPostEntity( $notification, (int) get_posts( array( 'numberposts' => 1, 'fields' => 'ids' ) )[0] ) );

	$queues = array();
	foreach ( array( 'sent' => $sent, 'scheduled' => $scheduled, 'history' => $history ) as $key => $newsletter ) {
		$task = new ScheduledTaskEntity();
		$task->setType( 'sending' );
		$task->setStatus( array( 'sent' => ScheduledTaskEntity::STATUS_COMPLETED, 'scheduled' => ScheduledTaskEntity::STATUS_SCHEDULED, 'history' => null )[ $key ] );
		$task->setScheduledAt( new DateTimeImmutable( 'scheduled' === $key ? '+1 day' : '-1 day' ) );
		$task->setProcessedAt( 'sent' === $key ? new DateTimeImmutable( '-1 day' ) : null );
		$em->persist( $task );
		$queue = new SendingQueueEntity();
		$queue->setTask( $task );
		$queue->setNewsletter( $newsletter );
		$queue->setNewsletterRenderedSubject( $newsletter->getSubject() );
		$queue->setNewsletterRenderedBody(
			array(
				'html' => '<p>Hi [subscriber:firstname]</p>',
				'text' => 'Hi',
			)
		);
		$queue->setCountTotal( count( $subscribers ) );
		$queue->setCountProcessed( 'sent' === $key ? count( $subscribers ) : 0 );
		$queue->setCountToProcess( 'sent' === $key ? 0 : count( $subscribers ) );
		$em->persist( $queue );
		$task->setSendingQueue( $queue );
		foreach ( $subscribers as $subscriber ) {
			$em->persist( new ScheduledTaskSubscriberEntity( $task, $subscriber, 'sent' === $key ? 1 : 0 ) );
		}
		$queues[ $key ] = $queue;
	}

	$queue = $queues['sent'];
	$agent = new UserAgentEntity( 'Mozilla/5.0 (SN seed) AppleWebKit/537.36' );
	$em->persist( $agent );
	$link = new NewsletterLinkEntity( $sent, $queue, 'https://client.example/sale', 'snseedhash' );
	$em->persist( $link );
	foreach ( $subscribers as $subscriber ) {
		$em->persist( new StatisticsNewsletterEntity( $sent, $queue, $subscriber ) );
	}
	$open = new StatisticsOpenEntity( $sent, $queue, $subscribers[0] );
	$open->setUserAgent( $agent );
	$em->persist( $open );
	$click = new StatisticsClickEntity( $sent, $queue, $subscribers[0], $link, 1 );
	$click->setUserAgent( $agent );
	$em->persist( $click );
	// The subscriber column is NOT NULL, and a failed flush closes the EntityManager for the rest of the request.
	$purchase = new StatisticsWooCommercePurchaseEntity( $sent, $queue, $click, 1234, 'EUR', 49.5, 'completed' );
	$purchase->setSubscriber( $subscribers[0] );
	$em->persist( $purchase );
	$unsubscribe = new StatisticsUnsubscribeEntity( $sent, $queue, $subscribers[1] );
	$unsubscribe->setSource( 'unsubscribe_link' );
	$unsubscribe->setReasonData( 'other', 'SN seed reason text' );
	$em->persist( $unsubscribe );
	$em->persist( new StatisticsBounceEntity( $sent, $queue, $subscribers[2] ) );

	$stats_task = new ScheduledTaskEntity();
	$stats_task->setType( 'stats_notification' );
	$stats_task->setStatus( ScheduledTaskEntity::STATUS_SCHEDULED );
	$stats_task->setScheduledAt( new DateTimeImmutable( '+1 day' ) );
	$em->persist( $stats_task );
	$em->persist( new StatsNotificationEntity( $sent, $stats_task ) );
	$em->flush();

	$automation = $container->get( \MailPoet\Automation\Engine\Builder\CreateAutomationFromTemplateController::class )->createAutomation( 'subscriber-welcome-email' );
	$trigger    = null;
	foreach ( $automation->getSteps() as $step ) {
		if ( 'trigger' === $step->getType() ) {
			$trigger = $step;
		}
	}
	$run    = new \MailPoet\Automation\Engine\Data\AutomationRun(
		$automation->getId(),
		$automation->getVersionId(),
		$trigger->getKey(),
		array( new \MailPoet\Automation\Engine\Data\Subject( 'mailpoet:subscriber', array( 'subscriber_id' => $subscribers[0]->getId() ) ) )
	);
	$run_id = $container->get( \MailPoet\Automation\Engine\Storage\AutomationRunStorage::class )->createAutomationRun( $run );
	$container->get( \MailPoet\Automation\Engine\Storage\AutomationRunLogStorage::class )->createAutomationRunLog( new \MailPoet\Automation\Engine\Data\AutomationRunLog( $run_id, $trigger->getId(), 'trigger' ) );

	as_schedule_single_action(
		time() + HOUR_IN_SECONDS,
		'mailpoet/automation/step',
		array(
			array(
				'automation_run_id' => $run_id,
				'step_id'           => $trigger->getNextSteps()[0]->getId(),
				'run_number'        => 1,
			),
		),
		'mailpoet-automation'
	);
	as_schedule_single_action( time() + HOUR_IN_SECONDS, 'sn_keep_hook', array( 'seed' => 1 ) );

	$container->get( \MailPoet\Logging\LoggerFactory::class )->getLogger( \MailPoet\Logging\LoggerFactory::TOPIC_MSS )->error( 'SN seed log entry for alice@example.com' );

	$wpdb->insert(
		$wpdb->prefix . 'mailpoet_feature_flags',
		array(
			'name'       => 'sn_seed_flag',
			'value'      => 1,
			'created_at' => current_time( 'mysql' ),
		)
	);
	$wpdb->insert(
		$wpdb->prefix . 'mailpoet_user_flags',
		array(
			'user_id'    => 1,
			'name'       => 'sn_seed_flag',
			'value'      => '1',
			'created_at' => current_time( 'mysql' ),
		)
	);

	sn_test_mailpoet_seed_settings();
	sn_test_mailpoet_install_premium_stub();
	sn_test_mailpoet_seed_export_files();

	return array(
		'list'        => (int) $list['id'],
		'form'        => $form->getId(),
		'newsletters' => array(
			'sent'         => $sent->getId(),
			'scheduled'    => $scheduled->getId(),
			'notification' => $notification->getId(),
			'history'      => $history->getId(),
			'sending'      => $sending->getId(),
		),
	);
}

function sn_test_mailpoet_upload_dir(): string {
	return wp_upload_dir( null, false )['basedir'] . '/mailpoet';
}

// Where MailPoet writes subscriber and statistics exports, now and in older versions, next to files that must stay.
function sn_test_mailpoet_seed_export_files(): void {
	$dir   = sn_test_mailpoet_upload_dir();
	$files = array(
		'exports/MailPoet_export_snseedtoken.csv'       => "email\nalice@example.com\n",
		'exports/MailPoet_stats_export_snseedtoken.csv' => "email,opened\nalice@example.com,1\n",
		'MailPoet_export_old.xlsx'                      => 'alice@example.com',
		'MailPoet_stats_export_old.csv'                 => 'alice@example.com',
		'exports/index.php'                             => "<?php\n// Silence is golden",
		'exports/.htaccess'                             => 'Deny from all',
		'exports/other.csv'                             => 'kept',
		'cache/sn-seed.txt'                             => 'kept',
	);
	foreach ( $files as $file => $contents ) {
		wp_mkdir_p( dirname( "$dir/$file" ) );
		if ( false === file_put_contents( "$dir/$file", $contents ) ) { // phpcs:ignore
			throw new RuntimeException( "Could not write $dir/$file" );
		}
	}
}

// Files directly in uploads/mailpoet and its exports folder; the cache folder only shows that subfolders are left alone.
function sn_test_mailpoet_export_files(): array {
	$dir   = sn_test_mailpoet_upload_dir();
	$files = array();
	foreach ( array( '', 'exports/', 'cache/' ) as $sub ) {
		foreach ( is_dir( $dir . '/' . $sub ) ? scandir( $dir . '/' . $sub ) : array() as $name ) {
			if ( is_file( "$dir/$sub$name" ) ) {
				$files[] = $sub . $name;
			}
		}
	}
	sort( $files );
	return $files;
}

// A failed read would look like an empty table, which is what most checks here expect.
function sn_test_mailpoet_rows( string $sql, string $output = ARRAY_A ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $sql, $output ); // phpcs:ignore
	if ( $wpdb->last_error ) {
		throw new RuntimeException( "Reading MailPoet data failed: {$wpdb->last_error} ($sql)" );
	}
	return $rows;
}

// Plain SQL, so it reads the same with MailPoet active, deactivated or never loaded.
function sn_test_mailpoet_state(): array {
	global $wpdb;
	$prefix = $wpdb->prefix . 'mailpoet_';
	$counts = array();
	foreach ( array_merge( SN_TEST_MAILPOET_DELETED, SN_TEST_MAILPOET_KEPT, array( 'newsletters', 'settings' ) ) as $table ) {
		$counts[ $table ] = sn_test_count( $prefix . $table );
	}

	// A hash of every row, so a kept table that lost or changed a row is caught without sending megabytes of templates.
	$kept = array();
	foreach ( SN_TEST_MAILPOET_KEPT as $table ) {
		$rows = array_map( 'wp_json_encode', sn_test_mailpoet_rows( "SELECT * FROM {$prefix}{$table}" ) );
		sort( $rows );
		$kept[ $table ] = count( $rows ) . ':' . md5( implode( "\n", $rows ) );
	}

	$newsletters = array();
	foreach ( sn_test_mailpoet_rows( "SELECT * FROM {$prefix}newsletters ORDER BY id" ) as $row ) {
		$newsletters[ $row['id'] ] = $row;
	}

	$settings = array();
	foreach ( sn_test_mailpoet_rows( "SELECT name, value FROM {$prefix}settings ORDER BY name", OBJECT ) as $row ) {
		$settings[ $row->name ] = maybe_unserialize( $row->value );
	}

	$hooks = array();
	foreach ( array( 'mailpoet/automation/step', 'mailpoet/cron/daemon-trigger', 'sn_keep_hook' ) as $hook ) {
		$hooks[ $hook ] = sn_test_count( $wpdb->prefix . 'actionscheduler_actions', $wpdb->prepare( 'hook = %s', $hook ) );
	}
	$hooks['step_logs'] = (int) sn_test_mailpoet_rows( "SELECT COUNT(*) AS n FROM {$wpdb->prefix}actionscheduler_logs lg INNER JOIN {$wpdb->prefix}actionscheduler_actions aa ON aa.action_id = lg.action_id WHERE aa.hook = 'mailpoet/automation/step'" )[0]['n'];

	$active = (array) get_option( 'active_plugins', array() );
	return array(
		'counts'      => $counts,
		'kept'        => $kept,
		'newsletters' => $newsletters,
		'settings'    => (object) $settings,
		'actions'     => $hooks,
		'files'       => sn_test_mailpoet_export_files(),
		'active'      => array(
			'mailpoet' => in_array( 'mailpoet/mailpoet.php', $active, true ),
			'premium'  => in_array( SN_TEST_MAILPOET_PREMIUM, $active, true ),
		),
	);
}

// Just enough of MailPoet's tables for Safety Net's SQL, at this site's prefix, for sites that never ran MailPoet.
function sn_test_seed_mailpoet_tables( string $key ): array {
	global $wpdb;
	$prefix = $wpdb->prefix . 'mailpoet_';
	foreach ( array( 'subscribers', 'statistics_opens', 'forms' ) as $table ) {
		$wpdb->query( "CREATE TABLE {$prefix}{$table} ( id INTEGER PRIMARY KEY, data TEXT )" ); // phpcs:ignore
		$wpdb->insert( $prefix . $table, array( 'data' => "$key@example.com" ) );
	}
	$wpdb->query( "CREATE TABLE {$prefix}settings ( id INTEGER PRIMARY KEY, name TEXT, value TEXT )" ); // phpcs:ignore
	$wpdb->insert(
		$prefix . 'settings',
		array(
			'name'  => 'mta',
			'value' => serialize( // phpcs:ignore
				array(
					'method'           => 'MailPoet',
					'mailpoet_api_key' => "sn-key-$key",
				)
			),
		)
	);
	return sn_test_mailpoet_tables_state();
}

function sn_test_mailpoet_tables_state(): array {
	global $wpdb;
	$prefix = $wpdb->prefix . 'mailpoet_';
	$state  = array();
	foreach ( array( 'subscribers', 'statistics_opens', 'forms' ) as $table ) {
		$state[ $table ] = sn_test_count( $prefix . $table );
	}
	$state['mta'] = maybe_unserialize( $wpdb->get_var( "SELECT value FROM {$prefix}settings WHERE name = 'mta'" ) ); // phpcs:ignore
	return $state;
}

// switch_to_blog() reads a site without loading it, so this never triggers that site's first run.
function sn_test_mailpoet_tables_on_sites( array $sites, bool $seed = false ): array {
	$state = array();
	foreach ( $sites as $key => $blog_id ) {
		switch_to_blog( $blog_id );
		$state[ $key ] = $seed ? sn_test_seed_mailpoet_tables( $key ) : sn_test_mailpoet_tables_state();
		restore_current_blog();
	}
	return $state;
}
