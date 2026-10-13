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
- **Status panel**: Tools > Safety Net starts with a Status table that says, one row per protection, what is on and what is off on this site: the environment type, emails, the email log, users, orders and subscriptions, denylisted options and plugins, payment gateway plugins, WooCommerce Subscriptions renewal actions, WooCommerce webhooks and REST API keys, administrators' application passwords, search engines and transients. Each row has an icon and a state in words, a one-line explanation and, where there is one, a link to where it is changed (see [Status panel](#status-panel)).
- **Stop Emails**: When Safety Net is activated, WordPress will be blocked from sending emails, unless **Send emails** is switched on. (Caution: may not block SMTP or other plugins from doing so. Plugins that Safety Net deactivated, such as MailPoet, can send email or sync data on their own if someone reactivates them; wp-admin then shows an error notice naming them, and the Tools page highlights them.) 
- **Send emails**: An off-by-default switch in **Email Log > Settings** that lets the site send every email to its real recipients, for testing email delivery. Switching it on asks for confirmation, and while it is on every wp-admin page shows an error notice that says how to turn it off, also with `wp option update safety_net_send_emails_toggle off`. It belongs to the site's address, so a copy of the site, or of production after this site is pushed there, starts with emails off. It has no effect while `SAFETY_NET_DELETE_DATA` keeps customer data (see [Sending emails](#sending-emails)).
- **Email Log**: Records the emails Safety Net blocks or lets through, and whether each one would have been sent or was sent, so administrators can read, filter and search them on the **Email Log** page in the main wp-admin menu. Logging and deleting entries after 6 months are both on by default and can be switched off in **Email Log > Settings** (see [Email log](#email-log)).
- **Pause Renewal Actions**: When Safety Net is activated, Action Scheduler will not claim renewal, payment retry or end of prepaid term actions from WooCommerce Subscriptions, effectively pausing them. Other scheduled actions will continue to run. This is toggleable in wp-admin, except while `SAFETY_NET_DELETE_DATA` is `false` (see [Keeping data on purpose](#keeping-data-on-purpose)).
- **Discourage Search Engines**: Sets the "Discourage search engines" option and disallows all user agents in the `robots.txt` file. Also disables Jetpack 'publicize' option.
- **Scrub Options**: Clears specific denylisted options, such as API keys, which could cause problems on a development site.
- **Deactivate Plugins**: Deactivates denylisted plugins. Also deactivates any plugin that registers a WooCommerce payment gateway (deactivates the actual plugin, not from the checkout settings). WooCommerce's built-in gateways and a few offline ones (such as Pre-Orders' "Pay Later" and Bookings' availability check) are left alone. To exclude a plugin from this step, use the `safety_net_payment_gateway_plugins` filter; plugins that also match the denylist (Stripe, PayPal, etc.) additionally need `safety_net_denylisted_plugins`.
- **Delete**: Deletes all non-admin users, WooCommerce orders and subscriptions, and the personal data some plugins keep in their own tables (see [Explanations](#explanations)), unless `SAFETY_NET_DELETE_DATA` is `false` (see [Keeping data on purpose](#keeping-data-on-purpose)).

#### Advanced features
- **CLI commands**: CLI equivalents of the above features: `wp safety-net scrub-options`, `wp safety-net deactivate-plugins`, and `wp safety-net delete`

### Email log

Safety Net records every email it blocks or lets through on the **Email Log** page, a top-level item in the wp-admin menu just below Tools with **All Emails** (the log) and **Settings** under it, which only users who can manage options can see (Tools > Safety Net links to it). Each entry has the date, the To addresses, the subject, the message, the headers the email was sent with, the sender and content type `wp_mail()` would have used (also when a plugin such as WooCommerce sets them only while it sends), the names of the attachments (not the files), its status, which is what Safety Net did with it (blocked it; blocked it because not every recipient was an administrator while `SAFETY_NET_DELETE_DATA` keeps data, see [Keeping data on purpose](#keeping-data-on-purpose); let it through; or let it through because emails are switched on, see [Sending emails](#sending-emails)), and its result:

- **Would send** or **Would fail**, for an email Safety Net blocked: when it is logged, Safety Net checks it the way PHPMailer checks an email before sending it (the recipients, the sender, the message), with a mailer of its own that never sends anything, connects anywhere or runs `phpmailer_init`. It cannot know whether a mail server would have accepted the email, and plugins that change the email in `phpmailer_init` are not taken into account; Safety Net deactivates SMTP plugins anyway. The Error column has PHPMailer's error for an email that would fail, such as "You must provide at least one recipient email address.", and, for one that would send, the recipients, headers and attachments `wp_mail()` would skip because they are invalid or cannot be read.
- **Sent** or **Failed**, for an email Safety Net let through: the real result of `wp_mail()`, with its error when it failed (for example "Could not instantiate mail function." on a host without a mail server). An email that another plugin stops after Safety Net lets it through has no result (—).
- The list shows 20 entries per page, newest first, with links that filter it by result (All, Would send, Would fail, Sent, Failed, each with its count) and a search box that finds text in the recipients, the subject or the message.
- **View** opens an entry in a popup (or on its own page without JavaScript), with three views of the message: **HTML** (or **Text** for a plain-text email), **Raw**, the MIME source rebuilt from the entry the way `wp_mail()` would build it, with the logged date and without the attachments, and **JSON**, the whole entry.
- Two switches on **Email Log > Settings**, both on until someone turns them off: **Log emails**, and **Delete old entries**, which deletes entries older than 6 months once a day. Turning logging off keeps the entries already logged, and they are still deleted after 6 months while the second switch is on. While logging is off, the log says so and links to Settings. Below them, in a section of its own, is **Send emails** (see [Sending emails](#sending-emails)).
- Before a message is saved, the value of every `key=` and `confirm_key=` parameter in its links, such as the key in password reset, new account and privacy request confirmation links, is replaced with `[redacted]`. The subject is saved as it is, and so are other secrets in a message, such as the password in WordPress's multisite welcome emails or passwords some plugins send.
- HTML messages, WooCommerce's multipart emails among them, are shown in a sandboxed frame that runs no scripts and loads no remote images. Raw shows the redacted message too. **Send to me** sends a copy of an entry to your own address only, and only if it is an administrator's, with the logged sender and content type (HTML for multipart emails), the redacted message and no attachments, Cc, Bcc or Reply-To. If a plugin adds another recipient who is not an administrator, the copy is not sent. Entries can be deleted one by one, or all at once with **Clear log**.
- The log is emptied whenever Safety Net deletes users and orders, on the first load, with the Delete button in Tools > Safety Net or with `wp safety-net delete`, because it can hold customers' emails while `SAFETY_NET_DELETE_DATA` keeps data. The keep step leaves it alone.
- Only emails sent through `wp_mail()` are logged, the same ones Safety Net can block.
- The log is the `{prefix}safety_net_email_log` table, one per site on multisite (deleting a site drops its table), created when the first email is logged. When Safety Net is removed, the table stays in the database, and so do the `safety_net_email_log_*` options and the daily `safety_net_email_log_cleanup` WP-Cron event, which then stays scheduled with nothing to run. All of them go with the database when a staging site is pushed to production, where Safety Net does nothing, so the log is never cleaned up there. Clear the log before pushing to production.
- The `safety_net_hide_admin` filter hides the Email Log pages, Settings included, with Tools > Safety Net; emails are still logged and old entries deleted as the switches say.

### Sending emails

**Send emails** in **Email Log > Settings** lets this site send every email WordPress sends to its real recipients: test customers, the site's staff, anyone a form or order emails. It is off until someone turns it on, and is meant only for testing email delivery. The setting is the `safety_net_send_emails_toggle` option, `on` or `off`; a missing option counts as `off`.

- Switching it on in the browser asks for confirmation first; Cancel leaves it off. The settings must then be saved, like the other switches. Switching it off asks nothing.
- While it is on, every email sent through `wp_mail()` goes through, password resets included, and is logged with the status **Let through: emails switched on** and its real result, **Sent** or **Failed**. The PHP error log says `Email sent: <subject> (emails are switched on)`. WordPress's own notifications that a user's password or email address changed are sent too.
- While it is on, every wp-admin page shows an error notice, to users who can manage options, that says emails are switched on and how to turn them off: in **Email Log > Settings** (unless `safety_net_hide_admin` hides it), or with WP-CLI:

  ```bash
  wp option update safety_net_send_emails_toggle off
  ```

  On multisite the switch belongs to one site, so the notice shows on that site's admin pages and its command adds that site's `--url`. The network admin sends its emails, such as the new site and new user notifications, as the main site, so it shows the notice, naming the main site, while the main site's switch is on.

  The block editors (posts and pages, the Site Editor and block widgets) and **WooCommerce > Settings > Payments** hide wp-admin's usual notices, so the notice does not show on those screens. Emails are still sent there; any other admin page shows the notice.
- **Send to me** in the email log still sends a copy to administrators only: a copy that a plugin also sends to someone else is blocked, as when emails are off.
- The switch only lifts Safety Net's own block: an email that another plugin blocks, or that a mailer plugin has already sent, before Safety Net's `pre_wp_mail` filter runs is left as that plugin decided, and is not logged.
- Copies and pushed databases start with emails off. Switching it on, from the Settings page, with WP-CLI or with `update_option()`, also stores which address it was switched on at (`safety_net_send_emails_site`: a hash of the `home` option as stored in the database and of `WP_HOME` when it is defined, each without the scheme, lowercased and without a trailing slash, so that a search-replace on the domain cannot rewrite it to a copy's address). Emails go out only while that matches the site's current address. A copy made from this site, or from production after this site is pushed there, has another address, so its emails stay blocked, no notice is shown, and **Email Log > Settings** shows the switch off with "Switched on for another address (this site is a copy); switch it on again to send emails here." The status panel says the same. Switching it on there binds it to the copy's address. An `on` without a stored address, for example one written straight into the database, counts as off in the same way. Changing the site's address on the same site also turns emails off until the switch is turned on again.
- On such a copy, `wp option update safety_net_send_emails_toggle on` reports that the value is unchanged and changes nothing, because WP-CLI skips a value that equals the stored one. Switch it on in **Email Log > Settings**, or run `wp option update safety_net_send_emails_toggle off` first.
- While `SAFETY_NET_DELETE_DATA` keeps customer data (see [Keeping data on purpose](#keeping-data-on-purpose)), the switch has no effect: it is shown off and disabled with "Unavailable while SAFETY_NET_DELETE_DATA keeps customer data.", saving the Settings page keeps it on if it was switched on at this site's address (and stores it off on a copy), emails are blocked as described there, and no notice is shown. The status panel then says that Send emails is on but held off. Emails also stay blocked from when the constants stop keeping data until the kept data has been deleted, for example in a request that is still running when the `SAFETY_NET_KEEP_UNTIL` date passes.
- `/wp-json/safety-net/v1/status` reports `emails_enabled`: `true` only while the switch is on for this site's address and data is not kept.
- Plugins that send email another way than `wp_mail()` are not affected by the switch; Safety Net neither blocks nor logs them.

### Status panel

Tools > Safety Net starts with a **Status** table, above the tools, so you can see at a glance what Safety Net protects on this site and what it does not. Each row shows an icon with a state in words (screen readers announce OK, Warning or Danger, so the colour is never the only cue), what the state means and, where there is one, a link to where it is changed:

- **Environment type**: the type Safety Net reads, such as `staging`.
- **Emails**: blocked except password resets; switched on, sent to real addresses (Danger); not blocked, when something removed Safety Net's mail filter (Danger); a warning when another plugin replaces `wp_mail()`, naming its file; or, while `SAFETY_NET_DELETE_DATA` keeps data, blocked with password resets only to administrators, noting when Send emails is on but held off. When Send emails is stored on for another address, or without one, the row says so.
- **Email log**: on or off, whether old entries are deleted and how many entries the log holds.
- **Users, orders and subscriptions**: deleted, kept (until the `SAFETY_NET_KEEP_UNTIL` date or with no expiry date) or not deleted yet.
- **Denylisted options**, **Denylisted plugins** and **Transients**: done or not yet; the plugins row names denylisted plugins that are active again. On a multisite subsite, plugins that are network-active again link to the network admin's Plugins page, or, for users who cannot manage network plugins, say that a network administrator must deactivate them.
- **Payment gateway plugins**: deactivated, active again (named), waiting for WooCommerce, no WooCommerce, or not deactivated yet (on a site an older Safety Net processed before it handled gateway plugins, until Deactivate Plugins runs).
- **WooCommerce Subscriptions renewal actions**, only where Action Scheduler runs and something can renew (WooCommerce Subscriptions or WooPayments' subscriptions is active, or renewal actions are pending): paused, held paused while data is kept, or not paused, also when the integration that pauses them is not loaded.
- **WooCommerce webhooks** and **WooCommerce REST API keys**, only where WooCommerce's tables exist: not disabled yet, disabled, or how many webhooks are active again; none, how many keys are not deleted yet, or how many were created after Safety Net ran, which apps and 3PLs can use to read and change orders. Where WordPress offers application passwords (it does over HTTPS, and on `local` sites), the keys row also counts the administrators who have at least one, which apps can still use for the REST API, WooCommerce's included, and warns with a link to Users even when there are no keys. Safety Net keeps administrators and their application passwords. On multisite, the count covers the super admins and the administrators who belong to this site.
- **Application passwords**, on sites without WooCommerce's tables, where WordPress offers them: none, or how many administrators have at least one, with a link to Users, because the REST API stays open to them.
- **Search engines**: discouraged, or a warning when something changed the "Discourage search engines" setting or removed Safety Net's `robots.txt` filter, or when a static `robots.txt` file in the site's folder is served instead of Safety Net's.

On multisite each site's Tools page shows that site's state.

### Keeping data on purpose

Safety Net deletes users, orders and subscriptions on a site's first non-production load. To keep them, for example on a staging copy used to look into a customer's orders, define `SAFETY_NET_DELETE_DATA` as the boolean `false` in the site's `wp-config.php`:

```php
define( 'SAFETY_NET_DELETE_DATA', false );
```

Or, with WP-CLI:

```bash
wp config set SAFETY_NET_DELETE_DATA false --raw --type=constant
```

`--raw` is what makes it the boolean `false`. Without it, WP-CLI writes the string `'false'`, which Safety Net ignores, so the data is deleted.

The whole delete step is skipped, so the personal data other plugins keep in their own tables (see [Explanations](#explanations)) is kept too. There is no expiry date: the data is kept until the constant is removed. To keep it until a date instead, also define `SAFETY_NET_KEEP_UNTIL` as a `YYYY-MM-DD` string. The data is then kept until the end of that day, UTC:

```php
define( 'SAFETY_NET_KEEP_UNTIL', '2026-11-15' );
```

```bash
wp config set SAFETY_NET_KEEP_UNTIL 2026-11-15 --type=constant
```

Leave out `--raw` here, so the date stays a string.

Define the constants before the site's first non-production load: before `WP_ENVIRONMENT_TYPE` is set to a non-production type, before Safety Net is activated, and before any `wp` command that loads WordPress (`wp config set` itself does not). Data that was already deleted does not come back.

While data is kept:

- The other steps still run: options are scrubbed, denylisted and payment gateway plugins deactivated, transients deleted, WooCommerce webhooks disabled, renewals paused, emails blocked and search engines discouraged.
- A keep step runs instead of the delete step, once per site. It deletes the `{option}_sn_backup` options, the copies scrubbing makes of the original values, so live credentials cannot be restored next to real customer data. Scrubbing, also from Tools > Safety Net or WP-CLI, makes no new backups.
- The keep step also deletes saved payment methods and WooCommerce REST API keys, which could still charge customers or reach the store from outside. In MailPoet, it empties the sending tasks and queues and sets scheduled emails back to drafts. If MailPoet is reactivated, its sending stays paused, also after Resume, because some of its sending methods do not go through `wp_mail()`; MailPoet shows why.
- Renewal actions stay paused even if "Pause renewal actions" was turned off in Tools > Safety Net, where it shows as checked and cannot be changed. WooCommerce Subscriptions also stays in its staging mode, which turns off automatic payments, even if someone clicks its "Enable automatic payments" button.
- Accounts copied from the live site, that is every account that existed when the keep step ran, cannot log in unless they are administrators. Shop managers, editors and customers are all refused, whether they use a password, a login cookie or an application password. Accounts created on the copy afterwards can log in, so use those to test as a customer.
- Password Reset emails, which Safety Net otherwise lets through, are only sent when every recipient is an administrator. **Send emails** in Email Log > Settings is held off: it is shown disabled, and a stored `on` changes nothing while the constants keep data.
- Users who can manage options see a wp-admin notice saying that the data is kept, until when, and what stops the copy from acting on it. The status route, `/wp-json/safety-net/v1/status`, reports `data_kept` (the keep step ran on this site), `data_deletion_disabled` (the constants currently stop the automatic run from deleting data; `data_deleted` still says whether it was deleted, for example from Tools > Safety Net), `keep_until` (the date, or `null`) and `emails_enabled` (always `false` while data is kept).
- The Delete button in Tools > Safety Net and `wp safety-net delete` still delete everything.

Once `SAFETY_NET_DELETE_DATA` is removed, or the `SAFETY_NET_KEEP_UNTIL` date has passed, the data is deleted the next time the site loads, in a browser or with a `wp` command.

Only the boolean `false` and a real date count. Safety Net ignores `SAFETY_NET_DELETE_DATA` when it has any other value, such as `'false'`, `0`, `''` or `true`, or when `SAFETY_NET_KEEP_UNTIL` is not a real `YYYY-MM-DD` date, and deletes the data; the wp-admin notice and the PHP error log say why. `SAFETY_NET_KEEP_UNTIL` does nothing unless `SAFETY_NET_DELETE_DATA` is `false`. A date that has already passed when the site first loads keeps nothing; the notice and the error log say so.

On multisite, `wp-config.php` applies to the whole network, so every site keeps its data, including sites created later. Each site runs its own keep step and has its own notice and status. Users are shared by the whole network, so Tools > Safety Net > Delete or `wp safety-net delete` on any one site deletes the customers of every site, while the other sites still report their data as kept.

On production the constants change nothing, but the production notice adds a line while `SAFETY_NET_DELETE_DATA` is `false` (and `SAFETY_NET_KEEP_UNTIL`, if set, has not passed), because non-production copies that inherit that `wp-config.php` keep their data.

#### Skipping GiveWP Data Deletion

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
3. Delete users, orders, and subscriptions.* (skipped while `SAFETY_NET_DELETE_DATA` is `false`, see [Keeping data on purpose](#keeping-data-on-purpose))
4. Stop emails, unless **Send emails** is switched on (see [Sending emails](#sending-emails)), and log them on the Email Log page (see [Email log](#email-log)). You can also test and view emails by activating the [WP Mail Logging plugin](https://wordpress.org/plugins/wp-mail-logging/). 
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
To keep users, orders and subscriptions, define `SAFETY_NET_DELETE_DATA` as `false` (see [Keeping data on purpose](#keeping-data-on-purpose)). There is deliberately no constant for the scrub and deactivate steps, since skipping them is what lets a copy charge customers or sync with third parties. To skip steps anyway, you can comment them out in `includes/bootstrap.php`; the change is lost when Safety Net is updated:
```php
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_scrub_options' );
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_deactivate_plugins' );
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_delete_data' );
```
Each step only runs after the one before it, so comment them out from the bottom up: the last one, the last two, or all three. Transients are then still deleted, WooCommerce webhooks disabled, renewals paused and emails blocked, and the skipped steps can still be run, in order, from Tools > Safety Net or WP-CLI. Commenting out only the second one, on a site whose plugins step never ran, means data is not deleted until that step is run from Tools > Safety Net or WP-CLI, and until then every page load logs that the deletion is postponed. Commenting out only the first one, on a site whose options were never scrubbed, stops every request, wp-admin and WP-CLI included, with "Safety Net Error: options need to be scrubbed first.", so nothing else runs; restore the line to recover.

## Explanations

What Safety Net does for each plugin it knows about is declared in one file per plugin in `includes/integrations/`, and `wp safety-net integrations` lists those declarations on a site. Plugins that Safety Net only deactivates are listed in `assets/data/plugin_denylist.txt` instead. Below, "plugins matching `stripe`" means every plugin whose path (`folder/file.php`) contains `stripe`, ignoring case. Scrubbed options are blanked, or set to the value given, after their value is copied to `{option}_sn_backup`, unless noted otherwise or `SAFETY_NET_DELETE_DATA` is `false`.

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
* While `SAFETY_NET_DELETE_DATA` is `false`, subscribers, their statistics and automation runs are kept, but the keep step empties the sending tasks and queues (`mailpoet_scheduled_tasks`, `mailpoet_scheduled_task_subscribers`, `mailpoet_sending_queues`), turns scheduled and sending newsletters into drafts and cancels the pending automation steps, so a reactivated MailPoet has nothing queued to send. MailPoet is still deactivated and its keys scrubbed. If it is reactivated, Safety Net pauses its sending on every request, the way MailPoet pauses after a sending error, so MailPoet's Resume and a reactivation do not bring sending back until the data is deleted, when Safety Net lifts its pause; preview and test emails an administrator sends, and MailPoet's notices to the site owner, are not paused.

MailPoet sends email itself, not through `wp_mail()`, so Safety Net's email blocking does not cover a reactivated MailPoet, except for the pause above while data is kept.

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
* While `SAFETY_NET_DELETE_DATA` is `false` (see [Keeping data on purpose](#keeping-data-on-purpose)), orders and customers are kept, but the keep step still deletes the saved payment tokens (`woocommerce_payment_tokens`, `woocommerce_payment_tokenmeta`) and the REST API keys (`woocommerce_api_keys`), and the webhooks are still disabled.

### WooCommerce Memberships

* Deletes user memberships (`wc_user_membership` posts) with their meta. Membership plans are kept.

### WooCommerce Subscriptions

* Deletes subscriptions (`shop_subscription` posts) with their meta, and the subscription IDs cached in the user meta that administrators keep (`_wcs_subscription_ids_cache`).
* Deletes the scheduled renewal payment, payment retry and end of prepaid term actions, with their logs.
* While "Pause renewal actions" is on, which it is from the first load until it is turned off in Tools > Safety Net, Action Scheduler does not claim those three kinds of action.
* While `SAFETY_NET_DELETE_DATA` is `false`, subscriptions and these actions are kept, the pause stays on even if it was turned off, and WooCommerce Subscriptions stays in its staging mode (`woocommerce_subscriptions_is_duplicate_site` returns `true`), which locks subscriptions to manual renewal so nothing is charged automatically, even after someone clicks its "Enable automatic payments" button.

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

### Xero

* Deactivates plugins matching `-xero`: WooCommerce Xero and most plugins on WordPress.org that sync a store to Xero, though not Xelation or Data Sync for Xero by Wbsync. Plugins that only have "xero" in their name, such as Xero Addons for Elementor, stay active. Only WooCommerce Xero's credentials are scrubbed.
* Scrubs the Xero app's client ID and secret (`wc_xero_client_id`, `wc_xero_client_secret`).
* Deletes WooCommerce Xero's access token, refresh token and Xero organisation ID (`xero_oauth_options`) without a backup, and any backup an earlier run left. Xero replaces the refresh token each time it is used, so a staging site that used its copy would disconnect the live store from Xero.
* Cancels the pending scheduled actions that would send an invoice or a payment to Xero or void an invoice there (hooks starting with `woocommerce_xero_`).
* Deletes WooCommerce Xero's log files (`uploads/wc-logs/xero-*.log`) when options are scrubbed, so also while `SAFETY_NET_DELETE_DATA` keeps data: with its debug logging filter on, versions before 1.9.15 wrote the tokens to them in plain text.
* The connection is not removed in Xero, because the live store shares it. Orders keep their Xero invoice and payment IDs, which nothing can use once the tokens are gone.
* If a copy used the token before Safety Net ran, the live store shows "Cannot request the access token, please connect your application again!" and has to be connected to Xero again.
* Staging sites that an earlier version of Safety Net already processed keep WooCommerce Xero active, with its tokens, until Scrub Options and Deactivate Plugins run again, from Tools > Safety Net or with `wp safety-net scrub-options` and `wp safety-net deactivate-plugins`. Do not open WooCommerce > Xero on such a site first: loading that page can refresh the token.

### Yotpo

* Deactivates plugins matching `yotpo`.
* Scrubs its settings (`yotpo_settings`).

### Zoho Mail

* Deactivates plugins matching `zoho`.
* Scrubs the access and refresh tokens, the authorization code and the client secret (`zmail_access_token`, `zmail_refresh_token`, `zmail_auth_code`, `zmail_integ_client_secret`).
