<?php
/**
 * Storage, defaults, sanitisation, reset and import/export for the public
 * status page's visual appearance settings.
 *
 * Deliberately kept in its own option (ssm_appearance_settings), separate
 * from the plugin's functional settings (ssm_settings) - so importing,
 * exporting or resetting "appearance" can never read or touch a provider
 * credential, notification rule, or any other functional setting.
 *
 * @package ServiceStatusManager
 */

namespace ServiceStatusManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AppearanceSettings {

	const OPTION = 'ssm_appearance_settings';

	const RADIUS_SCALES        = array( 'square', 'small', 'medium', 'standard', 'rounded', 'xl' );
	const SHADOW_PRESETS       = array( 'none', 'subtle', 'soft', 'elevated' );
	const BACKGROUND_PATTERNS  = array( 'none', 'grid', 'glow', 'default' );
	const BACKGROUND_STYLES    = array( 'solid', 'gradient' );
	const GRADIENT_DIRECTIONS  = array( 'to-bottom', 'to-right', 'diagonal', 'radial' );

	/**
	 * Every value here matches the plugin's existing hardcoded public.css
	 * design tokens exactly, so installing this feature - or upgrading to
	 * it - never changes how an existing status page looks until an admin
	 * actually changes something on the Appearance screen.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'primary_color'       => '#2563eb',
			'primary_hover_color' => '#1d4ed8',

			'bg_color'     => '#f7f9fc',
			'bg_alt_color' => '#eef2f8',

			'surface_color'       => '#ffffff',
			'surface_hover_color' => '#f8fafc',
			'card_border_color'   => '#e6eaf1',
			'card_border_width'   => 1,
			'card_radius_scale'   => 'standard',
			'card_shadow'         => 'subtle',

			'text_color'       => '#0f172a',
			'text_muted_color' => '#64748b',

			'status_operational_color' => '#10b981',
			'status_degraded_color'    => '#f59e0b',
			'status_partial_color'     => '#f97316',
			'status_outage_color'      => '#ef4444',
			'status_maintenance_color' => '#6366f1',
			'status_unknown_color'     => '#64748b',

			'content_max_width' => 920,

			// "Secondary colour" on the old Status Pages screen never had a
			// real use anywhere in the CSS. This one does: the header nav
			// links' hover colour. Defaults to the same value text_color
			// does (today's hardcoded hover colour), so leaving it alone
			// changes nothing.
			'accent_color' => '#0f172a',

			// Decorative layer already baked into the design (a faint grid
			// + a soft radial glow behind the hero) - "default" preserves
			// today's always-on look exactly; the other options let an
			// admin simplify it without needing Custom CSS.
			'background_pattern' => 'default',

			// The base page fill. "solid" (default) is just bg_color, as
			// today. "gradient" layers a configurable gradient on top of
			// it - bg_color/bg_alt_color stay in effect underneath (and
			// are still what tooltips use for contrast), so this can never
			// break tooltip legibility the way replacing bg_color outright
			// would.
			'background_style'      => 'solid',
			'gradient_start_color'  => '#eef4ff',
			'gradient_end_color'    => '#f7f9fc',
			'gradient_direction'    => 'to-bottom',

			'header_bg_color'   => '',
			'header_text_color' => '',
			'header_sticky'     => true,

			'button_text_color' => '#ffffff',

			// Off by default (dark mode keeps the plugin's existing,
			// already-designed dark palette exactly). Turning this on
			// substitutes these 7 values only while dark mode is active -
			// light mode is completely unaffected either way.
			'dark_mode_custom'        => false,
			'dark_bg_color'           => '#0a0f1a',
			'dark_bg_alt_color'       => '#0d1526',
			'dark_surface_color'      => '#121a2b',
			'dark_surface_hover_color' => '#182238',
			'dark_border_color'       => '#1e293b',
			'dark_text_color'         => '#f1f5f9',
			'dark_text_muted_color'   => '#97a3b8',

			'custom_css' => '',
		);
	}

	/**
	 * Returns the merged (stored + defaults) appearance settings.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
	}

	/**
	 * Merges, sanitises and persists new appearance settings.
	 *
	 * @param array $new_settings Raw (already wp_unslash()ed) input.
	 * @return bool
	 */
	public static function update( array $new_settings ) {
		$merged    = array_merge( self::get(), $new_settings );
		$sanitized = self::sanitize( $merged );

		$result = update_option( self::OPTION, $sanitized, false );

		/**
		 * Fires after appearance settings are saved (including a reset).
		 *
		 * @param array $settings The new, sanitised appearance settings.
		 */
		do_action( 'ssm_appearance_settings_saved', $sanitized );

		return $result;
	}

