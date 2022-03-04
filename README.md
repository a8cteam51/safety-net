# Anonymizer

## What's this?
This is a plugin by Team 51 that secures sensitive data on development, staging, and local sites. It anonymizes personally identifiable information as well as prevents sites from acting on user data (e.g. sending emails, processing renewals, etc.)

## How to use?
This plugin will run automatically if `wp_get_environment_type` doesn't return `production`. Alternatively, you can run it manually with a CLI command or through the settings. 