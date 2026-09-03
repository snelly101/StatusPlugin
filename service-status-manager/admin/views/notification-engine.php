<?php
/**
 * Admin view: Notification Engine - queue depth, dispatcher run history,
 * provider circuit-breaker state, and a queue-aging warning, so an admin
 * can tell at a glance whether notifications are actually flowing.
 *
 * Server-rendered, no new REST surface - every figure here is a plain
 * query against tables the plugin already owns.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\Notifications\NotificationQueue;
use ServiceStatusManager\Notifications\NotificationCircuitBreaker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$queue_table  = ssm_table( 'notification_queue' );
$runs_table   = ssm_table( 'notification_runs' );
$events_table = ssm_table( 'notification_events' );

$channels    = array( 'email', 'sms', 'teams' );
$channel_icons = array( 'email' => 'mail', 'sms' => 'smartphone', 'teams' => 'message-square' );
$channel_rows = array();

foreach ( $channels as $channel ) {
	$counts = $wpdb->get_results(
		$wpdb->prepare( "SELECT status, COUNT(*) AS total FROM {$queue_table} WHERE channel = %s GROUP BY status", $channel ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	);

	$by_status = array();
	foreach ( $counts as $row ) {
		$by_status[ $row->status ] = (int) $row->total;
	}

	$slug    = NotificationQueue::resolve_provider_slug( $channel );
	$breaker = NotificationCircuitBreaker::get_state( $slug );

	$channel_rows[ $channel ] = array(
		'queued'     => ( $by_status['pending'] ?? 0 ) + ( $by_status['retry_scheduled'] ?? 0 ),
		'processing' => $by_status['processing'] ?? 0,
		'failed'     => $by_status['failed'] ?? 0,
		'sent'       => $by_status['sent'] ?? 0,
		'provider'   => $slug,
		'breaker'    => $breaker,
	);
}

$oldest_due = $wpdb->get_var(
	$wpdb->prepare(
		"SELECT MIN(next_attempt_at) FROM {$queue_table} WHERE status IN ('pending','retry_scheduled') AND next_attempt_at <= %s",
		ssm_now()
	)
); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$oldest_due_minutes = $oldest_due ? (int) round( ( time() - strtotime( $oldest_due . ' UTC' ) ) / 60 ) : 0;
$pending_events     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events_table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$last_run       = $wpdb->get_row( "SELECT * FROM {$runs_table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$recent_runs    = $wpdb->get_results( "SELECT * FROM {$runs_table} ORDER BY id DESC LIMIT 10" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$last_run_option = get_option( 'ssm_last_notification_run' );

$minutes_since_last_run = $last_run_option ? ( time() - strtotime( $last_run_option . ' UTC' ) ) / 60 : null;

// This is an inference, not a direct measurement: WP-Cron/host cron are
// both configured to run roughly every minute, and the immediate-processing
// loopback fires within seconds of anything being queued - so if nothing
// has run in several minutes, *something* in that chain likely isn't
// working (loopback requests blocked, WP-Cron's HTTP trigger disabled with
// no real cron configured to replace it, etc.), though a genuinely idle
// site with nothing to send can also look like this.
if ( null === $minutes_since_last_run ) {
	$cron_inference = array( 'label' => __( 'Unknown - the dispatcher has never run on this site yet.', 'service-status-manager' ), 'ok' => false );
} elseif ( $minutes_since_last_run <= 3 ) {
	$cron_inference = array( 'label' => __( 'Looks active - a dispatcher run happened within the last few minutes.', 'service-status-manager' ), 'ok' => true );
} else {
	$cron_inference = array(
		'label' => sprintf(
			/* translators: %d: minutes since the last dispatcher run */
			__( 'No dispatcher run in %d minute(s) - check your WP-Cron/real cron setup on the Tools page (this can also just mean nothing has needed sending).', 'service-status-manager' ),
			(int) $minutes_since_last_run
		),
		'ok'    => false,
	);
}

