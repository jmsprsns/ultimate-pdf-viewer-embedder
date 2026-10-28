/**
 * Reads PDFs in an editor's browser, for the block editor and the Tools tab.
 *
 * It opens a PDF with the bundled PDF.js, pulls out the text of every page
 * (shown under the viewer for search engines and screen readers) and draws
 * the first page as a cover image (libraries, popup thumbnails), then saves
 * both with POST upve/v1/prepare. Doing this in the browser means the site
 * needs no PDF tools on the server.
 *
 * upvePrepareConfig (from PHP) holds the PDF.js file URLs.
 */

/* global upvePrepareConfig */
( function () {
	'use strict';

	const C = window.upvePrepareConfig || { pdfjs: {} };
	let libPromise = null;

	/** The same file on this page's origin (see viewer.js). */
	function local( url ) {
		try {
			const u = new URL( url, window.location.href );
			return u.origin === window.location.origin ? u.href : window.location.origin + u.pathname + u.search;
		} catch ( e ) {
			return url;
		}
	}

	function load() {
		if ( ! libPromise ) {
			libPromise = import( local( C.pdfjs.lib ) ).catch( () => import( C.pdfjs.lib ) ).then( ( lib ) => {
				lib.GlobalWorkerOptions.workerSrc = local( C.pdfjs.worker );
				return lib;
			} );
			libPromise.catch( () => {
				libPromise = null;
			} );
		}

		return libPromise;
	}

	function open( src ) {
		return load().then( ( lib ) => lib.getDocument( {
			url: src,
			cMapUrl: local( C.pdfjs.cmaps ),
			cMapPacked: true,
			standardFontDataUrl: local( C.pdfjs.fonts ),
			wasmUrl: local( C.pdfjs.wasm ),
			iccUrl: local( C.pdfjs.iccs ),
			enableXfa: false,
		} ).promise );
	}

	/**
	 * A page's text as paragraphs: lines are joined with spaces, and a
	 * bigger gap than usual between lines starts a new paragraph.
	 */
	function pageText( content ) {
		const lines = [];
		let line = '';
		let lastY = null;
		let lastH = 0;
		let gaps = [];

		content.items.forEach( ( item ) => {
			if ( typeof item.str !== 'string' ) {
				return;
			}

			const y = item.transform ? item.transform[ 5 ] : 0;
			const hgt = Math.abs( item.height || ( item.transform ? item.transform[ 3 ] : 0 ) ) || lastH;

			if ( lastY !== null && Math.abs( y - lastY ) > Math.max( 2, hgt * 0.5 ) && line.trim() ) {
				lines.push( { text: line.trim(), y: lastY, h: lastH } );
				gaps.push( Math.abs( lastY - y ) );
				line = '';
			}

			line += item.str;

			if ( item.hasEOL ) {
				line += ' ';
			}

			lastY = y;
			lastH = hgt || lastH;
		} );

		if ( line.trim() ) {
			lines.push( { text: line.trim(), y: lastY, h: lastH } );
		}

		if ( ! lines.length ) {
			return '';
		}

		// The most common line gap is normal spacing; much more is a paragraph break.
		gaps = gaps.filter( ( g ) => g > 0 ).sort( ( a, b ) => a - b );
		const normal = gaps.length ? gaps[ Math.floor( gaps.length / 2 ) ] : 0;
		let out = lines[ 0 ].text;

		for ( let i = 1; i < lines.length; i++ ) {
			const gap = Math.abs( lines[ i - 1 ].y - lines[ i ].y );
			const hyphen = /[a-z]-$/i.test( out );

			if ( normal && gap > normal * 1.45 ) {
				out += '\n\n' + lines[ i ].text;
			} else if ( hyphen ) {
				out = out.slice( 0, -1 ) + lines[ i ].text;
			} else {
				out += ' ' + lines[ i ].text;
			}
		}

		return out.replace( /[ \t]+/g, ' ' ).trim();
	}

	function text( pdf, maxPages ) {
		const last = Math.min( pdf.numPages, maxPages || 2000 );
		const pages = [];
		let n = 0;
		let size = 0;

		const step = () => {
			n++;

			if ( n > last || size > 1800000 ) {
				return Promise.resolve( pages );
			}

			return pdf.getPage( n ).then( ( page ) => page.getTextContent().then( ( content ) => {
				const str = pageText( content );

				size += str.length;
				pages.push( str );
				page.cleanup();
			} ) ).then( step );
		};

		return step();
	}

	function cover( pdf, width ) {
		return pdf.getPage( 1 ).then( ( page ) => {
			const base = page.getViewport( { scale: 1 } );
			const vp = page.getViewport( { scale: ( width || 720 ) / base.width } );
			const canvas = document.createElement( 'canvas' );
			const ctx = canvas.getContext( '2d' );

			canvas.width = Math.floor( vp.width );
			canvas.height = Math.floor( vp.height );
			ctx.fillStyle = '#fff';
			ctx.fillRect( 0, 0, canvas.width, canvas.height );

			return page.render( { canvasContext: ctx, canvas: canvas, viewport: vp } ).promise.then( () => {
				const data = canvas.toDataURL( 'image/jpeg', 0.85 );

				page.cleanup();
				return data;
			} );
		} );
	}

	/**
	 * Read a PDF and save its text and cover.
	 *
	 * @param {Object} item     { id, url, src } (src: where this browser can load it).
	 * @param {Object} options  { text: bool, cover: bool, maxPages: number }.
	 * @return {Promise<Object>} The REST answer ({ text, cover }).
	 */
	function prepare( item, options ) {
		const opts = Object.assign( { text: true, cover: true, maxPages: 2000 }, options || {} );

		return open( item.src ).then( ( pdf ) => Promise.all( [
			opts.text ? text( pdf, opts.maxPages ) : Promise.resolve( null ),
			opts.cover ? cover( pdf ) : Promise.resolve( '' ),
		] ).then( ( results ) => {
			const body = { id: item.id || 0, url: item.url || '' };

			if ( results[ 0 ] ) {
				body.pages = results[ 0 ];
				body.count = pdf.numPages;
			}

			if ( results[ 1 ] ) {
				body.cover = results[ 1 ];
			}

			// PDF.js 6 frees a document through its loading task.
			if ( pdf.loadingTask && pdf.loadingTask.destroy ) {
				pdf.loadingTask.destroy();
			} else if ( pdf.destroy ) {
				pdf.destroy();
			}

			return window.wp.apiFetch( { path: '/' + C.ns + '/prepare', method: 'POST', data: body } );
		} ) );
	}

	window.upvePrepare = { load: load, open: open, text: text, cover: cover, prepare: prepare };
} )();
