# Safety Net

**for Team51 Development Sites**

## What's this?
This is a WordPress plugin developed by WordPress.com Special Projects (Team 51) that secures sensitive data on development, staging, and local sites. It anonymizes personally identifiable information as well as prevents sites from acting on real user data (e.g. sending emails, processing renewals, etc.)

## Existing Features
- **Stop Emails**: When Safety Net is activated, WordPress will be blocked from sending emails. (Caution: may not block SMTP or other plugins from doing so). 
- **Disable Action Scheduler**: When Safety Net is activated, the default queue runner for Action Scheduler is unhooked. This means that WooCommerce Subscriptions renewals, for example, will not be triggered at all. 
- **Scrub Options**: Clears specific denylisted options, such as API keys, which could cause problems on a development site.
- **Deactivate Plugins**: Deactivates denylisted plugins. Also, runs through installed Woo payment gateways and deactivates them as well (deactivates the actual plugin, not from the checkout settings).
- **Anonymize**: Replaces all non-admin user data with fake data. Works on the user table, WooCommerce orders and subscriptions. Also detaches individual subscriptions from their payment methods. Runs as a background process to handle large sites.

#### Advanced features
- **Delete Users**: Deletes users from the site. Should be used with caution, and only if Woo payment gateways are deactivated. Runs as a background process to handle large sites.
- **CLI commands**: CLI equivalents of the above two features: `wp safety-net anonymize` and `wp safety-net delete`

## Planned Features
- The ability to pull in the denylisted plugins and options from a location that is more easily editable than a hardcoded array
- Multi-site (WordPress network) compatibility
- Add admin toggle to turn Action Scheduler and/or WP-Cron on and off.
- Do you have a suggestion for the next great feature to add? Please create an issue or submit a PR!

## How to use?
Download the plugin code directly from this repo.

Activating the plugin will:

1. Stop emails. You can still test and view emails by activating the [WP Mail Logging plugin](https://wordpress.org/plugins/wp-mail-logging/). 
2. Deactivate Action Scheduler. If you need to test anything that requires Action Scheduler, you will probably need to deactivate Safety Net.
3. Scrub denylisted options.*
4. Deactivate denylisted plugins.*
5. Anonymize users, orders, and subscriptions.*

* Only runs automatically if `wp_get_environment_type` returns `staging`, `development`, or `local`. If that environment variable is not set for your site, you can also visit **Tools > Safety Net** and manually click the buttons in the Tools section to perform these actions.

