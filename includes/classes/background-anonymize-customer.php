<?php
/**
 * Background Anonymize Customer Class
 *
 * @package SafetyNet
 */

namespace SafetyNet;

use function SafetyNet\Anonymize\anonymize_customers;

/**
 * Background Anonymize Customer class
 */
class Background_Anonymize_Customer extends \WP_Background_Process {

	/**
	 * @var string
	 */
	protected $action = 'anonymize_customer';

	/**
	 * Anonymizes customers in the queue.
	 *
	 * @param array $item Customer to anonymize
	 *
	 * @return bool|array
	 */
	protected function task( $item ) {
		$result = anonymize_customers( $item['offset'] );

		// If there are no more results, return false.
		if ( ! $result ) {
			return false;
		}

		// Increase the offset.
		$item['offset'] += 500;

		return $item;
	}

}

