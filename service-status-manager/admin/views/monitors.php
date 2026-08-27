<?php
/**
 * Admin view: manage monitors.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\MonitorManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$services       = ServiceManager::get_services();
$filter_service = isset( $_GET['service_id'] ) ? absint( $_GET['service_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$edit_id        = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$editing        = $edit_id ? MonitorManager::get_monitor( $edit_id ) : null;
$status_filter  = sanitize_key( wp_unslash( $_GET['status_filter'] ?? 'all' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$monitors = array();
foreach ( $services as $service ) {
	if ( $filter_service && (int) $service->id !== $filter_service ) {
		continue;
	}
	foreach ( MonitorManager::get_monitors_for_service( $service->id ) as $monitor ) {
		$monitor->service_name = $service->name;
		$monitors[]            = $monitor;
	}
}

// Failed monitors rise to the top regardless of sort order elsewhere,
// so an administrator sees problems immediately without hunting for them.
usort(
	$monitors,
	function ( $a, $b ) {
		$priority = array( 'major_outage' => 0, 'partial_outage' => 1 );
		$a_rank   = $priority[ $a->current_state ] ?? 2;
		$b_rank   = $priority[ $b->current_state ] ?? 2;
		return $a_rank <=> $b_rank;
	}
);

$filter_counts = array(
	'all'         => count( $monitors ),
	'operational' => 0,
	'degraded'    => 0,
	'failed'      => 0,
	'paused'      => 0,
);
foreach ( $monitors as $monitor ) {
	if ( ! $monitor->is_active ) {
		++$filter_counts['paused'];
	} elseif ( in_array( $monitor->current_state, array( 'major_outage', 'partial_outage' ), true ) ) {
		++$filter_counts['failed'];
	} elseif ( 'degraded' === $monitor->current_state ) {
		++$filter_counts['degraded'];
	} elseif ( 'operational' === $monitor->current_state ) {
		++$filter_counts['operational'];
	}
}

if ( 'all' !== $status_filter ) {
	$monitors = array_values(
		array_filter(
			$monitors,
			function ( $monitor ) use ( $status_filter ) {
				if ( 'paused' === $status_filter ) {
					return ! $monitor->is_active;
				}
				if ( 'failed' === $status_filter ) {
					return in_array( $monitor->current_state, array( 'major_outage', 'partial_outage' ), true );
				}
				return $monitor->current_state === $status_filter;
			}
		)
	);
}

/**
 * Builds a tiny inline SVG sparkline from a monitor's recent response
 * times, giving an at-a-glance trend without a charting library.
 *
 * @param int $monitor_id Monitor ID.
 * @return string
 */
