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
		<div class="ssm-history-service">
			<h4><?php echo esc_html( $service->name ); ?></h4>
			<?php
			$monitors = $monitors_by_service[ $service->id ] ?? array();
			if ( empty( $monitors ) ) :
				$uptime = UptimeAggregator::get_service_uptime_percentage( $service->id, $range_days );
				?>
				<p class="ssm-uptime-percentage"><?php echo esc_html( number_format_i18n( $uptime, 2 ) ); ?>% <?php esc_html_e( 'uptime', 'service-status-manager' ); ?></p>
			<?php else : ?>
				<?php foreach ( $monitors as $monitor ) : ?>
					<div class="ssm-history-monitor">
						<div class="ssm-history-monitor-head">
							<span><?php echo esc_html( $monitor->name ); ?></span>
							<span class="ssm-uptime-percentage"><?php echo esc_html( number_format_i18n( UptimeAggregator::get_monitor_uptime_percentage( $monitor->id, $range_days ), 2 ) ); ?>%</span>
						</div>
						<div class="ssm-uptime-bars" role="img" aria-label="<?php echo esc_attr( sprintf(
							/* translators: 1: monitor name, 2: number of days */
							__( '%1$s uptime for the last %2$d days', 'service-status-manager' ),
							$monitor->name,
							$range_days
						) ); ?>">
							<?php foreach ( UptimeAggregator::get_daily_history( $monitor->id, $range_days ) as $day ) :
								$bar_class = 'ssm-bar-nodata';
								$title     = $day['date'] . ': ' . esc_html__( 'No data', 'service-status-manager' );
								if ( null !== $day['uptime_pct'] ) {
									if ( $day['uptime_pct'] >= 99.9 ) {
										$bar_class = 'ssm-bar-up';
									} elseif ( $day['uptime_pct'] >= 95 ) {
										$bar_class = 'ssm-bar-degraded';
									} else {
										$bar_class = 'ssm-bar-down';
									}
									$title = $day['date'] . ': ' . number_format_i18n( $day['uptime_pct'], 2 ) . '% ' . __( 'uptime', 'service-status-manager' );
								}
								?>
								<span class="ssm-bar <?php echo esc_attr( $bar_class ); ?>" title="<?php echo esc_attr( $title ); ?>"></span>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>

	<?php if ( empty( $services ) ) : ?>
		<p><?php esc_html_e( 'No history is available yet.', 'service-status-manager' ); ?></p>
	<?php endif; ?>
</div>
