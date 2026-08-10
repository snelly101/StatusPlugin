# Tests

## Unit tests (this directory)

`StatusCalculatorTest`, `SsrfGuardTest`, and `EncryptionTest` cover pure
business logic - status roll-up priority, SSRF address validation, and
the encryption helper - using a lightweight bootstrap (`bootstrap.php`)
that stubs the small number of WordPress functions that logic touches,
rather than a full WordPress installation.

Run them with:

```bash
composer install
composer test
# or directly:
vendor/bin/phpunit
```

## What these do *not* cover

Anything that talks to `$wpdb` (every repository/manager class -
`ServiceManager`, `MonitorManager`, `IncidentManager`,
`SubscriberManager`, `NotificationQueue`, the REST controllers, cron
behaviour, etc.) needs a real WordPress test environment with a test
database, not a bare PHPUnit run. To test those:

1. Set up the standard WordPress PHPUnit scaffold, e.g. via
   `wp scaffold plugin-tests service-status-manager` (WP-CLI) or
   `@wordpress/env` (`wp-env`), which provides `WP_UnitTestCase` and a
   disposable test database.
2. Point it at this plugin directory.
3. Write `WP_UnitTestCase`-based tests that activate the plugin (so
   `Database::install()` runs), then exercise the manager classes
   directly against the real custom tables.

## Manual test checklist

The following require a running WordPress site and cannot be fully
automated without the integration setup above; test manually before
release and after significant changes:

- Activation creates all tables, capabilities, and the default status page.
- Reactivating an already-active install does not duplicate data.
- Creating a service/monitor, then confirming it appears on the public
  status page.
- HTTP and TCP monitor checks against both a reachable and unreachable
  target, and confirming an automatic incident is created after the
  failure threshold and resolved after the recovery threshold.
- Attempting to monitor `127.0.0.1`, `169.254.169.254`, and a
  `192.168.x.x` address without "Allow internal addresses" - all should
  be rejected; with it enabled, they should be accepted and logged.
- Full subscription flow: sign up, receive and click the confirmation
  link, receive a test incident notification, use the manage-subscription
  link to change selections, then fully unsubscribe.
- Submitting the subscription form twice with the same email produces no
  visible difference in the response (enumeration protection).
- Duplicate cron execution (trigger the REST cron endpoint twice in close
  succession) does not send duplicate notifications - check the
  notification queue's dedup_key uniqueness.
- Duplicate incoming webhook delivery (same idempotency key) is
  acknowledged but not reprocessed.
- REST API permission checks: anonymous requests can read `/status` and
  `/services` but are rejected from `/incidents` (POST); an authenticated
  user without `ssm_manage_incidents` is rejected with 403.
- WordPress Tools > Export/Erase Personal Data correctly finds and
  removes a subscriber matching a given email address.
- Deactivating the plugin stops cron but a subsequent reactivation does
  not lose any data; uninstalling with "Delete all plugin data" unchecked
  (the default) leaves every table intact.
- Time zone / DST: schedule maintenance spanning a DST transition and
  confirm the displayed local times and the UTC-stored values are correct
  before and after the transition.
- Accessibility: keyboard-only navigation through the subscription form
  and incident timeline; screen reader announcement of the status banner
  (`role="status"`/`aria-live="polite"`).
