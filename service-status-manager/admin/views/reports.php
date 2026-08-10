<?php
/**
 * Admin view: reports with CSV export.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\Reports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$reports = array(
	'uptime_by_service'    => __( 'Uptime by Service', 'service-status-manager' ),
	'uptime_by_monitor'    => __( 'Uptime by Monitor', 'service-status-manager' ),
	'incidents'             => __( 'Incidents (count, MTTA, MTTR)', 'service-status-manager' ),
	'notification_delivery' => __( 'Notification Delivery Success', 'service-status-manager' ),
	'subscriber_growth'    => __( 'Subscriber Growth', 'service-status-manager' ),
	'channel_usage'         => __( 'Subscription Channel Usage', 'service-status-manager' ),
	'subscribed_services'   => __( 'Most Subscribed Services', 'service-status-manager' ),
	'response_time'         => __( 'Monitor Response Time (7 days)', 'service-status-manager' ),
);

$selected = sanitize_key( wp_unslash( $_GET['report'] ?? 'uptime_by_service' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! isset( $reports[ $selected ] ) ) {
	$selected = 'uptime_by_service';
}

$rows = Reports::get_report_rows( $selected );
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Reports', 'service-status-manager' ); ?></h1>

	<ul class="subsubsub">
		<?php
		$links = array();
		foreach ( $reports as $key => $label ) {
			$class   = ( $selected === $key ) ? 'current' : '';
			$links[] = sprintf( '<li><a class="%s" href="%s">%s</a></li>', esc_attr( $class ), esc_url( add_query_arg( 'report', $key ) ), esc_html( $label ) );
		}
		echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</ul>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 12px 0;">
		<?php wp_nonce_field( 'ssm_export_report' ); ?>
		<input type="hidden" name="action" value="ssm_export_report" />
		<input type="hidden" name="report" value="<?php echo esc_attr( $selected ); ?>" />
		<?php submit_button( __( 'Export CSV', 'service-status-manager' ), 'secondary', '', false ); ?>
	</form>

	<table class="wp-list-table widefat fixed striped">
		<?php if ( empty( $rows ) ) : ?>
			<tbody><tr><td><?php esc_html_e( 'No data available for this report yet.', 'service-status-manager' ); ?></td></tr></tbody>
		<?php else : ?>
			<thead><tr>
				<?php foreach ( array_keys( (array) $rows[0] ) as $col ) : ?>
					<th><?php echo esc_html( ucwords( str_replace( '_', ' ', $col ) ) ); ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<?php foreach ( (array) $row as $value ) : ?>
						<td><?php echo esc_html( is_null( $value ) ? '—' : $value ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		<?php endif; ?>
	</table>
</div>
