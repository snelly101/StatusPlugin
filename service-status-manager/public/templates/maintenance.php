<?php
/**
 * Template: active, overdue, upcoming and recently completed scheduled
 * maintenance.
 *
 * Expects: $active_maintenance, $overdue_maintenance, $upcoming_maintenance,
 * $past_maintenance (arrays), $atts.
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
	'overdue'     => __( 'Awaiting update', 'service-status-manager' ),
	'completed'   => __( 'Completed', 'service-status-manager' ),
	'cancelled'   => __( 'Cancelled', 'service-status-manager' ),
);

// Plain-language explanation of what "impact" means, shown next to the
// window's schedule.
$impact_labels = array(
	'none'  => __( 'No expected impact', 'service-status-manager' ),
	'minor' => __( 'Minor - brief or partial disruption possible', 'service-status-manager' ),
	'major' => __( 'Major - services may be unavailable', 'service-status-manager' ),
);

/**
 * Renders one maintenance card.
 *
 * @param object $event Maintenance row.
 */
$render_event = function ( $event ) use ( $status_labels, $impact_labels ) {
	$services = MaintenanceManager::get_services_for_maintenance( $event->id );
	$updates  = MaintenanceManager::get_public_updates( $event->id );
	// Finished windows collapse their description + timeline behind a
	// toggle by default (same reasoning/pattern as resolved incidents in
	// incidents.php); windows still scheduled, in progress, or overdue
	// stay expanded, since that detail is what visitors need right now.
	$collapsible = in_array( $event->status, array( 'completed', 'cancelled' ), true );
	$detail_id   = 'ssm-maintenance-detail-' . $event->id;
	// "overdue" gets a warning-toned pill (the same tone as a degraded
	// service) rather than the neutral maintenance colour every other
	// status uses here - it is specifically an attention state, not a
	// normal step in the lifecycle.
	$pill_class  = 'overdue' === $event->status ? 'ssm-status-degraded' : 'ssm-status-maintenance';
	$site_tz     = ssm_get_timezone()->getName();
	$original_tz = $event->timezone;
	?>
	<article class="ssm-card ssm-maintenance<?php echo 'overdue' === $event->status ? ' ssm-maintenance--overdue' : ''; ?>">
		<header class="ssm-maintenance-header<?php echo $collapsible ? ' ssm-is-expandable' : ''; ?>"
			<?php if ( $collapsible ) : ?>
			role="button" tabindex="0" aria-expanded="false" aria-controls="<?php echo esc_attr( $detail_id ); ?>"
			<?php endif; ?>
		>
			<span class="ssm-icon" aria-hidden="true"><?php echo ssm_icon( 'calendar' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<h4><?php echo esc_html( $event->title ); ?></h4>
			<span class="ssm-status-pill <?php echo esc_attr( $pill_class ); ?>"><?php echo esc_html( $status_labels[ $event->status ] ?? $event->status ); ?></span>
			<?php if ( $collapsible ) : ?>
				<span class="ssm-maintenance-expand-icon"><?php echo ssm_icon( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			<?php endif; ?>
		</header>
		<p class="ssm-maintenance-window">
			<?php
			printf(
				/* translators: 1: start date/time, 2: end date/time, 3: timezone abbreviation/name */
				esc_html__( '%1$s to %2$s %3$s', 'service-status-manager' ),
				esc_html( ssm_format_datetime( $event->scheduled_start ) ),
				esc_html( ssm_format_datetime( $event->scheduled_end ) ),
				esc_html( $site_tz )
			);
			?>
			<?php if ( $original_tz && $original_tz !== $site_tz ) : ?>
				<span class="ssm-maintenance-original-tz">
					(<?php
					printf(
						/* translators: %s: the timezone the window was originally scheduled in */
						esc_html__( 'originally scheduled in %s', 'service-status-manager' ),
						esc_html( $original_tz )
					);
					?>)
				</span>
			<?php endif; ?>
			<?php if ( 'none' !== $event->impact ) : ?>
				&middot; <span title="<?php echo esc_attr( $impact_labels[ $event->impact ] ?? '' ); ?>"><?php esc_html_e( 'Expected impact:', 'service-status-manager' ); ?> <?php echo esc_html( ucfirst( $event->impact ) ); ?></span>
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

	<?php if ( ! empty( $overdue_maintenance ) ) : ?>
		<h3><?php esc_html_e( 'Awaiting update', 'service-status-manager' ); ?></h3>
		<p class="ssm-maintenance-section-note"><?php esc_html_e( "These windows have passed their scheduled end time; we're confirming they actually finished before marking them complete.", 'service-status-manager' ); ?></p>
		<?php foreach ( $overdue_maintenance as $event ) : $render_event( $event ); endforeach; ?>
	<?php endif; ?>

	<?php if ( ! empty( $upcoming_maintenance ) ) : ?>
		<h3><?php esc_html_e( 'Upcoming maintenance', 'service-status-manager' ); ?></h3>
		<?php foreach ( $upcoming_maintenance as $event ) : $render_event( $event ); endforeach; ?>
	<?php endif; ?>

	<?php if ( empty( $active_maintenance ) && empty( $overdue_maintenance ) && empty( $upcoming_maintenance ) ) : ?>
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
