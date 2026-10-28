<?php
/**
 * Main plugin orchestrator: owns the settings and boots every part.
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Ultimate_PDF_Viewer_Embedder_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'ultimate_pdf_viewer_embedder_settings';

	/**
	 * Single shared instance.
	 *
	 * @var Ultimate_PDF_Viewer_Embedder_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings for this request, defaults filled in.
	 *
	 * @var array<string,mixed>|null
	 */
	private $settings = null;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Ultimate_PDF_Viewer_Embedder_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		Ultimate_PDF_Viewer_Embedder_Install::init();
		Ultimate_PDF_Viewer_Embedder_Docs::init();
		Ultimate_PDF_Viewer_Embedder_Protect::init();
		Ultimate_PDF_Viewer_Embedder_Assets::init();
		Ultimate_PDF_Viewer_Embedder_Render::init();
		Ultimate_PDF_Viewer_Embedder_Rest::init();
		Ultimate_PDF_Viewer_Embedder_Block::init();
		Ultimate_PDF_Viewer_Embedder_Media::init();

		add_action( 'update_option_' . self::OPTION, array( $this, 'flush' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush' ) );

		if ( is_admin() ) {
			new Ultimate_PDF_Viewer_Embedder_Admin( $this );
		}
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Viewer.
			'width'             => '100%',
			'height'            => '800px',
			'zoom'              => 'auto',
			'scroll'            => 'continuous',
			'sidebar'           => 'closed',
			'text_select'       => 1,
			'new_tab_links'     => 1,
			'keyboard'          => 1,
			'deep_links'        => 1,
			'flip_cover'        => 1,
			'lightbox_label'    => '',

			// Toolbar and look.
			'toolbar'           => 1,
			'bar_position'      => 'top',
			'bar_style'         => 'bar',
			'btn_download'      => 1,
			'btn_print'         => 1,
			'btn_fullscreen'    => 1,
			'btn_search'        => 1,
			'btn_thumbs'        => 1,
			'btn_zoom'          => 1,
			'btn_mode'          => 1,
			'btn_share'         => 1,
			'theme'             => 'light',
			'color_bar'         => '#ffffff',
			'color_text'        => '#1f2937',
			'color_accent'      => '#2563eb',
			'color_bg'          => '#e9edf2',
			'radius'            => 10,

			// Protection.
			'secure'            => 0,
			'access'            => 'all',
			'roles'             => array(),
			'denied_message'    => '',
			'watermark'         => 0,
			'watermark_text'    => '{site} · {email}',
			'watermark_opacity' => 14,
			'watermark_layout'  => 'diagonal',

			// SEO and speed.
			'seo_text'          => 1,
			'seo_label'         => '',
			'seo_open'          => 0,
			'lazy'              => 1,
			'remote'            => 1,
			'remote_hours'      => 24,
			'remote_max_mb'     => 50,

			// Tracking.
			'track'             => 1,
			'track_editors'     => 0,
			'keep_days'         => 365,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		if ( null === $this->settings ) {
			$saved          = get_option( self::OPTION, array() );
			$this->settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}

		return $this->settings;
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Forget the cached settings after a save.
	 *
	 * @return void
	 */
	public function flush() {
		$this->settings = null;
	}
}
