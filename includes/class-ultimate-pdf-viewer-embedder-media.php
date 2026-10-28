<?php
/**
 * The "Add PDF" button in the classic editor, beside "Add Media".
 *
 * It opens the Media Library filtered to PDFs and inserts [pdf_viewer] (or
 * [pdf_library] when several PDFs are picked). The block editor has the
 * PDF Viewer and PDF Library blocks instead, and every PDF's details in the
 * Media Library show its shortcode (see the Protect class).
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classic editor button.
 */
final class Ultimate_PDF_Viewer_Embedder_Media {

	/**
	 * Script handle.
	 */
	const HANDLE = 'upve-media';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'media_buttons', array( __CLASS__, 'button' ), 15 );
	}

	/**
	 * Print the button and load its script.
	 *
	 * @param string $editor_id Editor ID.
	 * @return void
	 */
	public static function button( $editor_id = 'content' ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_script( self::HANDLE, ULTIMATE_PDF_VIEWER_EMBEDDER_URL . 'assets/media.js', array( 'jquery', 'media-editor' ), ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION, true );
		wp_localize_script(
			self::HANDLE,
			'upveMedia',
			array(
				'title'  => __( 'Add PDF', 'ultimate-pdf-viewer-embedder' ),
				'button' => __( 'Insert PDF viewer', 'ultimate-pdf-viewer-embedder' ),
				'notPdf' => __( 'Pick PDF files only.', 'ultimate-pdf-viewer-embedder' ),
			)
		);

		printf(
			'<button type="button" class="button upve-add-pdf" data-editor="%1$s"><span class="wp-media-buttons-icon" style="display:inline-flex;align-items:center;justify-content:center;vertical-align:text-top">%2$s</span> %3$s</button>',
			esc_attr( (string) $editor_id ),
			Ultimate_PDF_Viewer_Embedder_Icons::get( 'file-text', 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			esc_html__( 'Add PDF', 'ultimate-pdf-viewer-embedder' )
		);
	}
}
