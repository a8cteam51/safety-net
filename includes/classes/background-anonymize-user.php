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

}

