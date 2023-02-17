<?php
/**
 * Background Anonymize User Class
 *
 * @package SafetyNet
 */

namespace SafetyNet;

use function SafetyNet\Anonymize\anonymize_users;
use function SafetyNet\Anonymize\store_anonymized_user_data;

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

		// Store the anonymized users to the default tables.
		store_anonymized_user_data();

		// Flush the cache.
		wp_cache_flush();
	}

}

