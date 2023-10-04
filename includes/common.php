<?php

namespace SafetyNet\Common;

add_filter( 'send_password_change_email', '__return_false' );
add_filter( 'send_email_change_email', '__return_false' );

// stop Jetpack post subscription email sending per https://developer.jetpack.com/hooks/jetpack_is_post_mailable/
add_filter( 'jetpack_is_post_mailable', '__return_false' );

// Discourage search engines from indexing the site and disallow the entire site in robots.txt.
add_filter( 'option_blog_public', '__return_zero' );
add_action( 'robots_txt', __NAMESPACE__ . '\disallow_all_user_agents' );

function disallow_all_user_agents() {
	echo 'User-agent: *' . PHP_EOL;
	echo 'Disallow: /' . PHP_EOL;
}
