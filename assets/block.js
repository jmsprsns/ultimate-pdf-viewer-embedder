/**
 * The PDF Viewer and PDF Library blocks in the editor.
 *
 * Both blocks are drawn by the server on the site. In the editor, the
 * viewer shows a preview built from the PDF's first page, and the library
 * is the real server output. When a PDF has no saved text or cover yet,
 * the editor reads it in the background (assets/prepare.js) and saves
 * both, so the searchable text section and library covers fill themselves.
 *
 * Written without JSX so there's no build step. window.upveBlock holds the
 * site defaults and roles (see Ultimate_PDF_Viewer_Embedder_Block::editor_data).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element || ! wp.components ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var c = wp.components;
	var be = wp.blockEditor || wp.editor;
	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var apiFetch = wp.apiFetch;
	var ServerSideRender = wp.serverSideRender || c.ServerSideRender;
	var data = window.upveBlock || {};
	var D = data.defaults || {};
	var NS = 'upve/v1';
	var TD = 'ultimate-pdf-viewer-embedder';
	var prepared = {};
	var docCache = {};

	// Icons ------------------------------------------------------------------

	function svg( list, size ) {
		// Inline fill: WordPress's ".components-button svg { fill: currentColor }" beats the attribute.
		return el( 'svg', { width: size || 24, height: size || 24, viewBox: '0 0 24 24', fill: 'none', style: { fill: 'none' }, stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true, focusable: false }, list.map( function ( d, i ) {
			return el( 'path', { key: i, d: d } );
		} ) );
	}

	var icons = {
		file: svg( [ 'M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z', 'M14 2v4a2 2 0 0 0 2 2h4', 'M10 9H8', 'M16 13H8', 'M16 17H8' ] ),
		library: svg( [ 'm16 6 4 14', 'M12 6v14', 'M8 8v12', 'M4 4v16' ] ),
		book: svg( [ 'M12 7v14', 'M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z' ], 18 ),
		left: svg( [ 'm15 18-6-6 6-6' ], 16 ),
		right: svg( [ 'm9 18 6-6-6-6' ], 16 ),
		side: svg( [ 'M3 5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z', 'M9 3v18' ], 16 ),
		search: svg( [ 'm21 21-4.34-4.34', 'M11 3a8 8 0 1 0 0 16 8 8 0 0 0 0-16Z' ], 16 ),
		download: svg( [ 'M12 15V3', 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4', 'm7 10 5 5 5-5' ], 16 ),
		print: svg( [ 'M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2', 'M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6', 'M6 14h12v8H6z' ], 16 ),
		full: svg( [ 'M8 3H5a2 2 0 0 0-2 2v3', 'M21 8V5a2 2 0 0 0-2-2h-3', 'M3 16v3a2 2 0 0 0 2 2h3', 'M16 21h3a2 2 0 0 0 2-2v-3' ], 16 ),
		zoomIn: svg( [ 'M11 3a8 8 0 1 0 0 16 8 8 0 0 0 0-16Z', 'm21 21-4.35-4.35', 'M11 8v6', 'M8 11h6' ], 16 ),
		zoomOut: svg( [ 'M11 3a8 8 0 1 0 0 16 8 8 0 0 0 0-16Z', 'm21 21-4.35-4.35', 'M8 11h6' ], 16 ),
		lock: svg( [ 'M5 11h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z', 'M7 11V7a5 5 0 0 1 10 0v4' ], 16 ),
		up: svg( [ 'm18 15-6-6-6 6' ], 16 ),
		down: svg( [ 'm6 9 6 6 6-6' ], 16 ),
		remove: svg( [ 'M18 6 6 18', 'm6 6 12 12' ], 16 ),
	};

	// Helpers ------------------------------------------------------------------

	function onOff( value ) {
		return value ? __( 'On', TD ) : __( 'Off', TD );
	}

	/** "Site default (On) / On / Off" select. */
	function tri( label, value, def, onChange, help ) {
		return el( c.SelectControl, {
			label: label,
			value: value || '',
			help: help,
			__nextHasNoMarginBottom: true,
			options: [
				{ value: '', label: sprintf( __( 'Site default (%s)', TD ), onOff( def ) ) },
				{ value: 'on', label: __( 'On', TD ) },
				{ value: 'off', label: __( 'Off', TD ) },
			],
			onChange: onChange,
		} );
	}

	function pick( label, value, options, onChange, help ) {
		return el( c.SelectControl, {
			label: label,
			value: value,
			options: options,
			onChange: onChange,
			help: help,
			__nextHasNoMarginBottom: true,
		} );
	}

	function siteDefault( label ) {
		return { value: '', label: sprintf( __( 'Site default (%s)', TD ), label ) };
	}

	function zoomLabel( zoom ) {
		var names = { auto: __( 'Automatic', TD ), 'page-width': __( 'Fit to width', TD ), 'page-fit': __( 'Fit to page', TD ) };
		return names[ zoom ] || ( zoom + '%' );
	}

	/** Text from a rendered (HTML) title, never markup. */
	function plain( html ) {
		var text = String( html ).replace( /<[^>]*>/g, '' );

		return wp.htmlEntities ? wp.htmlEntities.decodeEntities( text ) : text;
	}

	function docKey( a ) {
		return a.id ? 'a:' + a.id : 'u:' + ( a.url || '' );
	}

	function fetchDoc( a, force ) {
		var key = docKey( a );

		if ( ! force && docCache[ key ] ) {
			return docCache[ key ];
		}

		var query = a.id ? 'id=' + encodeURIComponent( a.id ) : 'url=' + encodeURIComponent( a.url );

		docCache[ key ] = apiFetch( { path: '/' + NS + '/doc?' + query } );
		docCache[ key ].catch( function () {
			delete docCache[ key ];
		} );

		return docCache[ key ];
	}

	/** The site's own explanation when a file link fails (a Drive file that isn't public...). */
	function explain( src ) {
		if ( src.indexOf( '/' + NS + '/file' ) === -1 ) {
			return Promise.resolve( '' );
		}

		return window.fetch( src, { credentials: 'same-origin' } ).then( function ( res ) {
			return res.headers.get( 'content-type' ) && res.headers.get( 'content-type' ).indexOf( 'json' ) !== -1 ? res.json() : {};
		} ).then( function ( body ) {
			return body && body.message ? body.message : '';
		} ).catch( function () {
			return '';
		} );
	}

	/**
	 * Load the document's details, draw a cover if there's none saved, and
	 * read its text and cover in the background the first time.
	 */
	function useDoc( a ) {
		var state = useState( { loading: false, doc: null, error: '', cover: '', working: false } );
		var s = state[ 0 ];
		var set = state[ 1 ];
		var has = a.id || a.url;
		var key = docKey( a );

		useEffect( function () {
			var live = true;

			if ( ! has ) {
				set( { loading: false, doc: null, error: '', cover: '', working: false } );
				return;
			}

			set( { loading: true, doc: null, error: '', cover: '', working: false } );

			fetchDoc( a ).then( function ( doc ) {
				if ( ! live ) {
					return;
				}

				set( function ( prev ) {
					return Object.assign( {}, prev, { loading: false, doc: doc, cover: doc.cover || '' } );
				} );

				var needs = ! doc.hasText || ! doc.cover;

				if ( needs && doc.canPrepare && ! prepared[ key ] && window.upvePrepare ) {
					prepared[ key ] = true;
					set( function ( prev ) {
						return Object.assign( {}, prev, { working: true } );
					} );

					window.upvePrepare.prepare( { id: doc.id, url: doc.url, src: doc.src }, { maxPages: 600 } ).then( function () {
						return fetchDoc( a, true );
					} ).then( function ( fresh ) {
						if ( live ) {
							set( function ( prev ) {
								return Object.assign( {}, prev, { doc: fresh, cover: fresh.cover || prev.cover, working: false } );
							} );
						}
					} ).catch( function () {
						return explain( doc.src ).then( function ( msg ) {
							if ( live ) {
								set( function ( prev ) {
									return Object.assign( {}, prev, { working: false, error: msg || __( 'This PDF couldn\'t be opened. Check the file or link.', TD ) } );
								} );
							}
						} );
					} );
				} else if ( ! doc.cover && window.upvePrepare ) {
					// No saved cover and no right to save one: draw it just for the preview.
					window.upvePrepare.open( doc.src ).then( function ( pdf ) {
						return window.upvePrepare.cover( pdf, 600 );
					} ).then( function ( data ) {
						if ( live ) {
							set( function ( prev ) {
								return Object.assign( {}, prev, { cover: data } );
							} );
						}
					} ).catch( function () {
						return explain( doc.src ).then( function ( msg ) {
							if ( live ) {
								set( function ( prev ) {
									return Object.assign( {}, prev, { error: msg || __( 'This PDF couldn\'t be opened. Check the file or link.', TD ) } );
								} );
							}
						} );
					} );
				}
			} ).catch( function ( err ) {
				if ( live ) {
					set( { loading: false, doc: null, error: ( err && err.message ) || __( 'This PDF couldn\'t be found.', TD ), cover: '', working: false } );
				}
			} );

			return function () {
				live = false;
			};
		}, [ key ] );

		return [ s, set ];
	}

	// PDF Viewer block ---------------------------------------------------------

	function ViewerPreview( props ) {
		var a = props.attributes;
		var s = props.state;
		var doc = s.doc || {};
		var title = a.title || doc.title || '';
		var cover = s.cover ? el( 'img', { src: s.cover, alt: '' } ) : el( 'span', { className: 'upve-ed-blank' }, s.loading || s.working ? el( c.Spinner ) : icons.file );
		var isBook = a.mode === 'flipbook';
		var label = a.label || ( isBook ? __( 'Open flipbook', TD ) : __( 'View PDF', TD ) );

		if ( a.lightbox ) {
			if ( a.trigger === 'link' ) {
				return el( 'p', { className: 'upve-ed-link' }, el( 'a', { href: '#', onClick: function ( e ) {
					e.preventDefault();
				} }, a.label || __( 'View PDF', TD ) ), ' ', el( 'span', { className: 'upve-ed-tag' }, __( 'Opens in a popup', TD ) ) );
			}

			if ( a.trigger === 'thumbnail' ) {
				return el( 'div', { className: 'upve-ed-thumb' }, el( 'span', { className: 'upve-ed-cover' }, cover ), el( 'strong', null, a.label || title ), el( 'span', { className: 'upve-ed-tag' }, __( 'Opens in a popup', TD ) ) );
			}

			return el( 'div', { className: 'upve-ed-button-wrap' }, el( 'span', { className: 'upve-ed-button' }, isBook ? icons.book : icons.file, label ), el( 'span', { className: 'upve-ed-tag' }, __( 'Opens in a popup', TD ) ) );
		}

		var height = a.height || D.height || '800px';
		var px = /^\d+px$/.test( height ) ? parseInt( height, 10 ) : 640;
		var showBar = a.toolbar ? a.toolbar === 'on' : D.toolbar;

		return el( 'div', { className: 'upve-ed-frame' + ( isBook ? ' is-book' : '' ), style: { height: Math.max( 320, Math.min( 560, px ) ) + 'px' } },
			showBar ? el( 'div', { className: 'upve-ed-bar' },
				el( 'span', { className: 'upve-ed-group' }, icons.side ),
				el( 'span', { className: 'upve-ed-group upve-ed-nav' }, icons.left, el( 'span', { className: 'upve-ed-page' }, '1' ), el( 'span', null, sprintf( __( 'of %d', TD ), doc.pages || 1 ) ), icons.right ),
				isBook ? null : el( 'span', { className: 'upve-ed-group' }, icons.zoomOut, el( 'span', { className: 'upve-ed-zoom' }, zoomLabel( a.zoom || D.zoom || 'auto' ) ), icons.zoomIn ),
				el( 'span', { className: 'upve-ed-group upve-ed-end' }, isBook ? null : icons.search, icons.download, icons.print, icons.full )
			) : null,
			el( 'div', { className: 'upve-ed-stage' },
				isBook ? el( 'div', { className: 'upve-ed-spread' }, el( 'span', { className: 'upve-ed-page-img is-empty' } ), el( 'span', { className: 'upve-ed-page-img' }, cover ) ) : el( 'span', { className: 'upve-ed-page-img' }, cover )
			),
			el( 'span', { className: 'upve-ed-badge' }, isBook ? icons.book : icons.file, title || __( 'PDF', TD ) )
		);
	}

	function RolesControl( props ) {
		var roles = data.roles || [];
		var value = props.value || [];

		return el( 'div', { className: 'upve-ed-roles' }, roles.map( function ( role ) {
			return el( c.CheckboxControl, {
				key: role.value,
				label: role.label,
				checked: value.indexOf( role.value ) !== -1,
				__nextHasNoMarginBottom: true,
				onChange: function ( on ) {
					var next = value.filter( function ( r ) {
						return r !== role.value;
					} );

					if ( on ) {
						next.push( role.value );
					}

					props.onChange( next );
				},
			} );
		} ) );
	}

	function ViewerEdit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = be.useBlockProps ? be.useBlockProps() : { className: props.className };
		var docState = useDoc( a );
		var s = docState[ 0 ];
		var setState = docState[ 1 ];
		var doc = s.doc;
		var urlState = useState( a.url || '' );
		var protecting = useState( false );

		if ( ! a.id && ! a.url ) {
			return el( 'div', blockProps, el( be.MediaPlaceholder, {
				icon: icons.file,
				labels: {
					title: __( 'PDF Viewer', TD ),
					instructions: __( 'Upload a PDF, pick one from the Media Library, or insert it from a link. Google Drive, Google Docs and Dropbox share links work too.', TD ),
				},
				accept: 'application/pdf',
				allowedTypes: [ 'application/pdf' ],
				onSelect: function ( media ) {
					if ( media && media.id ) {
						set( { id: media.id, url: '' } );
					}
				},
				onSelectURL: function ( url ) {
					set( { url: url, id: 0 } );
				},
			} ) );
		}

		function setProtected( on ) {
			protecting[ 1 ]( true );
			apiFetch( { path: '/' + NS + '/protect', method: 'POST', data: { id: a.id, protect: on } } ).then( function () {
				return fetchDoc( a, true );
			} ).then( function ( fresh ) {
				setState( function ( prev ) {
					return Object.assign( {}, prev, { doc: fresh } );
				} );
			} ).catch( function ( err ) {
				setState( function ( prev ) {
					return Object.assign( {}, prev, { error: ( err && err.message ) || '' } );
				} );
			} ).then( function () {
				protecting[ 1 ]( false );
			} );
		}

		function rereadText() {
			if ( ! doc || ! window.upvePrepare ) {
				return;
			}

			setState( function ( prev ) {
				return Object.assign( {}, prev, { working: true, error: '' } );
			} );

			window.upvePrepare.prepare( { id: doc.id, url: doc.url, src: doc.src } ).then( function () {
				return fetchDoc( a, true );
			} ).then( function ( fresh ) {
				setState( function ( prev ) {
					return Object.assign( {}, prev, { doc: fresh, cover: fresh.cover || prev.cover, working: false } );
				} );
			} ).catch( function () {
				setState( function ( prev ) {
					return Object.assign( {}, prev, { working: false, error: __( 'This PDF couldn\'t be read.', TD ) } );
				} );
			} );
		}

		var accessLabels = { all: __( 'Everyone', TD ), login: __( 'Logged-in users', TD ), roles: __( 'Some roles', TD ) };
		var secureOn = a.secure ? a.secure === 'on' : D.secure;
		var markOn = a.watermark ? a.watermark === 'on' : D.watermark;

		var inspector = el( be.InspectorControls, null,
			el( c.PanelBody, { title: __( 'Document', TD ) },
				doc ? el( 'p', { className: 'upve-ed-docinfo' },
					el( 'strong', null, doc.filename ),
					el( 'br' ),
					doc.pages ? sprintf( _n( '%d page', '%d pages', doc.pages, TD ), doc.pages ) : __( 'Page count appears after the first view.', TD ),
					doc.remote ? el( 'span', { className: 'upve-ed-note' }, el( 'br' ), __( 'Loaded through your site, so it works even when the other site blocks direct embedding.', TD ) ) : null
				) : null,
				a.url ? el( 'div', { className: 'upve-ed-url' },
					el( c.TextControl, {
						label: __( 'PDF link', TD ),
						value: urlState[ 0 ],
						onChange: urlState[ 1 ],
						__nextHasNoMarginBottom: true,
						help: __( 'A PDF address, or a Google Drive, Google Docs or Dropbox share link.', TD ),
					} ),
					el( c.Button, { variant: 'secondary', isSecondary: true, onClick: function () {
						set( { url: urlState[ 0 ].trim() } );
					} }, __( 'Use this link', TD ) )
				) : null,
				el( be.MediaUploadCheck, null, el( be.MediaUpload, {
					allowedTypes: [ 'application/pdf' ],
					value: a.id || undefined,
					onSelect: function ( media ) {
						if ( media && media.id ) {
							set( { id: media.id, url: '' } );
						}
					},
					render: function ( o ) {
						return el( c.Button, { variant: 'secondary', isSecondary: true, onClick: o.open }, a.id ? __( 'Replace PDF', TD ) : __( 'Use a Media Library PDF instead', TD ) );
					},
				} ) ),
				el( c.TextControl, {
					label: __( 'Title', TD ),
					value: a.title,
					placeholder: doc ? doc.title : '',
					help: __( 'Shown on the popup and the loading screen.', TD ),
					onChange: function ( v ) {
						set( { title: v } );
					},
				} ),
				el( c.TextControl, {
					label: __( 'Start on page', TD ),
					type: 'number',
					min: 1,
					value: a.page || 1,
					onChange: function ( v ) {
						set( { page: Math.max( 1, parseInt( v, 10 ) || 1 ) } );
					},
				} )
			),
			el( c.PanelBody, { title: __( 'Layout', TD ), initialOpen: true },
				pick( __( 'Show as', TD ), a.mode === 'flipbook' ? 'flipbook' : 'viewer', [
					{ value: 'viewer', label: __( 'PDF viewer', TD ) },
					{ value: 'flipbook', label: __( 'Flipbook (page-turn effect)', TD ) },
				], function ( v ) {
					set( { mode: v } );
				} ),
				el( c.ToggleControl, {
					label: __( 'Open in a popup', TD ),
					help: __( 'Show a button, link or cover on the page, and open the PDF in a lightbox.', TD ),
					checked: !! a.lightbox,
					onChange: function ( v ) {
						set( { lightbox: v } );
					},
				} ),
				a.lightbox ? pick( __( 'Popup opens from', TD ), a.trigger || 'button', [
					{ value: 'button', label: __( 'A button', TD ) },
					{ value: 'link', label: __( 'A text link', TD ) },
					{ value: 'thumbnail', label: __( 'A cover thumbnail', TD ) },
				], function ( v ) {
					set( { trigger: v } );
				} ) : null,
				a.lightbox ? el( c.TextControl, {
					label: __( 'Button or link text', TD ),
					value: a.label,
					placeholder: __( 'View PDF', TD ),
					onChange: function ( v ) {
						set( { label: v } );
					},
				} ) : null,
				a.lightbox ? null : el( c.TextControl, {
					label: __( 'Width', TD ),
					value: a.width,
					placeholder: D.width,
					help: __( 'Any CSS width: 100%, 720px, 60em.', TD ),
					onChange: function ( v ) {
						set( { width: v } );
					},
				} ),
				a.lightbox ? null : el( c.TextControl, {
					label: __( 'Height', TD ),
					value: a.height,
					placeholder: D.height,
					help: __( 'Any CSS height (800px, 80vh), or "auto" to fit the first page. It never gets taller than the screen.', TD ),
					onChange: function ( v ) {
						set( { height: v } );
					},
				} ),
				a.mode === 'flipbook' ? null : pick( __( 'Zoom', TD ), a.zoom || '', [
					siteDefault( zoomLabel( D.zoom || 'auto' ) ),
					{ value: 'auto', label: __( 'Automatic', TD ) },
					{ value: 'page-width', label: __( 'Fit to width', TD ) },
					{ value: 'page-fit', label: __( 'Fit to page', TD ) },
					{ value: '50', label: '50%' },
					{ value: '75', label: '75%' },
					{ value: '100', label: '100%' },
					{ value: '125', label: '125%' },
					{ value: '150', label: '150%' },
					{ value: '200', label: '200%' },
				], function ( v ) {
					set( { zoom: v } );
				} ),
				a.mode === 'flipbook' ? null : pick( __( 'Scrolling', TD ), a.scroll || '', [
					siteDefault( D.scroll === 'page' ? __( 'One page at a time', TD ) : __( 'Continuous', TD ) ),
					{ value: 'continuous', label: __( 'Continuous', TD ) },
					{ value: 'page', label: __( 'One page at a time', TD ) },
				], function ( v ) {
					set( { scroll: v } );
				} ),
				pick( __( 'Sidebar on load', TD ), a.sidebar || '', [
					siteDefault( { closed: __( 'Closed', TD ), thumbnails: __( 'Thumbnails', TD ), outline: __( 'Table of contents', TD ) }[ D.sidebar ] || __( 'Closed', TD ) ),
					{ value: 'closed', label: __( 'Closed', TD ) },
					{ value: 'thumbnails', label: __( 'Thumbnails', TD ) },
					{ value: 'outline', label: __( 'Table of contents', TD ) },
				], function ( v ) {
					set( { sidebar: v } );
				}, __( 'Opens on screens wide enough for it.', TD ) ),
				pick( __( 'Theme', TD ), a.theme || '', [
					siteDefault( { light: __( 'Light', TD ), dark: __( 'Dark', TD ), auto: __( 'Match the visitor', TD ) }[ D.theme ] || __( 'Light', TD ) ),
					{ value: 'light', label: __( 'Light', TD ) },
					{ value: 'dark', label: __( 'Dark', TD ) },
					{ value: 'auto', label: __( 'Match the visitor\'s device', TD ) },
				], function ( v ) {
					set( { theme: v } );
				} )
			),
			el( c.PanelBody, { title: __( 'Toolbar', TD ), initialOpen: false },
				tri( __( 'Toolbar', TD ), a.toolbar, D.toolbar, function ( v ) {
					set( { toolbar: v } );
				} ),
				tri( __( 'Download button', TD ), a.download, D.download, function ( v ) {
					set( { download: v } );
				}, secureOn || markOn ? __( 'Off while secure mode or a watermark is on.', TD ) : undefined ),
				tri( __( 'Print button', TD ), a.print, D.print, function ( v ) {
					set( { print: v } );
				}, secureOn ? __( 'Off while secure mode is on.', TD ) : undefined ),
				a.mode === 'flipbook' ? null : tri( __( 'Search', TD ), a.search, D.search, function ( v ) {
					set( { search: v } );
				} ),
				tri( __( 'Full screen button', TD ), a.fullscreen, D.fullscreen, function ( v ) {
					set( { fullscreen: v } );
				} )
			),
			el( c.PanelBody, { title: __( 'Protection', TD ), initialOpen: false },
				tri( __( 'Secure mode', TD ), a.secure, D.secure, function ( v ) {
					set( { secure: v } );
				}, __( 'Hides the file\'s address and turns off downloading, printing, copying and right-click. It makes copies harder to get, but no viewer can stop someone from saving what\'s on their screen.', TD ) ),
				pick( __( 'Who can view', TD ), a.access || '', [
					siteDefault( accessLabels[ D.access ] || accessLabels.all ),
					{ value: 'all', label: accessLabels.all },
					{ value: 'login', label: accessLabels.login },
					{ value: 'roles', label: __( 'Only these roles', TD ) },
				], function ( v ) {
					set( { access: v } );
				} ),
				a.access === 'roles' ? el( RolesControl, { value: a.roles, onChange: function ( v ) {
					set( { roles: v } );
				} } ) : null,
				tri( __( 'Watermark', TD ), a.watermark, D.watermark, function ( v ) {
					set( { watermark: v } );
				}, __( 'Stamps the text from the settings page (like the viewer\'s email) on every page. Turns off downloads, since the original file has no watermark.', TD ) ),
				doc && doc.canProtect && ! doc.protected && ( secureOn || ( a.access ? a.access !== 'all' : D.access !== 'all' ) ) ? el( c.Notice, { status: 'info', isDismissible: false, className: 'upve-ed-hint' }, __( 'The page never shows this PDF\'s address, but anyone who already has it can still open the file. Turn on "Hide this file\'s address" below to stop that.', TD ) ) : null,
				doc && doc.canProtect ? el( 'div', { className: 'upve-ed-protect' },
					el( c.ToggleControl, {
						label: __( 'Hide this file\'s address', TD ),
						checked: !! doc.protected,
						disabled: protecting[ 0 ],
						help: doc.protected ? __( 'The file has a random name and only this plugin\'s viewer can open it. Old links to it no longer work.', TD ) : __( 'Gives the PDF a random file name and keeps its address out of your pages, so only the viewer can open it. Links to its current address stop working.', TD ),
						onChange: setProtected,
					} )
				) : null
			),
			el( c.PanelBody, { title: __( 'SEO and speed', TD ), initialOpen: false },
				tri( __( 'Searchable text', TD ), a.text, D.text, function ( v ) {
					set( { text: v } );
				}, __( 'Adds the PDF\'s text under the viewer in a collapsed section, so search engines can index it. Never shown for secure or restricted documents.', TD ) ),
				doc ? el( 'p', { className: 'upve-ed-status' },
					s.working ? el( c.Spinner ) : null,
					s.working ? __( 'Reading the PDF\'s text and cover…', TD ) : ( doc.hasText ? __( 'Text saved.', TD ) : __( 'No text saved yet.', TD ) ),
					' ',
					! s.working && doc.canPrepare ? el( c.Button, { variant: 'link', isLink: true, onClick: rereadText }, doc.hasText ? __( 'Read it again', TD ) : __( 'Read it now', TD ) ) : null
				) : null,
				tri( __( 'Lazy loading', TD ), a.lazy, D.lazy, function ( v ) {
					set( { lazy: v } );
				}, __( 'The viewer loads when it scrolls into view.', TD ) )
			),
			el( c.PanelBody, { title: __( 'Site defaults', TD ), initialOpen: false },
				el( 'p', null, __( 'Anything left on "Site default" follows Content Powered > Ultimate PDF Viewer and Embedder.', TD ) ),
				el( c.ExternalLink || 'a', { href: data.settingsUrl }, __( 'Open the settings', TD ) )
			)
		);

		var advanced = be.InspectorAdvancedControls ? el( be.InspectorAdvancedControls, null, el( c.TextControl, {
			label: __( 'Link name', TD ),
			value: a.anchor,
			help: __( 'Used in links to a page of this PDF, like #name&page=12. Letters, numbers and dashes.', TD ),
			onChange: function ( v ) {
				set( { anchor: v.replace( /[^A-Za-z0-9_-]/g, '' ) } );
			},
		} ) ) : null;

		var replace = be.MediaReplaceFlow && a.id ? el( be.BlockControls, { group: 'other' }, el( be.MediaReplaceFlow, {
			mediaId: a.id,
			allowedTypes: [ 'application/pdf' ],
			accept: 'application/pdf',
			onSelect: function ( media ) {
				if ( media && media.id ) {
					set( { id: media.id, url: '' } );
				}
			},
			name: __( 'Replace', TD ),
		} ) ) : null;

		return el( Fragment, null,
			replace,
			inspector,
			advanced,
			el( 'div', blockProps,
				s.error ? el( c.Notice, { status: 'warning', isDismissible: false }, s.error ) : null,
				el( ViewerPreview, { attributes: a, state: s } )
			)
		);
	}

	wp.blocks.registerBlockType( 'ultimate-pdf-viewer-embedder/viewer', {
		apiVersion: 3,
		title: __( 'PDF Viewer', TD ),
		description: __( 'Show a PDF on the page in a fast viewer, a flipbook or a popup.', TD ),
		category: 'media',
		icon: icons.file,
		keywords: [ 'pdf', __( 'document', TD ), __( 'flipbook', TD ), __( 'embed', TD ), __( 'brochure', TD ), __( 'menu', TD ) ],
		supports: { align: [ 'wide', 'full' ], html: false },
		edit: ViewerEdit,
		save: function () {
			return null;
		},
		transforms: {
			from: [
				{
					type: 'block',
					blocks: [ 'core/file' ],
					isMatch: function ( attrs ) {
						return !! attrs.id || /\.pdf($|\?)/i.test( attrs.href || '' );
					},
					transform: function ( attrs ) {
						return wp.blocks.createBlock( 'ultimate-pdf-viewer-embedder/viewer', attrs.id ? { id: attrs.id } : { url: attrs.href } );
					},
				},
				{
					type: 'shortcode',
					tag: 'pdf_viewer',
					attributes: {
						id: { type: 'number', shortcode: function ( attrs ) {
							return parseInt( attrs.named.id, 10 ) || 0;
						} },
						url: { type: 'string', shortcode: function ( attrs ) {
							return attrs.named.url || '';
						} },
					},
				},
			],
		},
	} );

	// PDF Library block --------------------------------------------------------

	function LibraryEdit( props ) {
		var a = props.attributes;
		var set = props.setAttributes;
		var blockProps = be.useBlockProps ? be.useBlockProps() : { className: props.className };
		var titles = useState( {} );
		var bump = useState( 0 );
		var working = useState( false );
		var ids = a.ids || [];
		var idsKey = ids.join( ',' );
		var mounted = useRef( true );

		useEffect( function () {
			mounted.current = true;
			return function () {
				mounted.current = false;
			};
		}, [] );

		// Titles of the picked PDFs.
		useEffect( function () {
			if ( ! ids.length ) {
				return;
			}

			apiFetch( { path: '/wp/v2/media?per_page=100&include=' + idsKey + '&_fields=id,title' } ).then( function ( items ) {
				var map = {};

				( items || [] ).forEach( function ( item ) {
					map[ item.id ] = item.title && item.title.rendered ? plain( item.title.rendered ) : '#' + item.id;
				} );

				if ( mounted.current ) {
					titles[ 1 ]( map );
				}
			} ).catch( function () {} );
		}, [ idsKey ] );

		// Make covers (and text) for picked PDFs that have none yet.
		useEffect( function () {
			if ( ! ids.length || ! window.upvePrepare ) {
				return;
			}

			var queue = ids.slice( 0, 40 );
			var changed = false;

			function nextOne() {
				var id = queue.shift();

				if ( ! id ) {
					if ( mounted.current ) {
						working[ 1 ]( false );

						if ( changed ) {
							bump[ 1 ]( function ( n ) {
								return n + 1;
							} );
						}
					}
					return;
				}

				var key = 'a:' + id;

				fetchDoc( { id: id } ).then( function ( doc ) {
					if ( ( ! doc.cover || ! doc.hasText ) && doc.canPrepare && ! prepared[ key ] ) {
						prepared[ key ] = true;

						if ( mounted.current ) {
							working[ 1 ]( true );
						}

						return window.upvePrepare.prepare( { id: doc.id, src: doc.src }, { maxPages: 600 } ).then( function () {
							changed = true;
							delete docCache[ key ];
						} );
					}
				} ).catch( function () {} ).then( nextOne );
			}

			nextOne();
		}, [ idsKey ] );

		function move( index, dir ) {
			var next = ids.slice();
			var to = index + dir;

			if ( to < 0 || to >= next.length ) {
				return;
			}

			next.splice( to, 0, next.splice( index, 1 )[ 0 ] );
			set( { ids: next } );
		}

		var picker = el( be.MediaUploadCheck, null, el( be.MediaUpload, {
			allowedTypes: [ 'application/pdf' ],
			multiple: true,
			value: ids,
			onSelect: function ( items ) {
				set( { ids: ( items || [] ).filter( function ( item ) {
					return ! item.mime || item.mime === 'application/pdf';
				} ).map( function ( item ) {
					return item.id;
				} ) } );
			},
			render: function ( o ) {
				return el( c.Button, { variant: ids.length ? 'secondary' : 'primary', isPrimary: ! ids.length, isSecondary: !! ids.length, onClick: o.open }, ids.length ? __( 'Change PDFs', TD ) : __( 'Choose PDFs', TD ) );
			},
		} ) );

		var inspector = el( be.InspectorControls, null,
			el( c.PanelBody, { title: __( 'Documents', TD ) },
				pick( __( 'Show', TD ), a.source || 'selected', [
					{ value: 'selected', label: __( 'PDFs I pick', TD ) },
					{ value: 'attached', label: __( 'PDFs uploaded to this page', TD ) },
					{ value: 'all', label: __( 'Every PDF in the Media Library', TD ) },
				], function ( v ) {
					set( { source: v } );
				} ),
				a.source === 'selected' || ! a.source ? el( Fragment, null,
					picker,
					ids.length ? el( 'ul', { className: 'upve-ed-list' }, ids.map( function ( id, i ) {
						return el( 'li', { key: id },
							el( 'span', { className: 'upve-ed-list-title' }, titles[ 0 ][ id ] || '#' + id ),
							el( c.Button, { icon: icons.up, label: __( 'Move up', TD ), disabled: i === 0, onClick: function () {
								move( i, -1 );
							} } ),
							el( c.Button, { icon: icons.down, label: __( 'Move down', TD ), disabled: i === ids.length - 1, onClick: function () {
								move( i, 1 );
							} } ),
							el( c.Button, { icon: icons.remove, label: __( 'Remove', TD ), onClick: function () {
								set( { ids: ids.filter( function ( x ) {
									return x !== id;
								} ) } );
							} } )
						);
					} ) ) : null
				) : el( c.RangeControl, {
					label: __( 'How many', TD ),
					value: a.limit || 12,
					min: 1,
					max: 60,
					onChange: function ( v ) {
						set( { limit: v } );
					},
				} ),
				pick( __( 'Order', TD ), a.orderby || 'selected', [
					{ value: 'selected', label: a.source === 'selected' || ! a.source ? __( 'The order above', TD ) : __( 'Newest first', TD ) },
					{ value: 'title', label: __( 'Title (A to Z)', TD ) },
					{ value: 'date', label: __( 'Newest first', TD ) },
				], function ( v ) {
					set( { orderby: v } );
				} ),
				working[ 0 ] ? el( 'p', { className: 'upve-ed-status' }, el( c.Spinner ), __( 'Making covers…', TD ) ) : null
			),
			el( c.PanelBody, { title: __( 'Layout', TD ) },
				el( c.RangeControl, {
					label: __( 'Columns', TD ),
					value: a.columns || 3,
					min: 1,
					max: 6,
					onChange: function ( v ) {
						set( { columns: v } );
					},
				} ),
				el( c.ToggleControl, {
					label: __( 'Show titles', TD ),
					checked: a.titles !== false,
					onChange: function ( v ) {
						set( { titles: v } );
					},
				} ),
				pick( __( 'Search box', TD ), a.search || '', [
					{ value: '', label: __( 'When there are more than 8 PDFs', TD ) },
					{ value: 'on', label: __( 'Always', TD ) },
					{ value: 'off', label: __( 'Never', TD ) },
				], function ( v ) {
					set( { search: v } );
				} ),
				pick( __( 'Clicking a PDF', TD ), a.open || 'lightbox', [
					{ value: 'lightbox', label: __( 'Opens it in a popup viewer', TD ) },
					{ value: 'tab', label: __( 'Opens the file in a new tab', TD ) },
				], function ( v ) {
					set( { open: v } );
				} ),
				a.open === 'tab' ? null : pick( __( 'Popup shows', TD ), a.mode || 'viewer', [
					{ value: 'viewer', label: __( 'PDF viewer', TD ) },
					{ value: 'flipbook', label: __( 'Flipbook', TD ) },
				], function ( v ) {
					set( { mode: v } );
				} )
			)
		);

		var empty = ( a.source === 'selected' || ! a.source ) && ! ids.length;

		return el( Fragment, null,
			inspector,
			el( 'div', blockProps,
				empty ? el( c.Placeholder, {
					icon: icons.library,
					label: __( 'PDF Library', TD ),
					instructions: __( 'A grid of PDFs with cover images, for menus, reports, brochures and resource pages. Visitors open them in a popup viewer.', TD ),
				}, picker ) : el( 'div', { className: 'upve-ed-library' }, el( ServerSideRender, {
					key: bump[ 0 ],
					block: 'ultimate-pdf-viewer-embedder/library',
					attributes: a,
					urlQueryArgs: { post_id: wp.data && wp.data.select( 'core/editor' ) ? wp.data.select( 'core/editor' ).getCurrentPostId() : undefined },
				} ) )
			)
		);
	}

	wp.blocks.registerBlockType( 'ultimate-pdf-viewer-embedder/library', {
		apiVersion: 3,
		title: __( 'PDF Library', TD ),
		description: __( 'A grid of PDFs with cover thumbnails that open in a popup viewer.', TD ),
		category: 'media',
		icon: icons.library,
		keywords: [ 'pdf', __( 'documents', TD ), __( 'downloads', TD ), __( 'resources', TD ), __( 'catalog', TD ), __( 'reports', TD ) ],
		supports: { align: [ 'wide', 'full' ], html: false },
		edit: LibraryEdit,
		save: function () {
			return null;
		},
	} );
} )( window.wp );
