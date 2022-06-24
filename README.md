# Safety Net

**for Team51 Development Sites**

## What's this?
This is a WordPress plugin developed by WordPress.com Special Projcts (Team 51) that secures sensitive data on development, staging, and local sites. It anonymizes personally identifiable information as well as prevents sites from acting on real user data (e.g. sending emails, processing renewals, etc.)


## Existing Features
- **Anonymize**: Manual button which replaces all non-admin user data with fake data. Works on the user table, WooCommerce orders and subscriptions. Also detaches individual subscriptions from their payment methods. Runs as a background process to handle large sites.
- **Delete Users**: Manual button which deletes users from the site. Should be used with caution, and only if Woo payment gateways are deactivated. Runs as a background process to handle large sites.
- Adds WP **CLI commands** to anonymize or delete all non-admin users: `wp safety-net anonymize` and `wp safety-net delete`
- **Stop Emails**: When Safety Net is activated, WordPress will be blocked from sending emails. (Caution: may not block SMTP or other plugins from doing so)
- **Deactivate plugins**: When activating Safety Net, it will deactivate a list of problem plugins. Also, runs through installed Woo payment gateways and deactivates them as well (deactivates the actual plugin, not from the checkout settings). Before deactivating any plugins, it runs through a list of options and scrubs them from the options table, such as API keys that have caused problems in the past. 

## Planned Features
- The ability to pull in the blacklisted plugins and options dynamically from a Google sheet, so that TAMs can update the lists without the use of code.
- Multi-site (WordPress network) compatibility
- Do you have a suggestion for the next great feature to add? Please create an issue or submit a PR!

## How to use?
Download the latest working version of the plugin from https://github.com/a8cteam51/safety-net/releases

To deactivate plugins and scrub related options, and stop emails, simply activate Safety Net from the main plugins menu.

To anonymize users, orders and subscriptions, visit **Tools > Safety Net** and manually click the **Anonymize** button. 

Note: If you're cloning the repo directly, you'll have to run `composer install` from the plugin folder to get the faker library added, so you can run it locally.
