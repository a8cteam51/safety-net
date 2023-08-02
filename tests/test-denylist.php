<?php 

/**
 * Tests the Utilities\get_denylist_array() function
 * 
 * This tests checks to be sure that an array is returned for get_denylist_array(), 
 * for $denylist_type 'options' and 'plugins', from /assets/data
 * 
 * @group unit
 * @covers \SafetyNet\Utilities\get_denylist_array
 */

include __DIR__ . '/../includes/utilities.php';

class DenylistTest extends PHPUnit\Framework\TestCase {

    // 'options'
    public function test_get_denylist_array_options() {

        //define file location independent of WordPress plugin_dir()
        define( 'SAFETY_NET_URL', __DIR__ . '/../' );    

        $actual = SafetyNet\Utilities\get_denylist_array( 'options' );
        $this->assertIsArray( $actual, "options list array expected" );
    }

    // 'plugins'
    public function test_get_denylist_array_plugins() {

        $actual = SafetyNet\Utilities\get_denylist_array( 'plugins' );
        $this->assertIsArray( $actual, "assert plugins list array expected" );
    }

}