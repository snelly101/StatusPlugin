<?php
/**
 * Admin view: scheduled maintenance list.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\MaintenanceManager;
use ServiceStatusManager\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$result = MaintenanceManager::query_for_admin( array( 'paged' => $paged ) );
?>
<div class="wrap ssm-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Scheduled Maintenance', 'service-status-manager' ); ?></h1>
	<?php if ( current_user_can( Capabilities::MANAGE_INCIDENTS ) ) : ?>
		<a href="<?php echo esc_url( add_query_arg( 'action', 'new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'service-status-manager' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end" />

	<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th><?php esc_html_e( 'Title', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Status', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Start', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'End', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Impact', 'service-status-manager' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $result['items'] ) ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No maintenance events found.', 'service-status-manager' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $result['items'] as $event ) : ?>
			<tr>
				<td><a href="<?php echo esc_url( add_query_arg( 'maintenance_id', $event->id, remove_query_arg( 'action' ) ) ); ?>"><?php echo esc_html( $event->title ); ?></a></td>
				<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $event->status ) ) ); ?></td>
				<td><?php echo esc_html( ssm_format_datetime( $event->scheduled_start ) ); ?></td>
				<td><?php echo esc_html( ssm_format_datetime( $event->scheduled_end ) ); ?></td>
				<td><?php echo esc_html( ucfirst( $event->impact ) ); ?></td>
				<td>
					<?php if ( current_user_can( Capabilities::MANAGE_INCIDENTS ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this maintenance event?', 'service-status-manager' ) ); ?>');">
							<?php wp_nonce_field( 'ssm_delete_maintenance' ); ?>
							<input type="hidden" name="action" value="ssm_delete_maintenance" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $event->id ); ?>" />
							<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'service-status-manager' ); ?></button>
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
