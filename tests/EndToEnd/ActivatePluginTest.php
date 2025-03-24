<?php


namespace Tests\EndToEnd;

use Tests\Support\EndToEndTester;
use Tests\Support\_generated\EndToEndTesterActions;

class ActivatePluginTest extends \Codeception\Test\Unit {


	protected EndToEndTester $tester;

	protected function _before() {

		// Activate WC

		// Setting up Chrome options to ignore SSL errors
		// $config =  $this->tester->getModule('WebDriver')->_getConfig();
		// $config['capabilities']['goog:chromeOptions']['args'][] = '--ignore-certificate-errors';
	}

	/**
	 * Logs in the user.
	 *
	 * @return void
	 */
	// public function loginAdminUser() {
	//  // Step 1: Open the WordPress login page
	//  $this->tester->amOnPage( '/wp-login.php' );
	//  $this->tester->fillField( array( 'name' => 'log' ), 'admin' );
	//  $this->tester->fillField( array( 'name' => 'pwd' ), '12345678' );
	//  $this->tester->click( 'wp-submit' ); // The ID or name of the login button in the form
	// }

	/**
	 * // Save to file.
	 *
	 * @param string $content
	 *
	 * @return void
	 */
	public function saveToFile( string $content ) {
		$filename = '/home/glynn/ddddh.html';
		file_put_contents( $filename, $content );
	}

	// tests
	public function testSomeFeature() {
		// $active = get_option( 'active_plugins' );

		// // Add the plugin to the active plugins.
		// $active[] = dirname( __DIR__, 2 ) . '/vendor/wpackagist-plugin/woocommerce/woocommerce.php';
		// update_option( 'active_plugins', $active );

		// // do_action( 'init' );

		require_once dirname( __DIR__, 2 ) . '/vendor/wpackagist-plugin/woocommerce/woocommerce.php';
		require_once dirname( __DIR__, 2 ) . '/safety-net.php';
		// do_action('activate_woocommerce/woocommerce.php');
		// $result = activate_plugin( dirname( __DIR__, 2 ) . '/vendor/wpackagist-plugin/woocommerce/woocommerce.php' );
		if ( function_exists( 'WC' ) ) {
			\WC_Install::install();
		}

		// Get all the tables from teh database..
		// global $wpdb;
		// $tables = $wpdb->get_results( 'SHOW TABLES' );

		// dump( $tables );

		// return;
		define( 'WP_ENVIRONMENT_TYPE', 'staging' );
		// $this->tester->loginAsAdmin();
		// $this->tester->wait( 2 ); // Wait for login to complete

		// // this->loginAdminUser();

		// // Head to the plugins page.
		// $this->tester->amOnPage( '/wp-admin/plugins.php' );

		// // $this->tester->click( '#activate-safety-net' );

		// $this->tester->activatePlugin( 'safety-net' );
		// activate_plugin( 'safety-net/safety-net.php' );
		// // $this->tester->activatePlugin( 'woocommerce' );

		// $this->tester->makeScreenshot();

		// dump( get_option( 'admin_email' ) );
		// dump( get_option( 'active_plugins' ) );

		// // GO to dashboard.
		// $this->tester->amOnPage( '/wp-admin/' );
		// do_action( 'init' );
		// dump( WP_ENVIRONMENT_TYPE, get_option( 'admin_email' ), array( 'SafetyNet\Utilities\is_production' => function_exists( 'SafetyNet\Utilities\is_production' ) ) );
		$this->tester->makeScreenshot( 'wp-admin' );
		// $this->saveToFile( $this->tester->grabPageSource() );
	}
}
//
