<?php
/**
 * Background Anonymize User Class
 *
 * @package SafetyNet
 */

namespace SafetyNet;

class Dummy {
	/**
	 * An instance of this class.
	 *
	 * @var null|Dummy
	 */
	private static $instance = null;

	/**
	 * The number to use to get a random user.
	 *
	 * @var int
	 */
	private int $seed = 0;

	/**
	 * An array of fake users.
	 *
	 * @var array
	 */
	private $fake_users = array();

	/**
	 * First Name
	 *
	 * example: Ileana
	 *
	 * @var string
	 */
	public $first_name = '';

	/**
	 * Last Name
	 *
	 * example: Hurling
	 *
	 * @var string
	 */
	public $last_name = '';

	/**
	 * IP Address (ipv4)
	 *
	 * example: 196.218.230.247
	 *
	 * @var string
	 */
	public $ip_address = '';

	/**
	 * Street Address
	 *
	 * example: 89 Elka Center
	 *
	 * @var string
	 */
	public $street_address = '';

	/**
	 * User Agent
	 *
	 * example: Mozilla/5.0 (Windows NT 6.1; rv:27.3) Gecko/20130101 Firefox/27.3
	 *
	 * @var string
	 */
	public $user_agent = '';

	/**
	 * City
	 *
	 * example: Springfield
	 *
	 * @var string
	 */
	public $city = '';

	/**
	 * State Abbreviation
	 *
	 * example: MA
	 *
	 * @var string
	 */
	public $state = '';

	/**
	 * Postcode / Zip Code
	 *
	 * example: 01152
	 *
	 * @var string
	 */
	public $postcode = '';

	/**
	 * Phone number
	 *
	 * example: 413-528-8087
	 *
	 * @var string
	 */
	public $phone = '';

	/**
	 * Username
	 *
	 * example: ihurling102
	 *
	 * @var string
	 */
	public $username = '';

	/**
	 * URL
	 *
	 * example: https://state.gov
	 *
	 * @var string
	 */
	public $url = '';

	/**
	 * Email address
	 *
	 * example: ileana.hurling101@fake-email.test
	 *
	 * @var string
	 */
	public $email_address = '';

	/**
	 * A description about this user.
	 *
	 * example: This user was anonymized on 2022/07/01 with seed 101
	 *
	 * @var string
	 */
	public $description = '';

	private function __construct() {
		$this->set_fake_users();
	}

	/**
	 * Returns an instance of this class.
	 *
	 * @return Dummy|null
	 */
	public static function get_instance( $seed = 0 ) {
		if ( is_null( self::$instance ) ) {
			self::$instance = new Dummy();
		}

		self::$instance->set_seed( $seed );
		self::$instance->setup();

		return self::$instance;
	}

	/**
	 * Sets the number that will be used to get a random user. You can use the user's ID, for example.
	 *
	 * @param int|string $seed
	 *
	 * @return int
	 */
	public function set_seed( $seed = 0 ): int {
		if ( empty( $seed ) || ! is_numeric( $seed ) ) {
			$seed = wp_rand( 1, 1000 );
		}

		$this->seed = intval( $seed );

		return $seed;
	}

	/**
	 * Sets the fake users array to be used later.
	 *
	 * @return void
	 */
	private function set_fake_users() {
		$this->fake_users = $this->get_fake_users();
	}

	/**
	 * Returns an array of fake user data.
	 *
	 * @return array
	 */
	private function get_fake_users(): array {
		static $users = array();

		if ( empty( $users ) ) {
			$file = fopen( SAFETY_NET_PATH . 'assets/data/fake_users.csv', 'r' ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions

			while ( false !== ( $line = fgetcsv( $file ) ) ) { // phpcs:ignore -- WordPress.CodeAnalysis.AssignmentInCondition

				$user = array(
					'first_name'     => $line[0],
					'last_name'      => $line[1],
					'ip_address'     => $line[2],
					'street_address' => $line[3],
					'user_agent'     => $line[4],
					'city'           => $line[5],
					'state'          => $line[6],
					'postcode'       => $line[7],
					'phone'          => $line[8],
					'username'       => $line[9],
					'url'            => $line[10],
				);

				$users[] = $user;
			}

			fclose( $file ); // phpcs:ignore -- WordPress.WP.AlternativeFunctions
		}

		return $users;
	}

	/**
	 * Sets up all the data for the class.
	 *
	 * @return void
	 */
	private function setup() {
		$fake_user = $this->fake_users[ $this->seed % 1000 ];
		$date      = gmdate( 'Y-m-d H:i:s T' );

		$this->first_name     = $fake_user['first_name'];
		$this->last_name      = $fake_user['last_name'];
		$this->ip_address     = $fake_user['ip_address'];
		$this->street_address = $fake_user['street_address'];
		$this->user_agent     = $fake_user['user_agent'];
		$this->city           = $fake_user['city'];
		$this->state          = $fake_user['state'];
		$this->postcode       = $fake_user['postcode'];
		$this->phone          = $fake_user['phone'];
		$this->username       = "{$fake_user['username']}{$this->seed}";
		$this->url            = $fake_user['url'];
		$this->email_address  = strtolower( "{$fake_user['first_name']}.{$fake_user['last_name']}{$this->seed}@fake-email.test" );
		$this->description    = "This user was anonymized on {$date} with seed {$this->seed}";
	}
}