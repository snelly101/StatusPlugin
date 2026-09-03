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

$def          = ssm_get_status_definition( $overall_status );
$icon_name    = ssm_status_icon_name( $overall_status );
$overall_def  = ssm_get_overall_status_definition( $overall_status );
?>
<section class="ssm-hero ssm-status-page-hero <?php echo esc_attr( $def['css_class'] ); ?>" role="status" aria-live="polite" id="ssm-hero-status">
	<span class="ssm-hero-icon"><?php echo ssm_icon( $icon_name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
	<div class="ssm-hero-body">
		<h1 class="ssm-hero-title" id="ssm-hero-title"><?php echo esc_html( $overall_def['title'] ); ?></h1>
		<p class="ssm-hero-desc" id="ssm-hero-desc"><?php echo esc_html( $overall_def['description'] ); ?></p>
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
