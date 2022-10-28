| :exclamation:  This is a public repository |
|--------------------------------------------|

# Safety Net

**for Team51 Development Sites**

## What's this?
This is a WordPress plugin developed by WordPress.com Special Projects (Team 51) that secures sensitive data on development, staging, and local sites. It deletes users and WooCommerce orders and subscriptions, as well as prevents sites from acting on user data (e.g. sending emails, processing renewals, etc.)

## Disclaimer
This public plugin is provided as an example of how such a plugin could be implemented, and is provided without any support or guarantees. Please use at your own discretion. Incorrect usage could result in data deletion.

## Existing Features
- **Stop Emails**: When Safety Net is activated, WordPress will be blocked from sending emails. (Caution: may not block SMTP or other plugins from doing so). 
- **Pause Renewal Actions**: When Safety Net is activated, Action Scheduler will not claim renewal actions or payment retry actions from WooCommerce Subscriptions, effectively pausing them. Other scheduled actions will continue to run.
- **Discourage Search Engines**: Sets the "Discourage search engines" option and disallows all user agents in the `robots.txt` file. Also disables Jetpack 'publicize' option.
- **Scrub Options**: Clears specific denylisted options, such as API keys, which could cause problems on a development site.
- **Deactivate Plugins**: Deactivates denylisted plugins. Also, runs through installed Woo payment gateways and deactivates them as well (deactivates the actual plugin, not from the checkout settings).
- **Delete**: Deletes all non-admin users, WooCommerce orders and subscriptions.
- **Anonymize**: Replaces all non-admin user data with fake data. Works on the user table, WooCommerce orders and subscriptions. Also detaches individual subscriptions from their payment methods. Runs as a background process to handle large sites.

#### Advanced features
- **CLI commands**: CLI equivalents of the above features: `wp safety-net scrub-options`, `wp safety-net deactivate-plugins`, `wp safety-net anonymize` and `wp safety-net delete`

## Planned Features
- Multi-site (WordPress network) compatibility
- Add admin toggle to turn Action Scheduler and/or WP-Cron on and off.
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

*Only runs automatically if `wp_get_environment_type` returns `staging`, `development`, or `local`. If that environment variable is not set for your site, you can also visit **Tools > Safety Net** and manually click the buttons in the Tools section to perform these actions.

## How to add plugins or options to the denylists
These denylists are `txt` files that live in the `assets/data/` folder. Each plugin or option is on its own line. 

You may also:
- Create a new issue or dev request to have a plugin or option added to the denylists, or
- Submit a PR to add something yourself, and let us know so we can merge it

## Troubleshooting

### Plugin not running
For Safety Net to run - and to access the tools page - the environment type needs to be set as `staging`, `development`, or `local`. The type can be set via the `WP_ENVIRONMENT_TYPE` global system variable, or a constant of the same name.

One way to do that is to edit your `wp-config.php` file, and add `define('WP_ENVIRONMENT_TYPE', 'development');`

Or, if you have access to WP-CLI, you can SSH in and run `wp config set WP_ENVIRONMENT_TYPE staging --type=constant`

### Plugin won't activate
It's possible that there is another copy of the plugin active on the site. Check in the `mu-plugins` folder.

### I don't want the functions to automatically run on my non-production site
You'll need to go into the `includes/bootstrap.php` file and comment out whichever of these 3 functions you don't want to run:
```php
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_scrub_options' );
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_deactivate_plugins' );
add_action( 'safety_net_loaded', __NAMESPACE__ . '\maybe_delete_data' )
```
