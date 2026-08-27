<?php
/**
 * Admin view: system information, manual actions, and uninstall preference.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$queue_pending = $wpdb->get_var( "SELECT COUNT(*) FROM " . ssm_table( 'notification_queue' ) . " WHERE status IN ('pending','retry_scheduled')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$queue_failed  = $wpdb->get_var( "SELECT COUNT(*) FROM " . ssm_table( 'notification_queue' ) . " WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$cron_secret = ssm_get_setting( 'cron_secret' );
$cron_url    = add_query_arg( 'token', $cron_secret, rest_url( 'service-status-manager/v1/cron/run' ) );

$active_providers = array( __( 'Email (wp_mail)', 'service-status-manager' ) );
if ( ! empty( ssm_get_setting( 'sms_credentials_encrypted' ) ) ) {
	$active_providers[] = sprintf( 'SMS (%s)', ucfirst( ssm_get_setting( 'sms_provider' ) ) );
}
$active_providers[] = __( 'Microsoft Teams (Incoming Webhook)', 'service-status-manager' );
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Tools', 'service-status-manager' ); ?></h1>

	<h2><?php esc_html_e( 'Manual actions', 'service-status-manager' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-right:8px;">
		<?php wp_nonce_field( 'ssm_run_checks_now' ); ?>
		<input type="hidden" name="action" value="ssm_run_checks_now" />
		<?php submit_button( __( 'Run monitor checks now', 'service-status-manager' ), 'secondary', '', false ); ?>
	</form>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
		<?php wp_nonce_field( 'ssm_process_notifications_now' ); ?>
		<input type="hidden" name="action" value="ssm_process_notifications_now" />
		<?php submit_button( __( 'Process notification queue now', 'service-status-manager' ), 'secondary', '', false ); ?>
	</form>
	<p class="description"><?php esc_html_e( 'Notifications (confirmation emails/SMS, incident updates, etc.) are queued and normally sent by WP-Cron or your server cron job within a minute or so. If subscribers report never receiving anything, use this button to process the queue immediately and check the Notifications screen for the outcome - it will show exactly why a message failed (e.g. no SMTP/SMS provider configured) rather than just going quiet.', 'service-status-manager' ); ?></p>

	<h2><?php esc_html_e( 'Server cron / WP-CLI setup', 'service-status-manager' ); ?></h2>
	<p><?php esc_html_e( 'WordPress Cron (WP-Cron) only runs when your site receives traffic, so it cannot guarantee timely monitor checks on a low-traffic site. For production use, disable WP-Cron\'s HTTP trigger and use either a real system cron job calling the secured endpoint below, or WP-CLI:', 'service-status-manager' ); ?></p>
	<p><code>define( 'DISABLE_WP_CRON', true );</code> <?php esc_html_e( '(in wp-config.php)', 'service-status-manager' ); ?></p>
	<p><strong><?php esc_html_e( 'Option A: system cron calling the secured REST endpoint', 'service-status-manager' ); ?></strong></p>
	<p><code>* * * * * curl -fsS "<?php echo esc_url( $cron_url ); ?>" &gt;/dev/null</code></p>
	<p class="description"><?php esc_html_e( 'This URL includes a secret token - treat it like a password. It only ever returns aggregate counts, never monitor configuration or credentials.', 'service-status-manager' ); ?></p>
	<p><strong><?php esc_html_e( 'Option B: WP-CLI', 'service-status-manager' ); ?></strong></p>
	<p><code>* * * * * cd /path/to/wordpress &amp;&amp; wp service-status run-checks --quiet</code></p>
	<p><code>*/5 * * * * cd /path/to/wordpress &amp;&amp; wp service-status process-notifications --quiet</code></p>

	<h2><?php esc_html_e( 'System information', 'service-status-manager' ); ?></h2>
	<table class="wp-list-table widefat fixed striped">
		<tbody>
			<tr><td><?php esc_html_e( 'Plugin version', 'service-status-manager' ); ?></td><td><?php echo esc_html( SSM_VERSION ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Database schema version', 'service-status-manager' ); ?></td><td><?php echo esc_html( get_option( 'ssm_db_version', '—' ) ); ?></td></tr>
			<tr><td><?php esc_html_e( 'WordPress version', 'service-status-manager' ); ?></td><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
			<tr><td><?php esc_html_e( 'PHP version', 'service-status-manager' ); ?></td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
			<tr><td><?php esc_html_e( 'MySQL/MariaDB version', 'service-status-manager' ); ?></td><td><?php echo esc_html( $wpdb->db_version() ); ?></td></tr>
			<tr><td><?php esc_html_e( 'WP-Cron status', 'service-status-manager' ); ?></td><td><?php echo esc_html( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? __( 'Disabled (using real cron / WP-CLI)', 'service-status-manager' ) : __( 'Enabled (default WP-Cron)', 'service-status-manager' ) ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Notification queue: pending', 'service-status-manager' ); ?></td><td><?php echo esc_html( $queue_pending ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Notification queue: failed', 'service-status-manager' ); ?></td><td><?php echo esc_html( $queue_failed ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Last monitor run', 'service-status-manager' ); ?></td><td><?php echo esc_html( wp_next_scheduled( \ServiceStatusManager\Cron::RUN_MONITOR_CHECKS ) ? ssm_format_datetime( gmdate( 'Y-m-d H:i:s', wp_next_scheduled( \ServiceStatusManager\Cron::RUN_MONITOR_CHECKS ) - MINUTE_IN_SECONDS ) ) : '—' ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Last notification queue run', 'service-status-manager' ); ?></td><td><?php $last_notif_run = get_option( 'ssm_last_notification_run' ); echo esc_html( $last_notif_run ? ssm_format_datetime( $last_notif_run ) : __( 'Never - check your cron setup below', 'service-status-manager' ) ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Last aggregation run (hourly)', 'service-status-manager' ); ?></td><td><?php echo esc_html( get_option( 'ssm_last_hourly_aggregate', '—' ) ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Last cleanup run', 'service-status-manager' ); ?></td><td><?php echo esc_html( get_option( 'ssm_last_cleanup_run', '—' ) ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Active notification providers', 'service-status-manager' ); ?></td><td><?php echo esc_html( implode( ', ', $active_providers ) ); ?></td></tr>
			<tr><td><?php esc_html_e( 'Object cache active', 'service-status-manager' ); ?></td><td><?php echo wp_using_ext_object_cache() ? esc_html__( 'Yes', 'service-status-manager' ) : esc_html__( 'No (recommended for large deployments)', 'service-status-manager' ); ?></td></tr>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'No credentials or secrets are shown on this page.', 'service-status-manager' ); ?></p>

	<?php if ( current_user_can( Capabilities::MANAGE_SETTINGS ) ) : ?>
		<h2><?php esc_html_e( 'Uninstall', 'service-status-manager' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ssm_uninstall_setting' ); ?>
			<input type="hidden" name="action" value="ssm_uninstall_setting" />
			<label>
				<input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( get_option( 'ssm_uninstall_delete_data', false ) ); ?> />
				<?php esc_html_e( 'Delete all plugin data (services, monitors, incidents, subscribers, logs, settings) when the plugin is uninstalled.', 'service-status-manager' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Unchecked by default: deactivating or uninstalling the plugin never deletes data unless you explicitly opt in here.', 'service-status-manager' ); ?></p>
			<?php submit_button( __( 'Save Uninstall Preference', 'service-status-manager' ), 'secondary', '', false ); ?>
		</form>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Privacy & UK GDPR', 'service-status-manager' ); ?></h2>
	<p><?php esc_html_e( 'This plugin provides tools (consent capture, double opt-in, data export, data erasure, configurable retention) that support UK GDPR compliance, but installing it does not by itself make your website compliant. You remain responsible for your lawful basis for processing, your published privacy notice, any contracts with your email/SMS/Teams providers, and your organisation\'s retention policy.', 'service-status-manager' ); ?></p>
</div>
