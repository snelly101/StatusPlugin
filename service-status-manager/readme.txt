=== Service Status Manager ===
Contributors: (your organisation)
Tags: status page, uptime monitoring, incidents, maintenance, notifications
Requires at least: 6.2
Tested up to: 6.6
Requires PHP: 8.1
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A complete public status page system for WordPress: services, monitors, incidents, scheduled maintenance, and subscriber notifications by email, SMS, and Microsoft Teams.

== Description ==

Service Status Manager turns WordPress into a self-hosted status page platform, similar in capability to hosted status-page products, using entirely original code.

Customers can:

* View the current status of your services and systems.
* See active incidents and known issues, and their full update history.
* Review scheduled maintenance.
* Browse historical incidents and uptime.
* Subscribe to an entire service, or to individual monitors within it.
* Choose email, SMS, Microsoft Teams, or a combination of channels.

Administrators get a full WordPress admin area to manage service groups, services, monitors (manual, HTTP/HTTPS, TCP port, Ping - with an extensible provider architecture for more), incidents, scheduled maintenance, subscribers, the notification queue, reports, settings, logs, and diagnostic tools.

= Design (v1.1.0) =

The public status page uses a self-contained design system - CSS custom properties for colour/spacing/radius/shadow/motion, all scoped under `.ssm-status-page` so nothing leaks into your theme. Highlights:

* A status hero (icon, title, description, last-checked time, 90-day uptime) that changes background tint/icon/accent per status, never colour alone.
* An optional sticky header (logo, section links, live status pill, compacts on scroll) - toggle per status page under **Service Status > Status Pages**, since your theme may already provide navigation.
* Expandable service rows showing per-monitor detail; uptime bars with real tooltips (date, uptime %, incident count, estimated degraded/down minutes).
* A "Get status updates" button opens a 3-step subscribe wizard (channels -> what to follow -> destination + consent) with an in-page confirmation - the classic inline form is still there as a `<noscript>` fallback, so subscribing never depends on JavaScript.
* Full dark mode (system-preference aware, plus a manual toggle when the header is enabled) - not just an inverted palette.
* Optional live status refresh: the hero and header poll the existing public `/status` REST endpoint at an admin-configurable interval (default 60s, 0 disables it) to reflect changes without a page reload.
* Self-authored inline SVG icons throughout - no external icon library or web font dependency.

= Architecture =

* Modern namespaced, object-oriented PHP (PHP 8.1+), organised into `includes/` (core services), `admin/` (wp-admin UI), `public/` (front-end templates/shortcodes), `api/` (REST + webhooks), `monitoring/` (provider-based monitor checks), and `notifications/` (provider-based notification channels).
* Custom database tables (via `$wpdb`, prepared statements throughout) for everything high-volume or relational - monitor checks, aggregates, incidents, subscribers, the notification queue, and the audit log - rather than storing this as WordPress posts/postmeta.
* Monitoring and notifications are both provider-based: new monitor types (DNS, SMTP, Microsoft 365, generic API, NinjaOne, PRTG, Auvik, Veeam, WatchGuard, custom webhooks) and SMS gateways (MessageBird, Vonage, AWS SNS, Esendex, etc.) can be added by implementing an interface and registering via a filter, with no changes to core code.
* WordPress capabilities, not role-name checks, gate every sensitive action; nonces protect every state-changing form and admin-post handler; every write to a custom table goes through a prepared statement.

== Installation ==

1. Upload the `service-status-manager` folder to `/wp-content/plugins/`, or install the zip through Plugins > Add New > Upload Plugin.
2. Activate the plugin. This creates the plugin's database tables, registers its roles/capabilities, seeds a default "main" status page, and schedules its cron events.
3. Go to **Service Status > Service Groups** and **Services** to create your services (e.g. "Microsoft 365", "Hosted Email", "Broadband").
4. Go to **Service Status > Monitors** to add monitors under each service (Manual, HTTP/HTTPS, TCP Port, or Ping).
5. Create a WordPress page and add the `[service_status_page]` shortcode, or use the individual shortcodes listed below to build a custom layout.
6. Go to **Service Status > Settings** to configure your sender email, SMS provider (optional), and data retention preferences.
7. See "Cron Configuration" below before relying on the plugin in production.

== Cron Configuration ==

