# Safety Net — Agent Instructions

This document helps coding agents work autonomously on the Safety Net WordPress plugin. Follow these instructions when making changes.

## Project Overview

Safety Net is a WordPress plugin by WordPress.com Special Projects that secures sensitive data on development, staging, and local sites. It:

- Deletes non-admin users, WooCommerce orders, subscriptions, and related data, plus the personal data some plugins keep in their own tables (e.g. MailPoet subscribers and their activity)
- Scrubs denylisted options (API keys, secrets), deletes AI provider credentials, and scrubs MailPoet's service keys, mail credentials and sender addresses
- Deactivates denylisted plugins and WooCommerce payment gateways
- Blocks outgoing emails
- Pauses WooCommerce Subscriptions renewal actions (toggleable)
- Discourages search engines and disallows all user agents in `robots.txt`
- Disables WooCommerce webhooks

**CRITICAL**: The plugin MUST NOT run on production. It checks `WP_ENVIRONMENT_TYPE`; if set to `production`, it shows a notice and does nothing else. It only runs when the environment is `staging`, `development`, or `local`.

---

## Tech Stack

| Component | Details |
|----------|---------|
| Language | PHP 8.1+ |
| Platform | WordPress (plugin) |
| Dependencies | None at runtime. Dev-only npm packages (`@wp-playground/cli` and `@wp-playground/blueprints`) run the tests and never ship |
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
│   ├── common.php          # Filters: disable emails, robots.txt
│   ├── utilities.php       # get_admin_user_ids, get_environment_type, get_denylist_array, is_production, get_keep_config
│   ├── scrub-options.php   # Scrubs the options in option_scrublist.txt and those the integrations declare
│   ├── integrations.php    # Integrations registry: loads includes/integrations/*.php, validates, run_phase()
│   ├── integrations/       # One file per third-party plugin, e.g. ai-connectors.php, mailpoet.php
│   ├── self-update.php     # Offers GitHub releases as plugin updates (loads on production too)
│   ├── deactivate-plugins.php
│   ├── delete.php          # delete_users_and_orders, keep_data
│   ├── delete-transients.php
│   ├── disable-webhooks.php
│   └── classes/
│       ├── cli/class-safetynet-cli.php
│       ├── class-actionscheduler-custom-dbstore.php
│       └── class-integration.php   # SafetyNet\Integrations\Integration: one plugin's declaration
├── assets/
│   ├── css/admin.css
│   ├── js/safety-net-admin.js
│   └── data/
│       ├── option_scrublist.txt   # Options to scrub (one per line)
│       └── plugin_denylist.txt    # Plugin slugs/partials to deactivate (one per line)
├── tests/smoke/            # Smoke tests (not shipped); see Tests below
├── package.json            # Pins @wp-playground/cli and @wp-playground/blueprints, same version, for the tests (not shipped)
├── phpcs.xml.dist          # phpcs ruleset: WordPress standard on safety-net.php, includes/ and assets/ (not shipped)
└── .github/workflows/
    ├── tests.yml           # Lint, release-zip check and smoke tests
    └── release.yml         # Runs tests.yml, then creates the zip on release
