<?php
/**
 * Template: grouped service + monitor list, with expand/collapse detail.
 *
 * Expects: $groups, $services (arrays), $atts.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\MonitorManager;
use ServiceStatusManager\ServiceManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$grouped = array();
foreach ( $services as $service ) {
	$grouped[ (int) $service->group_id ][] = $service;
}

$show_monitors = 'yes' === $atts['show_monitors'];

/**
 * Renders one service row, including its monitors when enabled.
 *
 * @param object $service Service row.
 */
$render_service = function ( $service ) use ( $show_monitors ) {
	$def      = ssm_get_status_definition( ServiceManager::get_display_status( $service ) );
	$monitors = $show_monitors ? array_values( array_filter( MonitorManager::get_monitors_for_service( $service->id ), fn( $m ) => $m->is_public ) ) : array();
	$has_detail = ! empty( $monitors ) || $service->description;
	$row_id   = 'ssm-service-' . $service->id;
	?>
	<div class="ssm-card ssm-service-row" data-ssm-service-id="<?php echo esc_attr( $service->id ); ?>" data-ssm-service-slug="<?php echo esc_attr( $service->slug ); ?>">
		<div class="ssm-service-heading<?php echo $has_detail ? ' ssm-is-expandable' : ''; ?>"
			<?php if ( $has_detail ) : ?>
			role="button" tabindex="0" aria-expanded="false" aria-controls="<?php echo esc_attr( $row_id ); ?>"
			<?php endif; ?>
		>
			<span class="ssm-service-icon"><?php echo ssm_icon( $service->icon && false === strpos( (string) $service->icon, 'dashicons' ) ? $service->icon : 'server' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<span class="ssm-service-name"><?php echo esc_html( $service->name ); ?></span>
			<span class="ssm-status-pill <?php echo esc_attr( $def['css_class'] ); ?>">
				<span class="screen-reader-text"><?php echo esc_html( $def['description'] ); ?></span>
				<?php echo esc_html( $def['label'] ); ?>
			</span>
			<?php if ( $has_detail ) : ?>
				<span class="ssm-service-expand-icon"><?php echo ssm_icon( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php endif; ?>
		</div>

		<?php if ( $has_detail ) : ?>
			<div class="ssm-service-detail" id="<?php echo esc_attr( $row_id ); ?>">
				<div class="ssm-service-detail-inner">
					<?php if ( $service->description ) : ?>
						<p class="ssm-service-desc"><?php echo wp_kses_post( $service->description ); ?></p>
					<?php endif; ?>

					<?php if ( ! empty( $monitors ) ) : ?>
						<ul class="ssm-monitor-list">
							<?php
							foreach ( $monitors as $monitor ) :
								$is_stale = MonitorManager::is_stale( $monitor );
								// A stale monitor's own last-known state is no
								// longer trustworthy - show it as Unknown here
								// too, matching the service-level treatment in
								// ServiceManager::get_display_status(), rather
								// than a per-monitor pill silently disagreeing
								// with the service pill above it.
								$mdef = ssm_get_status_definition( $is_stale ? 'unknown' : $monitor->current_state );
								?>
								<li class="ssm-monitor-row">
									<span class="ssm-status-dot <?php echo esc_attr( $mdef['css_class'] ); ?>"></span>
									<span class="ssm-monitor-name"><?php echo esc_html( $monitor->name ); ?></span>
									<?php if ( $monitor->last_response_time_ms && ! $is_stale ) : ?>
										<span class="ssm-monitor-meta"><?php echo esc_html( $monitor->last_response_time_ms ); ?> ms</span>
									<?php endif; ?>
									<?php if ( $monitor->last_checked_at ) : ?>
										<span class="ssm-monitor-meta ssm-monitor-checked<?php echo $is_stale ? ' ssm-is-stale' : ''; ?>" title="<?php echo esc_attr( ssm_format_datetime( $monitor->last_checked_at ) . ' ' . ssm_get_timezone()->getName() ); ?>">
											<?php
											echo esc_html(
												$is_stale
													/* translators: %s: how long ago the monitor was last checked, e.g. "3 hours ago" */
													? sprintf( __( 'Stale - last checked %s', 'service-status-manager' ), ssm_time_ago( $monitor->last_checked_at ) )
													/* translators: %s: how long ago the monitor was last checked, e.g. "30 seconds ago" */
													: sprintf( __( 'Checked %s', 'service-status-manager' ), ssm_time_ago( $monitor->last_checked_at ) )
											);
											?>
										</span>
									<?php elseif ( 'manual' !== $monitor->type ) : ?>
										<span class="ssm-monitor-meta ssm-is-stale"><?php esc_html_e( 'Not yet checked', 'service-status-manager' ); ?></span>
									<?php endif; ?>
									<span class="ssm-status-pill <?php echo esc_attr( $mdef['css_class'] ); ?>"><?php echo esc_html( $mdef['label'] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
};
?>
<div class="ssm-services">
	<?php
	if ( isset( $grouped[0] ) ) {
		foreach ( $grouped[0] as $service ) {
			$render_service( $service );
		}
	}

	foreach ( $groups as $group ) :
		if ( empty( $grouped[ $group->id ] ) ) {
			continue;
		}
		?>
		<div class="ssm-service-group">
			<h3 class="ssm-service-group-title"><?php echo esc_html( $group->name ); ?></h3>
			<div class="ssm-services">
				<?php foreach ( $grouped[ $group->id ] as $service ) : $render_service( $service ); endforeach; ?>
			</div>
		</div>
	<?php endforeach; ?>

	<?php
	if ( empty( $services ) ) :
		$empty_icon  = 'server';
		$empty_title = __( 'No services published yet', 'service-status-manager' );
		$empty_desc  = __( 'Once services are added, their status will appear here.', 'service-status-manager' );
		require SSM_PLUGIN_DIR . 'public/templates/parts/empty-state.php';
	endif;
	?>
</div>
