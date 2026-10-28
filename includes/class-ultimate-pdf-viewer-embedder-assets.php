<?php
/**
 * Front-end files: loaded only on pages that show a PDF.
 *
 * The viewer script is small. It waits until a viewer scrolls into view
 * (unless lazy loading is off), and only then loads PDF.js (bundled in
 * assets/pdfjs, from this site, never a CDN). Pages without a PDF get
 * nothing at all.
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Script and style registration.
 */
final class Ultimate_PDF_Viewer_Embedder_Assets {

	/**
	 * Handle of the viewer script and stylesheet.
	 */
	const HANDLE = 'upve-viewer';

	/**
	 * Bundled PDF.js version (see bin/build-pdfjs.sh).
	 */
	const PDFJS_VERSION = '6.4.299';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_early' ) );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'editor_styles' ) );
	}

	/**
	 * Register the files (they're only enqueued where needed).
	 *
	 * @return void
	 */
	public static function register() {
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_style( self::HANDLE, ULTIMATE_PDF_VIEWER_EMBEDDER_URL . 'assets/viewer.css', array(), ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION );
		wp_register_script( self::HANDLE, ULTIMATE_PDF_VIEWER_EMBEDDER_URL . 'assets/viewer' . $min . '.js', array(), ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION, true );
	}

	/**
	 * Add the colors and the browser config, once, when the files are used.
	 *
	 * @return void
	 */
	private static function add_data() {
		static $done = false;

		if ( $done ) {
			return;
		}

		$done = true;

		wp_add_inline_style( self::HANDLE, self::color_css() );
		wp_localize_script( self::HANDLE, 'upveConfig', self::browser_config() );
	}

	/**
	 * Colors from the settings page, as CSS variables.
	 *
	 * @return string
	 */
	private static function color_css() {
		$s      = ultimate_pdf_viewer_embedder_plugin()->get_settings();
		$colors = array(
			'--upve-bar'    => array( $s['color_bar'], '#ffffff' ),
			'--upve-fg'     => array( $s['color_text'], '#1f2937' ),
			'--upve-accent' => array( $s['color_accent'], '#2563eb' ),
			'--upve-bg'     => array( $s['color_bg'], '#e9edf2' ),
		);
		$css    = '';

		foreach ( $colors as $var => $pair ) {
			$hex  = sanitize_hex_color( (string) $pair[0] );
			$css .= $var . ':' . ( $hex ? $hex : $pair[1] ) . ';';
		}

		$css .= '--upve-radius:' . max( 0, min( 24, (int) $s['radius'] ) ) . 'px;';

		return '.upve,.upve-lightbox,.upve-library{' . $css . '}';
	}

	/**
	 * What the browser needs: where PDF.js lives, the API and the labels.
	 *
	 * @return array<string,mixed>
	 */
	public static function browser_config() {
		$base = ULTIMATE_PDF_VIEWER_EMBEDDER_URL . 'assets/pdfjs/';
		$ver  = '?ver=' . self::PDFJS_VERSION;

		return array(
			'pdfjs' => array(
				'lib'    => $base . 'pdf.min.js' . $ver,
				'worker' => $base . 'pdf.worker.min.js' . $ver,
				'viewer' => $base . 'pdf_viewer.min.js' . $ver,
				'css'    => $base . 'pdf_viewer.min.css' . $ver,
				'cmaps'  => $base . 'cmaps/',
				'fonts'  => $base . 'standard_fonts/',
				'wasm'   => $base . 'wasm/',
				'iccs'   => $base . 'iccs/',
			),
			'api'   => esc_url_raw( rest_url( Ultimate_PDF_Viewer_Embedder_Rest::NS . '/' ) ),
			'icons' => self::icons(),
			'i18n'  => array(
				'loading'    => __( 'Loading PDF…', 'ultimate-pdf-viewer-embedder' ),
				/* translators: %d: percent of the PDF downloaded. */
				'loadingPct' => __( 'Loading PDF… %d%%', 'ultimate-pdf-viewer-embedder' ),
				'failed'     => __( 'This PDF couldn\'t be loaded.', 'ultimate-pdf-viewer-embedder' ),
				'retry'      => __( 'Try again', 'ultimate-pdf-viewer-embedder' ),
				'openFile'   => __( 'Open the file', 'ultimate-pdf-viewer-embedder' ),
				'password'   => __( 'This PDF is password protected.', 'ultimate-pdf-viewer-embedder' ),
				'wrongPass'  => __( 'That password isn\'t right. Try again.', 'ultimate-pdf-viewer-embedder' ),
				'unlock'     => __( 'Open', 'ultimate-pdf-viewer-embedder' ),
				'passLabel'  => __( 'Password', 'ultimate-pdf-viewer-embedder' ),
				'login'      => __( 'Log in', 'ultimate-pdf-viewer-embedder' ),
				'sidebar'    => __( 'Toggle sidebar', 'ultimate-pdf-viewer-embedder' ),
				'pages'      => __( 'Pages', 'ultimate-pdf-viewer-embedder' ),
				'contents'   => __( 'Contents', 'ultimate-pdf-viewer-embedder' ),
				'prev'       => __( 'Previous page', 'ultimate-pdf-viewer-embedder' ),
				'next'       => __( 'Next page', 'ultimate-pdf-viewer-embedder' ),
				'page'       => __( 'Page', 'ultimate-pdf-viewer-embedder' ),
				/* translators: %d: page number. */
				'pageN'      => __( 'Page %d', 'ultimate-pdf-viewer-embedder' ),
				/* translators: %d: number of pages. */
				'of'         => __( 'of %d', 'ultimate-pdf-viewer-embedder' ),
				'zoomIn'     => __( 'Zoom in', 'ultimate-pdf-viewer-embedder' ),
				'zoomOut'    => __( 'Zoom out', 'ultimate-pdf-viewer-embedder' ),
				'zoom'       => __( 'Zoom', 'ultimate-pdf-viewer-embedder' ),
				'auto'       => __( 'Automatic', 'ultimate-pdf-viewer-embedder' ),
				'fitWidth'   => __( 'Fit to width', 'ultimate-pdf-viewer-embedder' ),
				'fitPage'    => __( 'Fit to page', 'ultimate-pdf-viewer-embedder' ),
				'search'     => __( 'Search', 'ultimate-pdf-viewer-embedder' ),
				'searchPh'   => __( 'Search this PDF', 'ultimate-pdf-viewer-embedder' ),
				/* translators: 1: current match, 2: number of matches. */
				'matches'    => __( '%1$d of %2$d', 'ultimate-pdf-viewer-embedder' ),
				'noMatches'  => __( 'No matches', 'ultimate-pdf-viewer-embedder' ),
				'prevMatch'  => __( 'Previous match', 'ultimate-pdf-viewer-embedder' ),
				'nextMatch'  => __( 'Next match', 'ultimate-pdf-viewer-embedder' ),
				'close'      => __( 'Close', 'ultimate-pdf-viewer-embedder' ),
				'continuous' => __( 'Continuous scrolling', 'ultimate-pdf-viewer-embedder' ),
				'single'     => __( 'One page at a time', 'ultimate-pdf-viewer-embedder' ),
				'share'      => __( 'Copy link to this page', 'ultimate-pdf-viewer-embedder' ),
				'copied'     => __( 'Link copied', 'ultimate-pdf-viewer-embedder' ),
				'download'   => __( 'Download', 'ultimate-pdf-viewer-embedder' ),
				'print'      => __( 'Print', 'ultimate-pdf-viewer-embedder' ),
				/* translators: 1: page being prepared, 2: number of pages. */
				'printing'   => __( 'Preparing to print… %1$d of %2$d', 'ultimate-pdf-viewer-embedder' ),
				'fullscreen' => __( 'Full screen', 'ultimate-pdf-viewer-embedder' ),
				'exitFull'   => __( 'Exit full screen', 'ultimate-pdf-viewer-embedder' ),
				'more'       => __( 'More options', 'ultimate-pdf-viewer-embedder' ),
				'toolbar'    => __( 'PDF toolbar', 'ultimate-pdf-viewer-embedder' ),
				'noOutline'  => __( 'This PDF has no table of contents.', 'ultimate-pdf-viewer-embedder' ),
				'flipPrev'   => __( 'Previous pages', 'ultimate-pdf-viewer-embedder' ),
				'flipNext'   => __( 'Next pages', 'ultimate-pdf-viewer-embedder' ),
				'dialog'     => __( 'PDF viewer', 'ultimate-pdf-viewer-embedder' ),
				'protected'  => __( 'Downloading, printing and copying are turned off for this document.', 'ultimate-pdf-viewer-embedder' ),
			),
		);
	}

	/**
	 * Toolbar icons (Lucide, bundled), keyed by name.
	 *
	 * @return array<string,string>
	 */
	private static function icons() {
		$names = array( 'panel-left', 'chevron-left', 'chevron-right', 'chevron-up', 'chevron-down', 'zoom-in', 'zoom-out', 'search', 'x', 'download', 'printer', 'maximize', 'minimize', 'ellipsis-vertical', 'link', 'rows-3', 'file', 'list-tree', 'layout-grid', 'lock', 'triangle-alert', 'book-open', 'key-round' );
		$icons = array();

		foreach ( $names as $name ) {
			$icons[ $name ] = Ultimate_PDF_Viewer_Embedder_Icons::get( $name, 18 );
		}

		return $icons;
	}

	/**
	 * Load the viewer files (called while drawing a viewer or library).
	 *
	 * @return void
	 */
	public static function enqueue() {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			self::register();
		}

		self::add_data();
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );
	}

	/**
	 * Only the viewer stylesheet and colors (the settings page preview).
	 *
	 * @return void
	 */
	public static function enqueue_styles_only() {
		self::add_data();
		wp_enqueue_style( self::HANDLE );
	}

	/**
	 * Load the stylesheet in the <head> on single pages whose content has a
	 * PDF block or shortcode, so the box is styled before the script runs.
	 * Anywhere else, drawing a viewer loads the files on its own.
	 *
	 * @return void
	 */
	public static function maybe_enqueue_early() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$content = (string) $post->post_content;

		if ( has_block( Ultimate_PDF_Viewer_Embedder_Block::VIEWER, $content ) || has_block( Ultimate_PDF_Viewer_Embedder_Block::LIBRARY, $content ) || has_shortcode( $content, 'pdf_viewer' ) || has_shortcode( $content, 'pdf_library' ) ) {
			self::enqueue();
		}
	}

	/**
	 * The viewer stylesheet in the block editor too (its iframe included),
	 * so previews look like the site.
	 *
	 * @return void
	 */
	public static function editor_styles() {
		if ( is_admin() ) {
			self::add_data();
			wp_enqueue_style( self::HANDLE );
		}
	}
}
