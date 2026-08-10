<?php
/**
 * Admin view: plugin settings (general, notifications, SMS provider,
 * data retention, security), plus outgoing/incoming webhook management.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\Encryption;
use ServiceStatusManager\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = ssm_get_settings();

global $wpdb;
$webhooks_out = $wpdb->get_results( 'SELECT * FROM ' . ssm_table( 'webhooks_outgoing' ) . ' ORDER BY id DESC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$webhooks_in  = $wpdb->get_results( 'SELECT * FROM ' . ssm_table( 'webhooks_incoming' ) . ' ORDER BY id DESC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$sms_credentials = json_decode( (string) Encryption::decrypt( $settings['sms_credentials_encrypted'] ), true ) ?: array();

$outgoing_events = array(
	'incident.created', 'incident.updated', 'incident.resolved',
	'maintenance.started', 'maintenance.completed', 'maintenance.cancelled',
	'service.status_changed', 'monitor.status_changed',
);
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Settings', 'service-status-manager' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssm_save_settings' ); ?>
		<input type="hidden" name="action" value="ssm_save_settings" />

		<h2><?php esc_html_e( 'General', 'service-status-manager' ); ?></h2>
		<table class="form-table">
			<tr><th><label for="ssm-from-name"><?php esc_html_e( 'Email sender name', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-from-name" name="from_name" class="regular-text" value="<?php echo esc_attr( $settings['from_name'] ); ?>" /></td></tr>
			<tr><th><label for="ssm-from-email"><?php esc_html_e( 'Email sender address', 'service-status-manager' ); ?></label></th>
				<td><input type="email" id="ssm-from-email" name="from_email" class="regular-text" value="<?php echo esc_attr( $settings['from_email'] ); ?>" /></td></tr>
			<tr><th><label for="ssm-team-name"><?php esc_html_e( 'Public team name', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-team-name" name="public_team_name" class="regular-text" value="<?php echo esc_attr( $settings['public_team_name'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Shown as the author of incident updates when no WordPress display name is used.', 'service-status-manager' ); ?></p></td></tr>
			<tr><th><label for="ssm-privacy-notice"><?php esc_html_e( 'Privacy notice', 'service-status-manager' ); ?></label></th>
				<td><textarea id="ssm-privacy-notice" name="privacy_notice" rows="3" class="large-text"><?php echo esc_textarea( $settings['privacy_notice'] ); ?></textarea></td></tr>
			<tr><th><label for="ssm-consent-version"><?php esc_html_e( 'Consent wording version', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-consent-version" name="consent_wording_version" value="<?php echo esc_attr( $settings['consent_wording_version'] ); ?>" />
				<p class="description"><?php esc_html_e( 'Increment this whenever you materially change the privacy notice, so you can identify which wording a subscriber consented to.', 'service-status-manager' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Retain IP addresses', 'service-status-manager' ); ?></th>
				<td><label><input type="checkbox" name="retain_ip_addresses" value="1" <?php checked( $settings['retain_ip_addresses'] ); ?> /> <?php esc_html_e( 'Store subscriber/audit-log IP addresses', 'service-status-manager' ); ?></label></td></tr>
		</table>

		<h2><?php esc_html_e( 'Notification Rules', 'service-status-manager' ); ?></h2>
		<table class="form-table">
			<tr><th><label for="ssm-min-severity"><?php esc_html_e( 'Do not notify below severity', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-min-severity" name="min_notify_severity">
						<?php foreach ( array( 'informational', 'minor', 'major', 'critical' ) as $level ) : ?>
							<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $settings['min_notify_severity'], $level ); ?>><?php echo esc_html( ucfirst( $level ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
			<tr><th><label for="ssm-suppress-recovery"><?php esc_html_e( 'Suppress recovery notice if incident lasted under (minutes)', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-suppress-recovery" name="suppress_short_incident_recovery_minutes" value="<?php echo esc_attr( $settings['suppress_short_incident_recovery_minutes'] ); ?>" min="0" /> <span class="description"><?php esc_html_e( '0 disables.', 'service-status-manager' ); ?></span></td></tr>
			<tr><th><?php esc_html_e( 'SMS quiet hours', 'service-status-manager' ); ?></th>
				<td>
					<input type="time" name="quiet_hours_start" value="<?php echo esc_attr( $settings['quiet_hours_start'] ); ?>" />
					<?php esc_html_e( 'to', 'service-status-manager' ); ?>
					<input type="time" name="quiet_hours_end" value="<?php echo esc_attr( $settings['quiet_hours_end'] ); ?>" />
					<input type="text" name="quiet_hours_timezone" value="<?php echo esc_attr( $settings['quiet_hours_timezone'] ); ?>" style="width:220px;" placeholder="<?php echo esc_attr( wp_timezone_string() ); ?>" />
					<p class="description"><?php esc_html_e( 'SMS sent during this window is deferred until it ends. Critical-severity messages always override quiet hours. Leave both times blank to disable.', 'service-status-manager' ); ?></p>
				</td></tr>
			<tr><th><label for="ssm-max-sms"><?php esc_html_e( 'Max SMS per subscriber per day', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-max-sms" name="max_sms_per_subscriber_per_day" value="<?php echo esc_attr( $settings['max_sms_per_subscriber_per_day'] ); ?>" min="0" /></td></tr>
		</table>

		<h2><?php esc_html_e( 'SMS Provider', 'service-status-manager' ); ?></h2>
		<table class="form-table">
			<tr><th><label for="ssm-sms-provider"><?php esc_html_e( 'Provider', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-sms-provider" name="sms_provider">
						<?php foreach ( apply_filters( 'ssm_sms_providers', array( 'twilio' => '' ) ) as $slug => $class ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['sms_provider'], $slug ); ?>><?php echo esc_html( ucfirst( $slug ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</td></tr>
			<tr><th><label for="ssm-sms-sender"><?php esc_html_e( 'Sender number / ID', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-sms-sender" name="sms_sender" value="<?php echo esc_attr( $settings['sms_sender'] ); ?>" /></td></tr>
			<tr><th><label for="ssm-sms-country"><?php esc_html_e( 'Default country', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-sms-country" name="sms_default_country" value="<?php echo esc_attr( $settings['sms_default_country'] ); ?>" style="width:80px;" /></td></tr>
			<tr><th><label for="ssm-sms-sid"><?php esc_html_e( 'Twilio Account SID', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-sms-sid" name="sms_account_sid" class="regular-text" placeholder="<?php echo esc_attr( ! empty( $sms_credentials['account_sid'] ) ? Encryption::mask( $sms_credentials['account_sid'] ) : '' ); ?>" autocomplete="off" />
				<p class="description"><?php esc_html_e( 'Leave blank to keep the currently saved value. Never displayed in full after saving.', 'service-status-manager' ); ?></p></td></tr>
			<tr><th><label for="ssm-sms-token"><?php esc_html_e( 'Twilio Auth Token', 'service-status-manager' ); ?></label></th>
				<td><input type="password" id="ssm-sms-token" name="sms_auth_token" class="regular-text" placeholder="<?php echo esc_attr( ! empty( $sms_credentials['auth_token'] ) ? Encryption::mask( $sms_credentials['auth_token'] ) : '' ); ?>" autocomplete="off" /></td></tr>
			<tr><th><label for="ssm-sms-maxlen"><?php esc_html_e( 'Maximum message length', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-sms-maxlen" name="sms_max_length" value="<?php echo esc_attr( $settings['sms_max_length'] ); ?>" /></td></tr>
			<tr><th><?php esc_html_e( 'Long message handling', 'service-status-manager' ); ?></th>
				<td>
					<select name="sms_truncate_mode">
						<option value="truncate" <?php selected( $settings['sms_truncate_mode'], 'truncate' ); ?>><?php esc_html_e( 'Truncate', 'service-status-manager' ); ?></option>
						<option value="split" <?php selected( $settings['sms_truncate_mode'], 'split' ); ?>><?php esc_html_e( 'Allow multi-part (let carrier split)', 'service-status-manager' ); ?></option>
					</select>
				</td></tr>
			<tr><th><label for="ssm-sms-monthly"><?php esc_html_e( 'Monthly send limit', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-sms-monthly" name="sms_monthly_limit" value="<?php echo esc_attr( $settings['sms_monthly_limit'] ); ?>" min="0" /> <span class="description"><?php esc_html_e( '0 = unlimited.', 'service-status-manager' ); ?></span></td></tr>
			<tr><th><label for="ssm-sms-per-incident"><?php esc_html_e( 'Per-incident SMS limit', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-sms-per-incident" name="sms_per_incident_limit" value="<?php echo esc_attr( $settings['sms_per_incident_limit'] ); ?>" min="0" /> <span class="description"><?php esc_html_e( '0 = unlimited.', 'service-status-manager' ); ?></span></td></tr>
		</table>

		<h2><?php esc_html_e( 'Data Retention', 'service-status-manager' ); ?></h2>
		<table class="form-table">
			<tr><th><label for="ssm-ret-raw"><?php esc_html_e( 'Raw monitor checks (days)', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-ret-raw" name="raw_check_retention_days" value="<?php echo esc_attr( $settings['raw_check_retention_days'] ); ?>" min="1" /></td></tr>
			<tr><th><label for="ssm-ret-hourly"><?php esc_html_e( 'Hourly aggregates (days)', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-ret-hourly" name="hourly_aggregate_retention_days" value="<?php echo esc_attr( $settings['hourly_aggregate_retention_days'] ); ?>" min="1" /></td></tr>
			<tr><th><label for="ssm-ret-daily"><?php esc_html_e( 'Daily aggregates (days)', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-ret-daily" name="daily_aggregate_retention_days" value="<?php echo esc_attr( $settings['daily_aggregate_retention_days'] ); ?>" min="1" /></td></tr>
			<tr><th><label for="ssm-ret-notif"><?php esc_html_e( 'Notification logs (days)', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-ret-notif" name="notification_log_retention_days" value="<?php echo esc_attr( $settings['notification_log_retention_days'] ); ?>" min="1" /></td></tr>
			<tr><th><label for="ssm-ret-audit"><?php esc_html_e( 'Audit log (days)', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-ret-audit" name="audit_log_retention_days" value="<?php echo esc_attr( $settings['audit_log_retention_days'] ); ?>" min="1" /></td></tr>
		</table>

		<h2><?php esc_html_e( 'Logging', 'service-status-manager' ); ?></h2>
		<table class="form-table">
			<tr><th><label for="ssm-log-level"><?php esc_html_e( 'Log level', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-log-level" name="log_level">
						<?php foreach ( array( 'error', 'warning', 'info', 'debug' ) as $level ) : ?>
							<option value="<?php echo esc_attr( $level ); ?>" <?php selected( $settings['log_level'], $level ); ?>><?php echo esc_html( ucfirst( $level ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Debug logging should stay off in normal production use.', 'service-status-manager' ); ?></p>
				</td></tr>
			<tr><th><label for="ssm-log-retention"><?php esc_html_e( 'Log retention (days)', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-log-retention" name="log_retention_days" value="<?php echo esc_attr( $settings['log_retention_days'] ); ?>" min="1" /></td></tr>
		</table>

		<h2><?php esc_html_e( 'SSRF Protection', 'service-status-manager' ); ?></h2>
		<table class="form-table">
			<tr><th><label for="ssm-ssrf-allowlist"><?php esc_html_e( 'Internal monitoring allow-list', 'service-status-manager' ); ?></label></th>
				<td><textarea id="ssm-ssrf-allowlist" name="ssrf_allowlist" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $settings['ssrf_allowlist'] ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'One hostname or IP address per line. Monitors targeting a private/internal address are blocked by default (see the SSRF protection notes in readme.txt) unless the exact hostname/IP appears here, or the individual monitor has "Allow internal addresses" enabled.', 'service-status-manager' ); ?></p></td></tr>
		</table>

		<?php submit_button( __( 'Save Settings', 'service-status-manager' ) ); ?>
	</form>

	<?php if ( current_user_can( Capabilities::MANAGE_INTEGRATIONS ) ) : ?>
	<h2><?php esc_html_e( 'Outgoing Webhooks', 'service-status-manager' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr><th><?php esc_html_e( 'Name', 'service-status-manager' ); ?></th><th><?php esc_html_e( 'URL', 'service-status-manager' ); ?></th><th><?php esc_html_e( 'Active', 'service-status-manager' ); ?></th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $webhooks_out as $hook ) : ?>
			<tr>
				<td><?php echo esc_html( $hook->name ); ?></td>
				<td><?php echo esc_html( $hook->url ); ?></td>
				<td><?php echo $hook->is_active ? esc_html__( 'Yes', 'service-status-manager' ) : esc_html__( 'No', 'service-status-manager' ); ?></td>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<?php wp_nonce_field( 'ssm_save_webhook_out' ); ?>
						<input type="hidden" name="action" value="ssm_save_webhook_out" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $hook->id ); ?>" />
						<input type="hidden" name="name" value="<?php echo esc_attr( $hook->name ); ?>" />
						<input type="hidden" name="url" value="<?php echo esc_attr( $hook->url ); ?>" />
						<input type="hidden" name="is_active" value="<?php echo esc_attr( $hook->is_active ); ?>" />
						<?php foreach ( json_decode( (string) $hook->events, true ) ?: array() as $e ) : ?>
							<input type="hidden" name="events[]" value="<?php echo esc_attr( $e ); ?>" />
						<?php endforeach; ?>
						<button type="submit" name="send_test" value="1" class="button-link"><?php esc_html_e( 'Test', 'service-status-manager' ); ?></button>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this webhook?', 'service-status-manager' ) ); ?>');">
						<?php wp_nonce_field( 'ssm_delete_webhook_out' ); ?>
						<input type="hidden" name="action" value="ssm_delete_webhook_out" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $hook->id ); ?>" />
						<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'service-status-manager' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssm_save_webhook_out' ); ?>
		<input type="hidden" name="action" value="ssm_save_webhook_out" />
		<table class="form-table">
			<tr><th><label for="ssm-wh-name"><?php esc_html_e( 'Name', 'service-status-manager' ); ?></label></th><td><input type="text" id="ssm-wh-name" name="name" required /></td></tr>
			<tr><th><label for="ssm-wh-url"><?php esc_html_e( 'URL', 'service-status-manager' ); ?></label></th><td><input type="url" id="ssm-wh-url" name="url" class="regular-text" required /></td></tr>
			<tr><th><label for="ssm-wh-secret"><?php esc_html_e( 'Secret (leave blank to auto-generate)', 'service-status-manager' ); ?></label></th><td><input type="text" id="ssm-wh-secret" name="secret" class="regular-text" /></td></tr>
			<tr><th><label for="ssm-wh-timeout"><?php esc_html_e( 'Timeout (seconds)', 'service-status-manager' ); ?></label></th><td><input type="number" id="ssm-wh-timeout" name="timeout_seconds" value="10" min="1" max="30" /></td></tr>
			<tr><th><?php esc_html_e( 'Events', 'service-status-manager' ); ?></th>
				<td><?php foreach ( $outgoing_events as $event ) : ?>
					<label class="ssm-checkbox-row-admin"><input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>" /> <?php echo esc_html( $event ); ?></label><br />
				<?php endforeach; ?></td></tr>
			<tr><th><?php esc_html_e( 'Active', 'service-status-manager' ); ?></th><td><label><input type="checkbox" name="is_active" value="1" checked /> <?php esc_html_e( 'Enabled', 'service-status-manager' ); ?></label></td></tr>
		</table>
		<?php submit_button( __( 'Add Outgoing Webhook', 'service-status-manager' ), 'secondary' ); ?>
	</form>

	<h2><?php esc_html_e( 'Incoming Webhooks', 'service-status-manager' ); ?></h2>
	<p class="description"><?php echo wp_kses_post( sprintf(
		/* translators: %s: REST endpoint base URL */
		__( 'External monitoring systems can POST to <code>%s/webhook/{id}</code> using the integration secret. See the REST API documentation in readme.txt for the signature format.', 'service-status-manager' ),
		esc_url( rest_url( 'service-status-manager/v1' ) )
	) ); ?></p>
	<table class="wp-list-table widefat fixed striped">
		<thead><tr><th><?php esc_html_e( 'ID', 'service-status-manager' ); ?></th><th><?php esc_html_e( 'Name', 'service-status-manager' ); ?></th><th><?php esc_html_e( 'Active', 'service-status-manager' ); ?></th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $webhooks_in as $hook ) : ?>
			<tr>
				<td><?php echo esc_html( $hook->id ); ?></td>
				<td><?php echo esc_html( $hook->name ); ?></td>
				<td><?php echo $hook->is_active ? esc_html__( 'Yes', 'service-status-manager' ) : esc_html__( 'No', 'service-status-manager' ); ?></td>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this incoming webhook?', 'service-status-manager' ) ); ?>');">
						<?php wp_nonce_field( 'ssm_delete_webhook_in' ); ?>
						<input type="hidden" name="action" value="ssm_delete_webhook_in" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $hook->id ); ?>" />
						<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'service-status-manager' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssm_save_webhook_in' ); ?>
		<input type="hidden" name="action" value="ssm_save_webhook_in" />
		<table class="form-table">
			<tr><th><label for="ssm-whi-name"><?php esc_html_e( 'Name', 'service-status-manager' ); ?></label></th><td><input type="text" id="ssm-whi-name" name="name" required /></td></tr>
			<tr><th><label for="ssm-whi-ips"><?php esc_html_e( 'IP allow-list (optional, one per line)', 'service-status-manager' ); ?></label></th><td><textarea id="ssm-whi-ips" name="allowed_ips" rows="3" class="large-text code"></textarea></td></tr>
			<tr><th><?php esc_html_e( 'Active', 'service-status-manager' ); ?></th><td><label><input type="checkbox" name="is_active" value="1" checked /> <?php esc_html_e( 'Enabled', 'service-status-manager' ); ?></label></td></tr>
		</table>
		<?php submit_button( __( 'Add Incoming Webhook', 'service-status-manager' ), 'secondary' ); ?>
	</form>
	<?php endif; ?>
</div>
