<?php
/**
 * Admin dashboard view: at-a-glance plugin health.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\MonitorManager;
use ServiceStatusManager\IncidentManager;
use ServiceStatusManager\MaintenanceManager;
use ServiceStatusManager\Notifications\NotificationQueue;
use ServiceStatusManager\SubscriberManager;

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

$active_incidents    = IncidentManager::get_active_incidents();
$upcoming_maintenance = MaintenanceManager::get_upcoming();
$overdue_monitors    = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . ssm_table( 'monitors' ) . " WHERE is_active = 1 AND type != 'manual' AND next_check_at < %s", ssm_now() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$failed_monitors     = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ssm_table( 'monitors' ) . " WHERE current_state IN ('major_outage','partial_outage')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$queue_size          = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ssm_table( 'notification_queue' ) . " WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$failed_notifications = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ssm_table( 'notification_queue' ) . " WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$subscriber_count    = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . ssm_table( 'subscribers' ) . " WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$overall             = ServiceManager::get_overall_status();
$overall_def         = ssm_get_status_definition( $overall );
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Service Status Manager', 'service-status-manager' ); ?></h1>

	<div class="ssm-overall-banner ssm-status-banner <?php echo esc_attr( $overall_def['css_class'] ); ?>">
		<span class="ssm-status-dot" aria-hidden="true"></span>
		<strong><?php esc_html_e( 'Overall status:', 'service-status-manager' ); ?></strong>
		<?php echo esc_html( $overall_def['label'] ); ?>
	</div>

	<div class="ssm-stat-grid">
		<div class="ssm-stat-card">
			<span class="ssm-stat-value"><?php echo esc_html( $counts['operational'] ); ?></span>
			<span class="ssm-stat-label"><?php esc_html_e( 'Operational services', 'service-status-manager' ); ?></span>
		</div>
		<div class="ssm-stat-card">
			<span class="ssm-stat-value"><?php echo esc_html( $counts['degraded'] + $counts['partial_outage'] + $counts['major_outage'] ); ?></span>
			<span class="ssm-stat-label"><?php esc_html_e( 'Degraded / outage services', 'service-status-manager' ); ?></span>
		</div>
		<div class="ssm-stat-card">
			<span class="ssm-stat-value"><?php echo esc_html( count( $active_incidents ) ); ?></span>
			<span class="ssm-stat-label"><?php esc_html_e( 'Active incidents', 'service-status-manager' ); ?></span>
		</div>
		<div class="ssm-stat-card">
			<span class="ssm-stat-value"><?php echo esc_html( count( $upcoming_maintenance ) ); ?></span>
			<span class="ssm-stat-label"><?php esc_html_e( 'Scheduled maintenance', 'service-status-manager' ); ?></span>
		</div>
		<div class="ssm-stat-card <?php echo $failed_monitors ? 'ssm-stat-card--warn' : ''; ?>">
			<span class="ssm-stat-value"><?php echo esc_html( $failed_monitors ); ?></span>
			<span class="ssm-stat-label"><?php esc_html_e( 'Failing monitors', 'service-status-manager' ); ?></span>
		</div>
		<div class="ssm-stat-card <?php echo $overdue_monitors ? 'ssm-stat-card--warn' : ''; ?>">
			<span class="ssm-stat-value"><?php echo esc_html( $overdue_monitors ); ?></span>
			<span class="ssm-stat-label"><?php esc_html_e( 'Checks overdue', 'service-status-manager' ); ?></span>
		</div>
		<div class="ssm-stat-card">
			<span class="ssm-stat-value"><?php echo esc_html( $queue_size ); ?></span>
			<span class="ssm-stat-label"><?php esc_html_e( 'Notifications queued', 'service-status-manager' ); ?></span>
		</div>
		<div class="ssm-stat-card <?php echo $failed_notifications ? 'ssm-stat-card--warn' : ''; ?>">
			<span class="ssm-stat-value"><?php echo esc_html( $failed_notifications ); ?></span>
			<span class="ssm-stat-label"><?php esc_html_e( 'Failed notifications', 'service-status-manager' ); ?></span>
		</div>
		<div class="ssm-stat-card">
			<span class="ssm-stat-value"><?php echo esc_html( $subscriber_count ); ?></span>
			<span class="ssm-stat-label"><?php esc_html_e( 'Active subscribers', 'service-status-manager' ); ?></span>
		</div>
	</div>

	<h2><?php esc_html_e( 'Active incidents', 'service-status-manager' ); ?></h2>
	<?php if ( empty( $active_incidents ) ) : ?>
		<p><?php esc_html_e( 'No active incidents.', 'service-status-manager' ); ?></p>
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

	<h2><?php esc_html_e( 'Recent activity', 'service-status-manager' ); ?></h2>
	<?php
	$recent = \ServiceStatusManager\AuditLog::query( array( 'per_page' => 10 ) );
	if ( empty( $recent['items'] ) ) :
		?>
		<p><?php esc_html_e( 'No recent activity recorded yet.', 'service-status-manager' ); ?></p>
	<?php else : ?>
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
	<?php endif; ?>
</div>
