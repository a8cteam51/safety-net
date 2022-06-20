# Safety Net
**for Team51 Development Sites**

## What's this?
This is a plugin by Team 51 that secures sensitive data on development, staging, and local sites. It anonymizes personally identifiable information as well as prevents sites from acting on user data (e.g. sending emails, processing renewals, etc.)

## Existing Features
- Anonymize Users: manual button that replaces user table fields such as name and email address with randomly generated fakes
- Anonymize Orders (and subscriptions): replaces user information in WooCommerce orders (and subscriptions) with randomly generated fakes. Also detaches individual subscriptions from their payment gateways.
- Adds WP CLI commands to anonymize or delete all non-admin users: `wp safety-net anonymize` and `wp safety-net delete`

## Planned Features
- Deactivate plugins: on activation, this plugins will deactivate all blacklisted plugins, WooCommerce payment gateways, and SMTP plugins
- Stop emails: will stop WP_Mail from sending emails
- Cheese grater: will integrate with the grater-3000 API to randomly grate some parmesan cheese for you

## How to use?
Download the latest working version of the plugin from https://github.com/a8cteam51/safety-net/releases

To anonymize users, orders and subscriptions, visit the settings page for this plugin and manually click the buttons to do so. In the future, there will be features which automatically run on activation, or will be active when the plugin is active.

Note: If you're cloning the repo directly, you'll have to run `composer install` from the plugin folder to get the faker library added, so you can run it locally.
