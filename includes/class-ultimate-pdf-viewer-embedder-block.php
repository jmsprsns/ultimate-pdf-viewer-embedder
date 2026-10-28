<?php
/**
 * The PDF Viewer and PDF Library blocks.
 *
 * Both are dynamic: the server draws them (Ultimate_PDF_Viewer_Embedder_Render),
 * so fixes and setting changes reach every embed without re-saving posts.
 * The editor side (assets/block.js) is plain JavaScript, no build step.
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Block registration and editor assets.
 */
final class Ultimate_PDF_Viewer_Embedder_Block {

	/**
	 * Viewer block name.
	 */
	const VIEWER = 'ultimate-pdf-viewer-embedder/viewer';

	/**
	 * Library block name.
	 */
	const LIBRARY = 'ultimate-pdf-viewer-embedder/library';

	/**
	 * Editor script handle.
	 */
	const EDITOR = 'upve-block';

	/**
	 * Shared PDF.js helper (text and cover extraction) handle.
	 */
	const PREPARE = 'upve-prepare';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Viewer block attributes. Empty strings mean "use the site default".
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function viewer_attributes() {
		$string = array(
			'type'    => 'string',
			'default' => '',
		);

		return array(
			'id'         => array(
				'type'    => 'number',
				'default' => 0,
			),
			'url'        => $string,
			'mode'       => array(
				'type'    => 'string',
				'default' => 'viewer',
			),
			'lightbox'   => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'trigger'    => array(
				'type'    => 'string',
				'default' => 'button',
			),
			'label'      => $string,
			'title'      => $string,
			'width'      => $string,
			'height'     => $string,
			'page'       => array(
				'type'    => 'number',
				'default' => 1,
			),
			'zoom'       => $string,
			'scroll'     => $string,
			'sidebar'    => $string,
			'toolbar'    => $string,
			'download'   => $string,
			'print'      => $string,
			'search'     => $string,
			'fullscreen' => $string,
			'theme'      => $string,
			'secure'     => $string,
			'access'     => $string,
			'roles'      => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => array( 'type' => 'string' ),
			),
			'watermark'  => $string,
			'text'       => $string,
			'lazy'       => $string,
			'anchor'     => $string,
			// No defaults: WordPress adds the align and custom class names itself.
			'align'      => array( 'type' => 'string' ),
			'className'  => array( 'type' => 'string' ),
		);
	}

	/**
	 * Library block attributes.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function library_attributes() {
		return array(
			'ids'       => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => array( 'type' => 'number' ),
			),
			'source'    => array(
				'type'    => 'string',
				'default' => 'selected',
			),
			'limit'     => array(
				'type'    => 'number',
				'default' => 12,
			),
			'columns'   => array(
				'type'    => 'number',
				'default' => 3,
			),
			'open'      => array(
				'type'    => 'string',
				'default' => 'lightbox',
			),
			'mode'      => array(
				'type'    => 'string',
				'default' => 'viewer',
			),
			'search'    => array(
				'type'    => 'string',
				'default' => '',
			),
			'titles'    => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'orderby'   => array(
				'type'    => 'string',
				'default' => 'selected',
			),
			'align'     => array( 'type' => 'string' ),
			'className' => array( 'type' => 'string' ),
		);
	}

	/**
	 * Register the blocks and their editor assets.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script( self::PREPARE, ULTIMATE_PDF_VIEWER_EMBEDDER_URL . 'assets/prepare.js', array( 'wp-api-fetch' ), ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION, true );

		wp_register_script(
			self::EDITOR,
			ULTIMATE_PDF_VIEWER_EMBEDDER_URL . 'assets/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n', 'wp-api-fetch', 'wp-server-side-render', 'wp-data', 'wp-html-entities', self::PREPARE ),
			ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION,
			true
		);

		wp_register_style( self::EDITOR, ULTIMATE_PDF_VIEWER_EMBEDDER_URL . 'assets/block-editor.css', array(), ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::EDITOR, 'ultimate-pdf-viewer-embedder' );
		}

		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_data' ) );

		register_block_type(
			self::VIEWER,
			array(
				'api_version'     => 3,
				'editor_script'   => self::EDITOR,
				'editor_style'    => self::EDITOR,
				'render_callback' => array( __CLASS__, 'render_viewer' ),
				'attributes'      => self::viewer_attributes(),
				'supports'        => array(
					'align'    => array( 'wide', 'full' ),
					'html'     => false,
					'multiple' => true,
				),
			)
		);

		register_block_type(
			self::LIBRARY,
			array(
				'api_version'     => 3,
				'editor_script'   => self::EDITOR,
				'editor_style'    => self::EDITOR,
				'render_callback' => array( __CLASS__, 'render_library' ),
				'attributes'      => self::library_attributes(),
				'supports'        => array(
					'align' => array( 'wide', 'full' ),
					'html'  => false,
				),
			)
		);
	}

	/**
	 * Settings, roles and PDF.js paths for the editor.
	 *
	 * @return void
	 */
	public static function editor_data() {
		$s     = ultimate_pdf_viewer_embedder_plugin()->get_settings();
		$roles = array();

		foreach ( wp_roles()->roles as $key => $role ) {
			$roles[] = array(
				'value' => $key,
				'label' => translate_user_role( $role['name'] ),
			);
		}

		wp_localize_script(
			self::EDITOR,
			'upveBlock',
			array(
				'settingsUrl' => admin_url( 'admin.php?page=' . Ultimate_PDF_Viewer_Embedder_Admin::PAGE ),
				'roles'       => $roles,
				'remote'      => (bool) $s['remote'],
				'defaults'    => array(
					'width'      => $s['width'],
					'height'     => $s['height'],
					'zoom'       => $s['zoom'],
					'scroll'     => $s['scroll'],
					'sidebar'    => $s['sidebar'],
					'theme'      => $s['theme'],
					'toolbar'    => (bool) $s['toolbar'],
					'download'   => (bool) $s['btn_download'],
					'print'      => (bool) $s['btn_print'],
					'search'     => (bool) $s['btn_search'],
					'fullscreen' => (bool) $s['btn_fullscreen'],
					'secure'     => (bool) $s['secure'],
					'access'     => $s['access'],
					'watermark'  => (bool) $s['watermark'],
					'text'       => (bool) $s['seo_text'],
					'lazy'       => (bool) $s['lazy'],
				),
			)
		);

		wp_localize_script( self::PREPARE, 'upvePrepareConfig', self::prepare_config() );
	}

	/**
	 * Config for the shared PDF.js helper.
	 *
	 * @return array<string,mixed>
	 */
	public static function prepare_config() {
		$config = Ultimate_PDF_Viewer_Embedder_Assets::browser_config();

		return array(
			'pdfjs' => $config['pdfjs'],
			'ns'    => Ultimate_PDF_Viewer_Embedder_Rest::NS,
		);
	}

	/**
	 * Draw the viewer block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_viewer( $attributes ) {
		$a = wp_parse_args( is_array( $attributes ) ? $attributes : array(), array( 'className' => '' ) );

		$a['lightbox'] = ! empty( $a['lightbox'] ) ? 'yes' : 'no';
		$a['class']    = isset( $a['className'] ) ? (string) $a['className'] : '';
		$a['roles']    = isset( $a['roles'] ) && is_array( $a['roles'] ) ? $a['roles'] : array();
		$a['align']    = isset( $a['align'] ) ? (string) $a['align'] : '';
		unset( $a['className'] );

		return Ultimate_PDF_Viewer_Embedder_Render::viewer( $a );
	}

	/**
	 * Draw the library block.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function render_library( $attributes ) {
		$a = is_array( $attributes ) ? $attributes : array();

		$a['class']  = isset( $a['className'] ) ? (string) $a['className'] : '';
		$a['titles'] = ! isset( $a['titles'] ) || $a['titles'] ? 'yes' : 'no';
		$a['align']  = isset( $a['align'] ) ? (string) $a['align'] : '';
		unset( $a['className'] );

		return Ultimate_PDF_Viewer_Embedder_Render::library( $a );
	}
}