$breaker_labels = array(
	NotificationCircuitBreaker::STATE_CLOSED    => __( 'Closed (healthy)', 'service-status-manager' ),
	NotificationCircuitBreaker::STATE_OPEN      => __( 'Open (paused)', 'service-status-manager' ),
	NotificationCircuitBreaker::STATE_HALF_OPEN => __( 'Half-open (testing)', 'service-status-manager' ),
);
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Notification Engine', 'service-status-manager' ); ?></h1>
	<p class="description"><?php esc_html_e( 'How the notification queue is actually performing right now - queue depth per channel, the last dispatcher run, and each provider\'s circuit-breaker state.', 'service-status-manager' ); ?></p>

	<?php if ( $oldest_due_minutes >= 15 ) : ?>
		<div class="notice notice-error"><p>
			<?php
			printf(
				/* translators: %d: minutes */
				esc_html__( 'The oldest due notification has been waiting %d minutes. Check the provider status below and your cron setup on the Tools page.', 'service-status-manager' ),
				(int) $oldest_due_minutes
			);
			?>
		</p></div>
	<?php elseif ( $oldest_due_minutes >= 5 ) : ?>
		<div class="notice notice-warning"><p>
			<?php
			printf(
				/* translators: %d: minutes */
				esc_html__( 'The oldest due notification has been waiting %d minutes - longer than expected if cron is running normally.', 'service-status-manager' ),
				(int) $oldest_due_minutes
			);
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( ! $cron_inference['ok'] ) : ?>
		<div class="notice notice-warning"><p><?php echo esc_html( $cron_inference['label'] ); ?></p></div>
	<?php endif; ?>

	<div class="ssm-stat-grid">
		<?php foreach ( $channel_rows as $channel => $row ) : ?>
			<div class="ssm-stat-card <?php echo ( $row['failed'] > 0 || NotificationCircuitBreaker::STATE_CLOSED !== $row['breaker']['state'] ) ? 'ssm-stat-card--warn' : ''; ?>">
				<span class="ssm-stat-card-icon"><?php echo ssm_icon( $channel_icons[ $channel ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="ssm-stat-card-body">
					<span class="ssm-stat-value"><?php echo esc_html( $row['queued'] ); ?></span>
					<span class="ssm-stat-label">
						<?php echo esc_html( ucfirst( $channel ) ); ?> - <?php esc_html_e( 'queued', 'service-status-manager' ); ?>
						<?php if ( $row['failed'] > 0 ) : ?>
							&nbsp;·&nbsp;<?php echo esc_html( sprintf( _n( '%d failed', '%d failed', $row['failed'], 'service-status-manager' ), $row['failed'] ) ); ?>
						<?php endif; ?>
					</span>
				</span>
			</div>
		<?php endforeach; ?>
		<div class="ssm-stat-card">
			<span class="ssm-stat-card-icon"><?php echo ssm_icon( 'inbox' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="ssm-stat-card-body">
				<span class="ssm-stat-value"><?php echo esc_html( $pending_events ); ?></span>
				<span class="ssm-stat-label"><?php esc_html_e( 'Events awaiting fan-out', 'service-status-manager' ); ?></span>
			</span>
		</div>
	</div>

	<div class="ssm-dashboard-section">
		<h2><?php esc_html_e( 'Providers', 'service-status-manager' ); ?></h2>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Channel', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'Provider', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'Circuit breaker', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'Consecutive failures', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'Sent (all time)', 'service-status-manager' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $channel_rows as $channel => $row ) : ?>
					<tr>
						<td><?php echo esc_html( ucfirst( $channel ) ); ?></td>
						<td><?php echo esc_html( $row['provider'] ); ?></td>
						<td><?php echo esc_html( $breaker_labels[ $row['breaker']['state'] ] ?? $row['breaker']['state'] ); ?></td>
						<td><?php echo esc_html( $row['breaker']['consecutive_failures'] ); ?></td>
						<td><?php echo esc_html( $row['sent'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'A provider stays "closed" (healthy) as long as it isn\'t failing repeatedly. It opens (pauses sending) after several transient failures in a row, or immediately on an authentication failure, and automatically tries again after a short cooldown.', 'service-status-manager' ); ?></p>
	</div>

	<div class="ssm-dashboard-section">
		<h2><?php esc_html_e( 'Recent dispatcher runs', 'service-status-manager' ); ?></h2>
		<?php if ( empty( $recent_runs ) ) : ?>
			<div class="ssm-dashboard-card"><p><?php esc_html_e( 'No dispatcher runs recorded yet.', 'service-status-manager' ); ?></p></div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Started', 'service-status-manager' ); ?></th>
					<th><?php esc_html_e( 'Trigger', 'service-status-manager' ); ?></th>
					<th><?php esc_html_e( 'Events fanned out', 'service-status-manager' ); ?></th>
					<th><?php esc_html_e( 'Claimed', 'service-status-manager' ); ?></th>
					<th><?php esc_html_e( 'Sent', 'service-status-manager' ); ?></th>
					<th><?php esc_html_e( 'Failed', 'service-status-manager' ); ?></th>
					<th><?php esc_html_e( 'Chained?', 'service-status-manager' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $recent_runs as $run ) : ?>
						<tr>
							<td><?php echo esc_html( ssm_format_datetime( $run->started_at ) ); ?></td>
							<td><?php echo esc_html( $run->trigger_source ); ?></td>
							<td><?php echo esc_html( $run->events_fanned_out ); ?></td>
							<td><?php echo esc_html( $run->rows_claimed ); ?></td>
							<td><?php echo esc_html( $run->rows_sent ); ?></td>
							<td><?php echo esc_html( $run->rows_failed ); ?></td>
							<td><?php echo $run->chained_next ? esc_html__( 'Yes', 'service-status-manager' ) : esc_html__( 'No', 'service-status-manager' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<p class="description">
		<?php
		echo wp_kses_post(
			sprintf(
				/* translators: 1: link to the Tools page, 2: WP-CLI command examples */
				__( 'Manual actions (run the queue now, view cron setup instructions) live on the %1$s page. WP-CLI: %2$s.', 'service-status-manager' ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=service-status-manager-tools' ) ) . '">' . esc_html__( 'Tools', 'service-status-manager' ) . '</a>',
				'<code>wp service-status queue status</code>, <code>wp service-status provider health</code>, <code>wp service-status queue retry-failed</code>'
			)
		);
		?>
	</p>
</div>
