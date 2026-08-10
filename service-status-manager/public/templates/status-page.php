<?php
/**
 * Template: the full [service_status_page] output - branding header,
 * summary, services, incidents, maintenance and a subscribe call-to-action.
 *
 * Expects: $atts.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\StatusPageManager;
use ServiceStatusManager\Publicweb\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$page       = StatusPageManager::get_page_by_slug( 'main' );
$shortcodes = new Shortcodes();
?>
<div class="ssm-status-page" data-ssm-theme="auto">
	<?php if ( $page ) : ?>
		<?php if ( $page->primary_color || $page->secondary_color ) : ?>
			<style>
				.ssm-status-page {
					<?php if ( $page->primary_color ) : ?>--ssm-primary: <?php echo esc_html( $page->primary_color ); ?>;<?php endif; ?>
					<?php if ( $page->secondary_color ) : ?>--ssm-secondary: <?php echo esc_html( $page->secondary_color ); ?>;<?php endif; ?>
				}
			</style>
		<?php endif; ?>

		<?php if ( ! empty( $page->custom_css ) ) : ?>
			<style><?php echo wp_strip_all_tags( $page->custom_css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
		<?php endif; ?>

		<header class="ssm-page-header">
			<?php if ( $page->logo_url ) : ?>
				<img src="<?php echo esc_url( $page->logo_url ); ?>" alt="<?php echo esc_attr( $page->name ); ?>" class="ssm-page-logo" />
			<?php endif; ?>
			<h1 class="ssm-page-title"><?php echo esc_html( $page->page_title ?: $page->name ); ?></h1>
			<?php if ( $page->intro_text ) : ?>
				<div class="ssm-page-intro"><?php echo wp_kses_post( $page->intro_text ); ?></div>
			<?php endif; ?>
		</header>
	<?php endif; ?>

	<?php echo $shortcodes->render_summary( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	<div class="ssm-page-section">
		<h2><?php esc_html_e( 'Services', 'service-status-manager' ); ?></h2>
		<?php echo $shortcodes->render_services( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<?php if ( 'yes' === $atts['show_subscribe'] ) : ?>
		<div class="ssm-page-section ssm-page-subscribe-cta">
			<details class="ssm-subscribe-toggle">
				<summary class="ssm-button ssm-button-primary"><?php esc_html_e( 'Get notified', 'service-status-manager' ); ?></summary>
				<div class="ssm-subscribe-toggle-content">
					<?php echo $shortcodes->render_subscribe( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</details>
		</div>
	<?php endif; ?>

	<div class="ssm-page-section">
		<h2><?php esc_html_e( 'Incident history', 'service-status-manager' ); ?></h2>
		<?php echo $shortcodes->render_incidents( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<div class="ssm-page-section">
		<h2><?php esc_html_e( 'Scheduled maintenance', 'service-status-manager' ); ?></h2>
		<?php echo $shortcodes->render_maintenance( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<div class="ssm-page-section">
		<h2><?php esc_html_e( 'Uptime history', 'service-status-manager' ); ?></h2>
		<?php echo $shortcodes->render_history( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<?php if ( $page && ( $page->support_url || $page->privacy_url || $page->terms_url ) ) : ?>
		<footer class="ssm-page-footer">
			<?php if ( $page->support_url ) : ?><a href="<?php echo esc_url( $page->support_url ); ?>"><?php esc_html_e( 'Support', 'service-status-manager' ); ?></a><?php endif; ?>
			<?php if ( $page->privacy_url ) : ?><a href="<?php echo esc_url( $page->privacy_url ); ?>"><?php esc_html_e( 'Privacy Policy', 'service-status-manager' ); ?></a><?php endif; ?>
			<?php if ( $page->terms_url ) : ?><a href="<?php echo esc_url( $page->terms_url ); ?>"><?php esc_html_e( 'Terms', 'service-status-manager' ); ?></a><?php endif; ?>
		</footer>
	<?php endif; ?>
</div>
