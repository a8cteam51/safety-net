<?php

/**
 * This is a bit of helper code to allow the die function found in the delete_users_and_orders function to be caught by the test suite.
 *
 * This is a bit of a hack, but it works.
 */

namespace Tests\Integration\Helper;

// Get all the passed args.
$arguments = [];
foreach ($argv as $arg) {
    $e = explode("=", $arg);
    if(count($e) == 2)
        $arguments[$e[0]] = $e[1];
}


require_once $arguments['abspath'] . 'wp-load.php'; // Load WP
require_once $arguments['plugin_base'] . 'includes/delete.php'; // Load the plugin

ob_start();
\SafetyNet\Delete\delete_users_and_orders(); // This should call `die()`
$output = ob_get_clean();

// Output the captured content so the test can assert it
echo $output;