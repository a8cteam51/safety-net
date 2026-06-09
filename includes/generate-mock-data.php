<?php
/**
 * Generates mock data (blog posts, and WooCommerce products, customers, and orders) from JSON fixtures.
 *
 * @package SafetyNet
 */

namespace SafetyNet\GenerateMockData;

use function SafetyNet\Utilities\get_admin_user_ids;
use function SafetyNet\Utilities\is_production;

add_action( 'safety_net_generate_mock_data', __NAMESPACE__ . '\generate_mock_data' );

/**
 * The data types that can be generated.
 *
 * @return string[]
 */
function allowed_types(): array {
	return array( 'posts', 'products', 'customers', 'orders' );
}

/**
 * The data types that require WooCommerce.
 *
 * @return string[]
 */
function woocommerce_types(): array {
	return array( 'products', 'customers', 'orders' );
}

/**
 * Whether WooCommerce is available.
 *
 * @return bool
 */
function is_woocommerce_active(): bool {
	return class_exists( 'WooCommerce' );
}

/**
 * The number of records generated per type. Filterable.
 *
 * @return array<string,int>
 */
function mock_data_counts(): array {
	return apply_filters(
		'safety_net_mock_data_counts',
		array(
			'posts'     => 15,
			'products'  => 20,
			'customers' => 12,
			'orders'    => 25,
		)
	);
}

/**
 * Generates mock data for the requested types.
 *
 * @param string[]|null $types Types to generate. Null/empty generates all applicable types.
 *
 * @return array<string,int> Map of type => number of records created.
 */
function generate_mock_data( $types = null ): array {
	$results = array();

	// Never generate data on production, regardless of how this is called.
	if ( is_production() ) {
		return $results;
	}

	$allowed = allowed_types();

	if ( empty( $types ) || ! is_array( $types ) ) {
		$types = $allowed;
	} else {
		$types = array_values( array_intersect( array_map( 'sanitize_key', $types ), $allowed ) );
	}

	// Drop WooCommerce types if WooCommerce isn't active.
	if ( ! is_woocommerce_active() ) {
		$types = array_values( array_diff( $types, woocommerce_types() ) );
	}

	$counts       = mock_data_counts();
	$product_ids  = array();
	$customer_ids = array();

	if ( in_array( 'products', $types, true ) ) {
		$product_ids         = create_products( (int) $counts['products'] );
		$results['products'] = count( $product_ids );
	}

	if ( in_array( 'customers', $types, true ) ) {
		$customer_ids         = create_customers( (int) $counts['customers'] );
		$results['customers'] = count( $customer_ids );
	}

	if ( in_array( 'orders', $types, true ) ) {
		// Orders need products and customers. Use what we just made, then fall back
		// to existing records, then scaffold a minimal product set as a last resort.
		if ( empty( $product_ids ) ) {
			$product_ids = existing_product_ids();
		}
		if ( empty( $customer_ids ) ) {
			$customer_ids = existing_customer_ids();
		}
		if ( empty( $product_ids ) ) {
			$product_ids = create_products( min( 5, max( 1, (int) $counts['products'] ) ) );
		}

		$results['orders'] = count( create_orders( (int) $counts['orders'], $product_ids, $customer_ids ) );
	}

	if ( in_array( 'posts', $types, true ) ) {
		$results['posts'] = count( create_posts( (int) $counts['posts'] ) );
	}

	return $results;
}

/**
 * Creates mock blog posts.
 *
 * @param int $count How many to create.
 *
 * @return int[] Created post IDs.
 */
function create_posts( int $count ): array {
	$fixtures = load_mock_fixture( 'posts' );
	if ( empty( $fixtures ) ) {
		return array();
	}

	$admin_ids = get_admin_user_ids();
	$author_id = ! empty( $admin_ids ) ? (int) $admin_ids[0] : 0;
	$pool      = count( $fixtures );
	$created   = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$fixture = $fixtures[ $i % $pool ];
		$suffix  = $i >= $pool ? ' #' . ( $i + 1 ) : '';

		$post_id = wp_insert_post(
			array(
				'post_title'   => $fixture['title'] . $suffix,
				'post_content' => $fixture['content'],
				'post_excerpt' => isset( $fixture['excerpt'] ) ? $fixture['excerpt'] : '',
				'post_status'  => 'publish',
				'post_type'    => 'post',
				'post_author'  => $author_id,
				'post_date'    => random_past_date(),
				'meta_input'   => array( '_safety_net_mock' => 1 ),
			),
			true
		);

		if ( ! is_wp_error( $post_id ) && $post_id ) {
			$created[] = (int) $post_id;
		}
	}

	return $created;
}

/**
 * Creates mock WooCommerce products.
 *
 * @param int $count How many to create.
 *
 * @return int[] Created product IDs.
 */
