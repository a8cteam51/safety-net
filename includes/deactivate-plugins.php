<?php

namespace SafetyNet\DeactivatePlugins;

function run_at_activation() {
  deactivate_payment_gateways();
  deactivate_plugins();
}
register_activation_hook( __FILE__, 'run_at_activation' );

/*
* Deactivate Woo payment gateways
*/
function deactivate_payment_gateways() {

}

function deactivate_plugins() {

  if ( ! function_exists( 'get_plugins' ) ) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
  }

  $all_installed_plugins = array_keys( get_plugins() );
  $blacklisted_plugins = array( 'smtp', 'wp-algolia', 'easy-wp-smtp', 'fluent-smtp', 'gmail-smtp', 'in-stock-mailer-for-wc', 'klaviyo', 'tribe-klaviyo', 'wp-mail-bank', 'mailchimp-woocommerce', 'mailgun', 'mailchimp-for-wp', 'metorik-helper', 'sendinblue', 'postman-smtp', 'wp-sendgrid-mailer', 'smtp-mailer', 'socketlabs', 'woocommerce-shipstation', 'wp-console', 'wp-mail-smtp', 'wp-ses', 'algolia', 'wp-smtp', 'zapier',  );

  foreach ( $blacklisted_plugins as $blacklisted_plugin ) {

    foreach ( $all_installed_plugins as $installed_plugin ) {

      if ( strstr( $blacklisted_plugin, $installed_plugin ) ) {

        if ( 'klaviyo' == $blacklisted_plugin ) {
          // delete klaviyo_settings option before deactivation
          delete_option( 'klaviyo_settings' );
        }
        if ( 'tribe-klaviyo' == $blacklisted_plugin ) {
          // delete klaviyo_api_key option before deactivation
          delete_option( 'klaviyo_api_key' );
        }

        deactivate_plugins( $installed_plugin, true ); // deactivate it silently and don't trigger any deactivation hooks
        break; // break out of nested loop once a match has been made

      }

    }

  }

}
