<?php
/**
 * Template: active + recently resolved incidents timeline.
 *
 * Expects: $active_incidents, $resolved_incidents (arrays), $atts.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\IncidentManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$severity_labels = array(
	'informational' => __( 'Informational', 'service-status-manager' ),
	'minor'         => __( 'Minor', 'service-status-manager' ),
	'major'         => __( 'Major', 'service-status-manager' ),
	'critical'      => __( 'Critical', 'service-status-manager' ),
);
$status_labels = array(
	'investigating' => __( 'Investigating', 'service-status-manager' ),
	'identified'    => __( 'Identified', 'service-status-manager' ),
	'monitoring'    => __( 'Monitoring', 'service-status-manager' ),
	'resolved'      => __( 'Resolved', 'service-status-manager' ),
);
$severity_status_class = array(
	'informational' => 'ssm-status-degraded',
	'minor'         => 'ssm-status-degraded',
	'major'         => 'ssm-status-partial-outage',
	'critical'      => 'ssm-status-major-outage',
);

/**
 * Renders a single incident card with its public update timeline.
 *
 * @param object $incident Incident row.
 */
$render_incident = function ( $incident ) use ( $severity_labels, $status_labels, $severity_status_class ) {
	$updates       = IncidentManager::get_public_updates( $incident->id );
	$services      = IncidentManager::get_services_for_incident( $incident->id );
	$is_resolved   = 'resolved' === $incident->status;
	$severity_class = $severity_status_class[ $incident->severity ] ?? 'ssm-status-unknown';
	?>
	<article class="ssm-card ssm-incident <?php echo esc_attr( $severity_class ); ?> <?php echo $is_resolved ? 'ssm-incident--resolved' : ''; ?> <?php echo $incident->is_pinned ? 'ssm-incident--pinned' : ''; ?>">
		<header class="ssm-incident-header">
			<h4 class="ssm-incident-title"><?php echo esc_html( $incident->title ); ?></h4>
			<span class="ssm-status-pill <?php echo esc_attr( $severity_class ); ?>"><?php echo esc_html( $severity_labels[ $incident->severity ] ?? $incident->severity ); ?></span>
			<span class="ssm-status-pill <?php echo $is_resolved ? 'ssm-status-operational' : esc_attr( $severity_class ); ?>"><?php echo esc_html( $status_labels[ $incident->status ] ?? $incident->status ); ?></span>
		</header>

		<?php if ( ! empty( $services ) ) : ?>
			<p class="ssm-incident-services">
				<?php esc_html_e( 'Affected:', 'service-status-manager' ); ?>
				<?php echo esc_html( implode( ', ', wp_list_pluck( $services, 'name' ) ) ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $incident->description ) : ?>
			<div class="ssm-incident-description"><?php echo wp_kses_post( wpautop( $incident->description ) ); ?></div>
		<?php endif; ?>

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
	</article>
	<?php
};
?>
<div class="ssm-incidents">
	<?php if ( ! empty( $active_incidents ) ) : ?>
		<?php foreach ( $active_incidents as $incident ) : $render_incident( $incident ); endforeach; ?>
	<?php else : ?>
		<?php
		$empty_icon  = 'check-circle';
		$empty_title = __( 'No incidents reported', 'service-status-manager' );
		$empty_desc  = __( 'Everything has been running smoothly.', 'service-status-manager' );
		require SSM_PLUGIN_DIR . 'public/templates/parts/empty-state.php';
		?>
	<?php endif; ?>

	<?php if ( ! empty( $resolved_incidents ) ) : ?>
		<h3><?php esc_html_e( 'Recently resolved', 'service-status-manager' ); ?></h3>
		<?php foreach ( $resolved_incidents as $incident ) : $render_incident( $incident ); endforeach; ?>
	<?php endif; ?>
</div>
