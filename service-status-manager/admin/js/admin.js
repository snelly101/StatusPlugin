/**
 * Service Status Manager - shared admin screen behaviour.
 *
 * Most admin screens are plain server-rendered forms; this file only
 * covers small enhancements that don't warrant a full page reload.
 */
( function ( $ ) {
	'use strict';

	$( function () {
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