function create_products( int $count ): array {
	if ( ! is_woocommerce_active() || ! class_exists( 'WC_Product_Simple' ) ) {
		return array();
	}

	$fixtures = load_mock_fixture( 'products' );
	if ( empty( $fixtures ) ) {
		return array();
	}

	$pool    = count( $fixtures );
	$created = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$fixture = $fixtures[ $i % $pool ];
		$suffix  = $i >= $pool ? ' #' . ( $i + 1 ) : '';

		try {
			$product = new \WC_Product_Simple();
			$product->set_name( $fixture['name'] . $suffix );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
			$product->set_description( $fixture['description'] );
			$product->set_short_description( $fixture['short_description'] );
			$product->set_regular_price( (string) random_price( (int) $fixture['price_min'], (int) $fixture['price_max'] ) );
			$product->set_sku( 'MOCK-' . ( $i + 1 ) . '-' . strtoupper( wp_generate_password( 4, false ) ) );

			if ( ! empty( $fixture['downloadable'] ) ) {
				$product->set_virtual( true );
				$product->set_downloadable( true );
				// Point the file at the uploads dir (approved by WooCommerce's download
				// directory rules). Isolate it so a rejected file never loses the product.
				try {
					$uploads  = wp_upload_dir();
					$download = new \WC_Product_Download();
					$download->set_id( 'mock-download-' . ( $i + 1 ) );
					$download->set_name( $fixture['name'] );
					$download->set_file( trailingslashit( $uploads['baseurl'] ) . 'safety-net-mock-' . ( $i + 1 ) . '.pdf' );
					$product->set_downloads( array( $download ) );
				} catch ( \Exception $e ) {
					$product->set_downloads( array() );
				}
			} else {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( wp_rand( 5, 200 ) );
				$product->set_stock_status( 'instock' );
			}

			$category_id = get_or_create_product_category( $fixture['category'] );
			if ( $category_id ) {
				$product->set_category_ids( array( $category_id ) );
			}

			$product->update_meta_data( '_safety_net_mock', 1 );
			$product_id = $product->save();

			if ( $product_id ) {
				$created[] = (int) $product_id;
			}
		} catch ( \Exception $e ) {
			continue;
		}
	}

	return $created;
}

/**
 * Creates mock WooCommerce customers.
 *
 * @param int $count How many to create.
 *
 * @return int[] Created user IDs.
 */
function create_customers( int $count ): array {
	if ( ! is_woocommerce_active() || ! function_exists( 'wc_create_new_customer' ) ) {
		return array();
	}

	$fixtures = load_mock_fixture( 'customers' );
	if ( empty( $fixtures ) ) {
		return array();
	}

	$pool    = count( $fixtures );
	$created = array();

	for ( $i = 0; $i < $count; $i++ ) {
		$fixture  = $fixtures[ $i % $pool ];
		$token    = strtolower( wp_generate_password( 6, false ) );
		$email    = sprintf(
			'mock.%s.%s.%s@example.com',
			sanitize_key( $fixture['first_name'] ),
			sanitize_key( $fixture['last_name'] ),
			$token
		);
		$username = sanitize_user( $fixture['first_name'] . '.' . $fixture['last_name'] . '.' . $token, true );

		$user_id = wc_create_new_customer( $email, $username, wp_generate_password( 16 ) );
		if ( is_wp_error( $user_id ) ) {
			continue;
		}

		$billing = isset( $fixture['billing'] ) && is_array( $fixture['billing'] ) ? $fixture['billing'] : array();

		update_user_meta( $user_id, 'first_name', $fixture['first_name'] );
		update_user_meta( $user_id, 'last_name', $fixture['last_name'] );
		update_user_meta( $user_id, 'billing_first_name', $fixture['first_name'] );
		update_user_meta( $user_id, 'billing_last_name', $fixture['last_name'] );
		update_user_meta( $user_id, 'billing_email', $email );
		update_user_meta( $user_id, 'billing_address_1', $billing['address_1'] ?? '' );
		update_user_meta( $user_id, 'billing_city', $billing['city'] ?? '' );
		update_user_meta( $user_id, 'billing_state', $billing['state'] ?? '' );
		update_user_meta( $user_id, 'billing_postcode', $billing['postcode'] ?? '' );
		update_user_meta( $user_id, 'billing_country', $billing['country'] ?? '' );
		update_user_meta( $user_id, 'safety_net_mock', 1 );

		$created[] = (int) $user_id;
	}

	return $created;
}

/**
 * Creates mock WooCommerce orders linked to the given products and customers.
 *
 * @param int   $count        How many to create.
 * @param int[] $product_ids  Product IDs to draw line items from.
 * @param int[] $customer_ids Customer IDs to assign (empty = guest orders).
 *
 * @return int[] Created order IDs.
 */
