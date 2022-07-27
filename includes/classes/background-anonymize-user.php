<?php
/**
 * Background Anonymize User Class
 *
 * @package SafetyNet
 */

namespace SafetyNet;

use function SafetyNet\Anonymize\anonymize_users;

/**
 * Background Anonymize User class
 */
class Background_Anonymize_User extends \WP_Background_Process {

	/**
	 * @var string
	 */
	protected $action = 'anonymize_user';

	/**
	 * Anonymizes users in the queue.
	 *
	 * @param array $item User to anonymize
	 *
	 * @return bool|array
	 */
	protected function task( $item ) {

		$result = anonymize_users( $item['offset'] );

		// If there are no more results, return false.
		if ( ! $result ) {
			return false;
		}

		// Increase the offset.
		$item['offset'] += 500;

		return $item;
	}

	/**
	 * When all users have been anonymized, and the queue is complete, move all the users and their meta from the temp
	 * table to the real ones. Also remove the temp tables when that's done.
	 */
	protected function complete() {
		global $wpdb;

		// Have to call complete function in the parent's class.
		parent::complete();

		$wpdb->query( "INSERT INTO $wpdb->users (SELECT * FROM {$wpdb->users}_temp WHERE id NOT IN (SELECT ID FROM $wpdb->users))" );
		$wpdb->query( "DROP TABLE {$wpdb->users}_temp" );
		$wpdb->query( "INSERT INTO $wpdb->usermeta (SELECT * FROM {$wpdb->usermeta}_temp WHERE user_id NOT IN (SELECT user_id FROM $wpdb->usermeta))" );
		$wpdb->query( "DROP TABLE {$wpdb->usermeta}_temp" );
	}

}