WordPress' built-in WP-Cron only runs when the site receives HTTP traffic. On a low-traffic site this means monitor checks and notification delivery can be delayed by minutes or hours, and check frequencies under a few minutes are not reliable. WP-Cron **will run out of the box with no configuration**, so the plugin works immediately after activation, but for production use with meaningful check frequencies, do this instead:

1. Add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`.
2. Configure a real, minute-level system cron job using **either**:

   * The secured REST endpoint (**Service Status > Tools** shows your exact URL and token):

     `* * * * * curl -fsS "https://example.com/wp-json/service-status-manager/v1/cron/run?token=SECRET" >/dev/null`

     The token is a 32-byte random secret generated on activation. The endpoint never returns monitor configuration, credentials, or subscriber data - only aggregate counts - and is rate-limited.

   * WP-CLI:

     `* * * * * cd /path/to/wordpress && wp service-status run-checks --quiet`
     `* * * * * cd /path/to/wordpress && wp service-status process-notifications --quiet`
     `*/5 * * * * cd /path/to/wordpress && wp service-status process-maintenance --quiet`
     `0 3 * * * cd /path/to/wordpress && wp service-status cleanup --quiet`
     `5,35 * * * * cd /path/to/wordpress && wp service-status aggregate --quiet`

Available WP-CLI commands: `run-checks [--all]`, `process-notifications`, `aggregate`, `process-maintenance`, `cleanup`.

== Email Setup ==

Email notifications use WordPress' built-in `wp_mail()`. Set your sender name/address under **Settings > General**; emails are sent as HTML with an automatic plain-text alternative.

By default no SMTP transport is configured, so delivery depends on your host's mail setup. Two ways to route mail through a real SMTP server:

* **Built in**: enable "SMTP relay" under **Settings > Outgoing Mail (SMTP)** and enter your host/port/encryption/credentials. This affects every outgoing email on the site (not just this plugin's), the same way a dedicated SMTP plugin would - do not enable both at once, they will conflict for the same `phpmailer_init` hook.
* **A separate SMTP plugin** (WP Mail SMTP, Post SMTP, etc.) - leave this plugin's SMTP relay disabled and the other plugin's configuration is used instead, since Service Status Manager only ever calls `wp_mail()`.

Either way, send a test message from **Notifications > Send a test notification** (Email channel) to confirm delivery.

== SMS Provider Setup ==

SMS requires a **paid, third-party account** with a supported provider - the plugin does not include SMS delivery itself. The reference implementation is **Twilio** (https://www.twilio.com/):

1. Create a Twilio account and buy/verify a sending number.
2. In **Settings > SMS Provider**, select Twilio, enter your Account SID, Auth Token, and sender number.
3. Send a test message from **Notifications > Send a test notification**.

Credentials are encrypted at rest (AES-256-GCM, keyed from your WordPress salts) and are never displayed in full after saving, never logged, and never included in exports. Additional gateways (MessageBird, Vonage, AWS SNS, Esendex, or other UK SMS providers) can be added by extending `Notifications\SmsProvider` and registering the class via the `ssm_sms_providers` filter - no core changes required.

== Microsoft Teams Setup ==

Teams notifications use **Incoming Webhooks**, posting an Office 365 Connector "MessageCard" payload. This is the current generally-available way to post into a Teams channel without an Azure AD app registration; Microsoft has signalled this mechanism may eventually be replaced by Workflows/Power Automate, so it is isolated entirely behind `Notifications\NotificationProviderInterface` (see `notifications/class-teams-provider.php`) - only that one file would need to change if/when Microsoft finalises a successor.

A subscriber sets this up themselves: in the Teams channel, add an **Incoming Webhook** connector, copy the generated URL, and paste it into the "Microsoft Teams" field of the subscription form. The URL is validated against Microsoft's known webhook domains, encrypted at rest, and never exposed publicly, in logs, or in exports.

== Subscription Workflow ==

1. A visitor fills in the subscription form (`[service_status_subscribe]` or as part of `[service_status_page]`), choosing one or more channels (email/SMS/Teams), which groups/services/monitors they care about, their minimum severity, and whether they want maintenance notifications, and ticks the consent checkbox.
2. Each selected channel goes through **double opt-in**: a confirmation link (email/SMS) or a verification link (Teams) is sent, and no notifications are sent to that channel until it is confirmed.
3. Every notification email/SMS/Teams message includes a link to **manage** the subscription (update selections, pause, or fully unsubscribe) and to **unsubscribe** directly, both using secure, single-purpose tokens - only their hash is stored in the database.
4. Subscribers can request their data (export) or request deletion (erase) from the management page; this also plugs into WordPress' native Tools > Export/Erase Personal Data screens.
5. The plugin never reveals whether a submitted email/phone number was already registered - every public response is identical either way, to prevent subscriber enumeration.

== Webhooks ==

**Incoming** (external systems pushing a monitor result in): `POST /wp-json/service-status-manager/v1/webhook/{id}`, configured under **Settings > Incoming Webhooks**. Required headers:

* `X-SSM-Timestamp` - Unix timestamp, must be within 5 minutes of the server's clock.
* `X-SSM-Signature` - `hash_hmac('sha256', "{timestamp}.{raw body}", secret)`.
* `X-SSM-Idempotency-Key` - any unique string; repeated deliveries with the same key are accepted once and acknowledged (not reprocessed) thereafter.

Sample payload:

`{"monitor_id": 12, "state": "major_outage", "message": "Connection timed out", "response_time_ms": null, "event_time": "2026-01-01T12:00:00Z"}`

Optional per-integration IP allow-list, and every attempt (accepted or rejected) is written to the delivery log.

**Outgoing** (notifying external systems of plugin events): configured under **Settings**, per-webhook secret, subscribe to any of `incident.created`, `incident.updated`, `incident.resolved`, `maintenance.started`, `maintenance.completed`, `maintenance.cancelled`, `service.status_changed`, `monitor.status_changed`. Payloads are signed the same way (`X-SSM-Signature`/`X-SSM-Timestamp` headers); delivery attempts are logged and a "Test" action is available. Delivery is currently synchronous with a configurable timeout; a failed delivery is visible in the log for manual retry (via "Test") rather than an automatic retry queue.

== REST API ==

Namespace: `service-status-manager/v1`.

Public, read-only, no authentication required, never expose subscriber data or internal incident notes:

* `GET /status` - overall status.
* `GET /services` - public services and their public monitors.
* `GET /incidents` - active incidents (with public update timeline).
* `GET /incidents/history?count=20` - recent resolved incidents.
* `GET /maintenance` - upcoming maintenance.
* `GET /maintenance/history?count=20` - completed maintenance.
* `GET /uptime/{monitor_id}?days=90` - uptime percentage and daily history for a public monitor.

Protected (WordPress cookie+nonce or Application Passwords, plus a plugin capability):

* `POST /incidents` - create (requires `ssm_manage_incidents`).
* `PUT/PATCH /incidents/{id}` - update.
* `POST /incidents/{id}/resolve` - resolve.
* `POST /services/{id}/status` - change a service's status (requires `ssm_manage_status`).
* `POST /monitors/{id}/result` - submit a manual monitor result (requires `ssm_manage_status`; for unauthenticated third-party systems use the incoming webhook instead).
* `POST /monitors/check` - trigger an immediate check run.
* `POST /notifications/test` - send a test notification (requires `ssm_manage_integrations`).

== Shortcodes ==

* `[service_status_page]` - the full page (summary, services, subscribe, incidents, maintenance, history).
* `[service_status_summary]` - overall status banner only.
* `[service_status_services]` - grouped service/monitor list.
* `[service_status_incidents]` - active + recently resolved incidents.
* `[service_status_maintenance]` - upcoming + recently completed maintenance.
* `[service_status_subscribe]` - subscription form.
* `[service_status_history]` - uptime bars and percentages.

Common attributes: `group="slug"`, `service="slug"`, `count="5"`, `show_monitors="yes|no"`, `show_subscribe="yes|no"`, `layout="full|compact"`, `range="30|60|90"`.

Templates live in `public/templates/` and can be overridden per-theme by copying a file to `your-theme/service-status-manager/<name>.php`.

== Capabilities & Roles ==

Custom capabilities (mapped to capabilities, never checked by role name):

* `ssm_manage_status` - full plugin access (services, monitors, status pages, settings).
* `ssm_manage_incidents` - create/update incidents and maintenance.
* `ssm_edit_updates` - add public incident updates only.
* `ssm_view_status` - read-only dashboard/reports access.
* `ssm_manage_subscribers`, `ssm_manage_settings`, `ssm_manage_integrations`, `ssm_export_data`, `ssm_delete_data`.

Bundled roles: Status Administrator, Incident Manager, Status Editor, Status Viewer (all also granted in full to Administrator on activation).

== Privacy & UK GDPR ==

This plugin provides tools that **support** UK GDPR compliance - it does not by itself make your website compliant. You remain responsible for your lawful basis for processing, your published privacy notice, any data-processing contracts with your email/SMS/Teams providers, and your organisation's retention policy.

Built-in tooling: explicit consent capture with timestamp/wording-version/source, double opt-in, self-service unsubscribe/pause/update at any time, data export and erasure (self-service and via WordPress' native Privacy Tools), configurable retention periods for raw checks/aggregates/notification logs/audit logs, an admin toggle for whether IP addresses are retained at all, and an audit trail of administrative actions.

== Security ==

* Every admin action: capability check + nonce check + input sanitisation + audit log entry.
* Every custom-table query uses `$wpdb::prepare()`.
* HTTP/TCP/Ping monitor targets are validated against loopback/private/link-local/reserved/cloud-metadata ranges before every check (not just when saved), with the resolved IP pinned for the actual connection to prevent DNS-rebinding bypass. Internal-address monitoring is opt-in per monitor, or via an explicit administrator-controlled allow-list, and is logged as a warning when used.
* Confirmation/unsubscribe/management links use cryptographically random tokens; only their SHA-256 hash is stored.
* SMS/Teams credentials and monitor auth headers are encrypted at rest (AES-256-GCM) and masked everywhere they might otherwise be displayed (logs, audit trail, exports).
* Public subscription, resend-confirmation, and test-notification endpoints are rate-limited; incoming webhooks require HMAC signatures, timestamp-bounded replay protection, and idempotency keys.
* Subscriber enumeration is prevented: public responses never reveal whether a contact was already registered.

== Known Limitations ==

* Outgoing webhook delivery is synchronous (short configurable timeout) with manual retry via the "Test" action, rather than an automatic backoff queue like the notification queue has.
* WP-Cron alone is not sufficient for reliable sub-minute monitoring or timely notification delivery on low-traffic sites - see "Cron Configuration".
* The Microsoft Teams integration uses classic Incoming Webhooks; if Microsoft retires that mechanism, only `notifications/class-teams-provider.php` needs to change, but message delivery for that channel would need reconfiguring once that happens.
* Only Twilio ships as a complete SMS provider; other gateways require a small amount of code (extending `SmsProvider`) even though the architecture supports them without touching core files.

== Developer Hooks ==

Actions: `ssm_incident_created`, `ssm_incident_updated`, `ssm_incident_resolved`, `ssm_service_status_changed`, `ssm_monitor_status_changed`, `ssm_maintenance_status_changed`, `ssm_maintenance_announced`/`started`/`completed`/`extended`/`cancelled`/`reminder`, `ssm_notification_sent`, `ssm_notification_failed`, `ssm_subscriber_confirmed`, `ssm_subscriber_unsubscribed`, `ssm_subscriber_erased`.

Filters: `ssm_overall_status`, `ssm_status_priority_order`, `ssm_status_definitions`, `ssm_notification_recipients` (via the subscriber-matching logic in `NotificationManager`), `ssm_email_subject`, `ssm_email_body`, `ssm_public_service_data`, `ssm_monitor_provider_types`, `ssm_monitor_provider_instance`, `ssm_sanitize_monitor_settings`, `ssm_sms_providers`, `ssm_valid_teams_webhook_host`, `ssm_database_schema`.

All hooks use the `ssm_` prefix consistently.

== Database Schema ==

Custom tables (WordPress-prefixed, e.g. `wp_ssm_services`): `status_pages`, `service_groups`, `services`, `monitors`, `monitor_checks`, `monitor_aggregates`, `incidents`, `incident_updates`, `incident_services`, `incident_monitors`, `maintenance`, `maintenance_services`, `maintenance_monitors`, `subscribers`, `subscriber_channels`, `subscriber_selections`, `verification_tokens`, `notification_queue`, `notification_log`, `audit_log`, `webhooks_outgoing`, `webhooks_incoming`, `webhook_delivery_log`, `logs`. See `includes/class-database.php` for full column definitions. Relationships are enforced in the application layer (repository/manager classes), not with MySQL foreign keys, for host compatibility. Schema changes are applied idempotently via `dbDelta()`, versioned through the `ssm_db_version` option.

== Upgrading ==

The plugin re-runs its schema installer automatically on the first admin page load after a version change (comparing `ssm_db_version`), using `dbDelta()`, which is additive/idempotent. Back up your database before major version upgrades regardless.

== Uninstallation ==

Deactivating the plugin never deletes data - it only unschedules cron events. Uninstalling (deleting the plugin through the WordPress admin) also retains all data **unless** you have explicitly ticked "Delete all plugin data on uninstall" under **Service Status > Tools**, which defaults to off.

== Troubleshooting ==

* **Monitors never check** - confirm you've set up real cron or WP-CLI (see "Cron Configuration"); WP-Cron alone only fires on site traffic.
* **Emails not arriving** - install an SMTP-configuration plugin if your host blocks PHP's default mail transport; Service Status Manager only calls `wp_mail()`.
* **HTTP monitor blocked with an SSRF warning** - by design, for a private/internal/loopback/cloud-metadata target. Enable "Allow internal addresses" on that specific monitor, or add the host to the SSRF allow-list under Settings, only if you intend to monitor an internal address.
* **Test notification fails** - check Settings > SMS Provider credentials, or the Teams webhook URL; the error message from the provider is shown directly.
* Enable Debug-level logging under Settings > Logging temporarily, then check **Service Status > Logs**.

== Changelog ==

= 1.6.0 =
Adds a "Ping" monitor type (Service Status > Monitors > Monitor type). PHP on typical WordPress hosting can't send real ICMP ping packets - that needs raw sockets/root, which shared and managed hosts don't allow, and shelling out to the system `ping` command needs exec()/shell_exec(), which most hosts disable for security - so this checks basic host reachability by attempting a TCP connection to a couple of common ports (80/443 by default, filterable via `ssm_ping_monitor_ports`) and succeeds as soon as one accepts a connection. Just enter a hostname or IP, no port required; to check one specific port instead, use a TCP Port monitor. Goes through the same SSRF validation (loopback/private/link-local/cloud-metadata protection, DNS-rebinding-safe) as the HTTP and TCP monitor types.

= 1.5.0 =
Resolved incidents on the public status page now collapse their description and full update timeline behind a click/tap toggle by default (matching the existing expandable service rows), instead of every past update always being fully shown - a long-lived status page no longer fills up with historical detail nobody needs at a glance. Active incidents are unaffected and stay fully expanded.

Also fixes a bug where resolving an incident could make an affected service's status page badge regress to "Unknown": resolving an incident recalculates the status of every affected automatic-mode service from its monitors, but a service with no monitors attached has no data to recalculate from, and this was being treated as "force it to Unknown" instead of "leave it alone". Recalculation is now skipped entirely when a service has no active monitors, so its existing status is preserved.

= 1.4.2 =
Incident update/resolved notifications didn't make clear they were an update to an existing incident rather than a new one - the "New Incident"/"Incident Update"/"Resolved" distinction only ever appeared in the email subject line, never in the SMS text or the email body itself, so anyone not reading the subject carefully (or receiving it as an SMS, which has no separate subject) had no way to tell. Both now lead with that label - the email body shows it as a small heading above the severity/status row, and SMS puts it first, ahead of the severity and title.

= 1.4.1 =
SMS notifications now carry the same extra detail as the 1.4.0 email improvements, within SMS length limits: severity and status (incidents) or scheduled window (maintenance), plus the affected service names, ahead of the link. The most important part (what happened) is always first, so if a message is long enough to hit your configured SMS length limit, it's the affected-services list that gets trimmed rather than the headline.

= 1.4.0 =
Notification emails now show far more detail. Incident/maintenance payloads already carried severity, status, affected services, and a status-page link, but the email only ever rendered the raw update message and threw the rest away. Emails now show a severity badge, status label, the list of affected services, a maintenance's scheduled start/end window, an incident's start time, and a "View on status page" button - all above the message text - and the plain-text version (used by mail clients that prefer it) mirrors the same information plus the manage-subscription/unsubscribe links, which previously only appeared in the HTML version.

= 1.3.3 =
The "manage subscription" page had no "Everything" option - just a plain checklist of services/groups/monitors, all unchecked by default - which reads as "notify me about nothing" even though the backend actually treats no selections as "notify me about everything". That ambiguity made it easy to end up re-ticking specific items (putting the subscription right back into a fixed, narrower list that stops matching incidents which don't happen to tag one of those exact items) when the intent was the opposite. The manage page now has the same explicit "Everything" toggle as the subscribe modal: ticking it clears and disables the individual boxes, and picking a specific item unticks it, so it's unambiguous which state you're in and saving does what it looks like it will do.

= 1.3.2 =
Adds debug logging to the "resend confirmation/management link" flow and to the notification queue's insert step, so a "I asked to resend and nothing happened, no row even appears in the queue" report can be pinned down precisely (invalid input, no subscriber matched that email address, a duplicate/DB error on insert, etc.) via Service Status > Logs with Settings > Logging set to Debug. No behaviour change, only visibility.

= 1.3.1 =
The "resend my confirmation or management link" request (and confirming a channel for the first time, which can also queue a management-link email) didn't get the same "send immediately" treatment added in 1.2.3 for new subscriptions - it was only ever sent via the best-effort background trigger or the next cron tick, so on a host where that background trigger doesn't run promptly, the email could sit in the queue for a while with no obvious sign anything was wrong. Both now send synchronously, the same way subscribing already does.

= 1.3.0 =
Subscribers can now manage their subscription without digging up an old email: the "Get status updates" modal has a new "Already subscribed? Resend my confirmation or management link" option (previously this only existed on the standalone `[service_status_subscribe]` shortcode form, not the modal most visitors actually use). Also, a subscriber is now automatically emailed their management link the moment they first confirm a channel, rather than only on request - so everyone gets one without having to ask.

= 1.2.5 =
Fixes the "Everything" checkbox in the subscribe modal (step 2 - "what to follow"): ticking it used to check every individual service/group/monitor box, which submitted an explicit, fixed list of today's services rather than "no specific selection" - the thing the backend actually treats as "notify me about everything". In practice that meant a subscriber who ticked "Everything" would only get notified about incidents that happened to tag one of those exact services, and never about a general incident with no services tagged (like a quick test incident) - the notifications simply had nothing to match and were never queued. "Everything" now clears and disables the individual boxes instead of ticking them, matching the backend's real "everything" behaviour, including automatically covering services added later. If you subscribed via the modal and ticked "Everything", go to your subscription's "manage" link (from the original confirmation email) and re-save with every individual service/group/monitor box unchecked to fix your existing subscription.

= 1.2.4 =
Adds detailed Debug-level logging to incident/maintenance notification targeting (why a subscriber was or wasn't matched, and whether a queue row was actually created for each channel), so an "I created an incident but nobody was notified" report can be diagnosed from Service Status > Logs (with Settings > Logging set to Debug) instead of guesswork. No behaviour change to who gets notified, only visibility into the decision. Also fixes a harmless PHP warning when queuing a brand-new incident's initial notification (a null property access in the de-duplication key).

= 1.2.3 =
Notifications now go out immediately instead of waiting for the next cron tick. Subscribing sends that subscriber's own confirmation email/SMS synchronously, straight away, before the page even redirects. Every other queued notification (incident/maintenance updates, verification links, etc.) now triggers a non-blocking background request to the plugin's own cron endpoint the moment it's queued, so it's typically sent within a second or two rather than on the next WP-Cron tick - this is best-effort (some hosts block loopback requests), so WP-Cron/real server cron and the "Process notification queue now" tool remain in place as a fallback and continue to work exactly as before.

= 1.2.2 =
Adds a "Process notification queue now" button under Tools (alongside "Run monitor checks now") and a "Last notification queue run" diagnostic, so an administrator can immediately flush pending confirmation/notification emails and SMS - and see exactly why one failed - without waiting on WP-Cron or setting up real server cron first. No functional change to how notifications are sent, only a manual trigger and better visibility for troubleshooting "I never received anything" reports.

= 1.2.1 =
Fixes the top "Get status updates" button rendering in the wrong colours on some themes (a theme's own global button styling was overriding the plugin's, since both had similar CSS specificity) and restyles it to match the rest of the design system (same solid brand-blue button used elsewhere on the page, just larger) instead of a separate gradient look.

= 1.2.0 =
Adds an optional built-in SMTP relay (Settings > Outgoing Mail) so outgoing email can be routed through a specific mail server without a separate SMTP plugin. Moves the public status page's "Get status updates" button to the top of the page (just under the hero) with a brighter, higher-contrast style.

= 1.1.0 =
Complete visual/UX redesign of the public status page (design system, dark mode, expandable services, uptime tooltips, subscribe wizard, optional sticky header, live refresh) and a polished admin dashboard/monitors/services screen. Adds a `checks_degraded` column to `monitor_aggregates` (additive migration) so monitor checks distinguish "degraded" from "down".

= 1.0.0 =
Initial release.
