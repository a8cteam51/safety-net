<?php
/**
 * Bootstrap
 *
 * Logic related to the loading of Safety Net
 */
namespace SafetyNet\Bootstrap;

use SafetyNet\Background_Anonymize_Customer;
use SafetyNet\Background_Anonymize_Order;
use SafetyNet\Background_Anonymize_User;

add_action( 'plugins_loaded', __NAMESPACE__ . '\instantiate_background_classes' );

/**
 * Background Process classes need to be instantiated on plugins_loaded hook.
 */
function instantiate_background_classes() {
	new Background_Anonymize_User();
	new Background_Anonymize_Order();
	new Background_Anonymize_Customer();
}
