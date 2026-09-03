/**
 * Appearance settings screen: WP colour pickers, a live self-contained
 * preview panel, the background-image media picker, theme presets, and a
 * client-side WCAG contrast checker. The preview reuses the real
 * public.css classes (loaded on this admin screen too) so it always
 * matches the actual status page exactly - this script only ever sets CSS
 * custom properties on the preview container, it never rebuilds or
 * restyles it directly. Nothing here saves anything on its own - every
 * change still has to go through the normal "Save Appearance" submit.
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

	// Starting points only - applying one just fills in the fields below,
	// nothing is saved until the admin clicks Save Appearance, and every
	// value can still be hand-adjusted afterwards.
	var PRESETS = {
		clean_light: {
			primary_color: '#2563eb', primary_hover_color: '#1d4ed8', accent_color: '#0f172a',
			bg_color: '#f7f9fc', bg_alt_color: '#eef2f8',
			surface_color: '#ffffff', surface_hover_color: '#f8fafc', card_border_color: '#e6eaf1',
			text_color: '#0f172a', text_muted_color: '#64748b',
			card_radius_scale: 'standard', card_shadow: 'subtle', background_pattern: 'default'
		},
		modern_dark: {
			primary_color: '#6366f1', primary_hover_color: '#4f46e5', accent_color: '#e2e8f0',
			bg_color: '#0a0f1a', bg_alt_color: '#0d1526',
			surface_color: '#121a2b', surface_hover_color: '#182238', card_border_color: '#1e293b',
			text_color: '#f1f5f9', text_muted_color: '#97a3b8',
			card_radius_scale: 'medium', card_shadow: 'elevated', background_pattern: 'glow'
		},
		cloud: {
			primary_color: '#0ea5e9', primary_hover_color: '#0284c7', accent_color: '#0c4a6e',
			bg_color: '#f0f7ff', bg_alt_color: '#e0f0ff',
			surface_color: '#ffffff', surface_hover_color: '#f4faff', card_border_color: '#dbeafe',
			text_color: '#0c4a6e', text_muted_color: '#5b8199',
			card_radius_scale: 'rounded', card_shadow: 'soft', background_pattern: 'glow'
		},
		minimal: {
			primary_color: '#111827', primary_hover_color: '#000000', accent_color: '#111827',
			bg_color: '#ffffff', bg_alt_color: '#f9fafb',
			surface_color: '#ffffff', surface_hover_color: '#f9fafb', card_border_color: '#e5e7eb',
			text_color: '#111827', text_muted_color: '#6b7280',
			card_radius_scale: 'small', card_shadow: 'none', background_pattern: 'none'
		},
		high_contrast: {
			primary_color: '#0000ee', primary_hover_color: '#000099', accent_color: '#000000',
			bg_color: '#ffffff', bg_alt_color: '#f0f0f0',
			surface_color: '#ffffff', surface_hover_color: '#f5f5f5', card_border_color: '#000000',
			text_color: '#000000', text_muted_color: '#3f3f3f', button_text_color: '#ffffff',
			card_radius_scale: 'square', card_shadow: 'none', background_pattern: 'none',
			card_border_width: 2
		}
	};

	// The colour pairs checked in the "Accessibility check" panel, as
	// data-pair="fieldA:fieldB" attributes read from the markup.
	var WCAG_AA_MIN_RATIO = 4.5;

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
		var backgroundStyle = $( '#ssm-a-background_style' ).val();
		toggleSection( $( '#ssm-a-gradient-fields' ), 'gradient' === backgroundStyle );
		toggleSection( $( '#ssm-a-image-fields' ), 'image' === backgroundStyle );
		toggleSection( $( '.ssm-appearance-dark-fields' ), $( '#ssm-a-dark_mode_custom' ).is( ':checked' ) );
	}

	// --- Background image (WP Media Library) --------------------------

	function setBackgroundImage( url ) {
		$( '#ssm-a-background_image_url' ).val( url );
		$( '#ssm-a-remove-bg-image' ).prop( 'hidden', ! url );

		var $preview = $( '#ssm-a-bg-image-preview' ).empty();
		if ( url ) {
			$( '<img />' )
				.attr( { src: url, alt: '' } )
				.css( { maxWidth: '200px', maxHeight: '100px', border: '1px solid #ddd', borderRadius: '4px' } )
				.appendTo( $preview );
		}
	}

	var mediaFrame = null;

	function initMediaPicker() {
		if ( ! window.wp || ! wp.media ) {
			// wp_enqueue_media() wasn't loaded for some reason - leave the
			// text field usable directly rather than a broken button.
			$( '#ssm-a-choose-bg-image' ).prop( 'disabled', true ).attr( 'title', 'Media library unavailable' );
			return;
		}

		$( '#ssm-a-choose-bg-image' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( ! mediaFrame ) {
				mediaFrame = wp.media( {
					title: 'Choose a background image',
					button: { text: 'Use this image' },
					multiple: false
				} );
				mediaFrame.on( 'select', function () {
					var attachment = mediaFrame.state().get( 'selection' ).first().toJSON();
					setBackgroundImage( attachment.url );
				} );
			}
			mediaFrame.open();
		} );

		$( '#ssm-a-remove-bg-image' ).on( 'click', function ( e ) {
			e.preventDefault();
			setBackgroundImage( '' );
		} );
	}

	// --- Theme presets ---------------------------------------------------

	function applyPreset( key ) {
		var preset = PRESETS[ key ];
		if ( ! preset ) {
			return;
		}

		$.each( preset, function ( fieldName, value ) {
			var $field = $( '[name="' + fieldName + '"]' );
			if ( ! $field.length ) {
				return;
			}
			$field.val( value );
			if ( $field.hasClass( 'ssm-color-picker' ) ) {
				$field.wpColorPicker( 'color', value );
			}
		} );

		updatePreview();
		syncConditionalSections();
		updateA11y();
	}

	// --- Accessibility contrast check ------------------------------------

	function hexToRgb( hex ) {
		hex = ( hex || '' ).replace( '#', '' );
		if ( 3 === hex.length ) {
			hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ];
		}
		if ( 6 !== hex.length || ! /^[0-9a-f]{6}$/i.test( hex ) ) {
			return null;
		}
		var num = parseInt( hex, 16 );
		return { r: ( num >> 16 ) & 255, g: ( num >> 8 ) & 255, b: num & 255 };
	}

	function relativeLuminance( rgb ) {
		function channel( c ) {
			c = c / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * channel( rgb.r ) + 0.7152 * channel( rgb.g ) + 0.0722 * channel( rgb.b );
	}

	// Standard WCAG 2.x contrast ratio formula: (L1 + 0.05) / (L2 + 0.05),
	// lighter colour first.
	function contrastRatio( hexA, hexB ) {
		var rgbA = hexToRgb( hexA );
		var rgbB = hexToRgb( hexB );
		if ( ! rgbA || ! rgbB ) {
			return null;
		}
		var lumA = relativeLuminance( rgbA ) + 0.05;
		var lumB = relativeLuminance( rgbB ) + 0.05;
		return lumA > lumB ? lumA / lumB : lumB / lumA;
	}

	function updateA11y() {
		$( '#ssm-a-a11y-results li' ).each( function () {
			var $li = $( this );
			var pair = ( $li.data( 'pair' ) || '' ).toString().split( ':' );
			var $ratio = $li.find( '.ssm-a11y-ratio' );

			if ( 2 !== pair.length ) {
				return;
			}

			var ratio = contrastRatio( $( '[name="' + pair[ 0 ] + '"]' ).val(), $( '[name="' + pair[ 1 ] + '"]' ).val() );

			if ( ! ratio ) {
				$ratio.text( '—' ).removeClass( 'ssm-a11y-pass ssm-a11y-fail' );
				return;
			}

			$ratio.text( ratio.toFixed( 2 ) + ':1' );
			$ratio.toggleClass( 'ssm-a11y-pass', ratio >= WCAG_AA_MIN_RATIO );
			$ratio.toggleClass( 'ssm-a11y-fail', ratio < WCAG_AA_MIN_RATIO );
		} );
	}

	$( function () {
		$( '.ssm-color-picker' ).wpColorPicker( {
			change: function () {
				// wpColorPicker's own change callback fires before the
				// underlying input's value is updated, so defer a tick.
				setTimeout( function () {
					updatePreview();
					updateA11y();
				}, 10 );
			},
			clear: function () {
				setTimeout( function () {
					updatePreview();
					updateA11y();
				}, 10 );
			}
		} );

		initMediaPicker();

		$( '#ssm-a-apply-preset' ).on( 'click', function ( e ) {
			e.preventDefault();
			applyPreset( $( '#ssm-a-theme-preset' ).val() );
		} );

		$( document ).on( 'input change', '#ssm-a-card_border_width, #ssm-a-card_radius_scale, #ssm-a-card_shadow', function () {
			updatePreview();
			updateA11y();
		} );
		$( document ).on( 'change', '#ssm-a-background_style, #ssm-a-dark_mode_custom', syncConditionalSections );

		updatePreview();
		syncConditionalSections();
		updateA11y();
	} );
} )( window.jQuery );
