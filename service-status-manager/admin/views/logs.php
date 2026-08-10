<?php
/**
 * Admin view: plugin logs (error/warning/info/debug) and the audit trail.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\AuditLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$tab = in_array( $_GET['tab'] ?? '', array( 'audit' ), true ) ? 'audit' : 'app'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Logs', 'service-status-manager' ); ?></h1>
	<p class="description"><?php echo wp_kses_post( sprintf(
		/* translators: %s: site timezone */
		__( 'Timestamps are shown in your site timezone (%s).', 'service-status-manager' ),
		wp_timezone_string()
	) ); ?></p>

	<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url( remove_query_arg( array( 'tab', 'paged' ) ) ); ?>" class="nav-tab <?php echo 'app' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Application Log', 'service-status-manager' ); ?></a>
		<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'audit', 'paged' => false ) ) ); ?>" class="nav-tab <?php echo 'audit' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Audit Trail', 'service-status-manager' ); ?></a>
	</h2>

	<?php if ( 'audit' === $tab ) : ?>
		<?php $result = AuditLog::query( array( 'paged' => $paged, 'per_page' => 50 ) ); ?>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th><?php esc_html_e( 'Date', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'User', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'Action', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'Object', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'IP', 'service-status-manager' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $result['items'] ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No audit entries yet.', 'service-status-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['items'] as $entry ) :
				$user = $entry->user_id ? get_userdata( $entry->user_id ) : null;
				?>
				<tr>
					<td><?php echo esc_html( ssm_format_datetime( $entry->created_at ) ); ?></td>
					<td><?php echo esc_html( $user ? $user->user_login : __( 'System', 'service-status-manager' ) ); ?></td>
					<td><?php echo esc_html( $entry->action ); ?></td>
					<td><?php echo esc_html( trim( $entry->object_type . ' #' . $entry->object_id, ' #' ) ); ?></td>
					<td><?php echo esc_html( $entry->ip_address ?: '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$total_pages = (int) ceil( $result['total'] / 50 );
		if ( $total_pages > 1 ) :
			?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => $total_pages ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div></div>
		<?php endif; ?>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 12px 0;">
			<?php wp_nonce_field( 'ssm_download_logs' ); ?>
			<input type="hidden" name="action" value="ssm_download_logs" />
			<?php submit_button( __( 'Download as CSV', 'service-status-manager' ), 'secondary', '', false ); ?>
		</form>
		<?php
		$logs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . ssm_table( 'logs' ) . ' ORDER BY created_at DESC LIMIT %d OFFSET %d', 50, ( $paged - 1 ) * 50 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th style="width:160px;"><?php esc_html_e( 'Date', 'service-status-manager' ); ?></th>
				<th style="width:90px;"><?php esc_html_e( 'Level', 'service-status-manager' ); ?></th>
				<th><?php esc_html_e( 'Message', 'service-status-manager' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'No log entries yet.', 'service-status-manager' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $logs as $log ) : ?>
				<tr>
					<td><?php echo esc_html( ssm_format_datetime( $log->created_at ) ); ?></td>
					<td><span class="ssm-log-level-<?php echo esc_attr( $log->level ); ?>"><?php echo esc_html( strtoupper( $log->level ) ); ?></span></td>
					<td><?php echo esc_html( $log->message ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<div class="tablenav"><div class="tablenav-pages">
			<?php echo paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => max( 1, $paged + ( count( $logs ) === 50 ? 1 : 0 ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div></div>
	<?php endif; ?>
</div>
