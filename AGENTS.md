# Safety Net — Agent Instructions

This document helps coding agents work autonomously on the Safety Net WordPress plugin. Follow these instructions when making changes.

## Project Overview

Safety Net is a WordPress plugin by WordPress.com Special Projects (Team 51) that secures sensitive data on development, staging, and local sites. It:

- Deletes non-admin users, WooCommerce orders, subscriptions, and related data
- Scrubs denylisted options (API keys, secrets)
- Deactivates denylisted plugins and WooCommerce payment gateways
- Blocks outgoing emails
- Pauses WooCommerce Subscriptions renewal actions (toggleable)
- Discourages search engines and disallows all user agents in `robots.txt`
- Disables WooCommerce webhooks
- Optionally generates themed mock data (blog posts, and WooCommerce products/customers/orders) from JSON fixtures — opt-in only, never automatic

**CRITICAL**: The plugin MUST NOT run on production. It checks `WP_ENVIRONMENT_TYPE`; if set to `production`, it shows a notice and does nothing else. It only runs when the environment is `staging`, `development`, or `local`.

---

## Tech Stack

| Component | Details |
|----------|---------|
| Language | PHP 7.4+ |
| Platform | WordPress (plugin) |
| Dependencies | None (no Composer, npm, or package managers) |
| Build tools | None—plugin is deployed as source |
| WP-CLI | Supported; commands registered when `WP_CLI` is defined |
| Coding standards | WordPress PHP Coding Standards (phpcs) |

---

## Directory Structure

```
safety-net/
├── safety-net.php          # Main plugin entry point
├── includes/
│   ├── bootstrap.php       # Registers maybe_* actions on safety_net_loaded
│   ├── admin.php           # Admin UI, AJAX handlers, email blocking
│   ├── common.php          # Filters: disable emails, Jetpack, robots.txt
│   ├── utilities.php       # get_admin_user_ids, get_environment_type, get_denylist_array, is_production
│   ├── scrub-options.php   # Scrubs options from option_scrublist.txt
│   ├── deactivate-plugins.php
│   ├── delete.php          # delete_users_and_orders
│   ├── delete-transients.php
│   ├── disable-webhooks.php
│   ├── generate-mock-data.php  # Generates mock data from JSON fixtures (opt-in)
│   └── classes/
│       ├── cli/class-safetynet-cli.php
│       └── class-actionscheduler-custom-dbstore.php
├── assets/
│   ├── css/admin.css
│   ├── js/safety-net-admin.js
│   └── data/
│       ├── option_scrublist.txt   # Options to scrub (one per line)
│       ├── plugin_denylist.txt    # Plugin slugs/partials to deactivate (one per line)
│       └── mock/                  # Mock data fixtures (posts.json, products.json, customers.json)
└── .github/workflows/release.yml   # Creates zip on release
```

---

## Commands

### Build / Install

There is no build step. The plugin is pure PHP and JavaScript/CSS. To use:

1. Copy the plugin into `wp-content/plugins/safety-net/` or install via the release zip.
2. Ensure `WP_ENVIRONMENT_TYPE` is `staging`, `development`, or `local` (not `production`).
3. Activate the plugin.

### Lint

The project uses WordPress PHP Coding Standards. Run:

```bash
phpcs --standard=WordPress .
```

Or with a typical WordPress setup:

```bash
composer require --dev wp-coding-standards/wpcs
vendor/bin/phpcs --standard=WordPress .
```

Use `phpcs:ignore` comments sparingly; they exist in the codebase for cases where the standard cannot be satisfied safely (e.g. direct DB updates, nonce checks in known contexts).

### Tests

TBD

### Release

1. Create a GitHub release (tag + release notes).
2. The `.github/workflows/release.yml` workflow runs on release creation and builds a zip via `git archive`.
3. The zip excludes paths in `.gitattributes` (e.g. `.git`, `.github`, `README.md`, hidden files).

---

## Conventions

### Code Style

- Follow WordPress PHP Coding Standards.
- Use namespaces: `SafetyNet\Bootstrap`, `SafetyNet\Admin`, `SafetyNet\Utilities`, etc.
- Functions in `utilities.php` live in `SafetyNet\Utilities` namespace.
- Prefix option names with `safety_net_` (e.g. `safety_net_options_scrubbed`, `safety_net_pause_renewal_actions_toggle`).

### Commit / PR / Branch Naming

- Use clear, descriptive commit messages.
- Branch names: `fix/...`, `feature/...`, or similar.
- PR descriptions should explain the change and any environment-specific notes.

### Denylists

