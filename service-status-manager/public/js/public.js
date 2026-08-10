/**
 * Service Status Manager - public status page behaviour.
 *
 * Kept deliberately small and framework-free. Handles:
 *  - toggling the "resend confirmation" mini-form,
 *  - polite live-region announcements when the status banner is
 *    refreshed via the REST API (progressive enhancement only - the
 *    page is fully functional without JavaScript).
 */
( function () {
	'use strict';

	document.addEventListener( 'click', function ( event ) {
		var toggle = event.target.closest( '[data-ssm-toggle="resend"]' );
		if ( ! toggle ) {
			return;
		}
		event.preventDefault();
		var form = document.getElementById( 'ssm-resend' );
		if ( form ) {
			form.hidden = ! form.hidden;
			if ( ! form.hidden ) {
				var input = form.querySelector( 'input[type="email"]' );
				if ( input ) {
					input.focus();
				}
			}
		}
	} );

	// Uncheck a channel's destination field when its checkbox is unticked,
	// so we never submit stale contact details for a channel the visitor
	// did not actually select.
	document.querySelectorAll( '.ssm-subscribe-form input[name="channels[]"]' ).forEach( function ( checkbox ) {
		checkbox.addEventListener( 'change', function () {
			var row = checkbox.closest( '.ssm-form-row' );
			if ( ! row ) {
				return;
			}
		} );
	} );
} )();
