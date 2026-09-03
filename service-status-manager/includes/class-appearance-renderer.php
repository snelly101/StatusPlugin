<?php
/**
 * Turns saved appearance settings into a single scoped CSS declaration
 * block, output once per page load via wp_add_inline_style() rather than
 * an inline <style> tag repeated per component.
 *
 * Most declarations here target the plain `.ssm-status-page` selector,
 * which is intentionally the same specificity as the design-system
 * defaults in public.css and lower specificity than its dark-mode
 * overrides (`.ssm-status-page:not([data-ssm-theme="light"])` and
 * `.ssm-status-page[data-ssm-theme="dark"]`, both one selector heavier).
 * That means these settings customise light mode - and any value that
 * genuinely isn't a light/dark concept (radius, shadow shape, content
 * width, border width, header position) - without fighting the built-in
 * dark palette.
 *
 * When an admin explicitly turns on "custom dark mode colours", a second
 * block is appended that mirrors those same two dark-mode selectors
 * exactly (same specificity, later in source order, so it wins the
 * cascade tie the same way the light block wins over the plain base
 * selector). Left off (the default), dark mode keeps using the plugin's
 * existing, already-designed dark palette - completely untouched.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AppearanceRenderer {

	/**
	 * Border-radius scale presets. "standard" matches public.css's
	 * existing hardcoded values exactly.
	 */
	const RADIUS_SCALES = array(
		'square'   => array( 'sm' => '0px', 'md' => '0px', 'lg' => '0px', 'xl' => '0px' ),
		'small'    => array( 'sm' => '4px', 'md' => '6px', 'lg' => '8px', 'xl' => '10px' ),
		'medium'   => array( 'sm' => '6px', 'md' => '8px', 'lg' => '10px', 'xl' => '14px' ),
		'standard' => array( 'sm' => '8px', 'md' => '12px', 'lg' => '16px', 'xl' => '24px' ),
		'rounded'  => array( 'sm' => '10px', 'md' => '16px', 'lg' => '20px', 'xl' => '28px' ),
		'xl'       => array( 'sm' => '14px', 'md' => '20px', 'lg' => '28px', 'xl' => '36px' ),
	);

	/**
	 * Shadow presets. "subtle" matches public.css's existing hardcoded
	 * light-mode shadow values exactly.
	 */
	const SHADOW_PRESETS = array(
		'none'     => array( 'sm' => 'none', 'md' => 'none', 'lg' => 'none' ),
		'subtle'   => array(
			'sm' => '0 1px 2px rgba(15,23,42,.05)',
			'md' => '0 6px 20px -6px rgba(15,23,42,.10), 0 2px 6px -2px rgba(15,23,42,.05)',
			'lg' => '0 16px 40px -12px rgba(15,23,42,.16), 0 4px 10px -4px rgba(15,23,42,.06)',
		),
		'soft'     => array(
			'sm' => '0 2px 6px rgba(15,23,42,.08)',
			'md' => '0 10px 28px -8px rgba(15,23,42,.16), 0 4px 10px -4px rgba(15,23,42,.08)',
			'lg' => '0 22px 50px -14px rgba(15,23,42,.22), 0 6px 14px -6px rgba(15,23,42,.10)',
		),
		'elevated' => array(
			'sm' => '0 4px 10px rgba(15,23,42,.12)',
			'md' => '0 16px 36px -8px rgba(15,23,42,.22), 0 6px 14px -4px rgba(15,23,42,.12)',
			'lg' => '0 30px 64px -16px rgba(15,23,42,.30), 0 10px 20px -6px rgba(15,23,42,.14)',
		),
	);

	/**
	 * Decorative background layers, keyed by the "Background pattern"
	 * setting. "default" is the plugin's original always-on look
	 * (a soft radial glow behind the hero plus a faint 40px grid) as one
	 * combined background-image list; the others let an admin simplify it
	 * without Custom CSS.
	 */
	const BACKGROUND_PATTERNS = array(
		'default' => 'radial-gradient(ellipse 900px 420px at 50% -8%, var(--ssm-primary-tint), transparent 65%), linear-gradient(var(--ssm-bg-alt) 1px, transparent 1px) 0 0 / 100% 40px, linear-gradient(90deg, var(--ssm-bg-alt) 1px, transparent 1px) 0 0 / 40px 100%',
		'grid'    => 'linear-gradient(var(--ssm-bg-alt) 1px, transparent 1px) 0 0 / 100% 40px, linear-gradient(90deg, var(--ssm-bg-alt) 1px, transparent 1px) 0 0 / 40px 100%',
		'glow'    => 'radial-gradient(ellipse 900px 420px at 50% -8%, var(--ssm-primary-tint), transparent 65%)',
		'none'    => 'none',
	);

	/**
	 * CSS gradient direction/shape for each "Gradient direction" option.
	 * "radial" builds a radial-gradient() instead of a linear-gradient().
	 */
	const GRADIENT_DIRECTIONS = array(
		'to-bottom' => 'to bottom',
		'to-right'  => 'to right',
		'diagonal'  => '135deg',
	);

	/** Keys mirrored into the custom-dark-mode override block. */
	const DARK_MODE_KEYS = array(
		'bg'            => 'dark_bg_color',
		'bg-alt'        => 'dark_bg_alt_color',
		'surface'       => 'dark_surface_color',
		'surface-hover' => 'dark_surface_hover_color',
		'border'        => 'dark_border_color',
		'text'          => 'dark_text_color',
		'text-muted'    => 'dark_text_muted_color',
	);

	/**
	 * Builds the CSS to inject: a single `.ssm-status-page{ --var:...; }`
	 * declaration block, plus the admin's raw Custom CSS appended
	 * verbatim (already stripped of tags at save time - stripped again
	 * here defensively).
	 *
	 * @param array|null $settings Pass a specific settings array (e.g. for
	 *                              a live preview of unsaved values);
	 *                              defaults to the saved settings.
	 * @return string
	 */
	public static function build_css( $settings = null ) {
		$s = null !== $settings ? AppearanceSettings::sanitize( $settings ) : AppearanceSettings::get();

		$vars = array(
			'--ssm-primary'       => $s['primary_color'],
			'--ssm-primary-hover' => $s['primary_hover_color'],
			'--ssm-accent'        => $s['accent_color'],

			'--ssm-bg'     => $s['bg_color'],
			'--ssm-bg-alt' => $s['bg_alt_color'],

			'--ssm-surface'           => $s['surface_color'],
			'--ssm-surface-hover'     => $s['surface_hover_color'],
			'--ssm-border'            => $s['card_border_color'],
			'--ssm-card-border-width' => absint( $s['card_border_width'] ) . 'px',

			'--ssm-text'       => $s['text_color'],
			'--ssm-text-muted' => $s['text_muted_color'],

			'--ssm-operational' => $s['status_operational_color'],
			'--ssm-degraded'    => $s['status_degraded_color'],
			'--ssm-partial'     => $s['status_partial_color'],
			'--ssm-outage'      => $s['status_outage_color'],
			'--ssm-maintenance' => $s['status_maintenance_color'],
			'--ssm-unknown'     => $s['status_unknown_color'],

			'--ssm-content-max-width' => absint( $s['content_max_width'] ) . 'px',

			'--ssm-button-primary-text' => $s['button_text_color'],
			'--ssm-header-position'     => ! empty( $s['header_sticky'] ) ? 'sticky' : 'static',
		);

		if ( '' !== $s['header_bg_color'] ) {
			$vars['--ssm-header-bg'] = $s['header_bg_color'];
		}
		if ( '' !== $s['header_text_color'] ) {
			$vars['--ssm-header-text'] = $s['header_text_color'];
		}

		$radius = self::RADIUS_SCALES[ $s['card_radius_scale'] ] ?? self::RADIUS_SCALES['standard'];
		$vars['--ssm-r-sm'] = $radius['sm'];
		$vars['--ssm-r-md'] = $radius['md'];
		$vars['--ssm-r-lg'] = $radius['lg'];
		$vars['--ssm-r-xl'] = $radius['xl'];

		$shadow = self::SHADOW_PRESETS[ $s['card_shadow'] ] ?? self::SHADOW_PRESETS['subtle'];
		$vars['--ssm-shadow-sm'] = $shadow['sm'];
		$vars['--ssm-shadow-md'] = $shadow['md'];
		$vars['--ssm-shadow-lg'] = $shadow['lg'];

		$vars['--ssm-bg-decoration'] = self::BACKGROUND_PATTERNS[ $s['background_pattern'] ] ?? self::BACKGROUND_PATTERNS['default'];
		$vars['--ssm-bg-final']      = self::background_fill_css( $s );

		if ( 'image' === $s['background_style'] && '' !== $s['background_image_url'] ) {
			$vars['--ssm-bg-image']            = 'url("' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $s['background_image_url'] ) . '")';
			$vars['--ssm-bg-image-position']   = $s['background_image_position'];
			$vars['--ssm-bg-image-size']       = $s['background_image_size'];
			$vars['--ssm-bg-image-repeat']     = $s['background_image_repeat'];
			$vars['--ssm-bg-image-attachment'] = $s['background_image_attachment'];

			$opacity = min( 100, max( 0, absint( $s['background_image_overlay_opacity'] ) ) ) / 100;
			if ( $opacity > 0 ) {
				$vars['--ssm-bg-image-overlay'] = self::hex_to_rgba( $s['background_image_overlay_color'], $opacity );
			}
		}

		$css = '.ssm-status-page{' . self::declarations( $vars ) . '}';

		if ( ! empty( $s['dark_mode_custom'] ) ) {
			$dark_vars = array();
			foreach ( self::DARK_MODE_KEYS as $css_suffix => $settings_key ) {
				$dark_vars[ '--ssm-' . $css_suffix ] = $s[ $settings_key ];
			}
			$dark_declarations = self::declarations( $dark_vars );

			$css .= '@media (prefers-color-scheme: dark){.ssm-status-page:not([data-ssm-theme="light"]){' . $dark_declarations . '}}';
			$css .= '.ssm-status-page[data-ssm-theme="dark"]{' . $dark_declarations . '}';
		}

		if ( ! empty( $s['custom_css'] ) ) {
			$css .= "\n" . wp_strip_all_tags( $s['custom_css'] );
		}

		return $css;
	}

	/**
	 * The final (bottom-most) background layer: the plain colour in
	 * "solid" mode (kept in sync with --ssm-bg via a var() reference,
	 * rather than a duplicated literal), or a linear/radial gradient
	 * built from the two configured stop colours in "gradient" mode.
	 *
	 * @param array $s Sanitised settings.
	 * @return string
	 */
	private static function background_fill_css( array $s ) {
		if ( 'gradient' !== $s['background_style'] ) {
			return 'var(--ssm-bg)';
		}

		if ( 'radial' === $s['gradient_direction'] ) {
			return 'radial-gradient(circle at 30% 0%, ' . $s['gradient_start_color'] . ', ' . $s['gradient_end_color'] . ')';
		}

		$direction = self::GRADIENT_DIRECTIONS[ $s['gradient_direction'] ] ?? self::GRADIENT_DIRECTIONS['to-bottom'];

		return 'linear-gradient(' . $direction . ', ' . $s['gradient_start_color'] . ', ' . $s['gradient_end_color'] . ')';
	}

	/**
	 * Converts a validated "#rrggbb"/"#rgb" colour plus an opacity fraction
	 * into an rgba() string, for the background-image overlay tint.
	 *
	 * @param string $hex     Sanitised hex colour (already passed through
	 *                         sanitize_hex_color()).
	 * @param float  $opacity 0-1.
	 * @return string
	 */
	private static function hex_to_rgba( $hex, $opacity ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return 'rgba(15,23,42,' . $opacity . ')';
		}

		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );

		return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $opacity . ')';
	}

	/**
	 * Joins a [custom-property => value] map into a CSS declaration string.
	 *
	 * @param array $vars Custom property name => value.
	 * @return string
	 */
	private static function declarations( array $vars ) {
		$declarations = '';
		foreach ( $vars as $name => $value ) {
			$declarations .= $name . ':' . $value . ';';
		}
		return $declarations;
	}
}
