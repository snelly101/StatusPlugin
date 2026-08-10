<?php
/**
 * Admin view: incident list.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\IncidentManager;
use ServiceStatusManager\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$status = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$result = IncidentManager::query_for_admin( array( 'paged' => $paged, 'status' => $status ) );
?>
<div class="wrap ssm-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Incidents', 'service-status-manager' ); ?></h1>
	<?php if ( current_user_can( Capabilities::MANAGE_INCIDENTS ) ) : ?>
		<a href="<?php echo esc_url( add_query_arg( 'action', 'new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'service-status-manager' ); ?></a>
	<?php endif; ?>
	<hr class="wp-header-end" />

	<ul class="subsubsub">
		<?php
		$filters = array( '' => __( 'All', 'service-status-manager' ) ) + array_combine( IncidentManager::STATUSES, array_map( 'ucfirst', IncidentManager::STATUSES ) );
		$links   = array();
		foreach ( $filters as $key => $label ) {
			$url     = remove_query_arg( 'status' );
			$url     = $key ? add_query_arg( 'status', $key, $url ) : $url;
			$class   = ( $status === $key ) ? 'current' : '';
			$links[] = sprintf( '<li><a href="%s" class="%s">%s</a></li>', esc_url( $url ), esc_attr( $class ), esc_html( $label ) );
		}
		echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</ul>

	<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th><?php esc_html_e( 'Title', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Severity', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Status', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Started', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Resolved', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Public', 'service-status-manager' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $result['items'] ) ) : ?>
			<tr><td colspan="7"><?php esc_html_e( 'No incidents found.', 'service-status-manager' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $result['items'] as $incident ) : ?>
			<tr>
				<td>
					<a href="<?php echo esc_url( add_query_arg( 'incident_id', $incident->id, remove_query_arg( 'action' ) ) ); ?>"><?php echo esc_html( $incident->title ); ?></a>
					<?php if ( $incident->is_pinned ) : ?><span class="dashicons dashicons-sticky" title="<?php esc_attr_e( 'Pinned', 'service-status-manager' ); ?>"></span><?php endif; ?>
				</td>
				<td><?php echo esc_html( ucfirst( $incident->severity ) ); ?></td>
				<td><?php echo esc_html( ucfirst( $incident->status ) ); ?></td>
				<td><?php echo esc_html( ssm_format_datetime( $incident->starts_at ) ); ?></td>
				<td><?php echo $incident->ends_at ? esc_html( ssm_format_datetime( $incident->ends_at ) ) : '—'; ?></td>
				<td><?php echo $incident->is_public ? esc_html__( 'Yes', 'service-status-manager' ) : esc_html__( 'No', 'service-status-manager' ); ?></td>
				<td>
					<?php if ( current_user_can( Capabilities::MANAGE_INCIDENTS ) ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this incident permanently?', 'service-status-manager' ) ); ?>');">
							<?php wp_nonce_field( 'ssm_delete_incident' ); ?>
							<input type="hidden" name="action" value="ssm_delete_incident" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $incident->id ); ?>" />
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
			<?php
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
