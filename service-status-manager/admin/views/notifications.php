<?php
/**
 * Admin view: notification queue and test notifications.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\Notifications\NotificationQueue;
use ServiceStatusManager\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$paged   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$channel = sanitize_key( wp_unslash( $_GET['channel'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$status  = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$result = NotificationQueue::query_for_admin( array( 'paged' => $paged, 'channel' => $channel, 'status' => $status ) );
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Notifications', 'service-status-manager' ); ?></h1>

	<?php if ( current_user_can( Capabilities::MANAGE_INTEGRATIONS ) ) : ?>
		<h2><?php esc_html_e( 'Send a test notification', 'service-status-manager' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ssm-test-notification-form">
			<?php wp_nonce_field( 'ssm_send_test_notification' ); ?>
			<input type="hidden" name="action" value="ssm_send_test_notification" />
			<select name="channel">
				<option value="email"><?php esc_html_e( 'Email', 'service-status-manager' ); ?></option>
				<option value="sms"><?php esc_html_e( 'SMS', 'service-status-manager' ); ?></option>
				<option value="teams"><?php esc_html_e( 'Microsoft Teams', 'service-status-manager' ); ?></option>
			</select>
			<input type="text" name="destination" placeholder="<?php esc_attr_e( 'Email address, phone number, or Teams webhook URL', 'service-status-manager' ); ?>" class="regular-text" required />
			<?php submit_button( __( 'Send Test', 'service-status-manager' ), 'secondary', '', false ); ?>
		</form>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Queue', 'service-status-manager' ); ?></h2>
	<form method="get" style="margin: 12px 0;">
		<input type="hidden" name="page" value="service-status-manager-notifications" />
		<select name="channel">
			<option value=""><?php esc_html_e( 'All channels', 'service-status-manager' ); ?></option>
			<?php foreach ( array( 'email', 'sms', 'teams' ) as $c ) : ?>
				<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $channel, $c ); ?>><?php echo esc_html( ucfirst( $c ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="status">
			<option value=""><?php esc_html_e( 'All statuses', 'service-status-manager' ); ?></option>
			<?php foreach ( array( 'pending', 'processing', 'sent', 'failed', 'cancelled', 'retry_scheduled' ) as $s ) : ?>
				<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status, $s ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $s ) ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php submit_button( __( 'Filter', 'service-status-manager' ), '', '', false ); ?>
	</form>

	<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th><?php esc_html_e( 'Channel', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Event', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Subscriber', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Status', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Attempts', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Next attempt', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Last error', 'service-status-manager' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $result['items'] ) ) : ?>
			<tr><td colspan="8"><?php esc_html_e( 'No notifications found.', 'service-status-manager' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $result['items'] as $row ) : ?>
			<tr>
				<td><?php echo esc_html( ucfirst( $row->channel ) ); ?></td>
				<td><?php echo esc_html( $row->event_type ); ?></td>
				<td>#<?php echo esc_html( $row->subscriber_id ); ?></td>
				<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $row->status ) ) ); ?></td>
				<td><?php echo esc_html( $row->attempts . ' / ' . $row->max_attempts ); ?></td>
				<td><?php echo esc_html( ssm_format_datetime( $row->next_attempt_at ) ); ?></td>
				<td><?php echo esc_html( $row->last_error ); ?></td>
				<td>
					<?php if ( in_array( $row->status, array( 'failed', 'cancelled' ), true ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'ssm_retry_notification' ); ?>
							<input type="hidden" name="action" value="ssm_retry_notification" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $row->id ); ?>" />
							<button type="submit" class="button-link"><?php esc_html_e( 'Retry', 'service-status-manager' ); ?></button>
						</form>
					<?php endif; ?>
					<?php if ( in_array( $row->status, array( 'pending', 'retry_scheduled' ), true ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'ssm_cancel_notification' ); ?>
							<input type="hidden" name="action" value="ssm_cancel_notification" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $row->id ); ?>" />
							<button type="submit" class="button-link-delete"><?php esc_html_e( 'Cancel', 'service-status-manager' ); ?></button>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php
	$total_pages = (int) ceil( $result['total'] / 20 );
	if ( $total_pages > 1 ) :
		?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $total_pages ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div></div>
	<?php endif; ?>
</div>
