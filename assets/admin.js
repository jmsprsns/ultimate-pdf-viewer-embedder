/**
 * Content Powered > Ultimate PDF Viewer and Embedder page behaviour. Only
 * loaded on that page.
 *
 * Tabs, rows that depend on a checkbox, colour pickers and the live toolbar
 * preview, watermark placeholders, copy buttons, "Delete all stats"
 * confirmation and the Tools tab's bulk text-and-cover reader.
 * upveAdmin.ns and .i18n are passed in from PHP
 * (see Ultimate_PDF_Viewer_Embedder_Admin::enqueue_assets).
 */

/* global upveAdmin, jQuery */
( function () {
	'use strict';

	var data = window.upveAdmin;

	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;

		return String( str ).replace( /%(?:(\d)\$)?[ds]/g, function ( m, n ) {
			return String( n ? args[ n - 1 ] : args[ i++ ] );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		var form = document.querySelector( '.upve-form' );

		initTabs( form );
		initDepends( form );
		initAccess( form );
		initPreview( form );
		initChips();
		initCopy();
		initConfirm();
		initPrepare();
	} );

	// Tabs ---------------------------------------------------------------------

	function initTabs( form ) {
		var tabs = document.querySelectorAll( '[data-upve-tab]' );
		var panels = document.querySelectorAll( '[data-upve-panel]' );
		var wrap = document.querySelector( '.upve-wrap' );
		var referer = form ? form.querySelector( 'input[name="_wp_http_referer"]' ) : null;
		var refererBase = referer ? referer.value.split( '#' )[ 0 ] : '';
		var wide = [ 'reports', 'tools' ];

		function show( id ) {
			var found = false;

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-upve-tab' ) === id;

				found = found || active;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			if ( ! found && tabs.length ) {
				show( tabs[ 0 ].getAttribute( 'data-upve-tab' ) );
				return;
			}

			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-upve-panel' ) !== id;
			} );

			if ( wrap ) {
				wrap.classList.toggle( 'is-wide', wide.indexOf( id ) !== -1 );
			}

			// Come back to this tab after saving.
			if ( referer ) {
				referer.value = refererBase + '#' + id;
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				var id = tab.getAttribute( 'data-upve-tab' );

				event.preventDefault();
				show( id );
				history.replaceState( null, '', '#' + id );
			} );
		} );

		document.querySelectorAll( '[data-upve-goto]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				show( link.getAttribute( 'data-upve-goto' ) );
				history.replaceState( null, '', '#' + link.getAttribute( 'data-upve-goto' ) );
				window.scrollTo( 0, 0 );
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.replace( '#', '' ) );
		} );

		show( window.location.hash.replace( '#', '' ) );
	}

	// Rows that only apply while a checkbox is ticked ---------------------------

	function initDepends( form ) {
		if ( ! form ) {
			return;
		}

		var rows = form.querySelectorAll( '[data-upve-depends]' );

		function update() {
			rows.forEach( function ( row ) {
				var key = row.getAttribute( 'data-upve-depends' );
				var box = form.querySelector( 'input[type="checkbox"][name$="[' + key + ']"]' );

				row.classList.toggle( 'is-disabled', !! box && ! box.checked );
			} );
		}

		form.addEventListener( 'change', update );
		update();
	}

	// Role checkboxes only matter for "Only these roles" ------------------------

	function initAccess( form ) {
		var roles = document.querySelector( '[data-upve-roles]' );

		if ( ! form || ! roles ) {
			return;
		}

		var row = roles.closest( 'tr' );

		function update() {
			var chosen = form.querySelector( 'input[data-upve-access]:checked' );

			row.classList.toggle( 'is-disabled', ! chosen || chosen.value !== 'roles' );
		}

		form.querySelectorAll( 'input[data-upve-access]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', update );
		} );

		update();
	}

	// Colour pickers and the live preview ----------------------------------------

	function initPreview( form ) {
		var preview = document.querySelector( '.upve-preview' );

		if ( ! form || ! preview ) {
			return;
		}

		var vars = {
			color_bar: '--upve-bar',
			color_text: '--upve-fg',
			color_accent: '--upve-accent',
			color_bg: '--upve-bg',
		};

		function value( key ) {
			var field = form.querySelector( '[data-upve-key="' + key + '"]' );

			if ( ! field ) {
				return null;
			}

			return field.type === 'checkbox' ? field.checked : field.value;
		}

		function update() {
			Object.keys( vars ).forEach( function ( key ) {
				var v = value( key );

				if ( v && /^#[0-9a-f]{3,8}$/i.test( v ) ) {
					preview.style.setProperty( vars[ key ], v );
				}
			} );

			var radius = parseInt( value( 'radius' ), 10 );

			if ( ! isNaN( radius ) ) {
				preview.style.setProperty( '--upve-radius', Math.max( 0, Math.min( 24, radius ) ) + 'px' );
			}

			[ 'light', 'dark', 'auto' ].forEach( function ( theme ) {
				preview.classList.toggle( 'upve-theme-' + theme, value( 'theme' ) === theme );
			} );
			preview.classList.toggle( 'upve-bar-top', value( 'bar_position' ) !== 'bottom' );
			preview.classList.toggle( 'upve-bar-bottom', value( 'bar_position' ) === 'bottom' );
			preview.classList.toggle( 'upve-style-bar', value( 'bar_style' ) !== 'floating' );
			preview.classList.toggle( 'upve-style-floating', value( 'bar_style' ) === 'floating' );
			preview.classList.toggle( 'has-watermark', !! value( 'watermark' ) );

			preview.querySelectorAll( '[data-pv]' ).forEach( function ( node ) {
				var on = value( node.getAttribute( 'data-pv' ) );

				node.hidden = on === false;
			} );

			var mark = preview.querySelector( '.upve-pv-mark' );
			var text = value( 'watermark_text' );

			if ( mark && typeof text === 'string' ) {
				mark.textContent = text.replace( /\{site\}/g, data.site || '' )
					.replace( /\{email\}/g, 'jane@example.com' )
					.replace( /\{name\}/g, 'Jane Doe' )
					.replace( /\{username\}/g, 'jane' )
					.replace( /\{date\}/g, new Date().toLocaleDateString() );
			}
		}

		if ( window.jQuery && jQuery.fn.wpColorPicker ) {
			jQuery( '.upve-color' ).wpColorPicker( {
				change: function () {
					setTimeout( update, 0 );
				},
				clear: function () {
					setTimeout( update, 0 );
				},
			} );
		}

		form.addEventListener( 'input', update );
		form.addEventListener( 'change', update );
		update();
	}

	// Watermark placeholders ------------------------------------------------------

	function initChips() {
		var field = document.getElementById( 'upve-watermark_text' );

		if ( ! field ) {
			return;
		}

		document.querySelectorAll( '.upve-chip[data-insert]' ).forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				var tag = chip.getAttribute( 'data-insert' );
				var start = field.selectionStart === null ? field.value.length : field.selectionStart;
				var end = field.selectionEnd === null ? field.value.length : field.selectionEnd;

				field.value = field.value.slice( 0, start ) + tag + field.value.slice( end );
				field.focus();
				field.setSelectionRange( start + tag.length, start + tag.length );
				field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			} );
		} );
	}

	// Copy buttons ------------------------------------------------------------------

	function initCopy() {
		document.querySelectorAll( '[data-upve-copy]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var text = btn.getAttribute( 'data-upve-copy' );
				var label = btn.querySelector( 'span' );
				var before = label ? label.textContent : '';
				var done = function () {
					if ( label ) {
						label.textContent = data.i18n.copied;
						setTimeout( function () {
							label.textContent = before;
						}, 1500 );
					}
				};

				if ( navigator.clipboard ) {
					navigator.clipboard.writeText( text ).then( done );
				} else {
					var area = document.createElement( 'textarea' );

					area.value = text;
					document.body.appendChild( area );
					area.select();
					document.execCommand( 'copy' );
					area.remove();
					done();
				}
			} );
		} );
	}

	function initConfirm() {
		document.querySelectorAll( '[data-upve-confirm]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				if ( ! window.confirm( data.i18n.confirm ) ) {
					event.preventDefault();
				}
			} );
		} );
	}

	// Bulk text and cover reader -------------------------------------------------------

	function initPrepare() {
		var box = document.querySelector( '[data-upve-prepare]' );

		if ( ! box || ! window.wp || ! window.wp.apiFetch ) {
			return;
		}

		var start = box.querySelector( '[data-upve-prepare-start]' );
		var all = box.querySelector( '[data-upve-prepare-all]' );
		var status = box.querySelector( '.upve-prepare-status' );
		var log = box.querySelector( '.upve-prepare-log' );
		var progress = box.querySelector( '.upve-progress' );
		var bar = box.querySelector( '.upve-progress-bar' );
		var running = false;
		var stop = false;
		var startLabel = start.innerHTML;

		function line( text, ok ) {
			var li = document.createElement( 'li' );

			li.className = ok ? 'is-ok' : 'is-bad';
			li.textContent = ( ok ? '✓ ' : '✕ ' ) + text;
			log.insertBefore( li, log.firstChild );
		}

		start.addEventListener( 'click', function () {
			if ( running ) {
				stop = true;
				return;
			}

			running = true;
			stop = false;
			log.textContent = '';
			status.textContent = data.i18n.checking;
			start.textContent = data.i18n.stopLabel;

			window.wp.apiFetch( { path: '/' + data.ns + '/pending' + ( all.checked ? '?all=1' : '' ) } ).then( function ( res ) {
				var items = res.items || [];
				var done = 0;
				var failed = 0;

				if ( ! items.length ) {
					status.textContent = data.i18n.none;
					return;
				}

				progress.hidden = false;
				bar.style.width = '0';
				status.textContent = fmt( data.i18n.found, items.length );

				var next = function ( index ) {
					if ( index >= items.length || stop ) {
						status.textContent = fmt( data.i18n.done, done, failed );
						return Promise.resolve();
					}

					var item = items[ index ];

					status.textContent = fmt( data.i18n.working, index + 1, items.length, item.title );

					return window.upvePrepare.prepare( item ).then( function () {
						done++;
						line( item.title, true );
					}, function ( err ) {
						failed++;
						line( item.title + ': ' + ( err && err.message ? err.message : data.i18n.failed ), false );
					} ).then( function () {
						bar.style.width = Math.round( 100 * ( index + 1 ) / items.length ) + '%';
						return next( index + 1 );
					} );
				};

				return next( 0 );
			} ).catch( function ( err ) {
				status.textContent = ( err && err.message ) || data.i18n.failed;
			} ).then( function () {
				running = false;
				start.innerHTML = startLabel;
			} );
		} );
	}
} )();
