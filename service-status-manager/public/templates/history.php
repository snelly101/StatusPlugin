<?php
/**
 * Template: daily uptime bars and percentage uptime per service.
 *
 * Expects: $services, $monitors_by_service, $range_days, $atts.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\UptimeAggregator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$range_days = in_array( $range_days, array( 30, 60, 90 ), true ) ? $range_days : 90;
?>
<div class="ssm-history">
	<?php foreach ( $services as $service ) : ?>
		<div class="ssm-card ssm-history-service">
			<h4><?php echo esc_html( $service->name ); ?></h4>
			<?php
			$monitors = $monitors_by_service[ $service->id ] ?? array();
			if ( empty( $monitors ) ) :
				$uptime = UptimeAggregator::get_service_uptime_percentage( $service->id, $range_days );
				?>
				<p class="ssm-uptime-percentage"><?php echo esc_html( number_format_i18n( $uptime, 2 ) ); ?>% <span class="ssm-uptime-range-label"><?php echo esc_html( sprintf( /* translators: %d: number of days */ __( '· last %d days', 'service-status-manager' ), $range_days ) ); ?></span></p>
			<?php else : ?>
				<?php foreach ( $monitors as $monitor ) : ?>
					<div class="ssm-history-monitor">
						<div class="ssm-history-monitor-head">
							<span><?php echo esc_html( $monitor->name ); ?></span>
							<span class="ssm-uptime-percentage"><?php echo esc_html( number_format_i18n( UptimeAggregator::get_monitor_uptime_percentage( $monitor->id, $range_days ), 2 ) ); ?>% <span class="ssm-uptime-range-label"><?php echo esc_html( sprintf( __( '· last %d days', 'service-status-manager' ), $range_days ) ); ?></span></span>
						</div>
						<div class="ssm-uptime-bars" data-ssm-uptime-bars role="img" aria-label="<?php echo esc_attr( sprintf(
							/* translators: 1: monitor name, 2: number of days */
							__( '%1$s uptime for the last %2$d days', 'service-status-manager' ),
							$monitor->name,
							$range_days
						) ); ?>">
							<?php foreach ( UptimeAggregator::get_daily_history( $monitor->id, $range_days ) as $day ) :
								$bar_class = 'ssm-bar-nodata';
								$tooltip   = array(
									'date'  => ssm_format_datetime( $day['date'], get_option( 'date_format' ) ),
									'label' => __( 'No data', 'service-status-manager' ),
								);

								if ( null !== $day['uptime_pct'] ) {
									if ( $day['uptime_pct'] >= 99.9 ) {
										$bar_class = 'ssm-bar-up';
									} elseif ( $day['uptime_pct'] >= 90 ) {
										$bar_class = 'ssm-bar-degraded';
									} else {
										$bar_class = 'ssm-bar-down';
									}

									$parts   = array( number_format_i18n( $day['uptime_pct'], 2 ) . '% ' . __( 'uptime', 'service-status-manager' ) );
									if ( $day['incidents'] > 0 ) {
										/* translators: %d: incident count */
										$parts[] = sprintf( _n( '%d incident', '%d incidents', $day['incidents'], 'service-status-manager' ), $day['incidents'] );
									}
									if ( $day['degraded_minutes'] > 0 ) {
										/* translators: %d: number of minutes */
										$parts[] = sprintf( __( '%d min degraded', 'service-status-manager' ), $day['degraded_minutes'] );
									}
									if ( $day['down_minutes'] > 0 ) {
										/* translators: %d: number of minutes */
										$parts[] = sprintf( __( '%d min down', 'service-status-manager' ), $day['down_minutes'] );
									}
									$tooltip['label'] = implode( ' · ', $parts );
								}
								?>
								<span
									class="ssm-bar <?php echo esc_attr( $bar_class ); ?>"
									tabindex="0"
									data-ssm-tooltip-date="<?php echo esc_attr( $tooltip['date'] ); ?>"
									data-ssm-tooltip-label="<?php echo esc_attr( $tooltip['label'] ); ?>"
								></span>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<?php
	if ( empty( $services ) ) :
		$empty_icon  = 'server';
		$empty_title = __( 'No history is available yet', 'service-status-manager' );
		$empty_desc  = __( 'Uptime history will appear here once monitoring data has been collected.', 'service-status-manager' );
		require SSM_PLUGIN_DIR . 'public/templates/parts/empty-state.php';
	endif;
	?>
</div>
