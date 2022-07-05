# Safety Net

**for Team51 Development Sites**

## What's this?
This is a WordPress plugin developed by WordPress.com Special Projects (Team 51) that secures sensitive data on development, staging, and local sites. It anonymizes personally identifiable information as well as prevents sites from acting on real user data (e.g. sending emails, processing renewals, etc.)

## Existing Features
- **Anonymize**: Replaces all non-admin user data with fake data. Works on the user table, WooCommerce orders and subscriptions. Also detaches individual subscriptions from their payment methods. Runs as a background process to handle large sites.
- **Scrub Options**: Clears specific denylisted options, such as API keys, which could cause problems on a development site.
- **Deactivate Plugins**: Deactivates denylisted plugins. Also, runs through installed Woo payment gateways and deactivates them as well (deactivates the actual plugin, not from the checkout settings).
- **Stop Emails**: When Safety Net is activated, WordPress will be blocked from sending emails. (Caution: may not block SMTP or other plugins from doing so)

#### Advanced features
- **Delete Users**: Deletes users from the site. Should be used with caution, and only if Woo payment gateways are deactivated. Runs as a background process to handle large sites.
- **CLI commands**: CLI equivalents of the above two features: `wp safety-net anonymize` and `wp safety-net delete`

## Planned Features
- The ability to pull in the denylisted plugins and options from a location that is more easily editable than a hardcoded array
- Multi-site (WordPress network) compatibility
- Do you have a suggestion for the next great feature to add? Please create an issue or submit a PR!

## How to use?
Download the latest working version of the plugin from https://github.com/a8cteam51/safety-net/releases

To stop emails, simply activate Safety Net from the main plugins menu.

To deactivate plugins, scrub denylisted options, or anonymize users, orders, and subscriptions, visit **Tools > Safety Net** and manually click the buttons in the Tools section.

## Changelog
```
2022.07.05 - version 1.0.0-beta.2
 * Batch processing - Anonymizes users in the background via a batch process

2022.06.29 - version 1.0.0-beta.1
 * First Release
```
