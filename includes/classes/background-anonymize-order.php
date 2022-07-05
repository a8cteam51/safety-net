<?php
/**
 * Background Anonymize Order Class
 *
 * @package SafetyNet
 */

namespace SafetyNet;

use function SafetyNet\Anonymize\anonymize_orders;

/**
 * Background Anonymize Order class.
 */
class Background_Anonymize_Order extends \WP_Background_Process {

	/**
	 * @var string
	 */
	protected $action = 'anonymize_order';

	/**
	 * Anonymizes orders in the queue.
	 *
	 * @param array $item WooCommerce order to anonymize
	 *
	 * @return bool|array
	 */
	protected function task( $item ) {
		$result = anonymize_orders( $item['offset'] );

		// If there are no more results, return false.
		if ( ! $result ) {
			return false;
		}

		// Increase the offset.
		$item['offset'] += 500;

		return $item;
	}

}

