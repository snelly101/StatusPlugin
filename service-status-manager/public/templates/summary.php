<?php
/**
 * Template: overall status summary banner.
 *
 * Expects: $overall_status (string), $atts (array).
 *
 * @package ServiceStatusManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$def = ssm_get_status_definition( $overall_status );
?>
<div class="ssm-summary ssm-status-banner <?php echo esc_attr( $def['css_class'] ); ?>" role="status" aria-live="polite">
	<span class="ssm-status-icon" aria-hidden="true"></span>
	<span class="ssm-summary-text">
		<strong><?php echo esc_html( $def['label'] ); ?></strong>
		<span class="ssm-summary-desc"><?php echo esc_html( $def['description'] ); ?></span>
	</span>
	<span class="ssm-summary-updated">
		<?php
		printf(
			/* translators: %s: date/time */
			esc_html__( 'Last updated: %s', 'service-status-manager' ),
			esc_html( ssm_format_datetime( ssm_now() ) )
		);
		?>
	</span>
</div>
