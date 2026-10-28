/**
 * "Add PDF" button in the classic editor: pick PDFs from the Media Library
 * and insert [pdf_viewer] (one) or [pdf_library] (several).
 *
 * upveMedia.title, .button and .notPdf come from PHP
 * (Ultimate_PDF_Viewer_Embedder_Media::button).
 */

/* global upveMedia, wp, jQuery */
( function ( $ ) {
	'use strict';

	var frame;
	var target = 'content';

	$( document ).on( 'click', '.upve-add-pdf', function ( event ) {
		event.preventDefault();
		target = $( this ).data( 'editor' ) || 'content';

		if ( ! frame ) {
			frame = wp.media( {
				title: upveMedia.title,
				button: { text: upveMedia.button },
				library: { type: 'application/pdf' },
				multiple: 'add',
			} );

			frame.on( 'select', function () {
				var ids = [];

				frame.state().get( 'selection' ).each( function ( item ) {
					if ( item.get( 'mime' ) === 'application/pdf' ) {
						ids.push( parseInt( item.get( 'id' ), 10 ) );
					}
				} );

				if ( ! ids.length ) {
					window.alert( upveMedia.notPdf );
					return;
				}

				var code = ids.length === 1
					? '[pdf_viewer id="' + ids[ 0 ] + '"]'
					: '[pdf_library ids="' + ids.join( ',' ) + '"]';

				// insert() writes into whichever editor wpActiveEditor names.
				window.wpActiveEditor = target;
				wp.media.editor.insert( code );
			} );
		}

		frame.open();
	} );
} )( jQuery );
