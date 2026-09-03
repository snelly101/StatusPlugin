/**
 * Appearance settings screen: WP colour pickers + a live, self-contained
 * preview panel. The preview reuses the real public.css classes (loaded on
 * this admin screen too) so it always matches the actual status page
 * exactly - this script only ever sets CSS custom properties on the
 * preview container, it never rebuilds or restyles it directly.
 */
( function ( $ ) {
	'use strict';

	if ( ! $ || ! $.fn.wpColorPicker ) {
		return;
	}

	// Mirrors AppearanceRenderer::RADIUS_SCALES / SHADOW_PRESETS in
	// includes/class-appearance-renderer.php. Only used for the preview -
	// the authoritative values are always applied server-side on save.
	var RADIUS_SCALES = {
		square: { sm: '0px', md: '0px', lg: '0px', xl: '0px' },
		small: { sm: '4px', md: '6px', lg: '8px', xl: '10px' },
		medium: { sm: '6px', md: '8px', lg: '10px', xl: '14px' },
		standard: { sm: '8px', md: '12px', lg: '16px', xl: '24px' },
		rounded: { sm: '10px', md: '16px', lg: '20px', xl: '28px' },
		xl: { sm: '14px', md: '20px', lg: '28px', xl: '36px' }
	};

	var SHADOW_PRESETS = {
		none: { sm: 'none', md: 'none' },
		subtle: { sm: '0 1px 2px rgba(15,23,42,.05)', md: '0 6px 20px -6px rgba(15,23,42,.10), 0 2px 6px -2px rgba(15,23,42,.05)' },
		soft: { sm: '0 2px 6px rgba(15,23,42,.08)', md: '0 10px 28px -8px rgba(15,23,42,.16), 0 4px 10px -4px rgba(15,23,42,.08)' },
		elevated: { sm: '0 4px 10px rgba(15,23,42,.12)', md: '0 16px 36px -8px rgba(15,23,42,.22), 0 6px 14px -4px rgba(15,23,42,.12)' }
	};

	var COLOR_FIELD_TO_VAR = {
		primary_color: '--ssm-primary',
		primary_hover_color: '--ssm-primary-hover',
		accent_color: '--ssm-accent',
		bg_color: '--ssm-bg',
		bg_alt_color: '--ssm-bg-alt',
		surface_color: '--ssm-surface',
		surface_hover_color: '--ssm-surface-hover',
		card_border_color: '--ssm-border',
		text_color: '--ssm-text',
		text_muted_color: '--ssm-text-muted',
		status_operational_color: '--ssm-operational',
		status_degraded_color: '--ssm-degraded',
		status_partial_color: '--ssm-partial',
		status_outage_color: '--ssm-outage',
		status_maintenance_color: '--ssm-maintenance',
		status_unknown_color: '--ssm-unknown',
		button_text_color: '--ssm-button-primary-text'
	};

	function updatePreview() {
		var $preview = $( '#ssm-appearance-preview-page' );
		if ( ! $preview.length ) {
			return;
		}
		var el = $preview.get( 0 );

		$.each( COLOR_FIELD_TO_VAR, function ( fieldName, cssVar ) {
			var value = $( '[name="' + fieldName + '"]' ).val();
			if ( value ) {
				el.style.setProperty( cssVar, value );
			}
		} );

		el.style.setProperty( '--ssm-card-border-width', ( $( '[name="card_border_width"]' ).val() || 1 ) + 'px' );

		var radiusKey = $( '[name="card_radius_scale"]' ).val();
		var radius = RADIUS_SCALES[ radiusKey ] || RADIUS_SCALES.standard;
		el.style.setProperty( '--ssm-r-sm', radius.sm );
		el.style.setProperty( '--ssm-r-md', radius.md );
		el.style.setProperty( '--ssm-r-lg', radius.lg );
		el.style.setProperty( '--ssm-r-xl', radius.xl );

		var shadowKey = $( '[name="card_shadow"]' ).val();
		var shadow = SHADOW_PRESETS[ shadowKey ] || SHADOW_PRESETS.subtle;
		el.style.setProperty( '--ssm-shadow-sm', shadow.sm );
		el.style.setProperty( '--ssm-shadow-md', shadow.md );
	}

	function toggleSection( $toggleEl, show ) {
		$toggleEl.toggle( show );
	}

	function syncConditionalSections() {
		toggleSection( $( '#ssm-a-gradient-fields' ), 'gradient' === $( '#ssm-a-background_style' ).val() );
		toggleSection( $( '.ssm-appearance-dark-fields' ), $( '#ssm-a-dark_mode_custom' ).is( ':checked' ) );
	}

	$( function () {
		$( '.ssm-color-picker' ).wpColorPicker( {
			change: function () {
				// wpColorPicker's own change callback fires before the
				// underlying input's value is updated, so defer a tick.
				setTimeout( updatePreview, 10 );
			},
			clear: function () {
				setTimeout( updatePreview, 10 );
			}
		} );

		$( document ).on( 'input change', '#ssm-a-card_border_width, #ssm-a-card_radius_scale, #ssm-a-card_shadow', updatePreview );
		$( document ).on( 'change', '#ssm-a-background_style, #ssm-a-dark_mode_custom', syncConditionalSections );

		updatePreview();
		syncConditionalSections();
	} );
} )( window.jQuery );
