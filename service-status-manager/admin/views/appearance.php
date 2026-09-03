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

// Same as $color_field, but the empty string is a valid, meaningful value
// ("inherit the page's normal colour") rather than "unset" - used only for
// the header overrides, which default to blank for exactly that reason.
$optional_color_field = function ( $key, $label, $description ) use ( $s ) {
	?>
	<tr>
		<th><label for="ssm-a-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
		<td>
			<input type="text" id="ssm-a-<?php echo esc_attr( $key ); ?>" class="ssm-color-picker" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>" data-default-color="" />
			<p class="description"><?php echo esc_html( $description ); ?></p>
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

			<h2><?php esc_html_e( 'Quick start: theme preset', 'service-status-manager' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><label for="ssm-a-theme-preset"><?php esc_html_e( 'Apply a preset', 'service-status-manager' ); ?></label></th>
					<td>
						<select id="ssm-a-theme-preset">
							<option value=""><?php esc_html_e( '— Choose a starting point —', 'service-status-manager' ); ?></option>
							<option value="clean_light"><?php esc_html_e( 'Clean Light', 'service-status-manager' ); ?></option>
							<option value="modern_dark"><?php esc_html_e( 'Modern Dark', 'service-status-manager' ); ?></option>
							<option value="cloud"><?php esc_html_e( 'Cloud', 'service-status-manager' ); ?></option>
							<option value="minimal"><?php esc_html_e( 'Minimal', 'service-status-manager' ); ?></option>
							<option value="high_contrast"><?php esc_html_e( 'High Contrast', 'service-status-manager' ); ?></option>
						</select>
						<button type="button" class="button" id="ssm-a-apply-preset"><?php esc_html_e( 'Apply', 'service-status-manager' ); ?></button>
						<p class="description"><?php esc_html_e( 'Fills in the fields below as a starting point - review and adjust anything, then Save Appearance. Applying a preset does not save anything by itself.', 'service-status-manager' ); ?></p>
					</td>
				</tr>
			</table>

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
				$color_field( 'bg_alt_color', __( 'Secondary background', 'service-status-manager' ), __( 'Used for subtle section fills, the decorative grid pattern below, and alternating stripes.', 'service-status-manager' ) );
				?>
				<tr>
					<th><label for="ssm-a-background_pattern"><?php esc_html_e( 'Background pattern', 'service-status-manager' ); ?></label></th>
					<td>
						<select id="ssm-a-background_pattern" name="background_pattern">
							<option value="default" <?php selected( $s['background_pattern'], 'default' ); ?>><?php esc_html_e( 'Grid + soft glow (default)', 'service-status-manager' ); ?></option>
							<option value="grid" <?php selected( $s['background_pattern'], 'grid' ); ?>><?php esc_html_e( 'Fine grid only', 'service-status-manager' ); ?></option>
							<option value="glow" <?php selected( $s['background_pattern'], 'glow' ); ?>><?php esc_html_e( 'Soft glow only', 'service-status-manager' ); ?></option>
							<option value="none" <?php selected( $s['background_pattern'], 'none' ); ?>><?php esc_html_e( 'None (flat)', 'service-status-manager' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'A subtle decorative texture behind the page content.', 'service-status-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ssm-a-background_style"><?php esc_html_e( 'Background style', 'service-status-manager' ); ?></label></th>
					<td>
						<select id="ssm-a-background_style" name="background_style">
							<option value="solid" <?php selected( $s['background_style'], 'solid' ); ?>><?php esc_html_e( 'Solid colour (default)', 'service-status-manager' ); ?></option>
							<option value="gradient" <?php selected( $s['background_style'], 'gradient' ); ?>><?php esc_html_e( 'Gradient', 'service-status-manager' ); ?></option>
							<option value="image" <?php selected( $s['background_style'], 'image' ); ?>><?php esc_html_e( 'Image', 'service-status-manager' ); ?></option>
						</select>
					</td>
				</tr>
				<tbody id="ssm-a-gradient-fields">
					<?php
					$color_field( 'gradient_start_color', __( 'Gradient start colour', 'service-status-manager' ) );
					$color_field( 'gradient_end_color', __( 'Gradient end colour', 'service-status-manager' ) );
					?>
					<tr>
						<th><label for="ssm-a-gradient_direction"><?php esc_html_e( 'Gradient direction', 'service-status-manager' ); ?></label></th>
						<td>
							<select id="ssm-a-gradient_direction" name="gradient_direction">
								<option value="to-bottom" <?php selected( $s['gradient_direction'], 'to-bottom' ); ?>><?php esc_html_e( 'Top to bottom', 'service-status-manager' ); ?></option>
								<option value="to-right" <?php selected( $s['gradient_direction'], 'to-right' ); ?>><?php esc_html_e( 'Left to right', 'service-status-manager' ); ?></option>
								<option value="diagonal" <?php selected( $s['gradient_direction'], 'diagonal' ); ?>><?php esc_html_e( 'Diagonal', 'service-status-manager' ); ?></option>
								<option value="radial" <?php selected( $s['gradient_direction'], 'radial' ); ?>><?php esc_html_e( 'Radial', 'service-status-manager' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Only used when Background style is set to Gradient.', 'service-status-manager' ); ?></p>
						</td>
					</tr>
				</tbody>
				<tbody id="ssm-a-image-fields">
					<tr>
						<th><label for="ssm-a-background_image_url"><?php esc_html_e( 'Background image', 'service-status-manager' ); ?></label></th>
						<td>
							<input type="text" id="ssm-a-background_image_url" name="background_image_url" class="regular-text" value="<?php echo esc_attr( $s['background_image_url'] ); ?>" readonly />
							<button type="button" class="button" id="ssm-a-choose-bg-image"><?php esc_html_e( 'Choose image', 'service-status-manager' ); ?></button>
							<button type="button" class="button" id="ssm-a-remove-bg-image" <?php echo $s['background_image_url'] ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove', 'service-status-manager' ); ?></button>
							<div id="ssm-a-bg-image-preview" style="margin-top:8px;">
								<?php if ( $s['background_image_url'] ) : ?>
									<img src="<?php echo esc_url( $s['background_image_url'] ); ?>" alt="" style="max-width:200px; max-height:100px; border:1px solid #ddd; border-radius:4px;" />
								<?php endif; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th><label for="ssm-a-background_image_position"><?php esc_html_e( 'Image position', 'service-status-manager' ); ?></label></th>
						<td>
							<select id="ssm-a-background_image_position" name="background_image_position">
								<?php foreach ( array( 'center', 'top', 'bottom', 'left', 'right' ) as $pos ) : ?>
									<option value="<?php echo esc_attr( $pos ); ?>" <?php selected( $s['background_image_position'], $pos ); ?>><?php echo esc_html( ucfirst( $pos ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ssm-a-background_image_size"><?php esc_html_e( 'Image size', 'service-status-manager' ); ?></label></th>
						<td>
							<select id="ssm-a-background_image_size" name="background_image_size">
								<option value="cover" <?php selected( $s['background_image_size'], 'cover' ); ?>><?php esc_html_e( 'Cover (fill, may crop)', 'service-status-manager' ); ?></option>
								<option value="contain" <?php selected( $s['background_image_size'], 'contain' ); ?>><?php esc_html_e( 'Contain (fit, no crop)', 'service-status-manager' ); ?></option>
								<option value="auto" <?php selected( $s['background_image_size'], 'auto' ); ?>><?php esc_html_e( 'Original size', 'service-status-manager' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ssm-a-background_image_repeat"><?php esc_html_e( 'Image repeat', 'service-status-manager' ); ?></label></th>
						<td>
							<select id="ssm-a-background_image_repeat" name="background_image_repeat">
								<option value="no-repeat" <?php selected( $s['background_image_repeat'], 'no-repeat' ); ?>><?php esc_html_e( 'No repeat', 'service-status-manager' ); ?></option>
								<option value="repeat" <?php selected( $s['background_image_repeat'], 'repeat' ); ?>><?php esc_html_e( 'Tile', 'service-status-manager' ); ?></option>
								<option value="repeat-x" <?php selected( $s['background_image_repeat'], 'repeat-x' ); ?>><?php esc_html_e( 'Tile horizontally', 'service-status-manager' ); ?></option>
								<option value="repeat-y" <?php selected( $s['background_image_repeat'], 'repeat-y' ); ?>><?php esc_html_e( 'Tile vertically', 'service-status-manager' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ssm-a-background_image_attachment"><?php esc_html_e( 'Scrolling', 'service-status-manager' ); ?></label></th>
						<td>
							<select id="ssm-a-background_image_attachment" name="background_image_attachment">
								<option value="scroll" <?php selected( $s['background_image_attachment'], 'scroll' ); ?>><?php esc_html_e( 'Scrolls with the page', 'service-status-manager' ); ?></option>
								<option value="fixed" <?php selected( $s['background_image_attachment'], 'fixed' ); ?>><?php esc_html_e( 'Fixed (parallax-style)', 'service-status-manager' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Fixed always scrolls normally on small screens, where it can cause rendering issues.', 'service-status-manager' ); ?></p>
						</td>
					</tr>
					<?php $color_field( 'background_image_overlay_color', __( 'Overlay colour', 'service-status-manager' ), __( 'A tint over the image to help text stay readable.', 'service-status-manager' ) ); ?>
					<tr>
						<th><label for="ssm-a-background_image_overlay_opacity"><?php esc_html_e( 'Overlay opacity', 'service-status-manager' ); ?></label></th>
						<td>
							<input type="number" id="ssm-a-background_image_overlay_opacity" name="background_image_overlay_opacity" min="0" max="100" style="width:90px;" value="<?php echo esc_attr( $s['background_image_overlay_opacity'] ); ?>" /> %
							<p class="description"><?php esc_html_e( '0 = no overlay (default), 100 = fully opaque.', 'service-status-manager' ); ?></p>
						</td>
					</tr>
				</tbody>
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

			<h2><?php esc_html_e( 'Header', 'service-status-manager' ); ?></h2>
			<table class="form-table">
				<?php
				$optional_color_field( 'header_bg_color', __( 'Header background', 'service-status-manager' ), __( 'Leave blank to use the card background colour above (today\'s default: a translucent, blurred header).', 'service-status-manager' ) );
				$optional_color_field( 'header_text_color', __( 'Header text', 'service-status-manager' ), __( 'Leave blank to use the page text colour above.', 'service-status-manager' ) );
				$color_field( 'accent_color', __( 'Accent colour', 'service-status-manager' ), __( 'Used for the header navigation links\' hover colour.', 'service-status-manager' ) );
				?>
				<tr>
					<th><?php esc_html_e( 'Sticky header', 'service-status-manager' ); ?></th>
					<td>
						<label><input type="checkbox" name="header_sticky" value="1" <?php checked( ! empty( $s['header_sticky'] ) ); ?> /> <?php esc_html_e( 'Keep the header pinned to the top of the screen while scrolling', 'service-status-manager' ); ?></label>
						<p class="description"><?php esc_html_e( 'Only applies to status pages with "Dedicated header" turned on (Status Pages screen).', 'service-status-manager' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Buttons', 'service-status-manager' ); ?></h2>
			<table class="form-table">
				<?php $color_field( 'button_text_color', __( 'Primary button text colour', 'service-status-manager' ), __( 'The Subscribe button and other primary actions. Corner roundness and shadow follow the Cards settings above.', 'service-status-manager' ) ); ?>
			</table>

			<h2><?php esc_html_e( 'Dark mode', 'service-status-manager' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Custom dark mode colours', 'service-status-manager' ); ?></th>
					<td>
						<label><input type="checkbox" id="ssm-a-dark_mode_custom" name="dark_mode_custom" value="1" <?php checked( ! empty( $s['dark_mode_custom'] ) ); ?> /> <?php esc_html_e( 'Use the colours below instead of the built-in dark palette', 'service-status-manager' ); ?></label>
						<p class="description"><?php esc_html_e( 'Left off, dark mode keeps its current appearance exactly - visitors who prefer dark mode (or whose system is set to it) are unaffected by the light-mode colours above.', 'service-status-manager' ); ?></p>
					</td>
				</tr>
			</table>
			<table class="form-table ssm-appearance-dark-fields">
				<?php
				$color_field( 'dark_bg_color', __( 'Dark page background', 'service-status-manager' ) );
				$color_field( 'dark_bg_alt_color', __( 'Dark secondary background', 'service-status-manager' ) );
				$color_field( 'dark_surface_color', __( 'Dark card background', 'service-status-manager' ) );
				$color_field( 'dark_surface_hover_color', __( 'Dark card hover background', 'service-status-manager' ) );
				$color_field( 'dark_border_color', __( 'Dark border colour', 'service-status-manager' ) );
				$color_field( 'dark_text_color', __( 'Dark text colour', 'service-status-manager' ) );
				$color_field( 'dark_text_muted_color', __( 'Dark muted text colour', 'service-status-manager' ) );
				?>
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

			<div class="ssm-appearance-a11y">
				<h2><?php esc_html_e( 'Accessibility check', 'service-status-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Live contrast ratios for the colour pairs visitors read text against. This is informational - your colours are never changed automatically.', 'service-status-manager' ); ?></p>
				<ul id="ssm-a-a11y-results" class="ssm-a11y-results">
					<li data-pair="text_color:bg_color"><?php esc_html_e( 'Body text on page background', 'service-status-manager' ); ?> <span class="ssm-a11y-ratio">—</span></li>
					<li data-pair="text_color:surface_color"><?php esc_html_e( 'Body text on card background', 'service-status-manager' ); ?> <span class="ssm-a11y-ratio">—</span></li>
					<li data-pair="text_muted_color:surface_color"><?php esc_html_e( 'Muted text on card background', 'service-status-manager' ); ?> <span class="ssm-a11y-ratio">—</span></li>
					<li data-pair="button_text_color:primary_color"><?php esc_html_e( 'Button text on primary colour', 'service-status-manager' ); ?> <span class="ssm-a11y-ratio">—</span></li>
				</ul>
				<p class="description"><?php esc_html_e( 'WCAG AA requires at least 4.5:1 for normal text. Ratios below that are flagged but not blocked - you may have a deliberate reason for a lower-contrast decorative element.', 'service-status-manager' ); ?></p>
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
