<?php
/**
 * Admin dashboard view: at-a-glance plugin health.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\IncidentManager;
use ServiceStatusManager\MaintenanceManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$services = ServiceManager::get_services();
$counts   = array_fill_keys( array_keys( ssm_get_status_definitions() ), 0 );
foreach ( $services as $service ) {
	if ( isset( $counts[ $service->status ] ) ) {
		++$counts[ $service->status ];
	}
}

$active_incidents     = IncidentManager::get_active_incidents();
$upcoming_maintenance = MaintenanceManager::get_upcoming();
$monitors_table       = ssm_table( 'monitors' );
$total_monitors       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$monitors_table} WHERE is_active = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$online_monitors      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$monitors_table} WHERE is_active = 1 AND current_state NOT IN ('major_outage','partial_outage')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$overdue_monitors     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$monitors_table} WHERE is_active = 1 AND type != 'manual' AND next_check_at < %s", ssm_now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$failed_monitors      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$monitors_table} WHERE current_state IN ('major_outage','partial_outage')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$queue_size           = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ssm_table( 'notification_queue' ) . " WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$failed_notifications = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ssm_table( 'notification_queue' ) . " WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$subscribers_table  = ssm_table( 'subscribers' );
$channels_table     = ssm_table( 'subscriber_channels' );
$subscriber_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subscribers_table} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$channel_counts     = $wpdb->get_results( "SELECT channel, COUNT(*) AS total FROM {$channels_table} WHERE is_active = 1 AND verified = 1 GROUP BY channel" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$channel_totals     = array( 'email' => 0, 'teams' => 0, 'sms' => 0 );
foreach ( $channel_counts as $row ) {
	if ( isset( $channel_totals[ $row->channel ] ) ) {
		$channel_totals[ $row->channel ] = (int) $row->total;
	}
}

$overall     = ServiceManager::get_overall_status();
$overall_def = ssm_get_status_definition( $overall );
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Service Status Manager', 'service-status-manager' ); ?></h1>

	<div class="ssm-overall-banner ssm-status-banner <?php echo esc_attr( $overall_def['css_class'] ); ?>">
		<?php echo ssm_icon( ssm_status_icon_name( $overall ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span>
			<strong><?php esc_html_e( 'Overall status:', 'service-status-manager' ); ?></strong>
			<?php echo esc_html( $overall_def['label'] ); ?>
		</span>
	</div>

	<div class="ssm-stat-grid">
		<?php
		$stat_cards = array(
			array( 'check-circle', $counts['operational'], __( 'Operational services', 'service-status-manager' ), false ),
			array( 'alert-triangle', $counts['degraded'] + $counts['partial_outage'] + $counts['major_outage'], __( 'Degraded / outage services', 'service-status-manager' ), $counts['major_outage'] > 0 ),
			array( 'alert-octagon', count( $active_incidents ), __( 'Active incidents', 'service-status-manager' ), count( $active_incidents ) > 0 ),
			array( 'calendar', count( $upcoming_maintenance ), __( 'Scheduled maintenance', 'service-status-manager' ), false ),
			array( 'server', $failed_monitors, __( 'Failing monitors', 'service-status-manager' ), $failed_monitors > 0 ),
			array( 'clock', $overdue_monitors, __( 'Checks overdue', 'service-status-manager' ), $overdue_monitors > 0 ),
			array( 'inbox', $queue_size, __( 'Notifications queued', 'service-status-manager' ), false ),
			array( 'alert-triangle', $failed_notifications, __( 'Failed notifications', 'service-status-manager' ), $failed_notifications > 0 ),
			array( 'users', $subscriber_count, __( 'Active subscribers', 'service-status-manager' ), false ),
		);
		foreach ( $stat_cards as $card ) :
			list( $icon, $value, $label, $warn ) = $card;
			?>
			<div class="ssm-stat-card <?php echo $warn ? 'ssm-stat-card--warn' : ''; ?>">
				<span class="ssm-stat-card-icon"><?php echo ssm_icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="ssm-stat-card-body">
					<span class="ssm-stat-value"><?php echo esc_html( $value ); ?></span>
					<span class="ssm-stat-label"><?php echo esc_html( $label ); ?></span>
				</span>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="ssm-dashboard-section">
		<h2><?php esc_html_e( 'Monitoring', 'service-status-manager' ); ?></h2>
		<div class="ssm-dashboard-card">
			<p>
				<strong><?php echo esc_html( $online_monitors ); ?> / <?php echo esc_html( $total_monitors ); ?></strong>
				<?php esc_html_e( 'monitors online', 'service-status-manager' ); ?>
				&nbsp;·&nbsp;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=service-status-manager-monitors' ) ); ?>"><?php esc_html_e( 'View all monitors', 'service-status-manager' ); ?></a>
			</p>
		</div>
	</div>

	<div class="ssm-dashboard-section">
		<h2><?php esc_html_e( 'Active incidents', 'service-status-manager' ); ?></h2>
		<?php if ( empty( $active_incidents ) ) : ?>
			<div class="ssm-dashboard-card"><p><?php esc_html_e( 'No active incidents.', 'service-status-manager' ); ?></p></div>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php esc_html_e( 'Title', 'service-status-manager' ); ?></th>
					<th><?php esc_html_e( 'Severity', 'service-status-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'service-status-manager' ); ?></th>
					<th><?php esc_html_e( 'Started', 'service-status-manager' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $active_incidents as $incident ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=service-status-manager-incidents&incident_id=' . $incident->id ) ); ?>"><?php echo esc_html( $incident->title ); ?></a></td>
						<td><?php echo esc_html( ucfirst( $incident->severity ) ); ?></td>
						<td><?php echo esc_html( ucfirst( $incident->status ) ); ?></td>
						<td><?php echo esc_html( ssm_format_datetime( $incident->starts_at ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="ssm-dashboard-section">
		<h2><?php esc_html_e( 'Upcoming maintenance', 'service-status-manager' ); ?></h2>
		<?php if ( empty( $upcoming_maintenance ) ) : ?>
			<div class="ssm-dashboard-card"><p><?php esc_html_e( 'Nothing scheduled.', 'service-status-manager' ); ?></p></div>
		<?php else : ?>
			<div class="ssm-dashboard-card">
				<?php $next = $upcoming_maintenance[0]; ?>
				<p>
					<strong><?php echo esc_html( $next->title ); ?></strong><br />
					<?php echo esc_html( ssm_format_datetime( $next->scheduled_start ) ); ?> &ndash; <?php echo esc_html( ssm_format_datetime( $next->scheduled_end ) ); ?>
				</p>
				<?php if ( count( $upcoming_maintenance ) > 1 ) : ?>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=service-status-manager-maintenance' ) ); ?>"><?php echo esc_html( sprintf(
						/* translators: %d: number of additional maintenance events */
						__( '+%d more scheduled', 'service-status-manager' ),
						count( $upcoming_maintenance ) - 1
					) ); ?></a></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="ssm-dashboard-section">
		<h2><?php esc_html_e( 'Subscribers', 'service-status-manager' ); ?></h2>
		<div class="ssm-stat-grid">
			<div class="ssm-stat-card">
				<span class="ssm-stat-card-icon"><?php echo ssm_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="ssm-stat-card-body">
					<span class="ssm-stat-value"><?php echo esc_html( $channel_totals['email'] ); ?></span>
					<span class="ssm-stat-label"><?php esc_html_e( 'Email', 'service-status-manager' ); ?></span>
				</span>
			</div>
			<div class="ssm-stat-card">
				<span class="ssm-stat-card-icon"><?php echo ssm_icon( 'message-square' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="ssm-stat-card-body">
					<span class="ssm-stat-value"><?php echo esc_html( $channel_totals['teams'] ); ?></span>
					<span class="ssm-stat-label"><?php esc_html_e( 'Microsoft Teams', 'service-status-manager' ); ?></span>
				</span>
			</div>
			<div class="ssm-stat-card">
				<span class="ssm-stat-card-icon"><?php echo ssm_icon( 'smartphone' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="ssm-stat-card-body">
					<span class="ssm-stat-value"><?php echo esc_html( $channel_totals['sms'] ); ?></span>
					<span class="ssm-stat-label"><?php esc_html_e( 'SMS', 'service-status-manager' ); ?></span>
				</span>
			</div>
		</div>
	</div>

	<div class="ssm-dashboard-section">
		<h2><?php esc_html_e( 'Recent activity', 'service-status-manager' ); ?></h2>
		<?php
		$recent = \ServiceStatusManager\AuditLog::query( array( 'per_page' => 10 ) );
		if ( empty( $recent['items'] ) ) :
			?>
			<div class="ssm-dashboard-card"><p><?php esc_html_e( 'No recent activity recorded yet.', 'service-status-manager' ); ?></p></div>
		<?php else : ?>
			<div class="ssm-dashboard-card">
				<ul class="ssm-activity-list">
					<?php foreach ( $recent['items'] as $entry ) : ?>
						<li>
							<code><?php echo esc_html( ssm_format_datetime( $entry->created_at ) ); ?></code>
							&mdash;
							<?php
							printf(
								/* translators: 1: action, 2: object type, 3: object ID */
								esc_html__( '%1$s (%2$s #%3$s)', 'service-status-manager' ),
								esc_html( $entry->action ),
								esc_html( $entry->object_type ),
								esc_html( $entry->object_id )
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
	</div>
</div>