if ( ! function_exists( __NAMESPACE__ . '\\ssm_admin_monitor_sparkline' ) ) {
	function ssm_admin_monitor_sparkline( $monitor_id ) {
		global $wpdb;
		$table  = ssm_table( 'monitor_checks' );
		$points = $wpdb->get_col( $wpdb->prepare( "SELECT response_time_ms FROM {$table} WHERE monitor_id = %d AND response_time_ms IS NOT NULL ORDER BY checked_at DESC LIMIT 20", $monitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( count( $points ) < 2 ) {
			return '';
		}

		$points = array_reverse( array_map( 'intval', $points ) );
		$max    = max( max( $points ), 1 );
		$width  = 100;
		$height = 24;
		$step   = $width / ( count( $points ) - 1 );

		$coords = array();
		foreach ( $points as $i => $value ) {
			$x        = round( $i * $step, 1 );
			$y        = round( $height - ( $value / $max * $height ), 1 );
			$coords[] = "{$x},{$y}";
		}

		return sprintf(
			'<svg class="ssm-sparkline" width="%1$d" height="%2$d" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none"><polyline points="%3$s" fill="none" stroke="#2563eb" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" /></svg>',
			$width,
			$height,
			esc_attr( implode( ' ', $coords ) )
		);
	}
}
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Monitors', 'service-status-manager' ); ?></h1>

	<div class="ssm-filter-pills">
		<?php
		$filter_labels = array(
			'all'         => __( 'All', 'service-status-manager' ),
			'operational' => __( 'Operational', 'service-status-manager' ),
			'degraded'    => __( 'Degraded', 'service-status-manager' ),
			'failed'      => __( 'Failed', 'service-status-manager' ),
			'paused'      => __( 'Paused', 'service-status-manager' ),
		);
		foreach ( $filter_labels as $key => $label ) :
			?>
			<a href="<?php echo esc_url( add_query_arg( 'status_filter', $key ) ); ?>" class="ssm-filter-pill <?php echo $status_filter === $key ? 'ssm-is-active' : ''; ?>">
				<?php echo esc_html( $label ); ?>
				<span class="ssm-count"><?php echo esc_html( $filter_counts[ $key ] ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>

	<table class="wp-list-table widefat fixed striped">
		<thead><tr>
			<th><?php esc_html_e( 'Name', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Service', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Type', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'State', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Last checked', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Last response', 'service-status-manager' ); ?></th>
			<th><?php esc_html_e( 'Trend', 'service-status-manager' ); ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if ( empty( $monitors ) ) : ?>
			<tr><td colspan="8"><?php esc_html_e( 'No monitors match this filter.', 'service-status-manager' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $monitors as $monitor ) :
			$state_def  = ssm_get_status_definition( $monitor->current_state );
			$is_failing = in_array( $monitor->current_state, array( 'major_outage', 'partial_outage' ), true );
			?>
			<tr class="<?php echo $is_failing ? 'ssm-monitor-priority-row' : ''; ?>">
				<td><a href="<?php echo esc_url( add_query_arg( 'edit', $monitor->id ) ); ?>"><?php echo esc_html( $monitor->name ); ?></a></td>
				<td><?php echo esc_html( $monitor->service_name ); ?></td>
				<td><?php echo esc_html( strtoupper( $monitor->type ) ); ?></td>
				<td><span class="ssm-badge <?php echo esc_attr( $state_def['css_class'] ); ?>"><?php echo esc_html( $state_def['label'] ); ?></span></td>
				<td><?php echo $monitor->last_checked_at ? esc_html( ssm_format_datetime( $monitor->last_checked_at ) ) : '—'; ?></td>
				<td><?php echo null !== $monitor->last_response_time_ms ? esc_html( $monitor->last_response_time_ms ) . ' ms' : '—'; ?></td>
				<td><?php echo 'manual' !== $monitor->type ? ssm_admin_monitor_sparkline( $monitor->id ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				<td>
					<?php if ( 'manual' === $monitor->type ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'ssm_set_monitor_state' ); ?>
							<input type="hidden" name="action" value="ssm_set_monitor_state" />
							<input type="hidden" name="id" value="<?php echo esc_attr( $monitor->id ); ?>" />
							<select name="state" onchange="this.form.submit()">
								<?php foreach ( ssm_get_status_definitions() as $slug => $def ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $monitor->current_state, $slug ); ?>><?php echo esc_html( $def['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this monitor?', 'service-status-manager' ) ); ?>');">
						<?php wp_nonce_field( 'ssm_delete_monitor' ); ?>
						<input type="hidden" name="action" value="ssm_delete_monitor" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $monitor->id ); ?>" />
						<button type="submit" class="button-link-delete"><?php esc_html_e( 'Delete', 'service-status-manager' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php echo $editing ? esc_html__( 'Edit Monitor', 'service-status-manager' ) : esc_html__( 'Add Monitor', 'service-status-manager' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ssm_save_monitor' ); ?>
		<input type="hidden" name="action" value="ssm_save_monitor" />
		<input type="hidden" name="id" value="<?php echo esc_attr( $editing->id ?? '' ); ?>" />

		<table class="form-table">
			<tr>
				<th><label for="ssm-m-name"><?php esc_html_e( 'Name', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-m-name" name="name" class="regular-text" required value="<?php echo esc_attr( $editing->name ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-m-service"><?php esc_html_e( 'Service', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-m-service" name="service_id" required>
						<option value=""></option>
						<?php foreach ( $services as $service ) : ?>
							<option value="<?php echo esc_attr( $service->id ); ?>" <?php selected( $editing->service_id ?? $filter_service, $service->id ); ?>><?php echo esc_html( $service->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ssm-m-type"><?php esc_html_e( 'Monitor type', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-m-type" name="type" onchange="ssmToggleMonitorFields(this.value)">
						<option value="manual" <?php selected( $editing->type ?? 'manual', 'manual' ); ?>><?php esc_html_e( 'Manual', 'service-status-manager' ); ?></option>
						<option value="http" <?php selected( $editing->type ?? '', 'http' ); ?>><?php esc_html_e( 'HTTP / HTTPS', 'service-status-manager' ); ?></option>
						<option value="tcp" <?php selected( $editing->type ?? '', 'tcp' ); ?>><?php esc_html_e( 'TCP Port', 'service-status-manager' ); ?></option>
						<option value="ping" <?php selected( $editing->type ?? '', 'ping' ); ?>><?php esc_html_e( 'Ping', 'service-status-manager' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Additional monitor types (DNS, SMTP, Microsoft 365, API, NinjaOne, PRTG, Auvik, Veeam, WatchGuard, webhook) can be added via the ssm_monitor_provider_types filter.', 'service-status-manager' ); ?></p>
				</td>
			</tr>

			<tbody id="ssm-fields-http" class="ssm-monitor-type-fields">
			<tr>
				<th><label for="ssm-m-url"><?php esc_html_e( 'URL', 'service-status-manager' ); ?></label></th>
				<td><input type="url" id="ssm-m-url" name="url" class="regular-text" value="<?php echo esc_attr( $editing->settings['url'] ?? '' ); ?>" placeholder="https://example.com/health" /></td>
			</tr>
			<tr>
				<th><label for="ssm-m-method"><?php esc_html_e( 'Request method', 'service-status-manager' ); ?></label></th>
				<td>
					<select id="ssm-m-method" name="method">
						<?php foreach ( array( 'GET', 'HEAD', 'POST' ) as $method ) : ?>
							<option value="<?php echo esc_attr( $method ); ?>" <?php selected( $editing->settings['method'] ?? 'GET', $method ); ?>><?php echo esc_html( $method ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="ssm-m-expected"><?php esc_html_e( 'Expected HTTP status', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-m-expected" name="expected_status" value="<?php echo esc_attr( $editing->settings['expected_status'] ?? 200 ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-m-required"><?php esc_html_e( 'Required text in response', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-m-required" name="required_text" class="regular-text" value="<?php echo esc_attr( $editing->settings['required_text'] ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-m-forbidden"><?php esc_html_e( 'Forbidden text in response', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-m-forbidden" name="forbidden_text" class="regular-text" value="<?php echo esc_attr( $editing->settings['forbidden_text'] ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Options', 'service-status-manager' ); ?></th>
				<td>
					<label><input type="checkbox" name="follow_redirects" value="1" <?php checked( $editing->settings['follow_redirects'] ?? true, 1 ); ?> /> <?php esc_html_e( 'Follow redirects', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="verify_ssl" value="1" <?php checked( $editing->settings['verify_ssl'] ?? true, 1 ); ?> /> <?php esc_html_e( 'Require valid SSL certificate', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="allow_internal" value="1" <?php checked( $editing->settings['allow_internal'] ?? false, 1 ); ?> /> <?php esc_html_e( 'Allow monitoring internal/private addresses (security risk - see documentation)', 'service-status-manager' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Custom headers', 'service-status-manager' ); ?></th>
				<td>
					<div id="ssm-header-rows">
						<?php
						$headers = $editing->settings['headers'] ?? array();
						if ( empty( $headers ) ) {
							$headers = array( array( 'name' => '', 'value' => '' ) );
						}
						foreach ( $headers as $header ) :
							?>
							<p><input type="text" name="header_name[]" placeholder="<?php esc_attr_e( 'Header name', 'service-status-manager' ); ?>" value="<?php echo esc_attr( $header['name'] ?? '' ); ?>" />
							<input type="text" name="header_value[]" placeholder="<?php esc_attr_e( 'Header value', 'service-status-manager' ); ?>" value="<?php echo esc_attr( $header['value'] ?? '' ); ?>" /></p>
						<?php endforeach; ?>
					</div>
					<button type="button" class="button" onclick="ssmAddHeaderRow()"><?php esc_html_e( 'Add header', 'service-status-manager' ); ?></button>
					<p class="description"><?php esc_html_e( 'Header values are stored encrypted and are never displayed in logs, exports, or the public API.', 'service-status-manager' ); ?></p>
				</td>
			</tr>
			</tbody>

			<tbody id="ssm-fields-tcp" class="ssm-monitor-type-fields">
			<tr>
				<th><label for="ssm-m-host"><?php esc_html_e( 'Hostname or IP address', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-m-host" name="host" class="regular-text" value="<?php echo esc_attr( $editing->settings['host'] ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-m-port"><?php esc_html_e( 'TCP port', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-m-port" name="port" min="1" max="65535" value="<?php echo esc_attr( $editing->settings['port'] ?? 443 ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Options', 'service-status-manager' ); ?></th>
				<td><label><input type="checkbox" name="allow_internal" value="1" <?php checked( $editing->settings['allow_internal'] ?? false, 1 ); ?> /> <?php esc_html_e( 'Allow monitoring internal/private addresses (security risk)', 'service-status-manager' ); ?></label></td>
			</tr>
			</tbody>

			<tbody id="ssm-fields-ping" class="ssm-monitor-type-fields">
			<tr>
				<th><label for="ssm-m-ping-host"><?php esc_html_e( 'Hostname or IP address', 'service-status-manager' ); ?></label></th>
				<td><input type="text" id="ssm-m-ping-host" name="ping_host" class="regular-text" value="<?php echo esc_attr( 'ping' === ( $editing->type ?? '' ) ? ( $editing->settings['host'] ?? '' ) : '' ); ?>" />
				<p class="description"><?php esc_html_e( 'PHP on typical WordPress hosting cannot send real ICMP ping packets, so this checks whether the host accepts a connection on a common port (80/443) instead - a reliable substitute that works without special server permissions. To check one specific port, use a TCP Port monitor instead.', 'service-status-manager' ); ?></p></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Options', 'service-status-manager' ); ?></th>
				<td><label><input type="checkbox" name="allow_internal" value="1" <?php checked( $editing->settings['allow_internal'] ?? false, 1 ); ?> /> <?php esc_html_e( 'Allow monitoring internal/private addresses (security risk)', 'service-status-manager' ); ?></label></td>
			</tr>
			</tbody>

			<tr>
				<th><label for="ssm-m-frequency"><?php esc_html_e( 'Check frequency (seconds)', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-m-frequency" name="check_frequency" min="60" value="<?php echo esc_attr( $editing->check_frequency ?? 300 ); ?>" />
				<p class="description"><?php esc_html_e( 'Minimum 60 seconds. WP-Cron only runs on site traffic; for reliable sub-5-minute checking configure a real system cron job or WP-CLI (see documentation).', 'service-status-manager' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="ssm-m-timeout"><?php esc_html_e( 'Timeout (seconds)', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-m-timeout" name="timeout_seconds" min="1" max="60" value="<?php echo esc_attr( $editing->timeout_seconds ?? 10 ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-m-fail"><?php esc_html_e( 'Failure threshold', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-m-fail" name="failure_threshold" min="1" value="<?php echo esc_attr( $editing->failure_threshold ?? 3 ); ?>" />
				<p class="description"><?php esc_html_e( 'Consecutive failed checks before an incident is triggered.', 'service-status-manager' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="ssm-m-recover"><?php esc_html_e( 'Recovery threshold', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-m-recover" name="recovery_threshold" min="1" value="<?php echo esc_attr( $editing->recovery_threshold ?? 2 ); ?>" />
				<p class="description"><?php esc_html_e( 'Consecutive successful checks before recovery is confirmed.', 'service-status-manager' ); ?></p></td>
			</tr>
			<tr>
				<th><label for="ssm-m-warn-ms"><?php esc_html_e( 'Response time warning threshold (ms)', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-m-warn-ms" name="warning_response_ms" value="<?php echo esc_attr( $editing->warning_response_ms ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="ssm-m-crit-ms"><?php esc_html_e( 'Response time critical threshold (ms)', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-m-crit-ms" name="critical_response_ms" value="<?php echo esc_attr( $editing->critical_response_ms ?? '' ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Options', 'service-status-manager' ); ?></th>
				<td>
					<label><input type="checkbox" name="auto_incident" value="1" <?php checked( $editing->auto_incident ?? 1, 1 ); ?> /> <?php esc_html_e( 'Automatically create an incident on failure', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="is_active" value="1" <?php checked( $editing->is_active ?? 1, 1 ); ?> /> <?php esc_html_e( 'Active (checks will run)', 'service-status-manager' ); ?></label><br />
					<label><input type="checkbox" name="is_public" value="1" <?php checked( $editing->is_public ?? 1, 1 ); ?> /> <?php esc_html_e( 'Show monitor-level detail on the public status page', 'service-status-manager' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="ssm-m-order"><?php esc_html_e( 'Display order', 'service-status-manager' ); ?></label></th>
				<td><input type="number" id="ssm-m-order" name="display_order" value="<?php echo esc_attr( $editing->display_order ?? 0 ); ?>" /></td>
			</tr>
		</table>

		<?php submit_button( $editing ? __( 'Update Monitor', 'service-status-manager' ) : __( 'Add Monitor', 'service-status-manager' ) ); ?>
	</form>
</div>
<script>
	function ssmAddHeaderRow() {
		var wrap = document.getElementById( 'ssm-header-rows' );
		var p = document.createElement( 'p' );
		p.innerHTML = '<input type="text" name="header_name[]" placeholder="Header name" /> <input type="text" name="header_value[]" placeholder="Header value" />';
		wrap.appendChild( p );
	}
	function ssmToggleMonitorFields( type ) {
		document.getElementById( 'ssm-fields-http' ).style.display = ( type === 'http' ) ? '' : 'none';
		document.getElementById( 'ssm-fields-tcp' ).style.display = ( type === 'tcp' ) ? '' : 'none';
		document.getElementById( 'ssm-fields-ping' ).style.display = ( type === 'ping' ) ? '' : 'none';
	}
	document.addEventListener( 'DOMContentLoaded', function () {
		ssmToggleMonitorFields( document.getElementById( 'ssm-m-type' ).value );
	} );
</script>
