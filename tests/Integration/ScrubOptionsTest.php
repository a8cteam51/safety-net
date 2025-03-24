<?php


namespace Tests\Integration;

use Tests\Support\IntegrationTester;
use function SafetyNet\ScrubOptions\scrub_options;

class ScrubOptionsTest extends \Codeception\Test\Unit {


	protected IntegrationTester $tester;

	protected function _before() {
	}

	// This method will be run after each test
	public function _after() {
		// Clear the scrub list filters.
		remove_all_filters( 'safety_net_options_to_clear' );

		// Remove the custom options
		delete_option( 'my_custom_option' ); // test_add_option_to_scrub_list()
		delete_option( 'jetpack_secrets' ); // test_scrub_options()
	}



	/**
	 * @testdox It should be possible to add an option to the scrub list via a filter and this be removed when called.
	 *
	 * @function scrub_options
	 * @return void
	 */
	public function test_add_option_to_scrub_list_via_filter() {
		// Add a custom option to the scrub list
		add_filter(
			'safety_net_options_to_clear',
			function ( $options ) {
				$options[] = 'my_custom_option';
				return $options;
			}
		);

		// Set the custom option
		update_option( 'my_custom_option', 'my_custom_option_value' );

		// Call the scrub options function
		scrub_options();

		// Check the custom option has been scrubbed
		$this->assertEmpty( get_option( 'my_custom_option' ) );
	}

	/**
	 * @testdox When the scrub options function is called, it should remove all options in the scrub list.
	 *
	 * @function scrub_options
	 * @return void
	 */
	public function test_scrub_options() {
		// Setup option jetpack_active_modules
		update_option( 'jetpack_secrets', 'jetpack_secrets_value' );

		// Call the scrub options function
		scrub_options();

		// Check the option has been scrubbed
		$this->assertEmpty( get_option( 'jetpack_secrets' ) );
	}


	/**
	 * @testdox When scrub options is run, all webhooks should be disabled when scrub option is called.
	 *
	 * @function scrub_options
	 */
	public function testDisableWebhooksViaScrubOptions()
	{
		// Create a mock webhook in WC and enable it
		$webhook = new \WC_Webhook();
		$webhook->set_name('Test Webhook Scrubbed');
		$webhook->set_status('active');
		$id = $webhook->save();

		// Get all webhooks
		$data_store = \WC_Data_Store::load('webhook');
		$webhooks = $data_store->search_webhooks();

		// Check the webhook is active
		$this->assertEquals('active', \wc_get_webhook($id)->get_status());
		$this->assertContains($id, $webhooks);

		// Run the scrub options function
		scrub_options();

		// Check the webhook is disabled
		$this->assertEquals('disabled', \wc_get_webhook($id)->get_status());
	}
}