function create_orders( int $count, array $product_ids, array $customer_ids ): array {
	if ( ! is_woocommerce_active() || ! function_exists( 'wc_create_order' ) || empty( $product_ids ) ) {
		return array();
	}

	$statuses = array( 'completed', 'completed', 'processing', 'processing', 'on-hold' );
	$created  = array();

	for ( $i = 0; $i < $count; $i++ ) {
		try {
			$order = wc_create_order();
			if ( is_wp_error( $order ) ) {
				continue;
			}

			if ( ! empty( $customer_ids ) ) {
				$customer_id = (int) $customer_ids[ array_rand( $customer_ids ) ];
				$order->set_customer_id( $customer_id );
				$customer = new \WC_Customer( $customer_id );
				$order->set_address(
					array(
						'first_name' => $customer->get_billing_first_name() ? $customer->get_billing_first_name() : $customer->get_first_name(),
						'last_name'  => $customer->get_billing_last_name() ? $customer->get_billing_last_name() : $customer->get_last_name(),
						'email'      => $customer->get_billing_email() ? $customer->get_billing_email() : $customer->get_email(),
						'address_1'  => $customer->get_billing_address_1(),
						'city'       => $customer->get_billing_city(),
						'state'      => $customer->get_billing_state(),
						'postcode'   => $customer->get_billing_postcode(),
						'country'    => $customer->get_billing_country(),
					),
					'billing'
				);
			}

			$shuffled = $product_ids;
			shuffle( $shuffled );
			$chosen = array_slice( $shuffled, 0, wp_rand( 1, min( 3, count( $shuffled ) ) ) );

			foreach ( $chosen as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( $product ) {
					$order->add_product( $product, wp_rand( 1, 3 ) );
				}
			}

			$order->set_status( $statuses[ array_rand( $statuses ) ] );
			$order->set_date_created( random_past_date() );
			$order->calculate_totals();
			$order->update_meta_data( '_safety_net_mock', 1 );
			$order_id = $order->save();

			if ( $order_id ) {
				$created[] = (int) $order_id;
			}
		} catch ( \Exception $e ) {
			continue;
		}
	}

	return $created;
}

/**
 * Gets a product category term ID, creating the term if needed.
 *
 * @param string $name Category name.
 *
 * @return int Term ID, or 0 on failure.
 */
function get_or_create_product_category( string $name ): int {
	$term = get_term_by( 'name', $name, 'product_cat' );
	if ( $term instanceof \WP_Term ) {
		return (int) $term->term_id;
	}

	$result = wp_insert_term( $name, 'product_cat' );
	if ( is_wp_error( $result ) ) {
		return 0;
	}

	return (int) $result['term_id'];
}

/**
 * Existing published product IDs (used when generating orders without products).
 *
 * @return int[]
 */
function existing_product_ids(): array {
	$ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'fields'         => 'ids',
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * Existing customer user IDs (used when generating orders without customers).
 *
 * @return int[]
 */
function existing_customer_ids(): array {
	$ids = get_users(
		array(
			'role'   => 'customer',
			'fields' => 'ID',
			'number' => 100,
		)
	);

	return array_map( 'intval', (array) $ids );
}

/**
 * A random price between two bounds.
 *
 * @param int $min Minimum.
 * @param int $max Maximum.
 *
 * @return string Price with two decimals.
 */
function random_price( int $min, int $max ): string {
	if ( $max <= $min ) {
		$max = $min + 1;
	}
	$dollars = wp_rand( $min, $max - 1 );
	$cents   = array( '00', '49', '95', '99' );
	return $dollars . '.' . $cents[ array_rand( $cents ) ];
}

/**
 * A random datetime within the past year.
 *
 * @return string MySQL-format datetime.
 */
function random_past_date(): string {
	$offset = wp_rand( 0, YEAR_IN_SECONDS );
	return gmdate( 'Y-m-d H:i:s', time() - $offset );
}

/**
 * Loads a JSON fixture from assets/data/mock/.
 *
 * @param string $name Fixture name without extension.
 *
 * @return array Decoded fixture, or empty array on failure.
 */
function load_mock_fixture( string $name ): array {
	global $wp_filesystem;

	if ( ! $wp_filesystem ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}

	$file_path = SAFETY_NET_PATH . 'assets/data/mock/' . $name . '.json';

	if ( ! $wp_filesystem || ! $wp_filesystem->exists( $file_path ) ) {
		return array();
	}

	$contents = $wp_filesystem->get_contents( $file_path );
	if ( false === $contents ) {
		return array();
	}

	$data = json_decode( $contents, true );

	return is_array( $data ) ? $data : array();
}
