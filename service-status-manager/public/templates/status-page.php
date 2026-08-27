<?php
/**
 * Template: the full [service_status_page] output - optional sticky
 * header, hero, services, subscribe call-to-action, incidents,
 * maintenance and uptime history.
 *
 * Expects: $atts.
 *
 * @package ServiceStatusManager
 */

use ServiceStatusManager\StatusPageManager;
use ServiceStatusManager\ServiceManager;
use ServiceStatusManager\Publicweb\Shortcodes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$page          = StatusPageManager::get_page_by_slug( 'main' );
$shortcodes    = new Shortcodes();
$page_settings = $page ? ( json_decode( (string) $page->settings, true ) ?: array() ) : array();
$show_header   = ! empty( $page_settings['show_header'] );
$show_uptime_history = ! isset( $page_settings['show_uptime_history'] ) || $page_settings['show_uptime_history'];
$theme_default = in_array( $page_settings['theme_default'] ?? 'system', array( 'light', 'dark' ), true ) ? $page_settings['theme_default'] : 'auto';
$overall       = ServiceManager::get_overall_status();
$overall_def   = ssm_get_status_definition( $overall );
?>
<div class="ssm-status-page" data-ssm-theme="<?php echo esc_attr( $theme_default ); ?>" data-ssm-theme-preference="<?php echo esc_attr( $page_settings['theme_default'] ?? 'system' ); ?>">
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
	<?php endif; ?>

	<?php if ( $show_header ) : ?>
		<header class="ssm-site-header" data-ssm-header>
			<a class="ssm-site-header-brand" href="#ssm-hero-status">
				<?php if ( $page && $page->logo_url ) : ?>
					<img src="<?php echo esc_url( $page->logo_url ); ?>" alt="" class="ssm-site-header-logo" />
				<?php endif; ?>
				<span><?php echo esc_html( $page ? ( $page->page_title ?: $page->name ) : get_bloginfo( 'name' ) ); ?></span>
			</a>
			<nav aria-label="<?php esc_attr_e( 'Status page sections', 'service-status-manager' ); ?>">
				<ul class="ssm-site-header-nav">
					<li><a href="#ssm-services"><?php esc_html_e( 'Status', 'service-status-manager' ); ?></a></li>
					<li><a href="#ssm-incidents"><?php esc_html_e( 'Incidents', 'service-status-manager' ); ?></a></li>
					<li><a href="#ssm-maintenance"><?php esc_html_e( 'Maintenance', 'service-status-manager' ); ?></a></li>
					<li><a href="#" data-ssm-open-modal="subscribe"><?php esc_html_e( 'Subscribe', 'service-status-manager' ); ?></a></li>
				</ul>
			</nav>
			<span class="ssm-site-header-status">
				<span class="ssm-status-pill <?php echo esc_attr( $overall_def['css_class'] ); ?>" data-ssm-live="status-pill">
					<?php echo esc_html( $overall_def['label'] ); ?>
				</span>
			</span>
			<button type="button" class="ssm-theme-toggle" data-ssm-theme-toggle aria-label="<?php esc_attr_e( 'Toggle dark mode', 'service-status-manager' ); ?>">
				<?php echo ssm_icon( 'moon', 'ssm-theme-icon-dark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo ssm_icon( 'sun', 'ssm-theme-icon-light' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
			<button type="button" class="ssm-site-header-toggle" data-ssm-nav-toggle aria-label="<?php esc_attr_e( 'Toggle menu', 'service-status-manager' ); ?>" aria-expanded="false">
				<?php echo ssm_icon( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</header>
	<?php endif; ?>

	<?php if ( $page ) : ?>
		<header class="ssm-page-header">
			<?php if ( ! $show_header && $page->logo_url ) : ?>
				<img src="<?php echo esc_url( $page->logo_url ); ?>" alt="<?php echo esc_attr( $page->name ); ?>" class="ssm-page-logo" />
			<?php endif; ?>
			<h1 class="ssm-page-title"><?php echo esc_html( $page->page_title ?: $page->name ); ?></h1>
			<?php if ( $page->intro_text ) : ?>
				<div class="ssm-page-intro"><?php echo wp_kses_post( $page->intro_text ); ?></div>
			<?php endif; ?>
		</header>
	<?php endif; ?>

	<?php if ( 'yes' === $atts['show_subscribe'] ) : ?>
		<div class="ssm-top-subscribe" id="ssm-subscribe">
			<button type="button" class="ssm-button ssm-button-primary ssm-button-lg" data-ssm-open-modal="subscribe">
				<?php echo ssm_icon( 'mail' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Get status updates', 'service-status-manager' ); ?>
			</button>
			<noscript>
				<div class="ssm-top-subscribe-noscript">
					<?php echo $shortcodes->render_subscribe( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			</noscript>
		</div>
		<?php require SSM_PLUGIN_DIR . 'public/templates/subscribe-modal.php'; ?>
	<?php endif; ?>

	<?php echo $shortcodes->render_summary( array_merge( $atts, array( 'show_uptime' => $show_uptime_history ? 'yes' : 'no' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	<div class="ssm-page-section" id="ssm-services">
		<h2><?php esc_html_e( 'Services', 'service-status-manager' ); ?></h2>
		<?php echo $shortcodes->render_services( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<div class="ssm-page-section" id="ssm-incidents">
		<h2><?php esc_html_e( 'Incident history', 'service-status-manager' ); ?></h2>
		<?php echo $shortcodes->render_incidents( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<div class="ssm-page-section" id="ssm-maintenance">
		<h2><?php esc_html_e( 'Scheduled maintenance', 'service-status-manager' ); ?></h2>
		<?php echo $shortcodes->render_maintenance( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>

	<?php if ( $show_uptime_history ) : ?>
	<div class="ssm-page-section" id="ssm-history">
		<h2><?php esc_html_e( 'Uptime history', 'service-status-manager' ); ?></h2>
		<?php echo $shortcodes->render_history( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<?php endif; ?>

	<?php if ( $page && ( $page->support_url || $page->privacy_url || $page->terms_url ) ) : ?>
		<footer class="ssm-page-footer">
			<?php if ( $page->support_url ) : ?><a href="<?php echo esc_url( $page->support_url ); ?>"><?php esc_html_e( 'Support', 'service-status-manager' ); ?></a><?php endif; ?>
			<?php if ( $page->privacy_url ) : ?><a href="<?php echo esc_url( $page->privacy_url ); ?>"><?php esc_html_e( 'Privacy Policy', 'service-status-manager' ); ?></a><?php endif; ?>
			<?php if ( $page->terms_url ) : ?><a href="<?php echo esc_url( $page->terms_url ); ?>"><?php esc_html_e( 'Terms', 'service-status-manager' ); ?></a><?php endif; ?>
		</footer>
	<?php endif; ?>

	<div class="ssm-toast-region" data-ssm-toast-region aria-live="polite"></div>
</div>