```

---

## Architecture

Safety Net is a fixed pipeline of steps plus a registry of integrations. The pipeline decides when each step runs, in which order and how often; an integration only declares what the steps do for one third-party plugin.

### Pipeline

- `safety-net.php` returns early when another copy already loaded (`SAFETY_NET_PATH`) or WordPress is still installing, loads `utilities.php` and `self-update.php`, and on production only adds the notice and stops. Otherwise it loads `integrations.php`, the integration files and their `hooks` phase, then the step files, the REST route and the WP-CLI command, and fires `safety_net_loaded`. That happens while WordPress is still including plugins: as a regular plugin, before every plugin that sorts after it, WooCommerce among them; as an mu-plugin, before every regular plugin.
- `bootstrap.php` runs on `safety_net_loaded`, in this order: the renewal pause toggle, scrub (`safety_net_scrub_options`), deactivate (`safety_net_deactivate_plugins`), delete (`safety_net_delete_data`), transients and webhooks. Each step runs once per site and records it in its flag option; the deactivate step refuses to run before the scrub, and the delete step before the deactivate step. While `SAFETY_NET_DELETE_DATA` is `false` and `SAFETY_NET_KEEP_UNTIL`, if set, has not passed (`get_keep_config()` in `utilities.php`), the delete step runs the keep step (`safety_net_keep_data`, flag `safety_net_data_kept`) instead, and `stop_emails()` lets a Password Reset through only when every recipient is an administrator. When the kept data is deleted later, automatically or from the Tools page or WP-CLI, both flags stay set. On `wp_loaded` it runs the payment gateway pass (once WooCommerce is loaded) and then the integrations' `late` phase.
- The Tools page buttons and the WP-CLI commands call the step functions directly: they skip the run-once check but keep the order checks.

How the steps use the registry (`includes/integrations.php`):

| Step | File | Reads | Then |
|------|------|-------|------|
| Scrub | `scrub-options.php` | `options_to_clear()` (option_scrublist.txt plus every integration's option fields and the stored options under its `delete_option_prefixes`), passed through `safety_net_options_to_clear`; `option_treatment()` picks blank, partial, value, delete or delete_partial per option; `cancel_action_scheduler_hooks` | `run_phase( 'scrub' )` |
| Deactivate | `deactivate-plugins.php` | `plugin_patterns()` (plugin_denylist.txt plus every integration's `plugins`), passed through `safety_net_denylisted_plugins` | |
| Gateway pass | `deactivate-plugins.php`, `utilities.php` | `offline_gateways()` | |
| Delete | `delete.php` | `tables`, `network_tables`, `post_types`, `comment_types`, `usermeta`, `action_scheduler_hooks`, `upload_globs` | `run_phase( 'delete' )`, then posts are reassigned to an administrator and the other users deleted. While data is kept, the keep step (`keep_data()`) runs instead: it reads none of these fields, deletes every `{option}_sn_backup` option (the scrub writes none meanwhile) and runs `run_phase( 'keep' )` |
| Transients, webhooks | `delete-transients.php`, `disable-webhooks.php` | nothing | |

The pipeline keeps core WordPress behaviour and shared machinery: the admin email, `default_pingback_flag` and `_pingme`, email blocking, search engines and `robots.txt`, transients, users and post authors, the Atomic exception for Jetpack's secrets, the Jetpack Autoloader workaround (`keep_in_jetpack_autoloader()`), gateway tracing and the multisite network flags, plus the webhooks step, which disables the rows of WooCommerce's `wc_webhooks` table.

### Integrations

- One file per plugin, `includes/integrations/<slug>.php`, adds one `SafetyNet\Integrations\Integration` to the `safety_net/integrations` filter at `BUILT_IN_PRIORITY`. The slug is the file name (`a-z`, `0-9` and hyphens). The constructor's docblock in `includes/classes/class-integration.php` describes every field. Files load in byte order of their names; names starting with `_` or `.` are skipped.
- Prefer the data fields; use a closure (a phase, or a closure as a `partial_options` value, which gets the option's array value after the backup and returns the new one) only for what data cannot express.
- `get_integrations()` collects the declarations once per request, when the `hooks` phase runs as Safety Net loads; declarations added later are ignored for the rest of that request, also by the Tools page, WP-CLI and `late`. Invalid declarations are logged (`Safety Net: ignoring integration …`) and skipped, and so is one that declares an option or plugin pattern an earlier one already covers, including an option under another integration's `delete_option_prefixes`. Safety Net's own declarations come first, so a site's overlapping declaration is the one skipped. A declaration built with the wrong types, or a closure that throws, stops the request with a PHP error on purpose, before the step's flag is set, rather than silently leaving that plugin's data.
- `assets/data/plugin_denylist.txt` keeps the plugins that are only deactivated; a plugin with an integration lists its patterns in `plugins` instead. `option_scrublist.txt` is empty: a known plugin's options belong in its integration. The steps still read both files, and `safety_net_options_to_clear` and `safety_net_denylisted_plugins` receive the files' entries together with the integrations'.
- Other code can add declarations only from an mu-plugin that loads before Safety Net; when Safety Net itself runs from `mu-plugins/`, from one whose file name sorts before the file that loads Safety Net.

### Phases and timing

- `hooks` runs as Safety Net loads on every non-production request, before the steps: always-on filters, such as PMPro's cron jobs, Jetpack's subscription emails and the paused Action Scheduler store.
- `scrub` and `delete` run inside their steps, after the declared data has been handled. The automatic pass runs before most plugins are loaded, so these closures, and closures in `partial_options`, use plain SQL through `$wpdb` and may call another plugin's function or class only behind `function_exists()` or `class_exists()` (as in `pmpro.php`, and in `woocommerce.php`, whose webhook pass usually finds WooCommerce loaded only when the scrub runs from the Tools page or WP-CLI).
- `keep` runs inside the keep step, instead of `delete`, only while data is kept, after the option backups are deleted. Its closures remove what could still charge, send or sign in next to the kept data, and follow the same plain-SQL rules as `scrub` and `delete`.
- `late` runs on `wp_loaded` of every non-production request, after the gateway pass, and is the only phase that can count on other plugins' classes.
- Each step runs a phase across all integrations, so the order between integrations must never matter; logic that needs an order belongs in the pipeline.

### Adding an integration

1. Copy the closest existing file: `klaviyo.php` (options and plugins), `jetpack-crm.php` (tables), `stripe.php` (keys inside an option), `pmpro.php` or `mailpoet.php` (closures). Rename the file, slug, label and namespace.
2. Use exact option names, table names without the table prefix, and plugin patterns checked against real plugin slugs, since a pattern matches any plugin basename that contains it.
3. Remove the plugin's patterns from `plugin_denylist.txt` and its options from `option_scrublist.txt`; nothing may be in both a data file and an integration.
4. Add a subsection to "Explanations" in `README.md`.
5. Seed the plugin's data in `tests/smoke/fixtures/php/` (options the scrub blanks go in `SN_TEST_BLANKED_OPTIONS`, which staging-plugin and staging-mu check) and assert the end state in the scenario that covers the plugin; add the integration to C8 in `wp-cli.test.mjs`; run `staging-plugin` and `wp-cli`.

### Census tests

- `staging-plugin` R1: every file in `includes/integrations/` registers exactly one declaration, at `BUILT_IN_PRIORITY`, whose slug is its file name, in file order; every declaration passes the collector's rules and leaves the table prefix out of its tables; no option or plugin pattern is declared twice, by two integrations or by an integration and a data file, or falls under another integration's `delete_option_prefixes`.
- `staging-plugin` R2: invalid and overlapping declarations from an mu-plugin are logged and skipped, and a valid one gets everything it declares scrubbed, deactivated and deleted.
- `wp-cli` C8: `wp safety-net integrations` lists every integration with its counts and phases (`scrub`, `delete`, `keep`, `hooks`, `late`), and `--format=json` returns the declarations.

---

## Commands

### Build / Install

There is no build step. The plugin is pure PHP and JavaScript/CSS. To use:

1. Copy the plugin into `wp-content/plugins/safety-net/` or install via the release zip.
2. Ensure `WP_ENVIRONMENT_TYPE` is `staging`, `development`, or `local` (not `production`).
3. Activate the plugin.

### Lint

The project uses WordPress PHP Coding Standards. `phpcs.xml.dist` limits phpcs to the plugin's own files (`safety-net.php`, `includes/`, `assets/`), so `node_modules/` and the test suite are never scanned. From the repo root, run:

```bash
phpcs
```

Or with a typical WordPress setup:

```bash
composer require --dev wp-coding-standards/wpcs
vendor/bin/phpcs
```

Both pick up `phpcs.xml.dist` automatically; pass `--standard=phpcs.xml.dist` if you run phpcs from another directory.

Use `phpcs:ignore` comments sparingly; they exist in the codebase for cases where the standard cannot be satisfied safely (e.g. direct DB updates, nonce checks in known contexts).

### Tests

Smoke tests boot real WordPress sites in [WordPress Playground](https://wordpress.github.io/wordpress-playground/) (PHP compiled to WebAssembly, SQLite database) and drive them over HTTP, in-process PHP and WP-CLI. PHP, MySQL and Docker are not needed. They need:

- Node.js 24.18 or later (`@php-wasm/node` declares it; older 24.x releases work but `npm ci` prints `EBADENGINE` warnings).
- `unzip` and `git` on `PATH` (unpacking WooCommerce and MailPoet, and building the release zip in `npm run test:archive`).
- Network access on every run, not just the first: Playground looks up the WordPress version on api.wordpress.org at every boot that installs WordPress, even when the zip is cached, and `SN_TEST_WC_VERSION=latest` or `SN_TEST_MAILPOET_VERSION=latest` is looked up too. Downloads are retried with backoff.

```bash
npm ci                                  # once: installs the pinned @wp-playground packages
npm test                                # every scenario, a few in parallel, longest first (about 2-3 minutes)
npm test -- multisite                   # one group: single-site, woocommerce or multisite
npm test -- staging-plugin wp-cli       # specific scenarios (names never clash with group names)
npm test -- --list                      # every scenario and what it covers
SN_TEST_PHP=8.1 npm test                # another PHP version (8.1 to 8.5)
SAFETY_NET_PATH=/path/to/copy npm test  # test a different copy of the plugin
npm run test:archive                    # check what the release zip would contain
```

`package.json` pins `@wp-playground/blueprints`, whose `enableMultisite` step the multisite boots call directly, to the same version as `@wp-playground/cli`. Update both together: `npm test` stops with an error when the installed versions differ, since a mismatch breaks every boot.

More settings: `SN_TEST_WP` (WordPress version, default `latest`), `SN_TEST_WC_VERSION` and `SN_TEST_MAILPOET_VERSION` (default: the versions pinned in `tests/smoke/lib/config.mjs`, or `latest`), `SN_TEST_CONCURRENCY`, `SN_TEST_OUTPUT` (default `tests/_output`), `SN_TEST_CACHE` (WooCommerce, MailPoet and WP-CLI downloads; default `~/.cache/safety-net-tests`, or `$XDG_CACHE_HOME/safety-net-tests`, or the system temp directory; kept outside the repo so nothing downloaded sits next to the plugin), `SN_TEST_BOOT_TIMEOUT` (milliseconds per Playground boot, default 240000), `SN_TEST_GROUP` / `SN_TEST_ONLY` (same as passing a group or scenario name), `SN_TEST_WORKERS` (PHP workers per site, default 2), `SN_TEST_PAGE_CONCURRENCY` (how many independent pages a test loads at once; default `SN_TEST_WORKERS` on Linux and 1 elsewhere, because on macOS concurrent PHP workers overwrite each other's lines in `debug.log` and `probe.jsonl`) and `SN_TEST_VERBOSE=1`.

Layout:

- `tests/smoke/scenarios/*.test.mjs`: one `node:test` file per scenario. Each boots a fresh site, seeds data, enables Safety Net, checks the first page load and then everything after it. Pages that a test loads after the first load, that change nothing and do not depend on each other's order, go through `site.getAll()`, which loads them up to `SN_TEST_PAGE_CONCURRENCY` at a time and reports every one that fails; first loads, logins, POSTs and requests after a state change stay sequential.
- `tests/smoke/scenarios.mjs`: the scenario list, their groups, what each covers, roughly how many `seconds` each takes and what it needs: `woocommerce` and `wp-cli` are downloaded before the run (multisite boots use the cached `wp-cli.phar` to convert the network), and `woocommerce-site` starts the scenario from a copy of a site with WooCommerce already activated (see `_woocommerce-site/` below). `run.mjs` starts the scenarios with the most `seconds` first, so the parallel lanes finish close together (`node --test` would start them alphabetically); update a scenario's `seconds` when it gets much longer or shorter.
- `tests/smoke/lib/`: booting Playground and the HTTP, PHP and WP-CLI helpers (`site.mjs`), shared assertions (`checks.mjs`), debug.log analysis, known issues and the reporter.
- `tests/smoke/fixtures/`: stub plugins (denylisted, payment gateways, controls), the test-helper mu-plugin (per-request probe, log canary, notice attribution, filter hooks, offline HTTP mock) and the PHP seed and inspection helpers.

Every scenario fails on an HTTP 500 or "critical error" page, on any PHP fatal error in `debug.log`, and on any warning, notice, deprecation or database error that comes from Safety Net. Core reports "called incorrectly" and deprecation notices from `wp-includes`, so the helper mu-plugin logs `SN_TEST attributed: ...` whenever one fires with Safety Net on the call stack, and those count as Safety Net's. The helper also logs `SN_TEST boot` on every request; the log checks fail if `debug.log` is missing or lacks it, so they cannot pass on a log that was never written. Admin requests fail if they end on the login form, so a lost session cannot pass as an empty page.

A Playground boot that stalls past `SN_TEST_BOOT_TIMEOUT` is retried like any other failed boot, except in `install-flow`, where Safety Net is already in `mu-plugins` during the install: there a failed boot is not retried and the error lists the install-time requests from `probe.jsonl` (including whether Safety Net loaded during the core install) plus the fatal and database errors in `debug.log`.

`tests/smoke/lib/known-issues.mjs` lists Safety Net bugs the suite found. Each has a test marked `todo`, which is reported as one line but does not fail the run, plus an allow-list pattern if the bug writes to `debug.log`. Each known-issue test is written so it cannot pass when nothing happened: it checks that Safety Net ran, or that the data it expects to change was seeded. When a known issue starts passing, the run prints it as fixed but still marked todo, and in CI (`CI` set) that fails the run. When you fix one, delete its entry and the `todo` option so the test guards against a regression.

The reporter prints one line per test (`✔` passed, `✖` failed, `-` known issue, `!` known issue now passing) and a summary at the end that names each failure's scenario, assertion and output folder. Ctrl-C (`SIGINT`), `SIGTERM` or `SIGHUP` stops every scenario process, counts the unfinished scenarios as not run and exits with 130 or 143. Output goes to `tests/_output/`:

- `<scenario>/`: `debug.log`, `probe.jsonl` (one line per request or WP-CLI call: what Safety Net ran and the status code), `http.jsonl` (outgoing HTTP attempts, all blocked or mocked), `environment.json` and `log-summary.json`. Files are written when there is something to record, so a scenario without outgoing HTTP has no `http.jsonl`.
- `_warm-up/`: the boot before the parallel run that downloads WordPress once.
- `_woocommerce-site/`: replaces the warm-up when two or more selected scenarios need `woocommerce-site`. It installs WordPress, activates WooCommerce and checks that WooCommerce installed cleanly, without Safety Net and without a wp-admin request. Each of those scenarios then boots from its own copy of that site, so it skips installing WordPress and activating WooCommerce but still starts where Safety Net never ran. The site and its copies live in a temporary directory outside the output (each is over 100 MB) that is deleted when the run ends, also when it is interrupted with Ctrl-C, `SIGTERM` or `SIGHUP` (closing the terminal); a `SIGKILL` or a crash can still leave an `sn-smoke-*` directory in the system temp directory. A scenario run on its own, or with no other such scenario, installs and activates as usual, and so does every scenario when building the site fails (the run prints why).
- `summary.json`: the counts, failures and known issues of the last run (also used to fail CI on fixed known issues).

CI (`.github/workflows/tests.yml`) runs on pull requests and trunk: `php -l` on PHP 8.1, 8.3, 8.4 and 8.5, the release-zip check plus a check that the smoke matrix runs every group in `scenarios.mjs` (add a new group to the matrix, or that check fails), and the smoke tests on PHP 8.1, 8.3, 8.4 and 8.5 for each group, against the tree `release.yml` would zip. Pull requests, trunk and releases always use the latest WordPress. The weekly scheduled run adds WordPress nightly and the latest WooCommerce and MailPoet; a failure there fails that run, which notifies maintainers, but never blocks a pull request or a release. Every leg restores the weekly download cache; only the PHP 8.3 leg of each group saves it. Make the "Tests result" check required in branch protection.

### Release

1. Create a GitHub release (tag + release notes).
2. The `.github/workflows/release.yml` workflow runs on release creation. It runs `tests.yml` against the tagged commit first and builds the zip via `git archive` only if every job passes, so `safety-net.zip` appears on the release about 10 minutes after it is created. Until then the download link and the self-updater offer nothing. If a job fails for an unrelated reason, a maintainer can open the workflow run and use "Re-run failed jobs"; the zip is attached once it passes.
3. The zip excludes paths in `.gitattributes` (e.g. `.git`, `.github`, `README.md`, hidden files, `tests/`, `package*.json`, `phpcs.xml.dist`). Add any new file that isn't part of the plugin there and to `.deployignore`. `npm run test:archive` checks this: only `safety-net.php`, `includes/` and `assets/` may ship, so a new path that should ship must also be added to `SHIPPED` in `tests/smoke/check-release-archive.mjs`.

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
  - `plugin_denylist.txt` — plugin slugs or partial matches (e.g. `smtp` matches any plugin with `smtp` in the path)
- One entry per line.
- To add entries: create an issue, submit a PR, or use filters (see Extensibility).
- A plugin that Safety Net does more for than deactivating gets an integration instead (see Architecture).

### Version Bumping

- Update the plugin version in the header of `safety-net.php` when releasing.

---

## Architectural Decisions

1. **Execution order is fixed**  
   Options must be scrubbed first, then plugins deactivated, then data deleted. Each step checks for the previous step’s completion via options (`safety_net_options_scrubbed`, `safety_net_plugins_deactivated`, etc.). Do not change this order.

2. **Direct DB updates for scrubbing**  
   Scrub operations use `$wpdb->update` (e.g. `safety_net_update_option_direct`) to avoid `update_option` and its hooks (e.g. notifications). This is intentional.

3. **Action Scheduler customization**  
   When “Pause renewal actions” is on, the plugin replaces Action Scheduler’s store class with `SafetyNet\ActionScheduler_Custom_DBStore` to skip claiming renewal, payment-retry and end-of-prepaid-term actions. Other actions still run.

4. **Environment detection**  
   `get_environment_type()` in `utilities.php` supports `sandbox`, `dev`, `develop` in addition to `staging`, `development`, `local` for hosts like Pressable and WPCOM Studio.

5. **Duplicate plugin prevention**  
   If `SAFETY_NET_PATH` is already defined, the plugin exits early to avoid running twice (e.g. in both `plugins` and `mu-plugins`).

6. **Multisite: network-wide changes happen once per network**  
   Each site runs the steps on its own first load, but network-activated plugins and the network admin email are changed only by the first automatic run on the network, which records it in network options (`safety_net_network_plugins_deactivated`, `safety_net_network_gateway_plugins_deactivated` once a gateway pass has run with WooCommerce loaded, and `safety_net_network_admin_email_scrubbed`), so a later site's first run doesn't undo a super admin's changes. `should_change_network()` in `utilities.php` decides this. From the Tools page, only super admins (`manage_network_plugins`, `manage_network_options`) re-apply the network-wide part; a site administrator's buttons change only their own site. The WP-CLI commands always apply it. Single sites never write these flags.

7. **Integrations registry**  
   Everything Safety Net does for one third-party plugin is declared in `includes/integrations/<slug>.php`, and the steps read those declarations instead of handling the plugin themselves (see Architecture).

8. **Keeping data narrows only the delete step**  
   `SAFETY_NET_DELETE_DATA` and `SAFETY_NET_KEEP_UNTIL` are constants, not options or filters, because the automatic run fires on a site's first request of any kind, while plugins are still loading, and a constant in `wp-config.php` is the one setting certain to exist by then. They fail closed: anything other than the boolean `false` and a valid `YYYY-MM-DD` date is ignored and the data is deleted. They never skip the scrub or deactivate steps, which stop a copy from charging customers or syncing with third parties, and the Tools page and `wp safety-net delete` always delete everything.

---

## Extensibility

Key filters and hooks:

| Filter / Hook | Purpose |
|---------------|---------|
| `safety_net_show_production_notice` | Return `false` to hide the production notice (use with care) |
| `safety_net_denylisted_plugins` | Add plugins to the denylist for the current site |
| `safety_net_options_to_clear` | Modify options to scrub |
| `safety_net_payment_gateway_plugins` | Modify the plugins deactivated because they register a WooCommerce payment gateway |
| `safety_net_hide_admin` | Return `true` to hide the Tools > Safety Net admin page |
| `safety_net_loaded` | Fired when the plugin is ready |
| `safety_net_scrub_options` | Fired to scrub options |
| `safety_net_deactivate_plugins` | Fired to deactivate plugins |
| `safety_net_deactivate_gateway_plugins` | Fired on `wp_loaded` (once WooCommerce has loaded) to deactivate plugins that register a payment gateway |
| `safety_net_delete_data` | Fired to delete users and orders |
| `safety_net_keep_data` | Fired instead of `safety_net_delete_data` while `SAFETY_NET_DELETE_DATA` is `false`, to delete the option backups and run the integrations' `keep` phase |
| `safety_net_delete_transients` | Fired to delete transients |
| `safety_net_disable_webhooks` | Fired to disable webhooks |
| `safety_net/integrations` | Add `SafetyNet\Integrations\Integration` declarations; collected once as Safety Net loads, so only code that runs before it can add them, and one that overlaps a Safety Net integration is skipped (see Architecture) |

Constants:

- `SAFETY_NET_DELETE_DATA` — When exactly `false`, the automatic run keeps users, orders and subscriptions: the keep step replaces the delete step. Any other value is ignored, so data is deleted, and reported in the wp-admin notice and the error log.
- `SAFETY_NET_KEEP_UNTIL` — A `YYYY-MM-DD` date, read only when `SAFETY_NET_DELETE_DATA` is `false`; once that day has ended (UTC), the next load deletes the data. An invalid date makes Safety Net ignore `SAFETY_NET_DELETE_DATA`.
- `SAFETY_NET_SKIP_GIVEWP` — When `true`, skip GiveWP data during deletion.

---

## Common Pitfalls

1. **Do not edit WordPress core**  
   Only modify plugin files. Never change core WordPress or WooCommerce files.

2. **Do not run on production**  
   The plugin intentionally does nothing on production. Do not add behavior that bypasses `is_production()`. The only exception is `self-update.php`, which loads first so production installs keep receiving updates.

3. **Do not change execution order**  
   Scrubbing → deactivating plugins → deleting data must stay in that order. The code enforces this with option checks. Do not add a way to skip the scrub or deactivate step; only the delete step has an alternative, the keep step (see Architectural Decisions 8).

4. **Do not remove `ABSPATH` check**  
   The main plugin file MUST guard with `if ( ! defined( 'ABSPATH' ) ) { exit; }`.

5. **Denylist format**  
   - Options: exact option name (e.g. `my_plugin_api_key`).
   - Plugins: slug or partial match (e.g. `smtp` matches `wp-mail-smtp/wp_mail_smtp.php`).

6. **Atomic / WP.com staging**  
   On Atomic sites (`jetpack_is_atomic_site()` or `wpcomstaging.com`), `jetpack_private_options` and `jetpack_secrets` are NOT scrubbed to avoid disconnecting Jetpack.

7. **Duplicate plugin**  
   If activation fails, check for another copy in `mu-plugins`; the plugin exits when `SAFETY_NET_PATH` is already defined.

8. **MailPoet settings**  
   MailPoet keeps its settings in `{prefix}mailpoet_settings`, one serialized array per top-level key, not in `wp_options`, so `option_scrublist.txt` and `safety_net_options_to_clear` don't reach them. The scrub in `includes/integrations/mailpoet.php` only blanks keys that exist inside those arrays: never replace a whole row with a string, because MailPoet writes into rows such as `reply_to` as arrays and fatals on PHP 8. The automatic run can't rely on MailPoet's classes (MailPoet is usually deactivated, and in mu-plugin mode not loaded yet), so both MailPoet passes are plain SQL. Safety Net only empties the MailPoet tables on its list, so the `mailpoet` scenario (M8) fails when MailPoet has a `{prefix}mailpoet_*` table that is neither on that list nor among the tables it keeps on purpose; a new MailPoet version shows up in the weekly run against the latest MailPoet.

9. **Deactivating plugins mid-load**  
   The automatic run deactivates plugins while WordPress is still including them, so plugins earlier in the list (such as MailPoet) are already running. Plugins that use the Jetpack Autoloader (MailPoet, WooCommerce, Jetpack) share one class map, which a later plugin rebuilds from `active_plugins` and the `jetpack_autoloader_plugin_paths` transient. `keep_in_jetpack_autoloader()` adds each deactivated plugin whose main file is already included to the autoloader's list for the rest of the request; without it that plugin fatals. A plugin not loaded yet never runs in that request, so it is left out (mu-plugin mode, or a site plugin deactivated by a network-activated Safety Net). Network-activated plugins load alphabetically before site plugins, so network-activated MailPoet runs before a network-activated Safety Net. The `woocommerce-mailpoet` and `multisite-network-mailpoet` scenarios cover these cases.

10. **AI provider credentials**  
   `includes/integrations/ai-connectors.php` declares the AI provider credentials, which the scrub step deletes without a backup and without option hooks: WordPress's connector keys and application passwords (`connectors_ai_*`), the AI plugin's encrypted `_secret_ai/*` keys, and the options in its `delete_options`, `delete_option_prefixes` and `delete_partial_options`, whose provider plugins keep keys outside core's names (settings that also hold other configuration only have their secrets blanked). The rows are changed with `$wpdb`, so each one is also dropped from the object cache right away, as `delete_option()` does; otherwise a later `add_option()` in the same request saves the stale `alloptions` copy back. The AI plugin and AI provider plugins are in its `plugins`; check a new entry against real plugin slugs, since entries are substring matches. `aiprfoex_api_key` (AI Provider for exo) is kept on purpose: it is an optional token for a model cluster the site owner runs locally, and the plugin itself is still deactivated. Keys supplied through `{PROVIDER}_API_KEY` environment variables or `wp-config.php` constants can't be scrubbed, a multisite subsite that is never loaded keeps its keys like every other scrubbed option, and sites that an earlier version already processed keep their AI keys until Scrub Options runs again.

---

## WP-CLI Commands

Requires WP-CLI and a non-production environment:

```bash
wp safety-net scrub-options      # Scrub denylisted options
wp safety-net deactivate-plugins # Deactivate denylisted plugins
wp safety-net delete             # Delete users and orders
wp safety-net delete-transients  # Delete transients
wp safety-net disable-webhooks   # Disable WooCommerce webhooks
wp safety-net integrations       # List the integrations and what each one declares
```

---

## Where to Find More

- **User-facing docs**: `README.md`
- **Plugin behavior**: `includes/bootstrap.php`, `includes/admin.php`
- **Data deletion logic**: `includes/delete.php`
- **What Safety Net does for each third-party plugin**: `includes/integrations/`
- **Environment detection**: `includes/utilities.php` (`get_environment_type`, `is_production`)
