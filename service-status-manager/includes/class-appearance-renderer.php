<?php
/**
 * Turns saved appearance settings into a single scoped CSS declaration
 * block, output once per page load via wp_add_inline_style() rather than
 * an inline <style> tag repeated per component.
 *
 * Every declaration here targets the plain `.ssm-status-page` selector,
 * which is intentionally the same specificity as the design-system
 * defaults in public.css and lower specificity than its dark-mode
 * overrides (`.ssm-status-page:not([data-ssm-theme="light"])` and
 * `.ssm-status-page[data-ssm-theme="dark"]`, both one selector heavier).
 * That means these settings customise light mode - and any value that
 * genuinely isn't a light/dark concept (radius, shadow shape, content
 * width, border width) - without fighting the built-in dark palette.
 * Full dark-mode-aware customisation is a later phase, not this one.
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
		);

		$radius = self::RADIUS_SCALES[ $s['card_radius_scale'] ] ?? self::RADIUS_SCALES['standard'];
		$vars['--ssm-r-sm'] = $radius['sm'];
		$vars['--ssm-r-md'] = $radius['md'];
		$vars['--ssm-r-lg'] = $radius['lg'];
		$vars['--ssm-r-xl'] = $radius['xl'];

		$shadow = self::SHADOW_PRESETS[ $s['card_shadow'] ] ?? self::SHADOW_PRESETS['subtle'];
		$vars['--ssm-shadow-sm'] = $shadow['sm'];
		$vars['--ssm-shadow-md'] = $shadow['md'];
		$vars['--ssm-shadow-lg'] = $shadow['lg'];

		$declarations = '';
		foreach ( $vars as $name => $value ) {
			$declarations .= $name . ':' . $value . ';';
		}

		$css = '.ssm-status-page{' . $declarations . '}';

		if ( ! empty( $s['custom_css'] ) ) {
			$css .= "\n" . wp_strip_all_tags( $s['custom_css'] );
		}

		return $css;
	}
}
