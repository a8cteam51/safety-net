| :exclamation:  This is a public repository |
|--------------------------------------------|

# Safety Net

**for development, staging, and local WordPress sites**

**[Download the latest release](https://github.com/a8cteam51/safety-net/releases/latest/download/safety-net.zip)**

## What's this?
This is a WordPress plugin developed by WordPress.com Special Projects that secures sensitive data on development, staging, and local sites. It deletes users and WooCommerce orders and subscriptions, as well as prevents sites from acting on user data (e.g. sending emails, processing renewals, etc.)

## Disclaimer
This public plugin is provided as an example of how such a plugin could be implemented, and is provided without any support or guarantees. Please use at your own discretion. Incorrect usage could result in data deletion.

## Existing Features
- **Stop Emails**: When Safety Net is activated, WordPress will be blocked from sending emails. (Caution: may not block SMTP or other plugins from doing so. Plugins that Safety Net deactivated, such as MailPoet, can send email or sync data on their own if someone reactivates them; wp-admin then shows an error notice naming them, and the Tools page highlights them.) 
- **Pause Renewal Actions**: When Safety Net is activated, Action Scheduler will not claim renewal, payment retry or end of prepaid term actions from WooCommerce Subscriptions, effectively pausing them. Other scheduled actions will continue to run. This is toggleable in wp-admin.
- **Discourage Search Engines**: Sets the "Discourage search engines" option and disallows all user agents in the `robots.txt` file. Also disables Jetpack 'publicize' option.
- **Scrub Options**: Clears specific denylisted options, such as API keys, which could cause problems on a development site.
- **Deactivate Plugins**: Deactivates denylisted plugins. Also deactivates any plugin that registers a WooCommerce payment gateway (deactivates the actual plugin, not from the checkout settings). WooCommerce's built-in gateways and a few offline ones (such as Pre-Orders' "Pay Later" and Bookings' availability check) are left alone. To exclude a plugin from this step, use the `safety_net_payment_gateway_plugins` filter; plugins that also match the denylist (Stripe, PayPal, etc.) additionally need `safety_net_denylisted_plugins`.
- **Delete**: Deletes all non-admin users, WooCommerce orders and subscriptions, and the personal data some plugins keep in their own tables (see [Explanations](#explanations)).

#### Advanced features
- **CLI commands**: CLI equivalents of the above features: `wp safety-net scrub-options`, `wp safety-net deactivate-plugins`, and `wp safety-net delete`

### Skipping GiveWP Data Deletion

By default, Safety Net will delete GiveWP donor data, payment records, and subscriptions when running the data deletion process. If you want to **preserve GiveWP data on a staging site**, you can define the following constant in your `wp-config.php` file:

```php
define( 'SAFETY_NET_SKIP_GIVEWP', true );
```

When this constant is set to `true`, all GiveWP-specific data will be excluded from the deletion process. This includes donor records, donation posts, subscription data, and related metadata.

## Planned Features
- Do you have a suggestion for the next great feature to add? Please create an issue or submit a PR!

## How to use?
Download the plugin code directly from this repo.

Activating the plugin on a non-production site will:

1. Scrub denylisted options.*
2. Deactivate denylisted plugins.*
3. Delete users, orders, and subscriptions.*
4. Stop emails. You can still test and view emails by activating the [WP Mail Logging plugin](https://wordpress.org/plugins/wp-mail-logging/). 
5. Pause Renewal Actions.
6. Discourage search engines.

*Only runs automatically if `wp_get_environment_type` returns `staging`, `development`, or `local`. If you have access to WP-CLI, you can SSH in and run `wp config set WP_ENVIRONMENT_TYPE staging --type=constant`

On a multisite network, each site runs these steps on its own first load. Network-activated plugins and the network admin email are handled once per network, by the first site that runs (for payment gateway plugins, the first one with WooCommerce active), so later sites don't undo what a super admin changes afterwards. On the Tools > Safety Net page, only super admins re-apply those network-wide changes; a site administrator's buttons only change their own site. `wp safety-net scrub-options` and `wp safety-net deactivate-plugins` always apply them.

## How to add plugins or options to the denylists
These denylists are `txt` files that live in the `assets/data/` folder. Each plugin or option is on its own line. 

A plugin that Safety Net should do more for than deactivate, such as scrub its options or delete its data, gets its own file in `includes/integrations/` instead (see [Adding an integration](AGENTS.md#adding-an-integration) in `AGENTS.md`); `plugin_denylist.txt` is for plugins that are only deactivated.

You may also:
- Create a new issue or dev request to have a plugin or option added to the denylists, or
- Submit a PR to add something yourself, and let us know so we can merge it

## Blocking Use in Production
Safety Net will not run on production sites. It will check the `WP_ENVIRONMENT_TYPE` global system variable, or a constant of the same name. If it is set to `production`, the plugin will not run, and users who can manage options see a notice in wp-admin saying that Safety Net is active on a production site. The `safety_net_show_production_notice` filter only decides whether the current user sees that notice; return `false` to hide it. It does not turn Safety Net on or off.

```php
add_filter( 'safety_net_show_production_notice', '__return_false' );
```

## Adding plugins to the Deny list.
You can add a plugin to the deny list for a single site using the following filter.
```php
add_filter( 'safety_net_denylisted_plugins', function( $denylist ) {
    // Add the full path to the plugin file here you wish to deny.
	$denylist[] = 'plugin-folder/plugin-file.php';

	// You can use partial names as well.
	$denylist[] = 'paypal'; // this would match any plugin with 'paypal' in the name.
    return $denylist;
} );
```

## Running the tests
The smoke tests boot real WordPress sites in [WordPress Playground](https://wordpress.github.io/wordpress-playground/), so you don't need PHP, MySQL or Docker. You need Node.js 24.18 or later (older 24.x releases work, with `EBADENGINE` warnings from `npm ci`), `unzip` and `git` on your `PATH`, and network access on every run:

```bash
npm ci
npm test                       # every scenario: single site, mu-plugin, production, WooCommerce, multisite, WP-CLI
npm test -- woocommerce        # one group (single-site, woocommerce or multisite)
npm test -- woocommerce-hpos   # one scenario; --list shows them all
SN_TEST_PHP=8.1 npm test       # another PHP version
```

They check that no request fails with a PHP fatal error or a 500, and that Safety Net does what this README says on each kind of site. Logs go to `tests/_output/`; downloads are cached in `~/.cache/safety-net-tests`. See the Tests section of `AGENTS.md` for the details. Releases are only built once the same tests pass, so the release zip appears about 10 minutes after a release is created.

## Troubleshooting

### Plugin not running
For Safety Net to run - and to access the tools page - the environment type needs to be set as `staging`, `development`, or `local`. The type can be set via the `WP_ENVIRONMENT_TYPE` global system variable, or a constant of the same name.

One way to do that is to edit your `wp-config.php` file, and add `define('WP_ENVIRONMENT_TYPE', 'development');`

Or, if you have access to WP-CLI, you can SSH in and run `wp config set WP_ENVIRONMENT_TYPE staging --type=constant`

If your site is on Pressable, you can also achieve this by [setting the site as a Staging Site](https://pressable.com/knowledgebase/how-sites-staging-websites-and-website-clones-work-at-pressable/#staging-websites).

### Plugin won't activate
It's possible that there is another copy of the plugin active on the site. Check in the `mu-plugins` folder.

### I don't want the functions to automatically run on my non-production site
There is no setting for this yet: [#65](https://github.com/a8cteam51/safety-net/issues/65) plans constants, such as `SAFETY_NET_DELETE_DATA` and `SAFETY_NET_SCRUB_OPTIONS`, that turn single steps off. Until then, you can comment out steps in `includes/bootstrap.php`; the change is lost when Safety Net is updated:
```php
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_scrub_options' );
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_deactivate_plugins' );
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_delete_data' );
```
Each step only runs after the one before it, so comment them out from the bottom up: the last one, the last two, or all three. Transients are then still deleted, WooCommerce webhooks disabled, renewals paused and emails blocked, and the skipped steps can still be run, in order, from Tools > Safety Net or WP-CLI. Commenting out only the second one, on a site whose plugins step never ran, means data is not deleted until that step is run from Tools > Safety Net or WP-CLI, and until then every page load logs that the deletion is postponed. Commenting out only the first one, on a site whose options were never scrubbed, stops every request, wp-admin and WP-CLI included, with "Safety Net Error: options need to be scrubbed first.", so nothing else runs; restore the line to recover.

## Explanations

What Safety Net does for each plugin it knows about is declared in one file per plugin in `includes/integrations/`, and `wp safety-net integrations` lists those declarations on a site. Plugins that Safety Net only deactivates are listed in `assets/data/plugin_denylist.txt` instead. Below, "plugins matching `stripe`" means every plugin whose path (`folder/file.php`) contains `stripe`, ignoring case. Scrubbed options are blanked, or set to the value given, after their value is copied to `{option}_sn_backup`, unless noted otherwise.

### AI providers

Safety Net removes the keys a staging site would use to call paid AI services as the live site.

* Deletes the AI provider API keys and application passwords that WordPress stores for its connectors (`connectors_ai_*` options), the AI plugin's encrypted copies of them (`_secret_ai/*`), and the keys that known AI provider plugins keep in options of their own. Settings of those plugins that also hold other configuration keep it; only their secrets are blanked. AI keys are never backed up, and backups an earlier run left of them are deleted. The optional token of AI Provider for exo (`aiprfoex_api_key`), for a model cluster the site owner runs locally, is kept; the plugin itself is still deactivated.
* Scrubs AI Engine's settings (`mwai_options`, `mwai_v2_options`) like other options, with a backup.
* Deactivates the AI plugin (`ai/ai.php`) and AI provider plugins, without running their deactivation hooks, so the AI plugin cannot write its decrypted keys back. Plugins that only talk to a local model server, such as Ollama or LM Studio, are deactivated too.
* Keys supplied through environment variables or `wp-config.php` constants, such as `OPENAI_API_KEY` or any other `{PROVIDER}_API_KEY`, are not in the database, so Safety Net cannot scrub them. Remove them from the staging site's environment and configuration.
* On a multisite network each site's keys are deleted on that site's first load, so a site that is never loaded keeps its keys, as it keeps every other scrubbed option.
* Staging sites that an earlier version of Safety Net already processed keep their AI keys until Scrub Options is run again, from Tools > Safety Net or with `wp safety-net scrub-options`.

### AutomateWoo

* Deactivates plugins matching `automatewoo`.
* Disables the published workflows, empties the queue of workflow runs (`automatewoo_queue`, `automatewoo_queue_meta`), and cancels the pending scheduled actions whose hook contains `automatewoo`.

### BuddyPress

* Deletes user profiles, signups, friends (and each user's friend count), messages and sitewide notices, and notifications. On a multisite network these tables are shared by every site.

### GiveWP

* Deactivates plugins matching `give-` or `give/`.
* Deletes donors, donations (`give_payment` posts), subscriptions, sessions and donation comments, with their meta, unless `SAFETY_NET_SKIP_GIVEWP` is `true` (see [Skipping GiveWP Data Deletion](#skipping-givewp-data-deletion)). The constant keeps the data only; the plugins are still deactivated.

### HubSpot

* Deactivates plugins matching `hubspot` or `leadin`.
* Scrubs the access token (`leadin_access_token`).

### Internet Archive Wayback Machine Link Fixer

* Deactivates plugins matching `internet-archive-wayback-machine-link-fixer`.
* Scrubs the Internet Archive API access key and secret (`iawmlf_archive_api_access`, `iawmlf_archive_api_secret`).

### Jetpack

* Deactivates plugins matching `jetpack-social`.
* Turns off the Enhanced Distribution, Publicize and Subscriptions modules, and keeps the other active modules.
* Scrubs Jetpack's connection secrets (`jetpack_private_options`, `jetpack_secrets`), except on WordPress.com Atomic sites (as Jetpack reports, or with an address ending in `wpcomstaging.com`), where that would disconnect Jetpack.
* Limits Jetpack's subscription emails to posts in a category that does not exist, so none are sent.

### Jetpack CRM

* Empties its contacts, companies, quotes, invoices, transactions, line items, events, logs and tags tables (`zbs_contacts`, `zbs_companies`, `zbs_quotes`, `zbs_invoices`, `zbs_transactions`, `zbs_lineitems`, `zbs_events`, `zbs_logs`, `zbs_tags`).
* Its other tables are kept, among them `zbs_meta`, the contact aliases (`zbs_aka`), the sent-email history (`zbs_sys_email_hist`) and the API keys (`zbscrm_api_keys`).

### Kit (formerly ConvertKit)

* Scrubs the API access settings: the access and refresh tokens, their expiry, and the API key and secret inside `_wp_convertkit_settings`. The other settings are kept.
* Deactivates plugins matching `convertkit`.

### Klaviyo

* Deactivates plugins matching `klaviyo`.
* Scrubs `klaviyo_api_key`, `klaviyo_edd_license_key`, `klaviyo_settings` and `novos_klaviyo_option_name`.

### Mailchimp

* Deactivates plugins matching `mailchimp`.
* Scrubs the settings of Mailchimp for WordPress (`mc4wp`) and Mailchimp for WooCommerce (`mailchimp-woocommerce`, `mailchimp-woocommerce-cached-api-account-name`).

### MailPoet

Safety Net keeps MailPoet's configuration and deletes its people, so a staging site keeps its forms and can be connected to its own accounts.

* Deactivates MailPoet and MailPoet Premium (plugins matching `mailpoet`).
* Deletes subscribers and everything recorded about them: list and tag memberships, custom field values, sending, open, click, bounce, unsubscribe, form and WooCommerce purchase statistics, sending queues and tasks, automation runs and their scheduled steps, the MailPoet log, the last sending error, and subscriber and statistics export files in `uploads/mailpoet`.
* Scrubs the MailPoet Sending Service and Premium keys and their cached key checks, the SMTP, Amazon SES and SendGrid credentials, and the reCAPTCHA and Turnstile secret keys. Forms that used reCAPTCHA or Turnstile switch to MailPoet's built-in captcha.
* Blanks the default sender, reply-to, bounce and notification email addresses, and each email's own sender and reply-to addresses. Emails without a sender use the default one, which a reactivated MailPoet fills in from the scrubbed admin email. Saving an automation copies the sender stored in its email step back to that email.
* Keeps forms, lists (now empty), segments, custom fields, tags, emails, templates, automations and the other settings. Scheduled and sending newsletters become drafts.

MailPoet sends email itself, not through `wp_mail()`, so Safety Net's email blocking does not cover a reactivated MailPoet.

### Mailster

* Deactivates plugins matching `mailster`.
* Scrubs its settings (`mailster_options`).

### Nelio Content

* Deactivates plugins matching `nelio-content`.
* Scrubs its settings (`nelio-content_settings`) and its cached news (`_transient_nelio_content_news` and its timeout).

### Newsletter

* Deactivates plugins matching `newsletter`.
* Deletes the subscribers (the `newsletter` table).

### Northbeam

* Scrubs the API key and client ID (`northbeam_api_key`, `northbeam_client_id`).

### Offline payment gateways

* When Safety Net deactivates the plugins that register a WooCommerce payment gateway, it does not count gateways that are, or extend, ones that never contact a payment processor: WooCommerce's bank transfer, check and cash on delivery gateways, Pre-Orders' "Pay Later", Bookings' availability check, Account Funds, Germanized's invoice and direct debit, and the card and cash gateways of WCPOS.

### Paid Memberships Pro (PMPro)

* Scrubs all database keys containing API keys for payment gateways, the reCAPTCHA keys and the Mailchimp add-on's settings (`pmpromc_options`).
* Clears the payment gateway (`pmpro_gateway`), sets the gateway environment to `sandbox`, and replaces the last known site URL (`pmpro_last_known_url`).
* Deletes user meta related to PMPro billing, like the billing address or Stripe customer ID.
* Deletes all database entries related to membership orders & subscriptions, including members' levels and coupon usage.
* Disables all cron jobs related to PMPro.
* PMPro itself stays active.

### Passport

* Scrubs the API key and secret (`passport_api_key`, `passport_api_secret`). The plugin stays active.

### Payment gateways

* Scrubs the settings of the Afterpay and WooPayments gateways (`woocommerce_afterpay_settings`, `woocommerce_woocommerce_payments_settings`).

### PayPal

* Deactivates plugins matching `paypal`.
* Blanks the `enabled` flag and the credentials (client IDs and secrets, merchant IDs and emails, keys and the webhook secret) inside the settings of PayPal Payments (`woocommerce_ppcp-gateway_settings`, `woocommerce-ppcp-settings`) and keeps its other settings, because PayPal Payments fatals when its whole settings are blank.
* Scrubs the settings of the PayPal Standard and Braintree gateways (`woocommerce_paypal_settings`, `woocommerce_braintree_paypal_settings`, `woocommerce_braintree_credit_card_settings`).

### Publish to Apple News

* Scrubs the API access settings.
* Disables API sync on WP post status updates.
* Disables the outgoing debugging email.
* Deactivates plugins matching `publish-to-apple-news`.

### ReferralCandy

* Deactivates plugins matching `referralcandy`.
* Scrubs its settings (`woocommerce_referralcandy_settings`).

### ShareASale

* Deactivates plugins matching `shareasale`.
* Scrubs its tracker settings (`shareasale_wc_tracker_options`).

### ShipStation

* Deactivates plugins matching `shipstation`.
* Scrubs the authentication key (`woocommerce_shipstation_auth_key`).

### Stripe

* Deactivates plugins matching `stripe`.
* Blanks the `enabled` flag and the credentials (client IDs and secrets, merchant IDs and emails, keys and the webhook secret) inside the gateway's settings (`woocommerce_stripe_settings`) and keeps its other settings, because the gateway fatals when its whole settings are blank.
* Scrubs `woocommerce_stripe_account_settings` and `woocommerce_stripe_api_settings`.

### TikTok

* Deactivates plugins matching `tiktok`.
* Scrubs its access token, secret, IDs and external data (the eight `tt4b_*` options).

### WooCommerce

* Deactivates these WooCommerce extensions: plugins matching `facebook-for-woocommerce`, `google-listings-and-ads`, `in-stock-mailer-for-wc`, `mailchimp-for-woocommerce`, `pinterest-for-woocommerce`, `woocommerce-amazon-fulfillment`, `woocommerce-google-adwords-conversion`, `woocommerce-payments`, `woocommerce-services`, `woocommerce-shipping/`, `woocommerce-square` and `woocommerce-zapier`. WooCommerce itself stays active.
* Deletes orders and refunds, in both order storages, with their items, addresses, notes and meta, the order statistics and order product lookup tables, the customer lookup table, saved payment tokens, REST API keys, webhooks, download permissions and the download log, customer sessions, and the logs WooCommerce keeps in the database.
* When Scrub Options runs with WooCommerce loaded, usually only from Tools > Safety Net or WP-CLI, it also disables the webhooks through WooCommerce.

### WooCommerce Memberships

* Deletes user memberships (`wc_user_membership` posts) with their meta. Membership plans are kept.

### WooCommerce Subscriptions

* Deletes subscriptions (`shop_subscription` posts) with their meta, and the subscription IDs cached in the user meta that administrators keep (`_wcs_subscription_ids_cache`).
* Deletes the scheduled renewal payment, payment retry and end of prepaid term actions, with their logs.
* While "Pause renewal actions" is on, which it is from the first load until it is turned off in Tools > Safety Net, Action Scheduler does not claim those three kinds of action.

### WP Mail Logging

* Deletes the logged emails (the `wpml_mails` table). The plugin stays active, so it can show the emails the site tries to send.

### WP Remote Users Sync

* Deactivates plugins matching `wp-remote-users-sync`.
* Blanks the encryption keys (`aes_key`, `hmac_key`) inside its settings (`wprus`) and keeps the other settings.

### WPForms

* Deletes form entries with their meta and fields. Forms are kept.

### wpMandrill

* Deactivates plugins matching `wpmandrill`.
* Scrubs its settings (`wpmandrill`).

### Yotpo

* Deactivates plugins matching `yotpo`.
* Scrubs its settings (`yotpo_settings`).

### Zoho Mail

* Deactivates plugins matching `zoho`.
* Scrubs the access and refresh tokens, the authorization code and the client secret (`zmail_access_token`, `zmail_refresh_token`, `zmail_auth_code`, `zmail_integ_client_secret`).
