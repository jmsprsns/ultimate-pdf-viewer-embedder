/**
 * Ultimate PDF Viewer and Embedder: the viewer on the site.
 *
 * Each embed is an element with a data-upve config (see
 * Ultimate_PDF_Viewer_Embedder_Render::config). Inline viewers start when
 * they scroll into view; popups (lightbox) and library cards start on click.
 * PDF.js is loaded from the plugin folder the first time any viewer starts,
 * with a dynamic import(), so pages that never show a PDF never load it.
 *
 * upveConfig (from PHP) holds the PDF.js file URLs, the REST API base, the
 * toolbar icons and the labels.
 */

/* global upveConfig */
( function () {
	'use strict';

	const CFG = window.upveConfig || {};
	const I18N = CFG.i18n || {};
	const ICONS = CFG.icons || {};
	const SESSION_MINUTES = 30;

	let pdfjsPromise = null;
	let firstRoot = null;

	// Helpers -----------------------------------------------------------------

	function t( key ) {
		return I18N[ key ] || key;
	}

	/** Fill %d, %s, %1$d and %2$d in a label. */
	function fmt( str ) {
		const args = Array.prototype.slice.call( arguments, 1 );
		let i = 0;

		return String( str ).replace( /%(?:(\d)\$)?[ds]/g, function ( m, n ) {
			return String( n ? args[ n - 1 ] : args[ i++ ] );
		} ).replace( /%%/g, '%' );
	}

	/** Create an element. */
	function h( tag, props, children ) {
		const node = document.createElement( tag );

		Object.keys( props || {} ).forEach( function ( key ) {
			const value = props[ key ];

			if ( value === null || value === undefined || value === false ) {
				return;
			}

			if ( key === 'class' ) {
				node.className = value;
			} else if ( key === 'text' ) {
				node.textContent = value;
			} else if ( key.indexOf( 'on' ) === 0 && typeof value === 'function' ) {
				node.addEventListener( key.slice( 2 ), value );
			} else {
				node.setAttribute( key, value === true ? '' : value );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );

		return node;
	}

	/** A bundled Lucide icon (trusted markup from PHP). */
	function icon( name ) {
		const span = h( 'span', { class: 'upve-i', 'aria-hidden': 'true' } );

		span.innerHTML = ICONS[ name ] || '';
		return span;
	}

	function button( name, label, onClick, extra ) {
		return h( 'button', Object.assign( { type: 'button', class: 'upve-btn', 'aria-label': label, title: label, onclick: onClick }, extra || {} ), [ icon( name ) ] );
	}

	/** A short, stable hash for session keys. */
	function hash( str ) {
		let h1 = 0x811c9dc5;

		for ( let i = 0; i < str.length; i++ ) {
			h1 ^= str.charCodeAt( i );
			h1 = Math.imul( h1, 0x01000193 );
		}

		return ( h1 >>> 0 ).toString( 36 );
	}

	function store( key, value ) {
		try {
			if ( value === undefined ) {
				return window.sessionStorage.getItem( key );
			}
			window.sessionStorage.setItem( key, value );
		} catch ( e ) {
			// Private mode or blocked storage: no session de-duplication.
		}
		return null;
	}

	/**
	 * The same file on this page's own origin. A CDN or a different
	 * www/non-www host would make the module and worker loads cross-origin,
	 * which browsers refuse without CORS headers.
	 */
	function local( url ) {
		try {
			const u = new URL( url, window.location.href );
			return u.origin === window.location.origin ? u.href : window.location.origin + u.pathname + u.search;
		} catch ( e ) {
			return url;
		}
	}

	function importModule( url ) {
		const near = local( url );

		return import( near ).catch( function ( err ) {
			if ( near !== url ) {
				return import( url );
			}
			throw err;
		} );
	}

	/** Load PDF.js and its viewer components once. */
	function loadPdfjs() {
		if ( pdfjsPromise ) {
			return pdfjsPromise;
		}

		const p = CFG.pdfjs || {};

		if ( ! document.querySelector( 'link[data-upve-pdfjs]' ) ) {
			document.head.appendChild( h( 'link', { rel: 'stylesheet', href: local( p.css ), 'data-upve-pdfjs': '1' } ) );
		}

		pdfjsPromise = importModule( p.lib ).then( function ( lib ) {
			lib.GlobalWorkerOptions.workerSrc = local( p.worker );
			// The viewer components read the library from here.
			window.pdfjsLib = lib;

			return importModule( p.viewer ).then( function ( components ) {
				return { lib: lib, ui: components };
			} );
		} );

		pdfjsPromise.catch( function () {
			pdfjsPromise = null;
		} );

		return pdfjsPromise;
	}

	function api( path, body ) {
		return window.fetch( CFG.api + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				data.status = res.status;
				return data;
			} );
		} );
	}

	function track( token, event, extra ) {
		const body = JSON.stringify( Object.assign( { t: token, e: event }, extra || {} ) );

		try {
			window.fetch( CFG.api + 'track', {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'application/json' },
				body: body,
			} ).catch( function () {} );
		} catch ( e ) {
			if ( navigator.sendBeacon ) {
				navigator.sendBeacon( CFG.api + 'track', body );
			}
		}
	}

	/** #page=12 (first viewer) or #pdf-2&page=12. */
	function parseHash() {
		let raw = '';

		try {
			raw = decodeURIComponent( window.location.hash.slice( 1 ) );
		} catch ( e ) {
			return null;
		}

		const m = raw.match( /^(?:([A-Za-z][\w-]*)&)?page=(\d{1,6})$/ );

		if ( ! m ) {
			return null;
		}

		return { id: m[ 1 ] || '', page: parseInt( m[ 2 ], 10 ) };
	}

	function hashTarget( root ) {
		const target = parseHash();

		if ( ! target || ! root.id ) {
			return 0;
		}

		if ( target.id ? target.id === root.id : root === firstRoot ) {
			return target.page;
		}

		return 0;
	}

	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			const area = h( 'textarea', { style: 'position:fixed;top:-1000px;opacity:0' } );

			area.value = text;
			document.body.appendChild( area );
			area.select();

			try {
				document.execCommand( 'copy' );
				resolve();
			} catch ( e ) {
				reject( e );
			}

			area.remove();
		} );
	}

	function today() {
		try {
			return new Date().toLocaleDateString();
		} catch ( e ) {
			return '';
		}
	}

	// Watermark ---------------------------------------------------------------

	function watermarkText( wm ) {
		return String( wm.t || '' ).replace( /\{date\}/g, today() ).trim();
	}

	/** A DOM overlay on a rendered page (above the drawing, below nothing clickable). */
	function watermarkNode( wm ) {
		const text = watermarkText( wm );
		const node = h( 'div', { class: 'upve-wm upve-wm-' + wm.l, 'aria-hidden': 'true', style: 'opacity:' + ( wm.o / 100 ) } );

		if ( ! text ) {
			return null;
		}

		const count = wm.l === 'tiled' ? 12 : 1;

		for ( let i = 0; i < count; i++ ) {
			node.appendChild( h( 'span', { text: text } ) );
		}

		return node;
	}

	/** Size the overlay text to its page, so long text never runs off the edges. */
	function fitWatermark( node, wm ) {
		const w = node.clientWidth;
		const hgt = node.clientHeight;
		const spans = node.querySelectorAll( 'span' );

		if ( ! w || ! hgt || ! spans.length ) {
			return;
		}

		const probe = spans[ 0 ];

		probe.style.fontSize = '100px';

		const natural = probe.scrollWidth || 1;
		let size;

		if ( wm.l === 'diagonal' ) {
			size = Math.min( 100 * Math.hypot( w, hgt ) * 0.78 / natural, hgt / 9 );
			spans.forEach( ( span ) => {
				span.style.transform = 'rotate(' + ( -Math.atan2( hgt, w ) * 180 / Math.PI ) + 'deg)';
			} );
		} else if ( wm.l === 'tiled' ) {
			size = Math.min( 100 * ( w / 3 ) * 0.8 / natural, hgt / 30 );
		} else {
			size = Math.min( 100 * w * 0.8 / natural, hgt / 60 );
		}

		spans.forEach( ( span ) => {
			span.style.fontSize = Math.max( 6, size ).toFixed( 1 ) + 'px';
		} );
	}

	/** The same watermark drawn onto a canvas (print, flipbook, thumbnails). */
	function drawWatermark( ctx, w, hgt, wm ) {
		const text = watermarkText( wm );

		if ( ! text ) {
			return;
		}

		ctx.save();
		ctx.globalAlpha = wm.o / 100;
		ctx.fillStyle = '#000';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';

		if ( wm.l === 'footer' ) {
			const size = Math.max( 9, w / 45 );
			ctx.font = '600 ' + size + 'px system-ui, sans-serif';
			ctx.fillText( text, w / 2, hgt - size * 1.6 );
		} else if ( wm.l === 'tiled' ) {
			const size = Math.max( 9, w / 32 );
			ctx.font = '600 ' + size + 'px system-ui, sans-serif';

			for ( let y = 0; y < 4; y++ ) {
				for ( let x = 0; x < 3; x++ ) {
					ctx.save();
					ctx.translate( ( x + 0.5 ) * w / 3, ( y + 0.5 ) * hgt / 4 );
					ctx.rotate( -Math.PI / 6 );
					ctx.fillText( text, 0, 0 );
					ctx.restore();
				}
			}
		} else {
			let size = w / 12;
			ctx.font = '700 ' + size + 'px system-ui, sans-serif';
			const max = Math.sqrt( w * w + hgt * hgt ) * 0.8;
			const measured = ctx.measureText( text ).width;

			if ( measured > max ) {
				size = size * max / measured;
				ctx.font = '700 ' + size + 'px system-ui, sans-serif';
			}

			ctx.translate( w / 2, hgt / 2 );
			ctx.rotate( -Math.atan2( hgt, w ) );
			ctx.fillText( text, 0, 0 );
		}

		ctx.restore();
	}

	// Viewer ------------------------------------------------------------------

	class Viewer {
		/**
		 * @param {HTMLElement} root  Element holding data-upve.
		 * @param {Object}      cfg   Parsed config.
		 * @param {HTMLElement} frame Element to draw into.
		 */
		constructor( root, cfg, frame ) {
			this.root = root;
			this.cfg = cfg;
			this.frame = frame;
			this.pdf = null;
			this.pageCount = 0;
			this.page = 1;
			this.started = false;
			this.seen = new Set();
			this.sent = new Set();
			this.furthest = 0;
			this.sentFurthest = 0;
			this.viewCounted = false;
			this.triedFallback = false;
			this.src = cfg.src || '';
			this.fallback = cfg.fb || '';
			this.dl = cfg.dl || '';
			this.wm = cfg.wm || null;
			this.thumbJobs = [];
			this.thumbBusy = false;
		}

		start() {
			if ( this.started ) {
				return;
			}

			this.started = true;
			this.status = this.frame.querySelector( '.upve-status' ) || h( 'div', { class: 'upve-status', 'aria-live': 'polite' } );

			if ( ! this.status.parentNode ) {
				this.frame.appendChild( this.status );
			}

			this.setStatus( 'loading', t( 'loading' ) );

			const ready = this.cfg.open ? this.openAccess() : Promise.resolve( true );

			ready.then( ( ok ) => {
				if ( ok ) {
					return loadPdfjs().then( ( pdfjs ) => {
						this.lib = pdfjs.lib;
						this.ui = pdfjs.ui;
						return this.load( this.src );
					} );
				}
			} ).catch( ( err ) => {
				this.fail( err );
			} );
		}

		/** Ask the site for the file link (secure, restricted or watermarked embeds). */
		openAccess() {
			return api( 'open', { t: this.cfg.t } ).then( ( data ) => {
				if ( data.status === 403 ) {
					this.denied( data.message || '', data.login || '' );
					return false;
				}

				if ( data.status !== 200 || ! data.src ) {
					const err = new Error( data.message || t( 'failed' ) );

					err.upve = true;
					throw err;
				}

				this.src = data.src;
				this.fallback = data.fb || '';
				this.dl = data.dl || '';

				if ( this.wm && typeof data.wm === 'string' ) {
					this.wm = Object.assign( {}, this.wm, { t: data.wm } );
				}

				return true;
			} );
		}

		load( src, password ) {
			const p = CFG.pdfjs;
			const task = this.lib.getDocument( {
				url: src,
				password: password || undefined,
				cMapUrl: local( p.cmaps ),
				cMapPacked: true,
				standardFontDataUrl: local( p.fonts ),
				wasmUrl: local( p.wasm ),
				iccUrl: local( p.iccs ),
				enableXfa: false,
			} );

			task.onPassword = ( update, reason ) => {
				this.askPassword( update, reason );
			};

			task.onProgress = ( progress ) => {
				if ( progress.total > 0 ) {
					this.setStatus( 'loading', fmt( t( 'loadingPct' ), Math.min( 100, Math.round( 100 * progress.loaded / progress.total ) ) ) );
				}
			};

			return task.promise.then( ( pdf ) => {
				this.pdf = pdf;
				this.pageCount = pdf.numPages;
				this.build();
			}, ( err ) => {
				const name = err && err.name;

				// A direct link that the browser can't read (a CDN without CORS,
				// cloud storage): try again through the site.
				if ( this.fallback && ! this.triedFallback && name !== 'PasswordException' && name !== 'InvalidPDFException' ) {
					this.triedFallback = true;
					this.src = this.fallback;
					return this.load( this.fallback );
				}

				throw err;
			} );
		}

		// Status overlay ---------------------------------------------------------

		setStatus( kind, text ) {
			const s = this.status;

			s.className = 'upve-status is-' + kind;
			s.hidden = false;
			s.textContent = '';

			if ( kind === 'loading' ) {
				s.appendChild( h( 'span', { class: 'upve-spinner', 'aria-hidden': 'true' } ) );
			}

			s.appendChild( h( 'span', { class: 'upve-status-text', text: text } ) );
			return s;
		}

		hideStatus() {
			this.status.hidden = true;
		}

		fail( err ) {
			const s = this.setStatus( 'error', err && err.upve ? err.message : t( 'failed' ) );

			s.insertBefore( icon( 'triangle-alert' ), s.firstChild );

			const actions = h( 'span', { class: 'upve-status-actions' } );

			actions.appendChild( h( 'button', {
				type: 'button',
				class: 'upve-status-btn',
				text: t( 'retry' ),
				onclick: () => {
					this.started = false;
					this.triedFallback = false;
					this.src = this.cfg.src || '';
					this.start();
				},
			} ) );

			if ( ! this.cfg.sec && this.dl ) {
				actions.appendChild( h( 'a', { class: 'upve-status-link', href: this.dl, text: t( 'openFile' ), target: '_blank', rel: 'noopener' } ) );
			}

			s.appendChild( actions );

			// The site's own file link explains what went wrong (a Google
			// Drive file that isn't shared, a file over the size limit...).
			if ( err && err.status >= 400 && this.src.indexOf( CFG.api + 'file' ) === 0 ) {
				window.fetch( this.src, { credentials: 'same-origin' } ).then( ( res ) => res.json() ).then( ( data ) => {
					if ( data && data.message ) {
						s.querySelector( '.upve-status-text' ).textContent = data.message;
					}
				} ).catch( () => {} );
			}

			if ( window.console && err ) {
				window.console.warn( 'PDF viewer:', err );
			}
		}

		denied( message, login ) {
			const s = this.setStatus( 'denied', message );

			s.insertBefore( icon( 'lock' ), s.firstChild );

			if ( login ) {
				s.appendChild( h( 'a', { class: 'upve-status-btn', href: login, text: t( 'login' ) } ) );
			}
		}

		askPassword( update, reason ) {
			const s = this.setStatus( 'password', reason === 2 ? t( 'wrongPass' ) : t( 'password' ) );
			const input = h( 'input', { type: 'password', class: 'upve-pass', 'aria-label': t( 'passLabel' ), placeholder: t( 'passLabel' ), autocomplete: 'off' } );
			const form = h( 'form', {
				class: 'upve-pass-form',
				onsubmit: ( event ) => {
					event.preventDefault();
					this.setStatus( 'loading', t( 'loading' ) );
					update( input.value );
				},
			}, [ input, h( 'button', { type: 'submit', class: 'upve-status-btn', text: t( 'unlock' ) } ) ] );

			s.insertBefore( icon( 'key-round' ), s.firstChild );
			s.appendChild( form );
			input.focus();
		}

		// Layout -------------------------------------------------------------------

		build() {
			const cfg = this.cfg;
			const root = this.root;
			const frame = this.frame;

			frame.classList.add( 'is-ready' );

			if ( ! cfg.sel ) {
				frame.classList.add( 'upve-noselect' );
			}

			this.body = h( 'div', { class: 'upve-body' } );
			this.stage = h( 'div', { class: 'upve-stage' } );
			this.side = h( 'div', { class: 'upve-side', hidden: true } );
			this.body.appendChild( this.side );
			this.body.appendChild( this.stage );

			if ( cfg.bar ) {
				this.bar = this.buildBar();
				frame.appendChild( this.bar );
			}

			this.findbar = this.buildFindbar();
			frame.appendChild( this.findbar );
			frame.appendChild( this.body );
			frame.appendChild( this.status );

			this.toast = h( 'div', { class: 'upve-toast', role: 'status', hidden: true } );
			frame.appendChild( this.toast );

			if ( cfg.mode === 'flipbook' ) {
				this.book = new Flipbook( this );
			} else {
				this.buildPdfViewer();
			}

			this.buildSidebar();
			this.bindKeys();
			this.watchSize();

			if ( cfg.sec ) {
				frame.addEventListener( 'contextmenu', ( e ) => e.preventDefault() );
				frame.addEventListener( 'dragstart', ( e ) => e.preventDefault() );
			}

			// Deep link wins over the embed's start page.
			const deep = cfg.deep ? hashTarget( root ) : 0;
			this.startPage = Math.min( this.pageCount, Math.max( 1, deep || cfg.page || 1 ) );

			if ( this.book ) {
				this.book.start( this.startPage );
				this.hideStatus();
				this.viewed();
			}
		}

		buildPdfViewer() {
			const ui = this.ui;
			const lib = this.lib;
			const cfg = this.cfg;

			this.scroller = h( 'div', { class: 'upve-scroll', tabindex: '-1' } );
			this.inner = h( 'div', { class: 'pdfViewer' } );
			this.scroller.appendChild( this.inner );
			this.stage.appendChild( this.scroller );

			this.bus = new ui.EventBus();
			this.links = new ui.PDFLinkService( {
				eventBus: this.bus,
				externalLinkTarget: cfg.nt ? ui.LinkTarget.BLANK : ui.LinkTarget.SELF,
				externalLinkRel: 'noopener noreferrer nofollow',
			} );
			this.finder = new ui.PDFFindController( { eventBus: this.bus, linkService: this.links } );
			this.pdfViewer = new ui.PDFViewer( {
				container: this.scroller,
				viewer: this.inner,
				eventBus: this.bus,
				linkService: this.links,
				findController: this.finder,
				textLayerMode: 1,
				annotationMode: lib.AnnotationMode.ENABLE,
			} );
			this.links.setViewer( this.pdfViewer );

			// PDF.js moves to a search match with the browser's scrollIntoView(),
			// which also scrolls the web page around the viewer. Scroll only the
			// viewer, and keep focusing from scrolling the page too.
			this.finder.scrollMatchIntoView = ( { element = null, pageIndex = -1, matchIndex = -1 } = {} ) => {
				const f = this.finder;

				if ( ! element || ! f._scrollMatches || matchIndex !== f._selected.matchIdx || pageIndex !== f._selected.pageIdx ) {
					return;
				}

				f._scrollMatches = false;

				const box = this.scroller.getBoundingClientRect();
				const hit = element.getBoundingClientRect();

				this.scroller.scrollTop += hit.top - box.top - Math.min( 80, box.height / 4 );
				this.scroller.scrollLeft += hit.left + hit.width / 2 - ( box.left + box.width / 2 );
			};
			this.pdfViewer.focus = () => this.scroller.focus( { preventScroll: true } );

			this.bus.on( 'pagesinit', () => {
				this.pdfViewer.currentScaleValue = this.scaleValue( cfg.zoom );

				if ( cfg.scroll === 'page' ) {
					this.setScrollMode( 'page' );
				}

				if ( this.startPage > 1 ) {
					this.pdfViewer.currentPageNumber = this.startPage;
				}

				this.hideStatus();
				this.onPage( this.pdfViewer.currentPageNumber );
				this.autoHeight();
			} );

			this.bus.on( 'pagerendered', ( evt ) => {
				if ( ! this.viewCounted ) {
					this.viewed();
				}

				if ( this.wm && evt.source && evt.source.div ) {
					const old = evt.source.div.querySelector( '.upve-wm' );
					const node = old || watermarkNode( this.wm );

					if ( node ) {
						if ( ! old ) {
							evt.source.div.appendChild( node );
						}

						fitWatermark( node, this.wm );
					}
				}
			} );

			this.bus.on( 'pagechanging', ( evt ) => this.onPage( evt.pageNumber ) );
			this.bus.on( 'scalechanging', ( evt ) => this.onScale( evt.presetValue || String( evt.scale ) ) );
			this.bus.on( 'updatefindmatchescount', ( evt ) => this.onMatches( evt.matchesCount ) );
			this.bus.on( 'updatefindcontrolstate', ( evt ) => this.onFindState( evt.state, evt.matchesCount ) );

			this.pdfViewer.setDocument( this.pdf );
			this.links.setDocument( this.pdf, null );

			// Pinch to zoom on touch screens, and ctrl + wheel / trackpad pinch.
			if ( lib.TouchManager && window.AbortController ) {
				this.touchAbort = new AbortController();
				this.touch = new lib.TouchManager( {
					container: this.scroller,
					signal: this.touchAbort.signal,
					isPinchingDisabled: () => false,
					onPinching: ( origin, prev, dist ) => {
						if ( prev > 0 ) {
							this.pdfViewer.updateScale( { drawingDelay: 300, scaleFactor: dist / prev, origin: origin } );
						}
					},
				} );
			}

			this.scroller.addEventListener( 'wheel', ( event ) => {
				if ( event.ctrlKey || event.metaKey ) {
					event.preventDefault();
					this.pdfViewer.updateScale( { drawingDelay: 300, scaleFactor: Math.exp( -event.deltaY / 120 ), origin: [ event.clientX, event.clientY ] } );
				}
			}, { passive: false } );
		}

		scaleValue( zoom ) {
			if ( zoom === 'auto' || zoom === 'page-fit' || zoom === 'page-width' ) {
				// Phones: fit the width, whatever the default says.
				return this.frame.clientWidth < 600 && zoom === 'auto' ? 'page-width' : zoom;
			}

			const n = parseInt( zoom, 10 );
			return n >= 10 ? String( n / 100 ) : 'auto';
		}

		/** "auto" height: as tall as the first page at full width, within the screen. */
		autoHeight() {
			if ( this.cfg.h !== 'auto' || ! this.pdf ) {
				return;
			}

			this.pdf.getPage( 1 ).then( ( page ) => {
				const vp = page.getViewport( { scale: 1 } );
				const width = this.stage.clientWidth || this.frame.clientWidth;
				const bars = this.frame.clientHeight - this.body.clientHeight;
				const want = Math.round( width * vp.height / vp.width + bars + 24 );

				this.root.style.setProperty( '--upve-h', Math.min( want, Math.round( window.innerHeight * 0.9 ) ) + 'px' );
			} );
		}

		// Toolbar -------------------------------------------------------------------

		buildBar() {
			const cfg = this.cfg;
			const btn = cfg.btn || {};
			const bar = h( 'div', { class: 'upve-bar', role: 'toolbar', 'aria-label': t( 'toolbar' ) } );
			const start = h( 'div', { class: 'upve-group upve-group-start' } );
			const nav = h( 'div', { class: 'upve-group upve-group-nav' } );
			const zoom = h( 'div', { class: 'upve-group upve-group-zoom' } );
			const end = h( 'div', { class: 'upve-group upve-group-end' } );

			if ( btn.th ) {
				this.sideBtn = button( 'panel-left', t( 'sidebar' ), () => this.toggleSide(), { 'aria-expanded': 'false' } );
				start.appendChild( this.sideBtn );
			}

			const isBook = cfg.mode === 'flipbook';

			this.prevBtn = button( 'chevron-left', isBook ? t( 'flipPrev' ) : t( 'prev' ), () => this.prev() );
			this.nextBtn = button( 'chevron-right', isBook ? t( 'flipNext' ) : t( 'next' ), () => this.next() );
			this.pageInput = h( 'input', {
				type: 'text',
				inputmode: 'numeric',
				class: 'upve-page-input',
				'aria-label': t( 'page' ),
				value: '1',
				onchange: () => this.goTo( parseInt( this.pageInput.value, 10 ) ),
				onkeydown: ( e ) => {
					if ( e.key === 'Enter' ) {
						this.goTo( parseInt( this.pageInput.value, 10 ) );
					}
				},
				onfocus: () => this.pageInput.select(),
			} );
			this.pageCountEl = h( 'span', { class: 'upve-page-count', text: fmt( t( 'of' ), this.pageCount ) } );
			nav.appendChild( this.prevBtn );
			nav.appendChild( h( 'label', { class: 'upve-page' }, [ this.pageInput, this.pageCountEl ] ) );
			nav.appendChild( this.nextBtn );

			if ( btn.zm ) {
				this.zoomSelect = h( 'select', { class: 'upve-zoom-select', 'aria-label': t( 'zoom' ), onchange: () => {
					this.pdfViewer.currentScaleValue = this.zoomSelect.value;
				} } );
				[ [ 'auto', t( 'auto' ) ], [ 'page-width', t( 'fitWidth' ) ], [ 'page-fit', t( 'fitPage' ) ], [ '0.5', '50%' ], [ '0.75', '75%' ], [ '1', '100%' ], [ '1.25', '125%' ], [ '1.5', '150%' ], [ '2', '200%' ], [ '3', '300%' ], [ 'custom', '' ] ].forEach( ( opt ) => {
					this.zoomSelect.appendChild( h( 'option', { value: opt[ 0 ], text: opt[ 1 ], hidden: opt[ 0 ] === 'custom' } ) );
				} );
				zoom.appendChild( button( 'zoom-out', t( 'zoomOut' ), () => this.pdfViewer.decreaseScale() ) );
				zoom.appendChild( this.zoomSelect );
				zoom.appendChild( button( 'zoom-in', t( 'zoomIn' ), () => this.pdfViewer.increaseScale() ) );
			}

			if ( btn.se ) {
				this.searchBtn = button( 'search', t( 'search' ), () => this.toggleFind() );
				end.appendChild( this.searchBtn );
			}

			const optional = [];

			if ( btn.md ) {
				this.modeBtn = button( 'file', t( 'single' ), () => this.setScrollMode( this.scrollMode === 'page' ? 'continuous' : 'page' ), { class: 'upve-btn upve-opt' } );
				optional.push( [ this.modeBtn, 'file', t( 'single' ), () => this.setScrollMode( this.scrollMode === 'page' ? 'continuous' : 'page' ) ] );
			}

			if ( btn.sh && this.anchor().id ) {
				optional.push( [ button( 'link', t( 'share' ), () => this.share(), { class: 'upve-btn upve-opt' } ), 'link', t( 'share' ), () => this.share() ] );
			}

			if ( btn.dl && this.dl ) {
				optional.push( [ button( 'download', t( 'download' ), () => this.download(), { class: 'upve-btn upve-opt' } ), 'download', t( 'download' ), () => this.download() ] );
			}

			if ( btn.pr ) {
				optional.push( [ button( 'printer', t( 'print' ), () => this.print(), { class: 'upve-btn upve-opt' } ), 'printer', t( 'print' ), () => this.print() ] );
			}

			optional.forEach( ( item ) => end.appendChild( item[ 0 ] ) );

			if ( btn.fs ) {
				this.fullBtn = button( 'maximize', t( 'fullscreen' ), () => this.toggleFull() );
				end.appendChild( this.fullBtn );
			}

			if ( optional.length ) {
				this.menu = h( 'div', { class: 'upve-menu', role: 'menu', hidden: true } );
				optional.forEach( ( item ) => {
					const entry = h( 'button', { type: 'button', class: 'upve-menu-item', role: 'menuitem', onclick: () => {
						this.closeMenu();
						item[ 3 ]();
					} }, [ icon( item[ 1 ] ), h( 'span', { text: item[ 2 ] } ) ] );

					if ( item[ 0 ] === this.modeBtn ) {
						this.modeMenuItem = entry;
					}

					this.menu.appendChild( entry );
				} );

				this.moreBtn = button( 'ellipsis-vertical', t( 'more' ), () => this.toggleMenu(), { class: 'upve-btn upve-more', 'aria-haspopup': 'menu', 'aria-expanded': 'false' } );
				end.appendChild( this.moreBtn );
				end.appendChild( this.menu );
			}

			bar.appendChild( start );
			bar.appendChild( nav );

			if ( btn.zm ) {
				bar.appendChild( zoom );
			}

			bar.appendChild( end );

			return bar;
		}

		toggleMenu() {
			const open = this.menu.hidden;

			this.menu.hidden = ! open;
			this.moreBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );

			if ( open ) {
				const first = this.menu.querySelector( 'button' );

				if ( first ) {
					first.focus();
				}

				this.menuAway = ( event ) => {
					if ( ! this.menu.contains( event.target ) && ! this.moreBtn.contains( event.target ) ) {
						this.closeMenu();
					}
				};
				document.addEventListener( 'pointerdown', this.menuAway );
			} else {
				this.closeMenu();
			}
		}

		closeMenu() {
			if ( ! this.menu ) {
				return;
			}

			this.menu.hidden = true;
			this.moreBtn.setAttribute( 'aria-expanded', 'false' );

			if ( this.menuAway ) {
				document.removeEventListener( 'pointerdown', this.menuAway );
				this.menuAway = null;
			}
		}

		watchSize() {
			if ( ! window.ResizeObserver ) {
				return;
			}

			let last = 0;

			this.resizer = new ResizeObserver( ( entries ) => {
				const width = entries[ 0 ].contentRect.width;

				if ( Math.abs( width - last ) < 2 ) {
					return;
				}

				last = width;
				this.frame.classList.toggle( 'upve-small', width < 640 );
				this.fitBar();

				if ( this.book ) {
					this.book.resize();
				}
			} );
			this.resizer.observe( this.frame );
		}

		/**
		 * Move the extra buttons into the "More" menu only when they don't
		 * fit, then drop the zoom group and page count if it's still tight.
		 * Measured, so it works for any mix of buttons, fonts and widths.
		 */
		fitBar() {
			const bar = this.bar;
			const frame = this.frame;

			if ( ! bar ) {
				return;
			}

			const floating = !! this.root.closest( '.upve-style-floating' );
			const room = floating ? frame.clientWidth - 24 : bar.clientWidth;
			const px = ( el, prop ) => parseFloat( window.getComputedStyle( el )[ prop ] ) || 0;
			const need = () => {
				let total = px( bar, 'paddingLeft' ) + px( bar, 'paddingRight' );

				Array.prototype.forEach.call( bar.children, ( group, i ) => {
					total += i ? px( bar, 'columnGap' ) || 6 : 0;
					total += px( group, 'marginLeft' ) + px( group, 'marginRight' ) + px( group, 'paddingLeft' ) + px( group, 'paddingRight' );

					Array.prototype.forEach.call( group.children, ( child ) => {
						if ( child.getClientRects().length && window.getComputedStyle( child ).position !== 'absolute' ) {
							total += child.offsetWidth + 2;
						}
					} );
				} );

				return total;
			};

			frame.classList.remove( 'upve-narrow', 'upve-tiny' );

			if ( need() > room ) {
				frame.classList.add( 'upve-narrow' );

				if ( need() > room ) {
					frame.classList.add( 'upve-tiny' );
				}
			}
		}

		// Navigation ----------------------------------------------------------------

		onPage( page ) {
			this.page = page;

			if ( this.pageInput && document.activeElement !== this.pageInput ) {
				this.pageInput.value = String( page );
			}

			if ( this.prevBtn ) {
				this.prevBtn.disabled = page <= 1;
				this.nextBtn.disabled = this.book ? this.book.atEnd() : page >= this.pageCount;
			}

			this.markSeen( page );

			if ( this.book ) {
				this.book.visiblePages().forEach( ( p ) => this.markSeen( p ) );
			}

			this.highlightThumb( page );
		}

		goTo( page ) {
			if ( ! page || isNaN( page ) ) {
				this.onPage( this.page );
				return;
			}

			page = Math.max( 1, Math.min( this.pageCount, page ) );

			if ( this.book ) {
				this.book.goTo( page );
			} else if ( this.pdfViewer ) {
				this.pdfViewer.currentPageNumber = page;
			}
		}

		prev() {
			if ( this.book ) {
				this.book.prev();
			} else if ( this.pdfViewer ) {
				this.pdfViewer.previousPage();
			}
		}

		next() {
			if ( this.book ) {
				this.book.next();
			} else if ( this.pdfViewer ) {
				this.pdfViewer.nextPage();
			}
		}

		onScale( value ) {
			if ( ! this.zoomSelect ) {
				return;
			}

			const known = Array.prototype.some.call( this.zoomSelect.options, ( opt ) => opt.value === value && opt.value !== 'custom' );
			const custom = this.zoomSelect.querySelector( 'option[value="custom"]' );

			if ( known ) {
				custom.hidden = true;
				this.zoomSelect.value = value;
			} else {
				custom.hidden = false;
				custom.textContent = Math.round( parseFloat( value ) * 100 ) + '%';
				this.zoomSelect.value = 'custom';
			}
		}

		setScrollMode( mode ) {
			if ( ! this.pdfViewer ) {
				return;
			}

			const page = this.pdfViewer.currentPageNumber;

			this.scrollMode = mode;
			this.pdfViewer.scrollMode = mode === 'page' ? this.ui.ScrollMode.PAGE : this.ui.ScrollMode.VERTICAL;
			this.pdfViewer.currentPageNumber = page;

			if ( this.modeBtn ) {
				const label = mode === 'page' ? t( 'continuous' ) : t( 'single' );
				const name = mode === 'page' ? 'rows-3' : 'file';

				[ this.modeBtn, this.modeMenuItem ].forEach( ( el ) => {
					if ( ! el ) {
						return;
					}

					el.replaceChild( icon( name ), el.querySelector( '.upve-i' ) );

					if ( el === this.modeBtn ) {
						el.setAttribute( 'aria-label', label );
						el.title = label;
					} else {
						el.querySelector( 'span:last-child' ).textContent = label;
					}
				} );
			}
		}

		// Keyboard ------------------------------------------------------------------

		bindKeys() {
			if ( ! this.cfg.keys ) {
				return;
			}

			this.frame.addEventListener( 'keydown', ( event ) => {
				const tag = event.target.tagName;
				const typing = tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA';
				const mod = event.ctrlKey || event.metaKey;

				if ( mod && ( event.key === 'f' || event.key === 'F' ) && this.cfg.btn.se ) {
					event.preventDefault();
					this.toggleFind( true );
					return;
				}

				if ( this.cfg.sec && mod && /^[spSP]$/.test( event.key ) ) {
					event.preventDefault();
					return;
				}

				if ( typing || mod || event.altKey ) {
					return;
				}

				const paged = this.book || this.scrollMode === 'page';

				if ( /^(Arrow|Page|Home|End| )/.test( event.key ) ) {
					this.refocus();
				}

				switch ( event.key ) {
					case 'ArrowLeft':
						event.preventDefault();
						this.prev();
						break;
					case 'ArrowRight':
						event.preventDefault();
						this.next();
						break;
					case 'PageUp':
						if ( paged ) {
							event.preventDefault();
							this.prev();
						}
						break;
					case 'PageDown':
					case ' ':
						if ( paged ) {
							event.preventDefault();
							this.next();
						}
						break;
					case 'Home':
						event.preventDefault();
						this.goTo( 1 );
						break;
					case 'End':
						event.preventDefault();
						this.goTo( this.pageCount );
						break;
					case '+':
					case '=':
						if ( this.pdfViewer ) {
							event.preventDefault();
							this.pdfViewer.increaseScale();
						}
						break;
					case '-':
						if ( this.pdfViewer ) {
							event.preventDefault();
							this.pdfViewer.decreaseScale();
						}
						break;
					case 'Escape':
						if ( this.menu && ! this.menu.hidden ) {
							this.closeMenu();
						} else if ( ! this.findbar.hidden ) {
							this.toggleFind( false );
						} else if ( this.pseudoFull ) {
							this.toggleFull();
						}
						break;
				}
			} );
		}

		/**
		 * One-page mode and the flipbook swap the page that had focus, which
		 * drops focus to <body> and the next key press would miss the viewer.
		 */
		refocus() {
			setTimeout( () => {
				const active = document.activeElement;

				if ( ! active || active === document.body || ! this.frame.contains( active ) ) {
					const target = this.book ? this.book.el : this.scroller;

					if ( target ) {
						target.focus( { preventScroll: true } );
					}
				}
			}, 80 );
		}

		// Search --------------------------------------------------------------------

		buildFindbar() {
			const bar = h( 'div', { class: 'upve-findbar', hidden: true, role: 'search' } );

			this.findInput = h( 'input', {
				type: 'search',
				class: 'upve-find-input',
				placeholder: t( 'searchPh' ),
				'aria-label': t( 'searchPh' ),
				oninput: () => {
					clearTimeout( this.findTimer );
					this.findTimer = setTimeout( () => this.find( '' ), 250 );
				},
				onkeydown: ( e ) => {
					if ( e.key === 'Enter' ) {
						e.preventDefault();
						this.find( 'again', e.shiftKey );
					} else if ( e.key === 'Escape' ) {
						e.preventDefault();
						this.toggleFind( false );
					}
				},
			} );
			this.findCount = h( 'span', { class: 'upve-find-count', 'aria-live': 'polite' } );

			bar.appendChild( icon( 'search' ) );
			bar.appendChild( this.findInput );
			bar.appendChild( this.findCount );
			bar.appendChild( button( 'chevron-up', t( 'prevMatch' ), () => this.find( 'again', true ) ) );
			bar.appendChild( button( 'chevron-down', t( 'nextMatch' ), () => this.find( 'again', false ) ) );
			bar.appendChild( button( 'x', t( 'close' ), () => this.toggleFind( false ) ) );

			return bar;
		}

		toggleFind( show ) {
			const open = typeof show === 'boolean' ? show : this.findbar.hidden;

			this.findbar.hidden = ! open;

			if ( this.searchBtn ) {
				this.searchBtn.classList.toggle( 'is-on', open );
			}

			if ( open ) {
				this.findInput.focus();
				this.findInput.select();
			} else {
				this.findInput.value = '';
				this.findCount.textContent = '';

				if ( this.bus ) {
					this.bus.dispatch( 'findbarclose', { source: this } );
				}

				if ( this.searchBtn ) {
					this.searchBtn.focus();
				}
			}
		}

		find( type, previous ) {
			if ( ! this.bus ) {
				return;
			}

			const query = this.findInput.value.trim();

			if ( ! query ) {
				this.findCount.textContent = '';
				this.bus.dispatch( 'findbarclose', { source: this } );
				return;
			}

			this.bus.dispatch( 'find', {
				source: this,
				type: type,
				query: query,
				caseSensitive: false,
				entireWord: false,
				highlightAll: true,
				findPrevious: !! previous,
				matchDiacritics: false,
			} );
		}

		onMatches( counts ) {
			if ( ! counts || ! this.findInput.value.trim() ) {
				return;
			}

			this.findCount.textContent = counts.total ? fmt( t( 'matches' ), counts.current, counts.total ) : t( 'noMatches' );
		}

		onFindState( state, counts ) {
			// 1 = not found.
			if ( state === 1 && this.findInput.value.trim() ) {
				this.findCount.textContent = t( 'noMatches' );
			} else if ( counts ) {
				this.onMatches( counts );
			}
		}

		// Sidebar: thumbnails and outline ---------------------------------------------

		buildSidebar() {
			if ( ! this.cfg.btn.th ) {
				return;
			}

			this.thumbs = h( 'div', { class: 'upve-thumbs', role: 'list' } );
			this.outline = h( 'div', { class: 'upve-outline', hidden: true } );
			this.tabs = h( 'div', { class: 'upve-side-tabs', role: 'tablist', hidden: true } );
			this.side.appendChild( this.tabs );
			this.side.appendChild( this.thumbs );
			this.side.appendChild( this.outline );

			this.pdf.getOutline().then( ( items ) => {
				if ( ! items || ! items.length ) {
					return;
				}

				this.outline.appendChild( this.outlineList( items, 0 ) );

				const tab = ( name, label, panel ) => h( 'button', {
					type: 'button',
					role: 'tab',
					class: 'upve-side-tab',
					'data-panel': name,
					onclick: () => this.showPanel( name ),
				}, [ icon( name === 'thumbs' ? 'layout-grid' : 'list-tree' ), h( 'span', { text: label } ) ] );

				this.tabs.appendChild( tab( 'thumbs', t( 'pages' ) ) );
				this.tabs.appendChild( tab( 'outline', t( 'contents' ) ) );
				this.tabs.hidden = false;
				this.showPanel( this.cfg.side === 'outline' ? 'outline' : 'thumbs' );
			} ).catch( () => {} );

			const wide = this.frame.clientWidth >= 720;

			if ( this.cfg.side !== 'closed' && wide ) {
				this.toggleSide( true );
			}
		}

		showPanel( name ) {
			this.thumbs.hidden = name !== 'thumbs';
			this.outline.hidden = name !== 'outline';
			this.tabs.querySelectorAll( '.upve-side-tab' ).forEach( ( tab ) => {
				tab.setAttribute( 'aria-selected', tab.getAttribute( 'data-panel' ) === name ? 'true' : 'false' );
			} );

			if ( name === 'thumbs' ) {
				this.highlightThumb( this.page, true );
			}
		}

		toggleSide( show ) {
			const open = typeof show === 'boolean' ? show : this.side.hidden;

			this.side.hidden = ! open;
			this.frame.classList.toggle( 'upve-side-open', open );

			if ( this.sideBtn ) {
				this.sideBtn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				this.sideBtn.classList.toggle( 'is-on', open );
			}

			if ( open && ! this.thumbsBuilt ) {
				this.buildThumbs();
			}

			if ( this.book ) {
				this.book.resize();
			}
		}

		buildThumbs() {
			this.thumbsBuilt = true;

			const io = 'IntersectionObserver' in window ? new IntersectionObserver( ( entries ) => {
				entries.forEach( ( entry ) => {
					if ( entry.isIntersecting ) {
						io.unobserve( entry.target );
						this.queueThumb( entry.target );
					}
				} );
			}, { root: this.side, rootMargin: '200px 0px' } ) : null;

			for ( let n = 1; n <= this.pageCount; n++ ) {
				const item = h( 'button', {
					type: 'button',
					class: 'upve-thumb',
					role: 'listitem',
					'data-page': String( n ),
					'aria-label': fmt( t( 'pageN' ), n ),
					// A click shouldn't focus (and so scroll the web page to) the thumbnail.
					onmousedown: ( e ) => e.preventDefault(),
					onclick: () => {
						this.goTo( n );

						if ( this.frame.classList.contains( 'upve-small' ) ) {
							this.toggleSide( false );
						}
					},
				}, [ h( 'span', { class: 'upve-thumb-img' } ), h( 'span', { class: 'upve-thumb-num', text: String( n ) } ) ] );

				this.thumbs.appendChild( item );

				if ( io ) {
					io.observe( item );
				} else if ( n <= 30 ) {
					this.queueThumb( item );
				}
			}

			this.highlightThumb( this.page, true );
		}

		queueThumb( item ) {
			this.thumbJobs.push( item );
			this.runThumbs();
		}

		runThumbs() {
			if ( this.thumbBusy || ! this.thumbJobs.length ) {
				return;
			}

			this.thumbBusy = true;

			const item = this.thumbJobs.shift();
			const n = parseInt( item.getAttribute( 'data-page' ), 10 );

			this.pdf.getPage( n ).then( ( page ) => {
				const base = page.getViewport( { scale: 1 } );
				const ratio = Math.min( 2, window.devicePixelRatio || 1 );
				const vp = page.getViewport( { scale: ( 118 / base.width ) * ratio } );
				const canvas = h( 'canvas', { width: Math.floor( vp.width ), height: Math.floor( vp.height ) } );
				const ctx = canvas.getContext( '2d' );

				return page.render( { canvasContext: ctx, canvas: canvas, viewport: vp } ).promise.then( () => {
					if ( this.wm ) {
						drawWatermark( ctx, canvas.width, canvas.height, this.wm );
					}

					item.querySelector( '.upve-thumb-img' ).appendChild( canvas );
					page.cleanup();
				} );
			} ).catch( () => {} ).then( () => {
				this.thumbBusy = false;
				this.runThumbs();
			} );
		}

		highlightThumb( page, scroll ) {
			if ( ! this.thumbs || ! this.thumbsBuilt ) {
				return;
			}

			const pages = this.book ? this.book.visiblePages() : [ page ];
			let active = null;

			this.thumbs.querySelectorAll( '.upve-thumb' ).forEach( ( item ) => {
				const on = pages.indexOf( parseInt( item.getAttribute( 'data-page' ), 10 ) ) !== -1;

				item.classList.toggle( 'is-current', on );

				if ( on ) {
					item.setAttribute( 'aria-current', 'page' );
					active = active || item;
				} else {
					item.removeAttribute( 'aria-current' );
				}
			} );

			// Keep the current page in view inside the sidebar only (never
			// scroll the whole web page).
			if ( active && ! this.side.hidden ) {
				const top = active.offsetTop - this.side.offsetTop;
				const view = this.side.scrollTop;

				if ( scroll || top < view || top + active.offsetHeight > view + this.side.clientHeight ) {
					this.side.scrollTop = top - ( this.side.clientHeight - active.offsetHeight ) / 2;
				}
			}
		}

		outlineList( items, depth ) {
			const list = h( 'ul', { class: 'upve-outline-list', role: depth ? 'group' : 'tree' } );

			items.forEach( ( item ) => {
				const li = h( 'li', { role: 'treeitem' } );
				const hasKids = item.items && item.items.length;
				const link = h( 'button', {
					type: 'button',
					class: 'upve-outline-link',
					text: item.title || '—',
					onclick: () => this.followOutline( item ),
				} );

				if ( hasKids ) {
					const kids = this.outlineList( item.items, depth + 1 );
					const open = depth === 0;
					const toggle = h( 'button', {
						type: 'button',
						class: 'upve-outline-toggle',
						'aria-label': item.title || '',
						'aria-expanded': open ? 'true' : 'false',
						onclick: () => {
							const now = kids.hidden;

							kids.hidden = ! now;
							toggle.setAttribute( 'aria-expanded', now ? 'true' : 'false' );
						},
					}, [ icon( 'chevron-right' ) ] );

					kids.hidden = ! open;
					li.appendChild( toggle );
					li.appendChild( link );
					li.appendChild( kids );
				} else {
					li.appendChild( h( 'span', { class: 'upve-outline-spacer' } ) );
					li.appendChild( link );
				}

				list.appendChild( li );
			} );

			return list;
		}

		followOutline( item ) {
			if ( item.url && /^(https?|mailto):/i.test( item.url ) ) {
				window.open( item.url, '_blank', 'noopener,noreferrer' );
				return;
			}

			if ( ! item.dest ) {
				return;
			}

			if ( this.links && ! this.book ) {
				this.links.goToDestination( item.dest );
			} else {
				this.pageForDest( item.dest ).then( ( n ) => this.goTo( n ) );
			}

			if ( this.frame.classList.contains( 'upve-small' ) ) {
				this.toggleSide( false );
			}
		}

		pageForDest( dest ) {
			const resolve = typeof dest === 'string' ? this.pdf.getDestination( dest ) : Promise.resolve( dest );

			return resolve.then( ( explicit ) => {
				if ( ! Array.isArray( explicit ) ) {
					return 1;
				}

				const ref = explicit[ 0 ];

				if ( typeof ref === 'number' ) {
					return ref + 1;
				}

				return this.pdf.getPageIndex( ref ).then( ( index ) => index + 1 );
			} ).catch( () => 1 );
		}

		// Share, download, print, full screen -------------------------------------------

		/** The element deep links point at (a popup's trigger, not the popup). */
		anchor() {
			return this.rootForLinks || this.root;
		}

		share() {
			const base = window.location.href.split( '#' )[ 0 ];
			const anchor = this.anchor();
			const prefix = anchor === firstRoot ? '' : anchor.id + '&';

			copyText( base + '#' + prefix + 'page=' + this.page ).then( () => this.flash( t( 'copied' ) ), () => {} );
		}

		flash( text ) {
			this.toast.textContent = text;
			this.toast.hidden = false;
			clearTimeout( this.toastTimer );
			this.toastTimer = setTimeout( () => {
				this.toast.hidden = true;
			}, 1800 );
		}

		download() {
			if ( ! this.dl ) {
				return;
			}

			const a = h( 'a', { href: this.dl, download: this.cfg.name || 'document.pdf', rel: 'noopener', style: 'display:none' } );

			document.body.appendChild( a );
			a.click();
			a.remove();

			if ( this.cfg.track ) {
				track( this.cfg.t, 'd' );
			}
		}

		/**
		 * Print: draw every page at 150 dpi into images and print those, so
		 * it works the same in every browser (and carries the watermark).
		 */
		print() {
			if ( this.printing || ! this.pdf ) {
				return;
			}

			this.printing = true;

			const holder = h( 'div', { class: 'upve-print-root' } );
			const total = this.pageCount;
			const urls = [];
			let n = 0;

			const step = () => {
				n++;

				if ( n > total ) {
					return Promise.resolve();
				}

				this.flash( fmt( t( 'printing' ), n, total ) );

				return this.pdf.getPage( n ).then( ( page ) => {
					const vp = page.getViewport( { scale: 150 / 72 } );
					const canvas = document.createElement( 'canvas' );
					const ctx = canvas.getContext( '2d' );

					canvas.width = Math.floor( vp.width );
					canvas.height = Math.floor( vp.height );

					return page.render( { canvasContext: ctx, canvas: canvas, viewport: vp, intent: 'print' } ).promise.then( () => {
						if ( this.wm ) {
							drawWatermark( ctx, canvas.width, canvas.height, this.wm );
						}

						return new Promise( ( resolve ) => canvas.toBlob( resolve, 'image/jpeg', 0.92 ) );
					} ).then( ( blob ) => {
						const url = URL.createObjectURL( blob );

						urls.push( url );
						holder.appendChild( h( 'img', { src: url, alt: '' } ) );
						page.cleanup();
						canvas.width = 0;
					} );
				} ).then( step );
			};

			step().then( () => {
				const imgs = Array.prototype.slice.call( holder.querySelectorAll( 'img' ) );

				document.body.appendChild( holder );
				document.documentElement.classList.add( 'upve-printing' );

				return Promise.all( imgs.map( ( img ) => img.decode ? img.decode().catch( () => {} ) : null ) );
			} ).then( () => {
				const done = () => {
					window.removeEventListener( 'afterprint', done );
					document.documentElement.classList.remove( 'upve-printing' );
					holder.remove();
					urls.forEach( ( url ) => URL.revokeObjectURL( url ) );
					this.printing = false;
				};

				window.addEventListener( 'afterprint', done );
				this.toast.hidden = true;
				window.print();

				// Browsers without afterprint: clean up a little later.
				setTimeout( () => {
					if ( this.printing ) {
						done();
					}
				}, 60000 );

				if ( this.cfg.track ) {
					track( this.cfg.t, 'r' );
				}
			} ).catch( () => {
				this.printing = false;
				document.documentElement.classList.remove( 'upve-printing' );
				holder.remove();
			} );
		}

		toggleFull() {
			const target = this.root.closest( '.upve-lightbox-panel' ) || this.root;
			const doc = document;
			const current = doc.fullscreenElement || doc.webkitFullscreenElement;

			if ( current || this.pseudoFull ) {
				if ( this.pseudoFull ) {
					this.setPseudoFull( false );
				} else if ( doc.exitFullscreen ) {
					doc.exitFullscreen();
				} else if ( doc.webkitExitFullscreen ) {
					doc.webkitExitFullscreen();
				}
				return;
			}

			const request = target.requestFullscreen || target.webkitRequestFullscreen;

			if ( request ) {
				const result = request.call( target );

				if ( result && result.catch ) {
					result.catch( () => this.setPseudoFull( true ) );
				}
			} else {
				// iPhone Safari only allows video full screen: fill the window instead.
				this.setPseudoFull( true );
			}

			if ( ! this.fullBound ) {
				this.fullBound = true;

				const sync = () => {
					const on = ( doc.fullscreenElement || doc.webkitFullscreenElement ) === target;

					target.classList.toggle( 'upve-is-full', on );
					this.syncFullButton( on );
					this.refit();
				};

				doc.addEventListener( 'fullscreenchange', sync );
				doc.addEventListener( 'webkitfullscreenchange', sync );
			}
		}

		setPseudoFull( on ) {
			const target = this.root.closest( '.upve-lightbox-panel' ) || this.root;

			this.pseudoFull = on;
			target.classList.toggle( 'upve-is-full', on );
			target.classList.toggle( 'upve-pseudo-full', on );
			document.documentElement.classList.toggle( 'upve-lock', on || !! document.querySelector( '.upve-lightbox.is-open' ) );
			this.syncFullButton( on );
			this.refit();
		}

		syncFullButton( on ) {
			if ( ! this.fullBtn ) {
				return;
			}

			const label = on ? t( 'exitFull' ) : t( 'fullscreen' );

			this.fullBtn.replaceChild( icon( on ? 'minimize' : 'maximize' ), this.fullBtn.querySelector( '.upve-i' ) );
			this.fullBtn.setAttribute( 'aria-label', label );
			this.fullBtn.title = label;
		}

		refit() {
			setTimeout( () => {
				if ( this.pdfViewer ) {
					const value = this.pdfViewer.currentScaleValue;

					if ( value === 'auto' || value === 'page-fit' || value === 'page-width' ) {
						this.pdfViewer.currentScaleValue = value;
					}
				}

				if ( this.book ) {
					this.book.resize();
				}
			}, 150 );
		}

		// Tracking ------------------------------------------------------------------

		viewed() {
			this.viewCounted = true;

			if ( ! this.cfg.track ) {
				return;
			}

			const key = 'upve:' + hash( this.cfg.t );
			const last = parseInt( store( key ) || '0', 10 );

			if ( Date.now() - last > SESSION_MINUTES * 60000 ) {
				track( this.cfg.t, 'v', { n: this.pageCount } );
			}

			store( key, String( Date.now() ) );

			const flush = () => this.flushPages();

			document.addEventListener( 'visibilitychange', () => {
				if ( document.visibilityState === 'hidden' ) {
					flush();
				}
			} );
			window.addEventListener( 'pagehide', flush );
		}

		markSeen( page ) {
			if ( page >= 1 && page <= this.pageCount ) {
				this.seen.add( page );
				this.furthest = Math.max( this.furthest, page );
			}
		}

		flushPages() {
			if ( ! this.cfg.track || ! this.viewCounted ) {
				return;
			}

			const fresh = [];

			this.seen.forEach( ( page ) => {
				if ( ! this.sent.has( page ) ) {
					fresh.push( page );
					this.sent.add( page );
				}
			} );

			if ( ! fresh.length && this.furthest <= this.sentFurthest ) {
				return;
			}

			track( this.cfg.t, 'p', { p: fresh.slice( 0, 500 ), f0: this.sentFurthest, f1: this.furthest, n: this.pageCount } );
			this.sentFurthest = this.furthest;
		}
	}

	// Flipbook ----------------------------------------------------------------

	class Flipbook {
		constructor( viewer ) {
			this.v = viewer;
			this.pdf = viewer.pdf;
			this.count = viewer.pageCount;
			this.cache = new Map();
			this.index = 0;
			this.busy = false;

			this.el = h( 'div', { class: 'upve-book', tabindex: '-1' } );
			this.inner = h( 'div', { class: 'upve-book-inner' } );
			this.left = h( 'div', { class: 'upve-leaf upve-leaf-left' }, [ h( 'canvas' ) ] );
			this.right = h( 'div', { class: 'upve-leaf upve-leaf-right' }, [ h( 'canvas' ) ] );
			this.inner.appendChild( this.left );
			this.inner.appendChild( this.right );
			this.el.appendChild( this.inner );
			this.prevBtn = h( 'button', { type: 'button', class: 'upve-book-nav upve-book-prev', 'aria-label': t( 'flipPrev' ), onclick: () => this.prev() }, [ icon( 'chevron-left' ) ] );
			this.nextBtn = h( 'button', { type: 'button', class: 'upve-book-nav upve-book-next', 'aria-label': t( 'flipNext' ), onclick: () => this.next() }, [ icon( 'chevron-right' ) ] );
			this.el.appendChild( this.prevBtn );
			this.el.appendChild( this.nextBtn );
			viewer.stage.appendChild( this.el );

			this.bindGestures();
		}

		start( page ) {
			this.pdf.getPage( 1 ).then( ( first ) => {
				const vp = first.getViewport( { scale: 1 } );

				this.ratio = vp.height / vp.width;
				this.layout();
				this.index = this.spreadFor( page );
				this.show();
			} );
		}

		/** Two pages side by side when there's room, else one. */
		layout() {
			const stage = this.v.stage;
			const double = this.count > 1 && stage.clientWidth >= 640;
			// One page (phones): the arrows sit under the page, so it can use the full width.
			const w = Math.max( 100, stage.clientWidth - ( double ? 96 : 24 ) );
			const hgt = Math.max( 100, stage.clientHeight - ( double ? 40 : 76 ) );
			let pw = double ? w / 2 : w;
			let ph = pw * this.ratio;

			if ( ph > hgt ) {
				ph = hgt;
				pw = ph / this.ratio;
			}

			this.double = double;
			this.pw = Math.floor( pw );
			this.ph = Math.floor( ph );
			this.inner.style.width = ( double ? this.pw * 2 : this.pw ) + 'px';
			this.inner.style.height = this.ph + 'px';
			this.el.classList.toggle( 'is-double', double );
			this.spreads = this.makeSpreads();
		}

		makeSpreads() {
			const list = [];

			if ( ! this.double ) {
				for ( let n = 1; n <= this.count; n++ ) {
					list.push( [ n ] );
				}
				return list;
			}

			let n = 1;

			// Cover on its own, like a printed book.
			if ( this.v.cfg.cover ) {
				list.push( [ 0, 1 ] );
				n = 2;
			}

			for ( ; n <= this.count; n += 2 ) {
				list.push( [ n, n + 1 <= this.count ? n + 1 : 0 ] );
			}

			return list;
		}

		spreadFor( page ) {
			for ( let i = 0; i < this.spreads.length; i++ ) {
				if ( this.spreads[ i ].indexOf( page ) !== -1 ) {
					return i;
				}
			}
			return 0;
		}

		visiblePages() {
			return ( this.spreads && this.spreads[ this.index ] ? this.spreads[ this.index ] : [] ).filter( Boolean );
		}

		atEnd() {
			return ! this.spreads || this.index >= this.spreads.length - 1;
		}

		resize() {
			if ( ! this.ratio ) {
				return;
			}

			clearTimeout( this.resizeTimer );
			this.resizeTimer = setTimeout( () => {
				const page = this.visiblePages()[ 0 ] || 1;

				this.cache.clear();
				this.layout();
				this.index = this.spreadFor( page );
				this.show();
			}, 120 );
		}

		/** A page drawn at the current size (cached). */
		render( n ) {
			if ( ! n ) {
				return Promise.resolve( null );
			}

			if ( this.cache.has( n ) ) {
				return this.cache.get( n );
			}

			const job = this.pdf.getPage( n ).then( ( page ) => {
				const base = page.getViewport( { scale: 1 } );
				const ratio = Math.min( 2, window.devicePixelRatio || 1 );
				const vp = page.getViewport( { scale: ( this.pw / base.width ) * ratio } );
				const canvas = document.createElement( 'canvas' );
				const ctx = canvas.getContext( '2d' );

				canvas.width = Math.floor( vp.width );
				canvas.height = Math.floor( vp.height );

				return page.render( { canvasContext: ctx, canvas: canvas, viewport: vp } ).promise.then( () => {
					if ( this.v.wm ) {
						drawWatermark( ctx, canvas.width, canvas.height, this.v.wm );
					}

					page.cleanup();
					return canvas;
				} );
			} );

			this.cache.set( n, job );

			// Keep memory in check on long documents.
			if ( this.cache.size > 12 ) {
				this.cache.delete( this.cache.keys().next().value );
			}

			return job;
		}

		paint( slot, n ) {
			const target = slot.querySelector( 'canvas' );

			slot.classList.toggle( 'is-empty', ! n );

			if ( ! n ) {
				target.width = 0;
				return Promise.resolve();
			}

			return this.render( n ).then( ( source ) => {
				if ( ! source ) {
					return;
				}

				target.width = source.width;
				target.height = source.height;
				target.getContext( '2d' ).drawImage( source, 0, 0 );
			} );
		}

		/** Pages for the left and right slots of a spread. */
		sides( spread ) {
			return this.double ? [ spread[ 0 ], spread[ 1 ] ] : [ 0, spread[ 0 ] ];
		}

		show() {
			const sides = this.sides( this.spreads[ this.index ] );

			this.inner.classList.toggle( 'is-single', ! this.double );
			this.left.hidden = ! this.double;
			this.position( sides );

			return Promise.all( [ this.double ? this.paint( this.left, sides[ 0 ] ) : null, this.paint( this.right, sides[ 1 ] ) ] ).then( () => {
				this.update();
				this.preload();
			} );
		}

		/** Centre a lone cover or back page. */
		position( sides ) {
			this.inner.classList.toggle( 'is-cover', this.double && ! sides[ 0 ] );
			this.inner.classList.toggle( 'is-back', this.double && ! sides[ 1 ] );
		}

		update() {
			const pages = this.visiblePages();

			this.prevBtn.disabled = this.index <= 0;
			this.nextBtn.disabled = this.atEnd();
			this.v.onPage( pages[ 0 ] || 1 );
		}

		preload() {
			[ this.index + 1, this.index + 2, this.index - 1 ].forEach( ( i ) => {
				if ( this.spreads[ i ] ) {
					this.spreads[ i ].forEach( ( n ) => this.render( n ) );
				}
			} );
		}

		next() {
			this.turn( 1 );
		}

		prev() {
			this.turn( -1 );
		}

		goTo( page ) {
			const target = this.spreadFor( page );

			if ( target === this.index ) {
				return;
			}

			if ( Math.abs( target - this.index ) === 1 ) {
				this.turn( target - this.index );
				return;
			}

			this.index = target;
			this.show();
		}

		/** Turn one leaf forward (1) or back (-1). */
		turn( dir ) {
			const to = this.index + dir;

			if ( this.busy || to < 0 || to >= this.spreads.length ) {
				return;
			}

			const reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			if ( reduce ) {
				this.index = to;
				this.show();
				return;
			}

			this.busy = true;

			const from = this.sides( this.spreads[ this.index ] );
			const next = this.sides( this.spreads[ to ] );
			const forward = dir > 0;
			const leaf = h( 'div', { class: 'upve-turn ' + ( forward ? 'is-forward' : 'is-backward' ) + ( this.double ? '' : ' is-single' ) } );
			const front = h( 'div', { class: 'upve-turn-face upve-turn-front' }, [ h( 'canvas' ) ] );
			const back = h( 'div', { class: 'upve-turn-face upve-turn-back' }, [ h( 'canvas' ) ] );

			leaf.appendChild( front );
			leaf.appendChild( back );

			// Forward: the right page lifts and lands on the left, showing the
			// next left page on its back. Back: the mirror image.
			const frontPage = forward ? from[ 1 ] : ( this.double ? from[ 0 ] : next[ 1 ] );
			const backPage = this.double ? ( forward ? next[ 0 ] : next[ 1 ] ) : 0;
			const underSlot = forward ? this.right : ( this.double ? this.left : this.right );
			const underPage = forward ? next[ 1 ] : ( this.double ? next[ 0 ] : from[ 1 ] );

			Promise.all( [ this.paint( front, frontPage ), this.paint( back, backPage ), this.render( underPage ) ] ).then( () => {
				this.inner.appendChild( leaf );

				return this.paint( underSlot, underPage );
			} ).then( () => {
				if ( ! this.double && ! forward ) {
					// Single page, going back: the old page stays under the
					// incoming one until it lands.
					leaf.classList.add( 'is-incoming' );
				}

				this.position( forward ? [ from[ 0 ], next[ 1 ] ] : [ next[ 0 ], from[ 1 ] ] );

				// Next frame, so the start position is painted before turning.
				window.requestAnimationFrame( () => window.requestAnimationFrame( () => leaf.classList.add( 'is-turning' ) ) );

				let finished = false;
				const finish = () => {
					if ( finished ) {
						return;
					}

					finished = true;
					this.index = to;
					this.show().then( () => {
						leaf.remove();
						this.busy = false;
					} );
				};

				leaf.addEventListener( 'transitionend', finish );
				setTimeout( finish, 900 );
			} ).catch( () => {
				leaf.remove();
				this.busy = false;
			} );
		}

		bindGestures() {
			let x0 = null;
			let y0 = 0;

			this.el.addEventListener( 'pointerdown', ( e ) => {
				if ( e.target.closest( 'button' ) ) {
					return;
				}

				x0 = e.clientX;
				y0 = e.clientY;
			} );

			this.el.addEventListener( 'pointerup', ( e ) => {
				if ( x0 === null ) {
					return;
				}

				const dx = e.clientX - x0;
				const dy = e.clientY - y0;

				x0 = null;

				if ( Math.abs( dx ) > 40 && Math.abs( dx ) > Math.abs( dy ) ) {
					this.turn( dx < 0 ? 1 : -1 );
					return;
				}

				// A tap on the right or left half turns the page.
				if ( Math.abs( dx ) < 8 && Math.abs( dy ) < 8 ) {
					const box = this.inner.getBoundingClientRect();

					if ( e.clientX >= box.left && e.clientX <= box.right && e.clientY >= box.top && e.clientY <= box.bottom ) {
						this.turn( e.clientX > box.left + box.width / 2 ? 1 : -1 );
					}
				}
			} );
		}
	}

	// Lightbox (popup) ----------------------------------------------------------

	const Lightbox = {
		el: null,
		panel: null,
		body: null,
		heading: null,
		current: null,
		opener: null,

		build() {
			if ( this.el ) {
				return;
			}

			this.heading = h( 'span', { class: 'upve-lightbox-title', id: 'upve-lightbox-title' } );
			this.body = h( 'div', { class: 'upve-lightbox-body' } );
			this.panel = h( 'div', { class: 'upve-lightbox-panel', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'upve-lightbox-title' }, [
				h( 'div', { class: 'upve-lightbox-head' }, [ this.heading, button( 'x', t( 'close' ), () => this.close(), { class: 'upve-btn upve-lightbox-close' } ) ] ),
				this.body,
			] );
			this.el = h( 'div', { class: 'upve-lightbox', hidden: true }, [ h( 'div', { class: 'upve-lightbox-backdrop', onclick: () => this.close() } ), this.panel ] );

			this.el.addEventListener( 'keydown', ( e ) => {
				if ( e.key === 'Escape' ) {
					const v = this.current && this.current.viewer;

					// Let the viewer close its search or menu first.
					if ( v && ( ( v.findbar && ! v.findbar.hidden ) || ( v.menu && ! v.menu.hidden ) || v.pseudoFull ) ) {
						return;
					}

					e.preventDefault();
					this.close();
				} else if ( e.key === 'Tab' ) {
					this.trap( e );
				}
			} );

			document.body.appendChild( this.el );
		},

		trap( e ) {
			const items = Array.prototype.filter.call(
				this.panel.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' ),
				( node ) => ! node.disabled && node.offsetParent !== null
			);

			if ( ! items.length ) {
				return;
			}

			const first = items[ 0 ];
			const last = items[ items.length - 1 ];

			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		},

		/** Theme and toolbar classes from a config. */
		looks( cfg ) {
			return 'upve-theme-' + ( cfg.theme || 'light' ) + ' upve-bar-' + ( cfg.pos || 'top' ) + ' upve-style-' + ( cfg.style || 'bar' );
		},

		/**
		 * @param {HTMLElement} owner  Trigger wrapper or library card (holds data-upve).
		 * @param {Object}      cfg    Config.
		 * @param {HTMLElement} opener Element to give focus back to.
		 * @param {number}      page   Page to open at (deep links).
		 */
		open( owner, cfg, opener, page ) {
			this.build();

			if ( ! owner.upveBox ) {
				const frame = h( 'div', { class: 'upve-frame' } );
				const box = h( 'div', { class: 'upve upve--in-lightbox ' + ( cfg.mode === 'flipbook' ? 'upve--flipbook' : 'upve--viewer' ) + ' ' + this.looks( cfg ) + ( cfg.sec ? ' upve-secure' : '' ) }, [ frame ] );

				box.id = owner.id ? owner.id + '-viewer' : '';
				owner.upveBox = box;
				owner.upveViewer = new Viewer( box, Object.assign( {}, cfg, { h: '100%', page: page || cfg.page } ), frame );
				owner.upveViewer.rootForLinks = owner;
			}

			this.panel.className = 'upve-lightbox-panel upve-theme-' + ( cfg.theme || 'light' );
			this.body.textContent = '';
			this.body.appendChild( owner.upveBox );
			this.heading.textContent = cfg.title || '';
			this.current = { owner: owner, viewer: owner.upveViewer };
			this.opener = opener || null;
			this.el.hidden = false;
			this.el.classList.add( 'is-open' );
			document.documentElement.classList.add( 'upve-lock' );

			owner.upveViewer.start();

			if ( page && owner.upveViewer.pdf ) {
				owner.upveViewer.goTo( page );
			}

			const close = this.panel.querySelector( '.upve-lightbox-close' );

			if ( close ) {
				close.focus();
			}
		},

		close() {
			if ( ! this.el || this.el.hidden ) {
				return;
			}

			const v = this.current && this.current.viewer;

			if ( v ) {
				if ( v.pseudoFull ) {
					v.setPseudoFull( false );
				}

				v.flushPages();
				v.closeMenu();
			}

			const fs = document.fullscreenElement || document.webkitFullscreenElement;

			if ( fs && this.panel.contains( fs ) ) {
				( document.exitFullscreen || document.webkitExitFullscreen ).call( document );
			}

			this.el.hidden = true;
			this.el.classList.remove( 'is-open' );
			document.documentElement.classList.remove( 'upve-lock' );

			if ( this.opener && this.opener.focus ) {
				this.opener.focus();
			}

			this.current = null;
		},
	};

	// Start-up ------------------------------------------------------------------

	function parse( node ) {
		try {
			return JSON.parse( node.getAttribute( 'data-upve' ) || '{}' );
		} catch ( e ) {
			return null;
		}
	}

	function setupInline( root, cfg, io ) {
		const frame = root.querySelector( '.upve-frame' );

		if ( ! frame ) {
			return;
		}

		root.upveViewer = new Viewer( root, cfg, frame );

		if ( ! cfg.lazy || hashTarget( root ) || ! io ) {
			root.upveViewer.start();
		} else {
			io.observe( root );
		}
	}

	function setupLightbox( root, cfg ) {
		const opener = root.querySelector( '.upve-open' );

		if ( ! opener ) {
			return;
		}

		opener.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			Lightbox.open( root, cfg, opener );
		} );

		const deep = cfg.deep ? hashTarget( root ) : 0;

		if ( deep ) {
			Lightbox.open( root, cfg, opener, deep );
		}
	}

	function setupLibrary( lib ) {
		const search = lib.querySelector( '.upve-library-search input' );
		const empty = lib.querySelector( '.upve-library-empty' );

		lib.addEventListener( 'click', ( e ) => {
			const link = e.target.closest( '.upve-card-link[data-upve]' );

			if ( ! link || ! lib.contains( link ) ) {
				return;
			}

			const cfg = parse( link );

			if ( ! cfg ) {
				return;
			}

			if ( link.getAttribute( 'data-open' ) === 'tab' ) {
				if ( cfg.track ) {
					track( cfg.t, 'v' );
				}
				return;
			}

			e.preventDefault();
			Lightbox.open( link, cfg, link );
		} );

		if ( search ) {
			search.addEventListener( 'input', () => {
				const q = search.value.trim().toLowerCase();
				let shown = 0;

				lib.querySelectorAll( '.upve-card' ).forEach( ( card ) => {
					const match = ! q || ( card.getAttribute( 'data-title' ) || '' ).indexOf( q ) !== -1;

					card.hidden = ! match;
					shown += match ? 1 : 0;
				} );

				if ( empty ) {
					empty.hidden = shown > 0;
				}
			} );
		}
	}

	function init( scope ) {
		const io = 'IntersectionObserver' in window ? new IntersectionObserver( ( entries ) => {
			entries.forEach( ( entry ) => {
				if ( entry.isIntersecting && entry.target.upveViewer ) {
					io.unobserve( entry.target );
					entry.target.upveViewer.start();
				}
			} );
		}, { rootMargin: '300px 0px' } ) : null;

		const roots = ( scope || document ).querySelectorAll( '.upve[data-upve]' );

		firstRoot = firstRoot || roots[ 0 ] || null;

		roots.forEach( ( root ) => {
			if ( root.upveReady ) {
				return;
			}

			root.upveReady = true;

			const cfg = parse( root );

			if ( ! cfg || ! cfg.t ) {
				return;
			}

			if ( root.classList.contains( 'upve--lightbox' ) ) {
				setupLightbox( root, cfg );
			} else {
				setupInline( root, cfg, io );
			}
		} );

		( scope || document ).querySelectorAll( '.upve-library' ).forEach( ( lib ) => {
			if ( ! lib.upveReady ) {
				lib.upveReady = true;
				setupLibrary( lib );
			}
		} );
	}

	// Jump when someone follows a #page= link on the same page.
	window.addEventListener( 'hashchange', () => {
		const target = parseHash();

		if ( ! target ) {
			return;
		}

		const root = target.id ? document.getElementById( target.id ) : firstRoot;

		if ( ! root ) {
			return;
		}

		if ( root.classList.contains( 'upve--lightbox' ) ) {
			const cfg = parse( root );

			if ( cfg ) {
				Lightbox.open( root, cfg, root.querySelector( '.upve-open' ), target.page );
			}
		} else if ( root.upveViewer ) {
			root.upveViewer.start();

			if ( root.upveViewer.pdf ) {
				root.upveViewer.goTo( target.page );
			} else {
				root.upveViewer.cfg.page = target.page;
			}

			root.scrollIntoView( { block: 'start' } );
		}
	} );

	// For content added later (AJAX themes): window.upveInit( container ).
	window.upveInit = init;

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => init() );
	} else {
		init();
	}
} )();
