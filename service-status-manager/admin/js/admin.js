/**
 * Service Status Manager - shared admin screen behaviour.
 *
 * Most admin screens are plain server-rendered forms; this file only
 * covers small enhancements that don't warrant a full page reload.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		// Subscriber edit form: the "Everything" toggle. Ticked means "no
		// specific selections", which the backend treats as "notify about
		// everything" - so it disables and clears the individual boxes rather
		// than ticking them all (ticking them all would store a fixed list
		// that stops matching newly added services). Mirrors the same
		// behaviour on the public manage-subscription page.
		$( document ).on( 'change', '[data-ssm-admin-select-all]', function () {
			var everything = $( this ).is( ':checked' );
			$( '[data-ssm-admin-selectable]' ).prop( 'disabled', everything ).each( function () {
				if ( everything ) {
					$( this ).prop( 'checked', false );
				}
			} );
		} );
		$( document ).on( 'change', '[data-ssm-admin-selectable]', function () {
			if ( $( this ).is( ':checked' ) ) {
				$( '[data-ssm-admin-select-all]' ).prop( 'checked', false );
			}
		} );

		// Auto-dismiss notices after a few seconds, without hiding errors.
		$( '.notice.is-dismissible' ).each( function () {
			var $notice = $( this );
			if ( $notice.hasClass( 'notice-success' ) ) {
				setTimeout( function () {
					$notice.fadeOut( 400 );
				}, 6000 );
			}
		} );
	} );
} )( jQuery );
