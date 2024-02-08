<?php

/**
 * Tests the Utilities\get_denylist_array() function
 *
 * This tests checks to be sure that the array returned for $denylist_type
 * 'options' and 'plugins' is a match for the data in the scrublist and denylist
 * files in /assets/data
 *
 * @group unit
 * @covers \SafetyNet\Utilities\get_denylist_array
 */

require_once __DIR__ . '/../includes/utilities.php';

class DenylistTest extends PHPUnit\Framework\TestCase {

	// 'options'
	public function test_get_denylist_array_options() {
		$expected = file( __DIR__ . '/../assets/data/option_scrublist.txt', FILE_IGNORE_NEW_LINES );
		$actual   = SafetyNet\Utilities\get_denylist_array( 'options' );
		$this->assertEqualsCanonicalizing( $expected, $actual, 'The options denylist array is NOT a match to the options_scrublist.txt file.' );
	}

	// 'plugins'
	public function test_get_denylist_array_plugins() {
		$expected = file( __DIR__ . '/../assets/data/plugin_denylist.txt', FILE_IGNORE_NEW_LINES );
		$actual   = SafetyNet\Utilities\get_denylist_array( 'plugins' );
		$this->assertEqualsCanonicalizing( $expected, $actual, 'The plugins denylist array is NOT a match to the plugin_denylist.txt file.' );
	}
}
