<?php
/**
 * Template: upcoming and recently completed scheduled maintenance.
 *
 * Expects: $upcoming_maintenance, $past_maintenance (arrays), $atts.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\MaintenanceManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_labels = array(
	'scheduled'  => __( 'Scheduled', 'service-status-manager' ),
	'in_progress' => __( 'In Progress', 'service-status-manager' ),
	'completed'  => __( 'Completed', 'service-status-manager' ),
	'cancelled'  => __( 'Cancelled', 'service-status-manager' ),
);

/**
 * Renders one maintenance card.
 *
 * @param object $event Maintenance row.
 */
$render_event = function ( $event ) use ( $status_labels ) {
	$services = MaintenanceManager::get_services_for_maintenance( $event->id );
	?>
	<article class="ssm-maintenance ssm-maintenance--<?php echo esc_attr( $event->status ); ?>">
		<header class="ssm-maintenance-header">
			<h4><?php echo esc_html( $event->title ); ?></h4>
			<span class="ssm-badge ssm-badge--maintenance-<?php echo esc_attr( $event->status ); ?>"><?php echo esc_html( $status_labels[ $event->status ] ?? $event->status ); ?></span>
		</header>
		<p class="ssm-maintenance-window">
			<?php
			printf(
				/* translators: 1: start date/time, 2: end date/time */
				esc_html__( '%1$s to %2$s', 'service-status-manager' ),
				esc_html( ssm_format_datetime( $event->scheduled_start ) ),
				esc_html( ssm_format_datetime( $event->scheduled_end ) )
			);
			?>
		</p>
		<?php if ( ! empty( $services ) ) : ?>
			<p class="ssm-maintenance-services">
				<?php esc_html_e( 'Affected:', 'service-status-manager' ); ?>
				<?php echo esc_html( implode( ', ', wp_list_pluck( $services, 'name' ) ) ); ?>
			</p>
		<?php endif; ?>
		<?php if ( $event->description ) : ?>
			<div class="ssm-maintenance-description"><?php echo wp_kses_post( wpautop( $event->description ) ); ?></div>
		<?php endif; ?>
	</article>
	<?php
};
?>
<div class="ssm-maintenance-list">
	<?php if ( ! empty( $upcoming_maintenance ) ) : ?>
		<h3><?php esc_html_e( 'Scheduled maintenance', 'service-status-manager' ); ?></h3>
		<?php foreach ( $upcoming_maintenance as $event ) : $render_event( $event ); endforeach; ?>
	<?php else : ?>
		<p class="ssm-no-maintenance"><?php esc_html_e( 'No scheduled maintenance.', 'service-status-manager' ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $past_maintenance ) ) : ?>
		<h3><?php esc_html_e( 'Maintenance history', 'service-status-manager' ); ?></h3>
		<?php foreach ( $past_maintenance as $event ) : $render_event( $event ); endforeach; ?>
	<?php endif; ?>
</div>
