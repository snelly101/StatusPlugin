<?php
/**
 * Admin view: Appearance settings for the public status page (colours,
 * background, cards, status colours, content width, custom CSS).
 *
 * Purely visual - saving this form never touches services, monitors,
 * incidents, subscribers, notifications or provider credentials.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager\Admin;

use ServiceStatusManager\Capabilities;
use ServiceStatusManager\AppearanceSettings;
use ServiceStatusManager\AppearanceRenderer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$s = AppearanceSettings::get();

$radius_labels = array(
	'square'   => __( 'Square', 'service-status-manager' ),
	'small'    => __( 'Small', 'service-status-manager' ),
	'medium'   => __( 'Medium', 'service-status-manager' ),
	'standard' => __( 'Standard (default)', 'service-status-manager' ),
	'rounded'  => __( 'Rounded', 'service-status-manager' ),
	'xl'       => __( 'Extra rounded', 'service-status-manager' ),
);

$shadow_labels = array(
	'none'     => __( 'None', 'service-status-manager' ),
	'subtle'   => __( 'Subtle (default)', 'service-status-manager' ),
	'soft'     => __( 'Soft', 'service-status-manager' ),
	'elevated' => __( 'Elevated', 'service-status-manager' ),
);

$color_field = function ( $key, $label, $description = '' ) use ( $s ) {
	?>
	<tr>
		<th><label for="ssm-a-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
		<td>
			<input type="text" id="ssm-a-<?php echo esc_attr( $key ); ?>" class="ssm-color-picker" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>" data-default-color="<?php echo esc_attr( AppearanceSettings::defaults()[ $key ] ); ?>" />
			<?php if ( $description ) : ?>
				<p class="description"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<?php
};
?>
<div class="wrap ssm-wrap">
	<h1><?php esc_html_e( 'Appearance', 'service-status-manager' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Customise how the public status page looks. These settings are purely visual - they never affect monitoring, incidents, notifications, or any other plugin behaviour.', 'service-status-manager' ); ?></p>

	<div class="ssm-two-col">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ssm-col-list ssm-appearance-form">
			<?php wp_nonce_field( 'ssm_save_appearance' ); ?>
			<input type="hidden" name="action" value="ssm_save_appearance" />

			<h2><?php esc_html_e( 'Colours', 'service-status-manager' ); ?></h2>
			<table class="form-table">
				<?php
				$color_field( 'primary_color', __( 'Primary colour', 'service-status-manager' ), __( 'Used for links, buttons, the subscribe call-to-action, and highlighted elements.', 'service-status-manager' ) );
				$color_field( 'primary_hover_color', __( 'Primary hover colour', 'service-status-manager' ) );
				$color_field( 'text_color', __( 'Text colour', 'service-status-manager' ) );
				$color_field( 'text_muted_color', __( 'Muted text colour', 'service-status-manager' ), __( 'Used for secondary/supporting text, timestamps, and descriptions.', 'service-status-manager' ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Background', 'service-status-manager' ); ?></h2>
			<table class="form-table">
				<?php
				$color_field( 'bg_color', __( 'Page background', 'service-status-manager' ) );
				$color_field( 'bg_alt_color', __( 'Secondary background', 'service-status-manager' ), __( 'Used for subtle section fills and alternating stripes.', 'service-status-manager' ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Cards', 'service-status-manager' ); ?></h2>
			<table class="form-table">
				<?php
				$color_field( 'surface_color', __( 'Card background', 'service-status-manager' ) );
				$color_field( 'surface_hover_color', __( 'Card hover background', 'service-status-manager' ) );
				$color_field( 'card_border_color', __( 'Card border colour', 'service-status-manager' ) );
				?>
				<tr>
					<th><label for="ssm-a-card_border_width"><?php esc_html_e( 'Card border width', 'service-status-manager' ); ?></label></th>
					<td><input type="number" id="ssm-a-card_border_width" name="card_border_width" min="0" max="10" style="width:90px;" value="<?php echo esc_attr( $s['card_border_width'] ); ?>" /> px</td>
				</tr>
				<tr>
					<th><label for="ssm-a-card_radius_scale"><?php esc_html_e( 'Corner roundness', 'service-status-manager' ); ?></label></th>
					<td>
						<select id="ssm-a-card_radius_scale" name="card_radius_scale">
							<?php foreach ( $radius_labels as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['card_radius_scale'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Applies consistently to cards, buttons, badges and the subscribe modal.', 'service-status-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ssm-a-card_shadow"><?php esc_html_e( 'Shadow strength', 'service-status-manager' ); ?></label></th>
					<td>
						<select id="ssm-a-card_shadow" name="card_shadow">
							<?php foreach ( $shadow_labels as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['card_shadow'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Status colours', 'service-status-manager' ); ?></h2>
			<table class="form-table">
				<?php
				$color_field( 'status_operational_color', __( 'Operational', 'service-status-manager' ) );
				$color_field( 'status_degraded_color', __( 'Degraded performance', 'service-status-manager' ) );
				$color_field( 'status_partial_color', __( 'Partial outage', 'service-status-manager' ) );
				$color_field( 'status_outage_color', __( 'Major outage', 'service-status-manager' ) );
				$color_field( 'status_maintenance_color', __( 'Under maintenance', 'service-status-manager' ) );
				$color_field( 'status_unknown_color', __( 'Unknown', 'service-status-manager' ) );
				?>
			</table>
			<p class="description"><?php esc_html_e( 'These colours are always shown alongside a text label and icon, never as the only signal - so custom colour choices here never make status harder to identify for colour-blind visitors.', 'service-status-manager' ); ?></p>

			<h2><?php esc_html_e( 'Layout', 'service-status-manager' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="ssm-a-content_max_width"><?php esc_html_e( 'Content width', 'service-status-manager' ); ?></label></th>
					<td>
						<input type="number" id="ssm-a-content_max_width" name="content_max_width" min="480" max="1600" step="10" style="width:100px;" value="<?php echo esc_attr( $s['content_max_width'] ); ?>" /> px
						<p class="description"><?php esc_html_e( 'Maximum width of the status page content. Default is 920px. The page always remains fully responsive below this width.', 'service-status-manager' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Custom CSS', 'service-status-manager' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="ssm-a-custom_css"><?php esc_html_e( 'Advanced: custom CSS', 'service-status-manager' ); ?></label></th>
					<td>
						<textarea id="ssm-a-custom_css" name="custom_css" rows="8" class="large-text code"><?php echo esc_textarea( $s['custom_css'] ); ?></textarea>
						<p class="description"><?php echo wp_kses(
							__( 'For administrators comfortable with CSS. Scope your selectors under <code>.ssm-status-page</code> so they only affect the status page. This cannot change plugin behaviour - only appearance.', 'service-status-manager' ),
							array( 'code' => array() )
						); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Appearance', 'service-status-manager' ) ); ?>
		</form>

		<div class="ssm-col-form ssm-appearance-side">
			<div class="ssm-appearance-preview-wrap">
				<h2><?php esc_html_e( 'Preview', 'service-status-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Updates live as you change colours above. Save to apply to the real status page.', 'service-status-manager' ); ?></p>
				<div class="ssm-appearance-preview">
					<div class="ssm-status-page ssm-appearance-preview-page" id="ssm-appearance-preview-page">
						<?php
						// Every status element below sets --ssm-c inline (highest
						// specificity) rather than relying on the .ssm-status-*
						// class alone - admin.css defines its own same-named
						// .ssm-status-operational etc. classes (hardcoded colours,
						// unrelated to these settings) for other admin screens, and
						// without this the two rules would collide unpredictably
						// depending on stylesheet load order.
						?>
						<div class="ssm-hero ssm-status-page-hero ssm-status-operational" style="margin-bottom:12px; --ssm-c: var(--ssm-operational);">
							<span class="ssm-hero-icon"><?php echo ssm_icon( 'check-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<div class="ssm-hero-body">
								<div class="ssm-hero-title"><?php esc_html_e( 'All systems operational', 'service-status-manager' ); ?></div>
							</div>
						</div>
						<div class="ssm-card" style="margin-bottom:10px; padding:14px;">
							<strong><?php esc_html_e( 'Example service', 'service-status-manager' ); ?></strong>
							<span class="ssm-status-pill ssm-status-operational" style="float:right; --ssm-c: var(--ssm-operational);"><?php esc_html_e( 'Operational', 'service-status-manager' ); ?></span>
						</div>
						<div class="ssm-card" style="margin-bottom:10px; padding:14px;">
							<span class="ssm-status-pill ssm-status-degraded" style="--ssm-c: var(--ssm-degraded);"><?php esc_html_e( 'Degraded', 'service-status-manager' ); ?></span>
							<span class="ssm-status-pill ssm-status-partial-outage" style="--ssm-c: var(--ssm-partial);"><?php esc_html_e( 'Partial outage', 'service-status-manager' ); ?></span>
							<span class="ssm-status-pill ssm-status-major-outage" style="--ssm-c: var(--ssm-outage);"><?php esc_html_e( 'Major outage', 'service-status-manager' ); ?></span>
							<span class="ssm-status-pill ssm-status-maintenance" style="--ssm-c: var(--ssm-maintenance);"><?php esc_html_e( 'Maintenance', 'service-status-manager' ); ?></span>
						</div>
						<button type="button" class="ssm-button ssm-button-primary"><?php esc_html_e( 'Subscribe', 'service-status-manager' ); ?></button>
					</div>
				</div>
			</div>

			<div class="ssm-appearance-tools">
				<h2><?php esc_html_e( 'Reset', 'service-status-manager' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Reset all appearance settings to their defaults? This only affects appearance - services, incidents, subscribers and notifications are untouched.', 'service-status-manager' ) ); ?>');">
					<?php wp_nonce_field( 'ssm_reset_appearance' ); ?>
					<input type="hidden" name="action" value="ssm_reset_appearance" />
					<?php submit_button( __( 'Reset to Defaults', 'service-status-manager' ), 'secondary', 'submit', false ); ?>
				</form>

				<h2><?php esc_html_e( 'Export', 'service-status-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Downloads a JSON file containing only these appearance settings - no credentials, subscribers, or other data.', 'service-status-manager' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ssm_export_appearance' ); ?>
					<input type="hidden" name="action" value="ssm_export_appearance" />
					<?php submit_button( __( 'Export Appearance', 'service-status-manager' ), 'secondary', 'submit', false ); ?>
				</form>

				<h2><?php esc_html_e( 'Import', 'service-status-manager' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'ssm_import_appearance' ); ?>
					<input type="hidden" name="action" value="ssm_import_appearance" />
					<input type="file" name="appearance_file" accept="application/json" required />
					<?php submit_button( __( 'Import Appearance', 'service-status-manager' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
	</div>
</div>
