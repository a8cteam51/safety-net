# Safety Net
<img align="right" width="200" height="200" src="safety-net.png">
**for Team51 Development Sites**

## What's this?
This is a plugin by Team 51 that secures sensitive data on development, staging, and local sites. It anonymizes personally identifiable information as well as prevents sites from acting on real user data (e.g. sending emails, processing renewals, etc.)


## Existing Features
- Anonymize: Manual button which replaces all non-admin user data with fake data. Works on the user table, WooCommerce orders and subscriptions. Also detaches individual subscriptions from their payment methods.
- Delete Users: Manual button which deletes users from the site. Should be used with caution, and only if Woo payment gateways are deactivated.
- Adds WP CLI commands to anonymize or delete all non-admin users: `wp safety-net anonymize` and `wp safety-net delete`
- Stop Emails: When Safety Net is activated, WordPress will be blocked from sending emails. (Caution: may not block SMTP or other plugins from doing so)

## Planned Features
- Deactivate plugins and payment gateways: on activation, the Safety Net plugin will deactivate all blacklisted plugins, all WooCommerce payment gateways, and any SMTP plugins
- Cheese grater: will integrate with the grater-3000 API to randomly grate some parmesan cheese for you

## How to use?
Download the latest working version of the plugin from https://github.com/a8cteam51/safety-net/releases

To anonymize users, orders and subscriptions, visit the settings page for this plugin and manually click the buttons to do so. In the future, there will be features which automatically run on activation, or will be active when the plugin is active.

Note: If you're cloning the repo directly, you'll have to run `composer install` from the plugin folder to get the faker library added, so you can run it locally.
