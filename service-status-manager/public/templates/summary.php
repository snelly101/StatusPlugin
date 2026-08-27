<?php
/**
 * Template: hero - overall system status.
 *
 * Expects: $overall_status (string), $overall_uptime (float|null), $atts (array).
 *
 * @package ServiceStatusManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$def       = ssm_get_status_definition( $overall_status );
$icon_name = ssm_status_icon_name( $overall_status );

$default_titles = array(
	'operational'    => __( 'All Systems Operational', 'service-status-manager' ),
	'degraded'       => __( 'Degraded Performance', 'service-status-manager' ),
	'partial_outage' => __( 'Partial System Outage', 'service-status-manager' ),
	'major_outage'   => __( 'Major System Outage', 'service-status-manager' ),
	'maintenance'    => __( 'Under Scheduled Maintenance', 'service-status-manager' ),
	'unknown'        => __( 'Status Unknown', 'service-status-manager' ),
);
$default_desc = array(
	'operational'    => __( 'All monitored services are operating normally.', 'service-status-manager' ),
	'degraded'       => __( 'Some services are experiencing degraded performance.', 'service-status-manager' ),
	'partial_outage' => __( 'Some services are currently unavailable.', 'service-status-manager' ),
	'major_outage'   => __( 'We are aware of a significant service disruption and are working to resolve it.', 'service-status-manager' ),
	'maintenance'    => __( 'Scheduled maintenance is currently in progress.', 'service-status-manager' ),
	'unknown'        => __( 'We could not determine the current status of all services.', 'service-status-manager' ),
);
?>
<section class="ssm-hero ssm-status-page-hero <?php echo esc_attr( $def['css_class'] ); ?>" role="status" aria-live="polite" id="ssm-hero-status">
	<span class="ssm-hero-icon"><?php echo ssm_icon( $icon_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<div class="ssm-hero-body">
		<h1 class="ssm-hero-title" id="ssm-hero-title"><?php echo esc_html( $default_titles[ $overall_status ] ?? $def['label'] ); ?></h1>
		<p class="ssm-hero-desc" id="ssm-hero-desc"><?php echo esc_html( $default_desc[ $overall_status ] ?? $def['description'] ); ?></p>
		<div class="ssm-hero-meta">
			<span id="ssm-hero-updated">
				<?php
				printf(
					/* translators: %s: date/time */
					esc_html__( 'Last checked: %s', 'service-status-manager' ),
					'<strong>' . esc_html( ssm_format_datetime( ssm_now() ) ) . '</strong>'
				);
				?>
			</span>
			<?php if ( null !== $overall_uptime ) : ?>
				<span><?php esc_html_e( 'Overall uptime:', 'service-status-manager' ); ?> <strong><?php echo esc_html( number_format_i18n( $overall_uptime, 2 ) ); ?>%</strong> <?php esc_html_e( '(90 days)', 'service-status-manager' ); ?></span>
			<?php endif; ?>
		</div>
	</div>
</section>
