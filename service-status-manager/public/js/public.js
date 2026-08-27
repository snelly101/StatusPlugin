/**
 * Service Status Manager - public status page behaviour.
 *
 * Vanilla JavaScript, no framework/build step. Every feature here is
 * progressive enhancement: the page is fully functional (subscription
 * form included, via <noscript>) with this file absent. Organised as:
 *
 *   1. Small DOM helpers
 *   2. Theme (light/dark/system) toggle
 *   3. Sticky header: compact-on-scroll + mobile nav
 *   4. Service rows: expand/collapse
 *   5. Uptime bar tooltips
 *   6. Subscribe modal / step wizard
 *   7. Toasts
 *   8. Live status refresh (polls the existing public REST API)
 *   9. Resend-confirmation mini form toggle
 */
( function () {
	'use strict';

	/* ------------------------------------------------------------ *
	 * 1. Helpers
	 * ------------------------------------------------------------ */

	function qs( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	function qsa( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}

	function on( el, evt, sel, handler ) {
		if ( typeof sel === 'function' ) {
			handler = sel;
			el.addEventListener( evt, handler );
			return;
		}
		el.addEventListener( evt, function ( e ) {
			var match = e.target.closest( sel );
			if ( match && el.contains( match ) ) {
				handler( e, match );
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		qsa( '.ssm-status-page' ).forEach( initStatusPage );
	} );

	/**
	 * @param {HTMLElement} root A single .ssm-status-page wrapper (a page
	 *                           can contain more than one, e.g. summary +
	 *                           services on separate shortcodes).
	 */
	function initStatusPage( root ) {
		initTheme( root );
		initHeader( root );
		initServiceRows( root );
		initUptimeTooltips( root );
		initSubscribeModal( root );
		initResendToggle( root );
		initLiveRefresh( root );
	}

	/* ------------------------------------------------------------ *
	 * 2. Theme toggle
	 * ------------------------------------------------------------ */

	function initTheme( root ) {
		var STORAGE_KEY = 'ssmTheme';
		var mql = window.matchMedia ? window.matchMedia( '(prefers-color-scheme: dark)' ) : null;

		function updateEffective() {
			var mode = root.getAttribute( 'data-ssm-theme' ) || 'auto';
			var effective = 'light';
			if ( 'dark' === mode ) {
				effective = 'dark';
			} else if ( 'auto' === mode && mql && mql.matches ) {
				effective = 'dark';
			}
			root.setAttribute( 'data-ssm-effective-theme', effective );
			root.style.colorScheme = effective;
		}

		try {
			var stored = window.localStorage.getItem( STORAGE_KEY );
			if ( stored ) {
				root.setAttribute( 'data-ssm-theme', stored );
			}
		} catch ( err ) {
			// Storage unavailable (private browsing, blocked) - fall back
			// to the server-rendered default silently.
		}

		updateEffective();

		if ( mql && mql.addEventListener ) {
			mql.addEventListener( 'change', updateEffective );
		}

		on( root, 'click', '[data-ssm-theme-toggle]', function () {
			var current = root.getAttribute( 'data-ssm-effective-theme' ) || 'light';
			var next = 'dark' === current ? 'light' : 'dark';
			root.setAttribute( 'data-ssm-theme', next );
			updateEffective();
			try {
				window.localStorage.setItem( STORAGE_KEY, next );
			} catch ( err ) {
				// Ignore - the toggle still works for this page view.
			}
		} );
	}

	/* ------------------------------------------------------------ *
	 * 3. Sticky header
	 * ------------------------------------------------------------ */

	function initHeader( root ) {
		var header = qs( '[data-ssm-header]', root );
		if ( ! header ) {
			return;
		}

		var lastCompact = false;
		function onScroll() {
			var compact = window.scrollY > 24;
			if ( compact !== lastCompact ) {
				header.classList.toggle( 'ssm-is-compact', compact );
				lastCompact = compact;
			}
		}
		window.addEventListener( 'scroll', onScroll, { passive: true } );
		onScroll();

		on( header, 'click', '[data-ssm-nav-toggle]', function ( e, btn ) {
			var open = header.classList.toggle( 'ssm-nav-open' );
			btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );

		// Close the mobile menu after choosing a link.
		on( header, 'click', '.ssm-site-header-nav a', function () {
			header.classList.remove( 'ssm-nav-open' );
		} );
	}

	/* ------------------------------------------------------------ *
	 * 4. Service rows: expand/collapse
	 * ------------------------------------------------------------ */

	function initServiceRows( root ) {
		on( root, 'click', '.ssm-service-heading.ssm-is-expandable', function ( e, heading ) {
			toggleServiceRow( heading );
		} );

		on( root, 'keydown', '.ssm-service-heading.ssm-is-expandable', function ( e, heading ) {
			if ( 'Enter' === e.key || ' ' === e.key ) {
				e.preventDefault();
				toggleServiceRow( heading );
			}
		} );
	}

	function toggleServiceRow( heading ) {
		var row = heading.closest( '.ssm-service-row' );
		var open = row.classList.toggle( 'ssm-is-open' );
		heading.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
	}

	/* ------------------------------------------------------------ *
	 * 5. Uptime bar tooltips
	 * ------------------------------------------------------------ */

	function initUptimeTooltips( root ) {
		qsa( '[data-ssm-uptime-bars]', root ).forEach( function ( bars ) {
			condenseIfNarrow( bars );

			var tooltip = document.createElement( 'div' );
			tooltip.className = 'ssm-tooltip';
			tooltip.setAttribute( 'role', 'tooltip' );
			bars.style.position = 'relative';
			bars.appendChild( tooltip );

			function show( bar ) {
				var date = bar.getAttribute( 'data-ssm-tooltip-date' );
				var label = bar.getAttribute( 'data-ssm-tooltip-label' );
				if ( ! date ) {
					return;
				}
				tooltip.innerHTML = '';
				var strong = document.createElement( 'strong' );
				strong.textContent = date;
				var span = document.createElement( 'span' );
				span.textContent = label || '';
				tooltip.appendChild( strong );
				tooltip.appendChild( span );

				var barRect = bar.getBoundingClientRect();
				var barsRect = bars.getBoundingClientRect();
				var left = barRect.left - barsRect.left + barRect.width / 2;
				tooltip.style.left = left + 'px';
				tooltip.classList.add( 'ssm-is-visible' );
			}

			function hide() {
				tooltip.classList.remove( 'ssm-is-visible' );
			}

			on( bars, 'mouseover', '.ssm-bar', function ( e, bar ) {
				show( bar );
			} );
			on( bars, 'focus', '.ssm-bar', function ( e, bar ) {
				show( bar );
			} );
			bars.addEventListener( 'mouseleave', hide );
			on( bars, 'blur', '.ssm-bar', hide );
		} );

		window.addEventListener( 'resize', debounce( function () {
			qsa( '[data-ssm-uptime-bars]', root ).forEach( condenseIfNarrow );
		}, 200 ) );
	}

	/**
	 * On narrow viewports, thin out alternating day-segments (handled in
	 * CSS via .ssm-is-condensed) rather than letting the bar overflow or
	 * scroll horizontally, per the "no horizontal scrolling" requirement.
	 *
	 * @param {HTMLElement} bars
	 */
	function condenseIfNarrow( bars ) {
		var segments = qsa( '.ssm-bar', bars ).length;
		var tooNarrow = bars.clientWidth > 0 && ( bars.clientWidth / Math.max( segments, 1 ) ) < 4;
		bars.classList.toggle( 'ssm-is-condensed', tooNarrow );
	}

	function debounce( fn, wait ) {
		var t;
		return function () {
			clearTimeout( t );
			var args = arguments;
			t = setTimeout( function () {
				fn.apply( null, args );
			}, wait );
		};
	}

	/* ------------------------------------------------------------ *
	 * 6. Subscribe modal / step wizard
	 * ------------------------------------------------------------ */

	function initSubscribeModal( root ) {
		var modal = qs( '#ssm-subscribe-modal', root );

		on( root, 'click', '[data-ssm-open-modal="subscribe"]', function ( e ) {
			e.preventDefault();
			if ( modal ) {
				openModal( modal );
			}
		} );

		if ( ! modal ) {
			return;
		}

		var wizard = qs( '[data-ssm-wizard]', modal );
		var form = qs( '[data-ssm-subscribe-form]', modal );
		var steps = qsa( '.ssm-step', modal );
		var progressDots = qsa( '[data-ssm-progress]', modal );
		var backBtn = qs( '[data-ssm-back]', modal );
		var nextBtn = qs( '[data-ssm-next]', modal );
		var submitBtn = qs( '[data-ssm-submit]', modal );
		var errorBox = qs( '[data-ssm-wizard-error]', modal );
		var confirmation = qs( '[data-ssm-confirmation]', modal );
		var current = 1;

		on( modal, 'click', '[data-ssm-close-modal]', function () {
			closeModal( modal );
		} );
		on( modal, 'click', function ( e ) {
			if ( e.target === modal ) {
				closeModal( modal );
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && modal.classList.contains( 'ssm-is-open' ) ) {
				closeModal( modal );
			}
		} );

		// Keep a JS-driven "selected" class in sync as a fallback for
		// browsers without CSS :has() support, which the stylesheet also
		// uses to highlight a checked choice-card.
		on( modal, 'change', '.ssm-choice-card input', function ( e, input ) {
			input.closest( '.ssm-choice-card' ).classList.toggle( 'ssm-is-selected', input.checked );
		} );

		// Step 1 -> 3: show/hide the matching destination field.
		on( modal, 'change', 'input[data-ssm-channel]', function () {
			var checked = qsa( 'input[data-ssm-channel]', modal ).filter( function ( c ) {
				return c.checked;
			} ).map( function ( c ) {
				return c.getAttribute( 'data-ssm-channel' );
			} );

			qsa( '[data-ssm-destination]', modal ).forEach( function ( field ) {
				var channel = field.getAttribute( 'data-ssm-destination' );
				var show = checked.indexOf( channel ) !== -1;
				field.hidden = ! show;
				var input = qs( 'input', field );
				if ( input ) {
					input.required = show;
				}
			} );
		} );

		// Step 2: "Everything" means "no specific selection", which is what
		// the backend actually treats as "subscribe to everything" (see
		// SubscriberManager::get_matching_subscribers()) - it also then
		// keeps matching newly-added services/monitors automatically.
		// Ticking every individual box instead would submit an explicit,
		// fixed list, which stops matching as soon as an incident doesn't
		// happen to tag one of those exact items (e.g. a quick test
		// incident with no services attached) - so this disables and
		// clears the individual boxes rather than checking them.
		on( modal, 'change', '[data-ssm-select-all]', function ( e, box ) {
			qsa( '[data-ssm-selectable]', modal ).forEach( function ( cb ) {
				cb.checked = false;
				cb.disabled = box.checked;
			} );
		} );

		// Picking a specific item overrides "Everything".
		on( modal, 'change', '[data-ssm-selectable]', function ( e, cb ) {
			if ( cb.checked ) {
				var selectAll = qs( '[data-ssm-select-all]', modal );
				if ( selectAll ) {
					selectAll.checked = false;
				}
			}
		} );

		function goToStep( n ) {
			steps.forEach( function ( step ) {
				step.classList.toggle( 'ssm-is-active', parseInt( step.getAttribute( 'data-step' ), 10 ) === n );
			} );
			progressDots.forEach( function ( dot ) {
				var dotStep = parseInt( dot.getAttribute( 'data-ssm-progress' ), 10 );
				dot.classList.toggle( 'ssm-is-active', dotStep === n );
				dot.classList.toggle( 'ssm-is-done', dotStep < n );
			} );
			backBtn.hidden = n === 1;
			nextBtn.hidden = n === steps.length;
			submitBtn.hidden = n !== steps.length;
			current = n;
			hideError();

			var firstField = qs( '.ssm-step.ssm-is-active input, .ssm-step.ssm-is-active select', modal );
			if ( firstField ) {
				firstField.focus( { preventScroll: true } );
			}
		}

		function showError( message ) {
			errorBox.textContent = message;
			errorBox.hidden = false;
		}

		function hideError() {
			errorBox.hidden = true;
		}

		function validateStep( n ) {
			if ( 1 === n ) {
				var anyChannel = qsa( 'input[data-ssm-channel]', modal ).some( function ( c ) {
					return c.checked;
				} );
				if ( ! anyChannel ) {
					showError( ( window.ssmPublic && window.ssmPublic.i18n && window.ssmPublic.i18n.selectChannel ) || 'Please select at least one notification channel.' );
					return false;
				}
			}
			if ( 3 === n ) {
				var invalid = qsa( '[data-ssm-destination]', modal ).some( function ( field ) {
					if ( field.hidden ) {
						return false;
					}
					var input = qs( 'input', field );
					return input && ! input.value.trim();
				} );
				if ( invalid ) {
					showError( ( window.ssmPublic && window.ssmPublic.i18n && window.ssmPublic.i18n.enterDestination ) || 'Please fill in the highlighted field.' );
					return false;
				}
				var consent = qs( 'input[name="consent"]', modal );
				if ( consent && ! consent.checked ) {
					showError( ( window.ssmPublic && window.ssmPublic.i18n && window.ssmPublic.i18n.consentRequired ) || 'Please confirm your consent to continue.' );
					return false;
				}
			}
			return true;
		}

		on( modal, 'click', '[data-ssm-next]', function () {
			if ( ! validateStep( current ) ) {
				return;
			}
			goToStep( Math.min( steps.length, current + 1 ) );
		} );

		on( modal, 'click', '[data-ssm-back]', function () {
			goToStep( Math.max( 1, current - 1 ) );
		} );

		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				if ( ! validateStep( 3 ) ) {
					return;
				}

				submitBtn.disabled = true;
				submitBtn.textContent = submitBtn.getAttribute( 'data-loading-text' ) || submitBtn.textContent;

				var data = new FormData( form );
				var restUrl = window.ssmPublic ? window.ssmPublic.ajaxUrl : form.action;

				fetch( restUrl, {
					method: 'POST',
					body: data,
					credentials: 'same-origin',
				} )
					.then( function ( response ) {
						return response.json().catch( function () {
							return { success: false, data: {} };
						} );
					} )
					.then( function ( json ) {
						submitBtn.disabled = false;
						if ( json && json.success ) {
							wizard.hidden = true;
							confirmation.hidden = false;
							var msgEl = qs( '[data-ssm-confirmation-message]', confirmation );
							if ( msgEl && json.data && json.data.message ) {
								msgEl.textContent = json.data.message;
							}
							showToast( ( json.data && json.data.message ) || 'Subscribed.', 'success' );
						} else {
							var message = ( json && json.data && json.data.message ) || 'Something went wrong. Please try again.';
							showError( message );
						}
					} )
					.catch( function () {
						submitBtn.disabled = false;
						showError( 'Something went wrong. Please try again.' );
					} );
			} );
		}

		// Reset the wizard back to step 1 each time it is (re)opened.
		modal.addEventListener( 'ssm:open', function () {
			wizard.hidden = false;
			confirmation.hidden = true;
			goToStep( 1 );
			if ( form ) {
				form.reset();
				qsa( '[data-ssm-destination]', modal ).forEach( function ( f ) {
					f.hidden = true;
				} );
			}
		} );
	}

	var lastFocusedBeforeModal = null;

	function openModal( modal ) {
		lastFocusedBeforeModal = document.activeElement;
		modal.classList.add( 'ssm-is-open' );
		modal.setAttribute( 'aria-hidden', 'false' );
		document.body.style.overflow = 'hidden';
		modal.dispatchEvent( new Event( 'ssm:open' ) );
	}

	function closeModal( modal ) {
		modal.classList.remove( 'ssm-is-open' );
		modal.setAttribute( 'aria-hidden', 'true' );
		document.body.style.overflow = '';
		if ( lastFocusedBeforeModal && lastFocusedBeforeModal.focus ) {
			lastFocusedBeforeModal.focus();
		}
	}

	/* ------------------------------------------------------------ *
	 * 7. Toasts
	 * ------------------------------------------------------------ */

	function showToast( message, type ) {
		var region = qs( '[data-ssm-toast-region]' );
		if ( ! region || ! message ) {
			return;
		}
		var toast = document.createElement( 'div' );
		toast.className = 'ssm-toast' + ( 'error' === type ? ' ssm-toast--error' : '' );
		toast.setAttribute( 'role', 'status' );
		toast.textContent = message;
		region.appendChild( toast );

		setTimeout( function () {
			toast.style.transition = 'opacity 300ms ease';
			toast.style.opacity = '0';
			setTimeout( function () {
				toast.remove();
			}, 320 );
		}, 5000 );
	}

	/* ------------------------------------------------------------ *
	 * 8. Live status refresh
	 * ------------------------------------------------------------ */

	function initLiveRefresh( root ) {
		if ( ! window.ssmPublic || ! window.ssmPublic.restUrl ) {
			return;
		}
		var interval = parseInt( window.ssmPublic.refreshInterval, 10 );
		if ( ! interval || interval <= 0 ) {
			return;
		}

		var heroTitle = qs( '#ssm-hero-title', root );
		var heroDesc = qs( '#ssm-hero-desc', root );
		var heroUpdated = qs( '#ssm-hero-updated strong', root );
		var heroSection = qs( '#ssm-hero-status', root );
		var headerPill = qs( '[data-ssm-live="status-pill"]', root );

		function applyStatusClass( el, cssClass ) {
			if ( ! el ) {
				return;
			}
			el.className = el.className.replace( /ssm-status-[a-z_-]+/g, '' ).trim();
			el.classList.add( cssClass );
		}

		function tick() {
			fetch( window.ssmPublic.restUrl + '/status', { credentials: 'omit' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					if ( ! data || ! data.status ) {
						return;
					}
					if ( heroSection ) {
						applyStatusClass( heroSection, 'ssm-status-' + data.status.replace( /_/g, '-' ) );
					}
					if ( heroTitle ) {
						heroTitle.textContent = data.label || heroTitle.textContent;
					}
					if ( heroDesc && data.description ) {
						heroDesc.textContent = data.description;
					}
					if ( heroUpdated && data.updated_at ) {
						heroUpdated.textContent = new Date( data.updated_at.replace( ' ', 'T' ) + 'Z' ).toLocaleString();
					}
					if ( headerPill ) {
						applyStatusClass( headerPill, 'ssm-status-' + data.status.replace( /_/g, '-' ) );
						headerPill.textContent = data.label || headerPill.textContent;
					}
				} )
				.catch( function () {
					// Silent - the page still shows the last server-rendered
					// status; a failed background refresh should never be
					// disruptive.
				} );
		}

		// A gentle floor: never poll a shared server more than once every
		// 15 seconds, regardless of what is configured.
		var effectiveInterval = Math.max( interval, 15 ) * 1000;
		setInterval( tick, effectiveInterval );
	}

	/* ------------------------------------------------------------ *
	 * 9. Resend-confirmation toggle
	 * ------------------------------------------------------------ */

	function initResendToggle( root ) {
		on( root, 'click', '[data-ssm-toggle="resend"]', function ( e, toggle ) {
			e.preventDefault();
			var targetId = toggle.getAttribute( 'href' ) || '#ssm-resend';
			var form = qs( targetId, root );
			if ( form ) {
				form.hidden = ! form.hidden;
				if ( ! form.hidden ) {
					var input = qs( 'input[type="email"]', form );
					if ( input ) {
						input.focus();
					}
				}
			}
		} );
	}
} )();