- Denylists live in `assets/data/`:
  - `option_scrublist.txt` — WordPress option names to scrub
  - `plugin_denylist.txt` — plugin slugs or partial matches (e.g. `paypal` matches any plugin with `paypal` in the path)
- One entry per line.
- To add entries: create an issue, submit a PR, or use filters (see Extensibility).

### Version Bumping

- Update the plugin version in the header of `safety-net.php` when releasing.

---

## Architectural Decisions

1. **Execution order is fixed**  
   Options must be scrubbed first, then plugins deactivated, then data deleted. Each step checks for the previous step’s completion via options (`safety_net_options_scrubbed`, `safety_net_plugins_deactivated`, etc.). Do not change this order.

2. **Direct DB updates for scrubbing**  
   Scrub operations use `$wpdb->update` (e.g. `safety_net_update_option_direct`) to avoid `update_option` and its hooks (e.g. notifications). This is intentional.

3. **Action Scheduler customization**  
   When “Pause renewal actions” is on, the plugin replaces Action Scheduler’s store class with `SafetyNet\ActionScheduler_Custom_DBStore` to skip claiming renewal/payment-retry actions. Other actions still run.

4. **Environment detection**  
   `get_environment_type()` in `utilities.php` supports `sandbox`, `dev`, `develop` in addition to `staging`, `development`, `local` for hosts like Pressable and WPCOM Studio.

5. **Duplicate plugin prevention**  
   If `SAFETY_NET_PATH` is already defined, the plugin exits early to avoid running twice (e.g. in both `plugins` and `mu-plugins`).

---

## Extensibility

Key filters and hooks:

| Filter / Hook | Purpose |
|---------------|---------|
| `safety_net_show_production_notice` | Return `false` to hide the production notice (use with care) |
| `safety_net_denylisted_plugins` | Add plugins to the denylist for the current site |
| `safety_net_options_to_clear` | Modify options to scrub |
| `safety_net_hide_admin` | Return `true` to hide the Tools > Safety Net admin page |
| `safety_net_loaded` | Fired when the plugin is ready |
| `safety_net_scrub_options` | Fired to scrub options |
| `safety_net_deactivate_plugins` | Fired to deactivate plugins |
| `safety_net_delete_data` | Fired to delete users and orders |
| `safety_net_delete_transients` | Fired to delete transients |
| `safety_net_disable_webhooks` | Fired to disable webhooks |
| `safety_net_generate_mock_data` | Fired to generate mock data (optional arg: array of types) |
| `safety_net_mock_data_counts` | Filter the number of records generated per type |

Constant:

- `SAFETY_NET_SKIP_GIVEWP` — When `true`, skip GiveWP data during deletion.

---

## Common Pitfalls

1. **Do not edit WordPress core**  
   Only modify plugin files. Never change core WordPress or WooCommerce files.

2. **Do not run on production**  
   The plugin intentionally does nothing on production. Do not add behavior that bypasses `is_production()`.

3. **Do not change execution order**  
   Scrubbing → deactivating plugins → deleting data must stay in that order. The code enforces this with option checks.

4. **Do not remove `ABSPATH` check**  
   The main plugin file MUST guard with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.

5. **Denylist format**  
   - Options: exact option name (e.g. `jetpack_active_modules`).
   - Plugins: slug or partial match (e.g. `paypal` matches `woocommerce-paypal-payments/woocommerce-paypal-payments.php`).

6. **Atomic / WP.com staging**  
   On Atomic sites (`jetpack_is_atomic_site()` or `wpcomstaging.com`), `jetpack_private_options` and `jetpack_secrets` are NOT scrubbed to avoid disconnecting Jetpack.

7. **Duplicate plugin**  
   If activation fails, check for another copy in `mu-plugins`; the plugin exits when `SAFETY_NET_PATH` is already defined.

---

## WP-CLI Commands

Requires WP-CLI and a non-production environment:

```bash
wp safety-net scrub-options      # Scrub denylisted options
wp safety-net deactivate-plugins # Deactivate denylisted plugins
wp safety-net delete             # Delete users and orders
wp safety-net delete-transients  # Delete transients
wp safety-net disable-webhooks   # Disable WooCommerce webhooks
wp safety-net generate-mock-data # Generate mock data (optional: --types=posts,products,customers,orders)
```

---

## Where to Find More

- **User-facing docs**: `README.md`
- **Plugin behavior**: `includes/bootstrap.php`, `includes/admin.php`
- **Data deletion logic**: `includes/delete.php`
- **Environment detection**: `includes/utilities.php` (`get_environment_type`, `is_production`)
