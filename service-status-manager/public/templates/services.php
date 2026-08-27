<?php
/**
 * Template: grouped service + monitor list, with expand/collapse detail.
 *
 * Expects: $groups, $services (arrays), $atts.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\MonitorManager;

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
	$def      = ssm_get_status_definition( $service->status );
	$monitors = $show_monitors ? array_values( array_filter( MonitorManager::get_monitors_for_service( $service->id ), fn( $m ) => $m->is_public ) ) : array();
	$has_detail = ! empty( $monitors ) || $service->description;
	$row_id   = 'ssm-service-' . $service->id;
	?>
	<div class="ssm-card ssm-service-row" data-ssm-service-id="<?php echo esc_attr( $service->id ); ?>">
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
							<?php foreach ( $monitors as $monitor ) : $mdef = ssm_get_status_definition( $monitor->current_state ); ?>
								<li class="ssm-monitor-row">
									<span class="ssm-status-dot <?php echo esc_attr( $mdef['css_class'] ); ?>"></span>
									<span class="ssm-monitor-name"><?php echo esc_html( $monitor->name ); ?></span>
									<?php if ( $monitor->last_response_time_ms ) : ?>
										<span class="ssm-monitor-meta"><?php echo esc_html( $monitor->last_response_time_ms ); ?> ms</span>
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
