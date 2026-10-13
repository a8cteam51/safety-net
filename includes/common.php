<?php

namespace SafetyNet\Common;

use function SafetyNet\Utilities\are_emails_enabled;
use function SafetyNet\Utilities\bind_send_emails_switch;
use const SafetyNet\Utilities\SEND_EMAILS_OPTION;

add_filter( 'send_password_change_email', __NAMESPACE__ . '\follow_send_emails_switch' );
add_filter( 'send_email_change_email', __NAMESPACE__ . '\follow_send_emails_switch' );

add_action( 'add_option_' . SEND_EMAILS_OPTION, __NAMESPACE__ . '\bind_added_send_emails_switch', 10, 2 );
add_action( 'update_option_' . SEND_EMAILS_OPTION, __NAMESPACE__ . '\bind_updated_send_emails_switch', 10, 2 );
add_filter( 'pre_update_option_' . SEND_EMAILS_OPTION, __NAMESPACE__ . '\bind_resaved_send_emails_switch', 10, 2 );
add_action( 'delete_option_' . SEND_EMAILS_OPTION, __NAMESPACE__ . '\bind_send_emails_switch_off' );

// Discourage search engines from indexing the site and disallow the entire site in robots.txt.
add_filter( 'option_blog_public', '__return_zero' );
add_filter( 'robots_txt', __NAMESPACE__ . '\disallow_all_user_agents' );

/**
 * Stops core's password change and email change notifications, unless emails are switched on.
 *
 * @param bool $send Whether core sends the notification.
 *
 * @return bool
 */
function follow_send_emails_switch( $send ) {
	return are_emails_enabled() ? $send : false;
}

/**
 * Binds the Send emails switch to this site's address when it is added.
 *
 * @param string $option The option's name.
 * @param mixed  $value  Its value.
 *
 * @return void
 */
function bind_added_send_emails_switch( $option, $value ) {
	bind_send_emails_switch( $value );
}

/**
 * Binds the Send emails switch to this site's address when it changes to 'on', and unbinds it when it changes to anything else.
 *
 * @param mixed $old_value The previous value.
 * @param mixed $value     The new value.
 *
 * @return void
 */
function bind_updated_send_emails_switch( $old_value, $value ) {
	bind_send_emails_switch( $value );
}

/**
 * Binds the Send emails switch to this site's address when 'on' is saved over a stored 'on', which update_option() then skips, as on a copy where it was on for another address.
 *
 * @param mixed $value     The new value.
 * @param mixed $old_value The stored value.
 *
 * @return mixed
 */
function bind_resaved_send_emails_switch( $value, $old_value ) {
	if ( 'on' === $value && $value === $old_value ) {
		bind_send_emails_switch( $value );
	}

	return $value;
}

/**
 * Forgets the address the Send emails switch was bound to when the switch is deleted.
 *
 * @return void
 */
function bind_send_emails_switch_off() {
	bind_send_emails_switch( 'off' );
}

function disallow_all_user_agents( $output ) {
	return "User-agent: *\nDisallow: /\n";
}
