<?php
/**
 * Template: active, upcoming and recently completed scheduled maintenance.
 *
 * Expects: $active_maintenance, $upcoming_maintenance, $past_maintenance
 * (arrays), $atts.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\MaintenanceManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_labels = array(
	'scheduled'   => __( 'Scheduled', 'service-status-manager' ),
	'in_progress' => __( 'In Progress', 'service-status-manager' ),
	'completed'   => __( 'Completed', 'service-status-manager' ),
	'cancelled'   => __( 'Cancelled', 'service-status-manager' ),
);

/**
 * Renders one maintenance card.
 *
 * @param object $event Maintenance row.
 */
$render_event = function ( $event ) use ( $status_labels ) {
	$services = MaintenanceManager::get_services_for_maintenance( $event->id );
	$updates  = MaintenanceManager::get_public_updates( $event->id );
	// Finished windows collapse their description + timeline behind a
	// toggle by default (same reasoning/pattern as resolved incidents in
	// incidents.php); windows still scheduled or in progress stay expanded.
	$collapsible = in_array( $event->status, array( 'completed', 'cancelled' ), true );
	$detail_id   = 'ssm-maintenance-detail-' . $event->id;
	?>
	<article class="ssm-card ssm-maintenance">
		<header class="ssm-maintenance-header<?php echo $collapsible ? ' ssm-is-expandable' : ''; ?>"
			<?php if ( $collapsible ) : ?>
			role="button" tabindex="0" aria-expanded="false" aria-controls="<?php echo esc_attr( $detail_id ); ?>"
			<?php endif; ?>
		>
			<span class="ssm-icon" aria-hidden="true"><?php echo ssm_icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<h4><?php echo esc_html( $event->title ); ?></h4>
			<span class="ssm-status-pill ssm-status-maintenance"><?php echo esc_html( $status_labels[ $event->status ] ?? $event->status ); ?></span>
			<?php if ( $collapsible ) : ?>
				<span class="ssm-maintenance-expand-icon"><?php echo ssm_icon( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php endif; ?>
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
			<?php if ( 'none' !== $event->impact ) : ?>
				&middot; <?php esc_html_e( 'Expected impact:', 'service-status-manager' ); ?> <?php echo esc_html( ucfirst( $event->impact ) ); ?>
			<?php endif; ?>
		</p>
		<?php if ( ! empty( $services ) ) : ?>
			<p class="ssm-maintenance-services">
				<?php esc_html_e( 'Affected:', 'service-status-manager' ); ?>
				<?php echo esc_html( implode( ', ', wp_list_pluck( $services, 'name' ) ) ); ?>
			</p>
		<?php endif; ?>

		<?php
		$render_body = function () use ( $event, $updates, $status_labels ) {
			?>
			<?php if ( $event->description ) : ?>
				<div class="ssm-maintenance-description"><?php echo wp_kses_post( wpautop( $event->description ) ); ?></div>
			<?php endif; ?>

			<?php if ( ! empty( $updates ) ) : ?>
				<ol class="ssm-incident-timeline">
					<?php foreach ( $updates as $update ) : ?>
						<li>
							<span class="ssm-timeline-status"><?php echo esc_html( $status_labels[ $update->status ] ?? $update->status ); ?></span>
							<time datetime="<?php echo esc_attr( $update->created_at ); ?>"><?php echo esc_html( ssm_format_datetime( $update->created_at ) ); ?></time>
							<div class="ssm-timeline-message"><?php echo wp_kses_post( wpautop( $update->message ) ); ?></div>
							<?php if ( $update->author_name ) : ?>
								<span class="ssm-timeline-author">&mdash; <?php echo esc_html( $update->author_name ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
			<?php
		};
		?>

		<?php if ( $collapsible ) : ?>
			<div class="ssm-maintenance-detail" id="<?php echo esc_attr( $detail_id ); ?>">
				<div class="ssm-maintenance-detail-inner">
					<?php $render_body(); ?>
				</div>
			</div>
		<?php else : ?>
			<?php $render_body(); ?>
		<?php endif; ?>
	</article>
	<?php
};
?>
<div class="ssm-maintenance-list">
	<?php if ( ! empty( $active_maintenance ) ) : ?>
		<h3><?php esc_html_e( 'Active maintenance', 'service-status-manager' ); ?></h3>
		<?php foreach ( $active_maintenance as $event ) : $render_event( $event ); endforeach; ?>
	<?php endif; ?>

	<?php if ( ! empty( $upcoming_maintenance ) ) : ?>
		<h3><?php esc_html_e( 'Upcoming maintenance', 'service-status-manager' ); ?></h3>
		<?php foreach ( $upcoming_maintenance as $event ) : $render_event( $event ); endforeach; ?>
	<?php endif; ?>

	<?php if ( empty( $active_maintenance ) && empty( $upcoming_maintenance ) ) : ?>
		<?php
		$empty_icon  = 'calendar';
		$empty_title = __( 'No scheduled maintenance', 'service-status-manager' );
		$empty_desc  = __( 'Nothing planned at the moment - check back later.', 'service-status-manager' );
		require SSM_PLUGIN_DIR . 'public/templates/parts/empty-state.php';
		?>
	<?php endif; ?>

	<?php if ( ! empty( $past_maintenance ) ) : ?>
		<h3><?php esc_html_e( 'Maintenance history', 'service-status-manager' ); ?></h3>
		<?php foreach ( $past_maintenance as $event ) : $render_event( $event ); endforeach; ?>
	<?php endif; ?>
</div>
