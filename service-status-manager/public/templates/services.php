<?php
/**
 * Template: grouped service + monitor list.
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
	$def = ssm_get_status_definition( $service->status );
	?>
	<div class="ssm-service-row">
		<div class="ssm-service-heading">
			<?php if ( $service->icon ) : ?>
				<span class="dashicons <?php echo esc_attr( $service->icon ); ?>" aria-hidden="true"></span>
			<?php endif; ?>
			<span class="ssm-service-name"><?php echo esc_html( $service->name ); ?></span>
			<span class="ssm-badge <?php echo esc_attr( $def['css_class'] ); ?>">
				<span class="screen-reader-text"><?php echo esc_html( $def['description'] ); ?></span>
				<?php echo esc_html( $def['label'] ); ?>
			</span>
		</div>
		<?php if ( $service->description ) : ?>
			<p class="ssm-service-desc"><?php echo wp_kses_post( $service->description ); ?></p>
		<?php endif; ?>

		<?php if ( $show_monitors ) :
			$monitors = array_filter( MonitorManager::get_monitors_for_service( $service->id ), fn( $m ) => $m->is_public );
			if ( ! empty( $monitors ) ) :
				?>
				<ul class="ssm-monitor-list">
					<?php foreach ( $monitors as $monitor ) : $mdef = ssm_get_status_definition( $monitor->current_state ); ?>
						<li class="ssm-monitor-row">
							<span class="ssm-monitor-name"><?php echo esc_html( $monitor->name ); ?></span>
							<span class="ssm-badge ssm-badge--sm <?php echo esc_attr( $mdef['css_class'] ); ?>"><?php echo esc_html( $mdef['label'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif;
		endif;
		?>
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
			<?php foreach ( $grouped[ $group->id ] as $service ) : $render_service( $service ); endforeach; ?>
		</div>
	<?php endforeach; ?>

	<?php if ( empty( $services ) ) : ?>
		<p><?php esc_html_e( 'No services are currently published.', 'service-status-manager' ); ?></p>
	<?php endif; ?>
</div>