	/**
	 * Restores every appearance setting to its default. Only ever touches
	 * the ssm_appearance_settings option - no other plugin data.
	 *
	 * @return array The restored defaults.
	 */
	public static function reset() {
		$defaults = self::defaults();
		update_option( self::OPTION, $defaults, false );

		do_action( 'ssm_appearance_settings_saved', $defaults );

		return $defaults;
	}

	/**
	 * Validates and clamps every recognised key; unknown keys are dropped
	 * so this can never be used to smuggle unrelated option data in via
	 * import.
	 *
	 * @param array $input Raw settings (defaults already merged in by the caller).
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$defaults  = self::defaults();
		$sanitized = array();

		$color_keys = array(
			'primary_color', 'primary_hover_color', 'accent_color',
			'bg_color', 'bg_alt_color',
			'surface_color', 'surface_hover_color', 'card_border_color',
			'text_color', 'text_muted_color',
			'status_operational_color', 'status_degraded_color', 'status_partial_color',
			'status_outage_color', 'status_maintenance_color', 'status_unknown_color',
			'gradient_start_color', 'gradient_end_color',
			'button_text_color',
			'dark_bg_color', 'dark_bg_alt_color', 'dark_surface_color', 'dark_surface_hover_color',
			'dark_border_color', 'dark_text_color', 'dark_text_muted_color',
		);

		foreach ( $color_keys as $key ) {
			$value          = isset( $input[ $key ] ) ? sanitize_hex_color( (string) $input[ $key ] ) : null;
			$sanitized[ $key ] = $value ? $value : $defaults[ $key ];
		}

		// Header colours are allowed to be blank - blank means "inherit the
		// page's surface/text colours", which is also today's behaviour, so
		// an admin who never touches this section sees no change.
		foreach ( array( 'header_bg_color', 'header_text_color' ) as $key ) {
			$raw = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( '' === $raw ) {
				$sanitized[ $key ] = '';
				continue;
			}
			$value             = sanitize_hex_color( $raw );
			$sanitized[ $key ] = $value ? $value : '';
		}

		$sanitized['card_border_width'] = min( 10, max( 0, absint( $input['card_border_width'] ?? $defaults['card_border_width'] ) ) );
		$sanitized['content_max_width'] = min( 1600, max( 480, absint( $input['content_max_width'] ?? $defaults['content_max_width'] ) ) );

		$sanitized['card_radius_scale'] = in_array( $input['card_radius_scale'] ?? '', self::RADIUS_SCALES, true )
			? $input['card_radius_scale']
			: $defaults['card_radius_scale'];

		$sanitized['card_shadow'] = in_array( $input['card_shadow'] ?? '', self::SHADOW_PRESETS, true )
			? $input['card_shadow']
			: $defaults['card_shadow'];

		$sanitized['background_pattern'] = in_array( $input['background_pattern'] ?? '', self::BACKGROUND_PATTERNS, true )
			? $input['background_pattern']
			: $defaults['background_pattern'];

		$sanitized['background_style'] = in_array( $input['background_style'] ?? '', self::BACKGROUND_STYLES, true )
			? $input['background_style']
			: $defaults['background_style'];

		$sanitized['gradient_direction'] = in_array( $input['gradient_direction'] ?? '', self::GRADIENT_DIRECTIONS, true )
			? $input['gradient_direction']
			: $defaults['gradient_direction'];

		$sanitized['header_sticky']  = ! empty( $input['header_sticky'] );
		$sanitized['dark_mode_custom'] = ! empty( $input['dark_mode_custom'] );

		// Same trust model as the existing per-status-page Custom CSS field
		// (Status Pages screen): stripped of tags on save, and stripped
		// again defensively at render time.
		$sanitized['custom_css'] = wp_strip_all_tags( (string) ( $input['custom_css'] ?? '' ) );

		return $sanitized;
	}

	/**
	 * JSON export of appearance-only settings - deliberately just the
	 * sanitised settings array, never subscriber, credential, incident or
	 * monitor data.
	 *
	 * @return string
	 */
	public static function export_json() {
		return wp_json_encode( self::get(), JSON_PRETTY_PRINT );
	}

	/**
	 * Imports a previously exported JSON payload. Every value still goes
	 * through sanitize(), so malformed or malicious input can only ever
	 * resolve to a valid, in-range appearance value - never arbitrary
	 * option data.
	 *
	 * @param string $json Raw JSON payload.
	 * @return true|\WP_Error
	 */
	public static function import_json( $json ) {
		$decoded = json_decode( (string) $json, true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'ssm_invalid_appearance_import', __( 'That file is not a valid appearance export.', 'service-status-manager' ) );
		}

		self::update( $decoded );

		return true;
	}
}
