<?php
/**
 * Admin side: the Content Powered > Ultimate PDF Viewer and Embedder page
 * and the Plugins-screen link.
 *
 * One page with tabs: Viewer, Toolbar & Colors, Protection, SEO & Speed and
 * Tracking hold the site-wide defaults (every embed can override them);
 * Reports shows views and read depth; Tools reads text and covers for PDFs
 * in bulk and lists the ways to embed. Built on WordPress's own admin
 * pieces (postboxes, form tables, buttons), like the other Content Powered
 * plugins.
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page controller.
 */
final class Ultimate_PDF_Viewer_Embedder_Admin {

	/**
	 * Settings page slug, also used as the settings group.
	 */
	const PAGE = 'ultimate-pdf-viewer-embedder';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'file-text';

	/**
	 * Tabs and their icons.
	 */
	const TABS = array(
		'viewer'     => 'file-text',
		'look'       => 'palette',
		'protection' => 'shield-check',
		'seo'        => 'gauge',
		'tracking'   => 'activity',
		'reports'    => 'chart-column',
		'tools'      => 'wrench',
	);

	/**
	 * Plugin instance.
	 *
	 * @var Ultimate_PDF_Viewer_Embedder_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the settings page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Ultimate_PDF_Viewer_Embedder_Plugin $plugin Plugin instance.
	 */
	public function __construct( Ultimate_PDF_Viewer_Embedder_Plugin $plugin ) {
		$this->plugin = $plugin;

		Ultimate_PDF_Viewer_Embedder_Menu::init();
		Ultimate_PDF_Viewer_Embedder_Feedback::init();
		Ultimate_PDF_Viewer_Embedder_Reports::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( ULTIMATE_PDF_VIEWER_EMBEDDER_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * A bundled icon.
	 *
	 * @param string $name Icon name.
	 * @param int    $size Size in px.
	 * @return string
	 */
	private static function icon( $name, $size = 18 ) {
		return Ultimate_PDF_Viewer_Embedder_Icons::get( $name, $size );
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ),
			esc_html__( 'Settings', 'ultimate-pdf-viewer-embedder' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Ultimate_PDF_Viewer_Embedder_Icons::get( self::ICON, 17, 'ultimate-pdf-viewer-embedder-menu-icon' );
		$menu_title = $icon . '<span class="ultimate-pdf-viewer-embedder-menu-label">' . esc_html__( 'Ultimate PDF Viewer and Embedder', 'ultimate-pdf-viewer-embedder' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Ultimate_PDF_Viewer_Embedder_Menu::SLUG,
			__( 'Ultimate PDF Viewer and Embedder', 'ultimate-pdf-viewer-embedder' ),
			$menu_title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		}
	}

	/**
	 * Body class for the settings page (full-width header).
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && $screen->id === $this->hook_suffix ) {
			$classes .= ' upve-settings-page';
		}

		return $classes;
	}

	/**
	 * Register the settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Ultimate_PDF_Viewer_Embedder_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Ultimate_PDF_Viewer_Embedder_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * Every key is checked against its own list, range or pattern; anything
	 * else falls back to its default.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$input = is_array( $input ) ? wp_unslash( $input ) : array();
		$d     = Ultimate_PDF_Viewer_Embedder_Plugin::defaults();
		$out   = array();

		$pick = function ( $key, array $allowed ) use ( $input, $d ) {
			return isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ? $input[ $key ] : $d[ $key ];
		};
		$int  = function ( $key, $min, $max ) use ( $input, $d ) {
			return isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ? max( $min, min( $max, (int) $input[ $key ] ) ) : $d[ $key ];
		};
		$text = function ( $key, $max ) use ( $input ) {
			return isset( $input[ $key ] ) ? Ultimate_PDF_Viewer_Embedder_Docs::clip( sanitize_text_field( (string) $input[ $key ] ), $max ) : '';
		};
		$hex  = function ( $key ) use ( $input, $d ) {
			$color = isset( $input[ $key ] ) ? sanitize_hex_color( (string) $input[ $key ] ) : '';
			return $color ? $color : $d[ $key ];
		};

		foreach ( array( 'text_select', 'new_tab_links', 'keyboard', 'deep_links', 'flip_cover', 'toolbar', 'btn_download', 'btn_print', 'btn_fullscreen', 'btn_search', 'btn_thumbs', 'btn_zoom', 'btn_mode', 'btn_share', 'secure', 'watermark', 'seo_text', 'seo_open', 'lazy', 'remote', 'track', 'track_editors' ) as $key ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$width          = Ultimate_PDF_Viewer_Embedder_Render::length( isset( $input['width'] ) ? $input['width'] : '' );
		$height         = Ultimate_PDF_Viewer_Embedder_Render::length( isset( $input['height'] ) ? $input['height'] : '', true );
		$zoom           = Ultimate_PDF_Viewer_Embedder_Render::zoom( isset( $input['zoom'] ) ? $input['zoom'] : '' );
		$out['width']   = '' !== $width ? $width : $d['width'];
		$out['height']  = '' !== $height ? $height : $d['height'];
		$out['zoom']    = '' !== $zoom ? $zoom : $d['zoom'];
		$out['scroll']  = $pick( 'scroll', array( 'continuous', 'page' ) );
		$out['sidebar'] = $pick( 'sidebar', array( 'closed', 'thumbnails', 'outline' ) );

		$out['lightbox_label'] = $text( 'lightbox_label', 80 );
		$out['bar_position']   = $pick( 'bar_position', array( 'top', 'bottom' ) );
		$out['bar_style']      = $pick( 'bar_style', array( 'bar', 'floating' ) );
		$out['theme']          = $pick( 'theme', array( 'light', 'dark', 'auto' ) );
		$out['color_bar']      = $hex( 'color_bar' );
		$out['color_text']     = $hex( 'color_text' );
		$out['color_accent']   = $hex( 'color_accent' );
		$out['color_bg']       = $hex( 'color_bg' );
		$out['radius']         = $int( 'radius', 0, 24 );

		$out['access']            = $pick( 'access', array( 'all', 'login', 'roles' ) );
		$out['roles']             = Ultimate_PDF_Viewer_Embedder_Render::roles( isset( $input['roles'] ) && is_array( $input['roles'] ) ? $input['roles'] : array() );
		$out['denied_message']    = $text( 'denied_message', 300 );
		$out['watermark_text']    = $text( 'watermark_text', 160 );
		$out['watermark_opacity'] = $int( 'watermark_opacity', 3, 60 );
		$out['watermark_layout']  = $pick( 'watermark_layout', array( 'diagonal', 'tiled', 'footer' ) );

		if ( '' === $out['watermark_text'] ) {
			$out['watermark_text'] = $d['watermark_text'];
		}

		if ( 'roles' === $out['access'] && ! $out['roles'] ) {
			$out['access'] = 'login';
		}

		$out['seo_label']     = $text( 'seo_label', 120 );
		$out['remote_hours']  = $int( 'remote_hours', 1, 720 );
		$out['remote_max_mb'] = $int( 'remote_max_mb', 1, 500 );
		$out['keep_days']     = $int( 'keep_days', 0, 3650 );

		return $out;
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="ultimate-pdf-viewer-embedder-menu-icon-style">'
			. '#adminmenu a:has(>.ultimate-pdf-viewer-embedder-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .ultimate-pdf-viewer-embedder-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .ultimate-pdf-viewer-embedder-menu-icon,#adminmenu a:hover .ultimate-pdf-viewer-embedder-menu-icon,#adminmenu a:focus .ultimate-pdf-viewer-embedder-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the settings-page styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_style( 'ultimate-pdf-viewer-embedder-admin', ULTIMATE_PDF_VIEWER_EMBEDDER_URL . 'assets/admin.css', array( Ultimate_PDF_Viewer_Embedder_Assets::HANDLE ), ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION );
		wp_enqueue_script( 'ultimate-pdf-viewer-embedder-admin', ULTIMATE_PDF_VIEWER_EMBEDDER_URL . 'assets/admin.js', array( 'wp-color-picker', 'wp-api-fetch', Ultimate_PDF_Viewer_Embedder_Block::PREPARE ), ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION, true );

		wp_localize_script( Ultimate_PDF_Viewer_Embedder_Block::PREPARE, 'upvePrepareConfig', Ultimate_PDF_Viewer_Embedder_Block::prepare_config() );
		wp_localize_script(
			'ultimate-pdf-viewer-embedder-admin',
			'upveAdmin',
			array(
				'ns'   => Ultimate_PDF_Viewer_Embedder_Rest::NS,
				'site' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'i18n' => array(
					'checking'  => __( 'Looking for PDFs…', 'ultimate-pdf-viewer-embedder' ),
					'none'      => __( 'Every PDF already has its text and cover.', 'ultimate-pdf-viewer-embedder' ),
					/* translators: %d: number of PDFs. */
					'found'     => __( '%d PDFs need their text or cover read.', 'ultimate-pdf-viewer-embedder' ),
					/* translators: 1: position, 2: number of PDFs, 3: PDF title. */
					'working'   => __( 'Reading %1$d of %2$d: %3$s', 'ultimate-pdf-viewer-embedder' ),
					/* translators: 1: PDFs read, 2: PDFs that failed. */
					'done'      => __( 'Done. %1$d read, %2$d couldn\'t be opened.', 'ultimate-pdf-viewer-embedder' ),
					'failed'    => __( 'Couldn\'t open', 'ultimate-pdf-viewer-embedder' ),
					'ok'        => __( 'Done', 'ultimate-pdf-viewer-embedder' ),
					'confirm'   => __( 'Delete every view, download and page count? This can\'t be undone.', 'ultimate-pdf-viewer-embedder' ),
					'copied'    => __( 'Copied', 'ultimate-pdf-viewer-embedder' ),
					'stopLabel' => __( 'Stop', 'ultimate-pdf-viewer-embedder' ),
				),
			)
		);

		// The live toolbar preview uses the real viewer styles.
		Ultimate_PDF_Viewer_Embedder_Assets::enqueue_styles_only();
	}

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'viewer'     => __( 'Viewer', 'ultimate-pdf-viewer-embedder' ),
			'look'       => __( 'Toolbar & Colors', 'ultimate-pdf-viewer-embedder' ),
			'protection' => __( 'Protection', 'ultimate-pdf-viewer-embedder' ),
			'seo'        => __( 'SEO & Speed', 'ultimate-pdf-viewer-embedder' ),
			'tracking'   => __( 'Tracking', 'ultimate-pdf-viewer-embedder' ),
			'reports'    => __( 'Reports', 'ultimate-pdf-viewer-embedder' ),
			'tools'      => __( 'Tools & Embedding', 'ultimate-pdf-viewer-embedder' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * The settings boxes, in order.
	 *
	 * @return array<string,array<string,string>>
	 */
	private function sections() {
		return array(
			'layout'    => array(
				'panel'  => 'viewer',
				'icon'   => 'layout-template',
				'title'  => __( 'Size and layout', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => __( 'Defaults for every PDF on the site. Each block or shortcode can change any of these for its own PDF.', 'ultimate-pdf-viewer-embedder' ),
				'render' => 'render_layout',
			),
			'behaviour' => array(
				'panel'  => 'viewer',
				'icon'   => 'mouse-pointer-click',
				'title'  => __( 'Reading', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => '',
				'render' => 'render_behaviour',
			),
			'preview'   => array(
				'panel'  => 'look',
				'icon'   => 'eye',
				'title'  => __( 'Preview', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => __( 'Updates as you change the toolbar and colors below. Nothing changes on the site until you save.', 'ultimate-pdf-viewer-embedder' ),
				'render' => 'render_preview',
			),
			'toolbar'   => array(
				'panel'  => 'look',
				'icon'   => 'panel-top',
				'title'  => __( 'Toolbar', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => __( 'Pick the buttons visitors get. On narrow screens the extras move into a "More" menu, so the toolbar always fits.', 'ultimate-pdf-viewer-embedder' ),
				'render' => 'render_toolbar',
			),
			'colors'    => array(
				'panel'  => 'look',
				'icon'   => 'palette',
				'title'  => __( 'Colors and theme', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => __( 'The dark theme uses its own dark colors with your accent color. "Match the visitor\'s device" switches between light and dark with their system setting.', 'ultimate-pdf-viewer-embedder' ),
				'render' => 'render_colors',
			),
			'secure'    => array(
				'panel'  => 'protection',
				'icon'   => 'lock',
				'title'  => __( 'Secure mode', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => __( 'Secure mode loads the PDF through your site instead of its file address, and turns off downloading, printing, text copying and right-click. It makes copies harder to get; no viewer can fully stop someone determined from saving what\'s on their screen.', 'ultimate-pdf-viewer-embedder' ),
				'render' => 'render_secure',
			),
			'access'    => array(
				'panel'  => 'protection',
				'icon'   => 'users',
				'title'  => __( 'Who can view', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => __( 'Restricted PDFs show a "Log in" message to everyone else, and their file link only works for people the rule allows.', 'ultimate-pdf-viewer-embedder' ),
				'render' => 'render_access',
			),
			'watermark' => array(
				'panel'  => 'protection',
				'icon'   => 'stamp',
				'title'  => __( 'Watermark', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => __( 'Text stamped across every page in the viewer, the thumbnails and printouts. Watermarked PDFs have no download button, since the original file has no watermark.', 'ultimate-pdf-viewer-embedder' ),
				'render' => 'render_watermark',
			),
			'seo'       => array(
				'panel'  => 'seo',
				'icon'   => 'scan-text',
				'title'  => __( 'Searchable text', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => __( 'Search engines can\'t read text drawn inside a viewer. This adds the PDF\'s text to the page in a collapsed section, so Google indexes it and screen readers can read it.', 'ultimate-pdf-viewer-embedder' ),
				'render' => 'render_seo',
			),
			'speed'     => array(
				'panel'  => 'seo',
				'icon'   => 'gauge',
				'title'  => __( 'Speed', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => '',
				'render' => 'render_speed',
			),
			'remote'    => array(
				'panel'  => 'seo',
				'icon'   => 'globe',
				'title'  => __( 'PDFs from other sites', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => __( 'Paste a link to a PDF anywhere, including Google Drive, Google Docs and Dropbox share links. Your site fetches a copy and keeps it for a while, so it loads even where the other site blocks embedding.', 'ultimate-pdf-viewer-embedder' ),
				'render' => 'render_remote',
			),
			'tracking'  => array(
				'panel'  => 'tracking',
				'icon'   => 'activity',
				'title'  => __( 'View and download tracking', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => __( 'Counts views, downloads, prints and how far into each PDF people read. Only totals are stored: no IP addresses, cookies or visitor details, so there\'s nothing to add to a cookie banner.', 'ultimate-pdf-viewer-embedder' ),
				'render' => 'render_tracking',
			),
			'reports'   => array(
				'panel'  => 'reports',
				'icon'   => 'chart-column',
				'title'  => __( 'Reports', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => '',
				'render' => 'render_reports',
			),
			'prepare'   => array(
				'panel'  => 'tools',
				'icon'   => 'wand-sparkles',
				'title'  => __( 'Read text and covers', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => __( 'The editor reads each PDF\'s text and first page when you add it to a page. To do it for PDFs that are already on your site, run this once. It works in your browser, so leave this tab open until it finishes.', 'ultimate-pdf-viewer-embedder' ),
				'render' => 'render_prepare',
			),
			'embed'     => array(
				'panel'  => 'tools',
				'icon'   => 'code',
				'title'  => __( 'Ways to add a PDF', 'ultimate-pdf-viewer-embedder' ),
				'intro'  => '',
				'render' => 'render_embed',
			),
		);
	}

	/**
	 * Render the settings page: a header with the tabs, then WordPress's
	 * two-column box layout (settings on the left, Save and status on the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="upve-header">
			<div class="upve-header-title">
				<span class="upve-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="upve-version"><?php echo esc_html( 'v' . ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION ); ?></span>
			</div>
			<nav class="upve-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'ultimate-pdf-viewer-embedder' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" class="upve-tab" role="tab" data-upve-tab="<?php echo esc_attr( $id ); ?>">
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap upve-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();
			Ultimate_PDF_Viewer_Embedder_Reports::notices();
			?>

			<form action="options.php" method="post" class="upve-form">
				<?php settings_fields( self::PAGE ); ?>

				<div id="poststuff">
					<div id="post-body" class="metabox-holder columns-2">
						<div id="post-body-content">
							<?php
							foreach ( $this->sections() as $id => $section ) {
								$this->render_section( $id, $section );
							}
							?>
						</div>

						<div id="postbox-container-1" class="postbox-container">
							<div class="upve-sidebar">
								<?php $this->render_sidebar(); ?>
							</div>
						</div>
					</div>
					<br class="clear">
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * One settings box.
	 *
	 * @param string               $id      Section ID.
	 * @param array<string,string> $section Section details from {@see sections()}.
	 * @return void
	 */
	private function render_section( $id, array $section ) {
		?>
		<div class="postbox upve-box" id="upve-<?php echo esc_attr( $id ); ?>" data-upve-panel="<?php echo esc_attr( $section['panel'] ); ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( $section['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $section['title'] ); ?>
				</h2>
			</div>
			<div class="inside">
				<?php if ( '' !== $section['intro'] ) : ?>
					<p class="upve-intro"><?php echo esc_html( $section['intro'] ); ?></p>
				<?php endif; ?>
				<?php call_user_func( array( $this, $section['render'] ) ); ?>
			</div>
		</div>
		<?php
	}

	// Field helpers ----------------------------------------------------------------

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function name( $key ) {
		return Ultimate_PDF_Viewer_Embedder_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Checkbox with a label.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $desc  Description under it.
	 * @return void
	 */
	private function checkbox( $key, $label, $desc = '' ) {
		printf(
			'<label class="upve-check"><input type="checkbox" name="%1$s" value="1" %2$s data-upve-key="%3$s"> <span>%4$s</span></label>',
			esc_attr( self::name( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_attr( $key ),
			esc_html( $label )
		);

		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	/**
	 * Select box.
	 *
	 * @param string               $key     Setting key.
	 * @param array<string,string> $options Value => label.
	 * @return void
	 */
	private function select( $key, array $options ) {
		$value = (string) $this->plugin->get_setting( $key );

		printf( '<select id="upve-%1$s" name="%2$s" data-upve-key="%1$s">', esc_attr( $key ), esc_attr( self::name( $key ) ) );

		foreach ( $options as $option => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( $value, (string) $option, false ), esc_html( $label ) );
		}

		echo '</select>';
	}

	/**
	 * Text input.
	 *
	 * @param string $key         Setting key.
	 * @param string $placeholder Placeholder.
	 * @param string $class       CSS class.
	 * @return void
	 */
	private function text( $key, $placeholder = '', $class = 'regular-text' ) {
		printf(
			'<input type="text" id="upve-%1$s" name="%2$s" value="%3$s" placeholder="%4$s" class="%5$s" data-upve-key="%1$s">',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			esc_attr( (string) $this->plugin->get_setting( $key ) ),
			esc_attr( $placeholder ),
			esc_attr( $class )
		);
	}

	/**
	 * Number input.
	 *
	 * @param string $key    Setting key.
	 * @param int    $min    Minimum.
	 * @param int    $max    Maximum.
	 * @param string $suffix Text after the box.
	 * @return void
	 */
	private function number( $key, $min, $max, $suffix = '' ) {
		printf(
			'<input type="number" class="small-text" id="upve-%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1" data-upve-key="%1$s"> %6$s',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			(int) $this->plugin->get_setting( $key ),
			(int) $min,
			(int) $max,
			esc_html( $suffix )
		);
	}

	/**
	 * Color picker.
	 *
	 * @param string $key Setting key.
	 * @return void
	 */
	private function color( $key ) {
		$defaults = Ultimate_PDF_Viewer_Embedder_Plugin::defaults();

		printf(
			'<input type="text" class="upve-color" id="upve-%1$s" name="%2$s" value="%3$s" data-default-color="%4$s" data-upve-key="%1$s">',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			esc_attr( (string) $this->plugin->get_setting( $key ) ),
			esc_attr( $defaults[ $key ] )
		);
	}

	/**
	 * Form table row.
	 *
	 * @param string   $label     Row label.
	 * @param callable $content   Prints the row's controls.
	 * @param string   $label_for ID the label points at.
	 * @param string   $depends   Setting that has to be on for this row to apply.
	 * @return void
	 */
	private function row( $label, $content, $label_for = '', $depends = '' ) {
		echo '<tr' . ( '' !== $depends ? ' data-upve-depends="' . esc_attr( $depends ) . '"' : '' ) . '><th scope="row">';
		echo '' !== $label_for ? '<label for="' . esc_attr( $label_for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '</th><td>';
		call_user_func( $content );
		echo '</td></tr>';
	}

	// Viewer tab -------------------------------------------------------------------

	/**
	 * Size and layout.
	 *
	 * @return void
	 */
	public function render_layout() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Width', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->text( 'width', '100%', 'small-text upve-wide-small' );
				echo '<p class="description">' . esc_html__( 'Any CSS width: 100%, 720px, 60em. The viewer never gets wider than the space it\'s in.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			},
			'upve-width'
		);

		$this->row(
			__( 'Height', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->text( 'height', '800px', 'small-text upve-wide-small' );
				echo '<p class="description">' . esc_html__( 'Any CSS height (800px, 80vh), or "auto" to fit the first page. It never gets taller than the screen, so it fits on phones and tablets.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			},
			'upve-height'
		);

		$this->row(
			__( 'Zoom', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->select(
					'zoom',
					array(
						'auto'       => __( 'Automatic', 'ultimate-pdf-viewer-embedder' ),
						'page-width' => __( 'Fit to width', 'ultimate-pdf-viewer-embedder' ),
						'page-fit'   => __( 'Fit to page', 'ultimate-pdf-viewer-embedder' ),
						'50'         => '50%',
						'75'         => '75%',
						'100'        => '100%',
						'125'        => '125%',
						'150'        => '150%',
						'200'        => '200%',
					)
				);
				echo '<p class="description">' . esc_html__( 'Phones always start at "Fit to width". Visitors can zoom with the buttons, ctrl + scroll or by pinching.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			},
			'upve-zoom'
		);

		$this->row(
			__( 'Scrolling', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->select(
					'scroll',
					array(
						'continuous' => __( 'Continuous (scroll from page to page)', 'ultimate-pdf-viewer-embedder' ),
						'page'       => __( 'One page at a time', 'ultimate-pdf-viewer-embedder' ),
					)
				);
			},
			'upve-scroll'
		);

		$this->row(
			__( 'Sidebar on load', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->select(
					'sidebar',
					array(
						'closed'     => __( 'Closed', 'ultimate-pdf-viewer-embedder' ),
						'thumbnails' => __( 'Page thumbnails', 'ultimate-pdf-viewer-embedder' ),
						'outline'    => __( 'Table of contents (bookmarks)', 'ultimate-pdf-viewer-embedder' ),
					)
				);
				echo '<p class="description">' . esc_html__( 'Opens only where there\'s room for it. The table of contents shows for PDFs that have bookmarks.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			},
			'upve-sidebar'
		);

		echo '</table>';
	}

	/**
	 * Reading behaviour.
	 *
	 * @return void
	 */
	public function render_behaviour() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Text', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'text_select', __( 'Let visitors select and copy text', 'ultimate-pdf-viewer-embedder' ), __( 'Always off in secure mode.', 'ultimate-pdf-viewer-embedder' ) );
			}
		);

		$this->row(
			__( 'Links', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'new_tab_links', __( 'Open web links inside PDFs in a new tab', 'ultimate-pdf-viewer-embedder' ), __( 'Links to other pages of the same PDF always jump within the viewer.', 'ultimate-pdf-viewer-embedder' ) );
			}
		);

		$this->row(
			__( 'Keyboard', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'keyboard', __( 'Keyboard shortcuts', 'ultimate-pdf-viewer-embedder' ) );
				echo '<p class="description">' . esc_html__( 'While the viewer has focus: arrow keys turn pages, Home and End jump to the first and last page, + and - zoom, ctrl/cmd + F searches.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			}
		);

		$this->row(
			__( 'Deep links', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'deep_links', __( 'Open on the page in the link', 'ultimate-pdf-viewer-embedder' ) );
				echo '<p class="description">' . wp_kses( __( 'Add <code>#page=12</code> to a page\'s address and its first PDF opens on page 12. Popups open too. The "Copy link to this page" button makes these links for visitors.', 'ultimate-pdf-viewer-embedder' ), array( 'code' => array() ) ) . '</p>';
			}
		);

		$this->row(
			__( 'Flipbooks', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'flip_cover', __( 'Show the first page on its own, like a book cover', 'ultimate-pdf-viewer-embedder' ) );
			}
		);

		$this->row(
			__( 'Popup button text', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->text( 'lightbox_label', __( 'View PDF', 'ultimate-pdf-viewer-embedder' ) );
				echo '<p class="description">' . esc_html__( 'Default text on buttons and links that open a PDF in a popup.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			},
			'upve-lightbox_label'
		);

		echo '</table>';
	}

	// Toolbar & Colors tab ---------------------------------------------------------

	/**
	 * Toolbar options and buttons.
	 *
	 * @return void
	 */
	public function render_toolbar() {
		$buttons = array(
			'btn_thumbs'     => array( 'panel-left', __( 'Sidebar', 'ultimate-pdf-viewer-embedder' ), __( 'Page thumbnails and table of contents', 'ultimate-pdf-viewer-embedder' ) ),
			'btn_zoom'       => array( 'zoom-in', __( 'Zoom', 'ultimate-pdf-viewer-embedder' ), __( 'Zoom in and out, fit to width or page', 'ultimate-pdf-viewer-embedder' ) ),
			'btn_search'     => array( 'search', __( 'Search', 'ultimate-pdf-viewer-embedder' ), __( 'Find words, with highlighted results', 'ultimate-pdf-viewer-embedder' ) ),
			'btn_mode'       => array( 'rows-3', __( 'Page mode', 'ultimate-pdf-viewer-embedder' ), __( 'Switch between scrolling and one page at a time', 'ultimate-pdf-viewer-embedder' ) ),
			'btn_share'      => array( 'link', __( 'Copy link', 'ultimate-pdf-viewer-embedder' ), __( 'Copies a link that opens on the current page', 'ultimate-pdf-viewer-embedder' ) ),
			'btn_download'   => array( 'download', __( 'Download', 'ultimate-pdf-viewer-embedder' ), __( 'Off for secure and watermarked PDFs', 'ultimate-pdf-viewer-embedder' ) ),
			'btn_print'      => array( 'printer', __( 'Print', 'ultimate-pdf-viewer-embedder' ), __( 'Off for secure PDFs', 'ultimate-pdf-viewer-embedder' ) ),
			'btn_fullscreen' => array( 'maximize', __( 'Full screen', 'ultimate-pdf-viewer-embedder' ), __( 'Fills the screen, phones included', 'ultimate-pdf-viewer-embedder' ) ),
		);

		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Toolbar', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'toolbar', __( 'Show the toolbar', 'ultimate-pdf-viewer-embedder' ) );
			}
		);

		$this->row(
			__( 'Position', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->select(
					'bar_position',
					array(
						'top'    => __( 'Top', 'ultimate-pdf-viewer-embedder' ),
						'bottom' => __( 'Bottom', 'ultimate-pdf-viewer-embedder' ),
					)
				);
			},
			'upve-bar_position',
			'toolbar'
		);

		$this->row(
			__( 'Style', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->select(
					'bar_style',
					array(
						'bar'      => __( 'Full-width bar', 'ultimate-pdf-viewer-embedder' ),
						'floating' => __( 'Floating, over the pages', 'ultimate-pdf-viewer-embedder' ),
					)
				);
			},
			'upve-bar_style',
			'toolbar'
		);

		echo '<tr data-upve-depends="toolbar"><th scope="row">' . esc_html__( 'Buttons', 'ultimate-pdf-viewer-embedder' ) . '</th><td><div class="upve-buttons">';

		foreach ( $buttons as $key => $button ) {
			printf(
				'<label class="upve-option"><input type="checkbox" name="%1$s" value="1" %2$s data-upve-key="%3$s"><span class="upve-option-icon">%4$s</span><span class="upve-option-text"><strong>%5$s</strong><span>%6$s</span></span></label>',
				esc_attr( self::name( $key ) ),
				checked( 1, (int) $this->plugin->get_setting( $key ), false ),
				esc_attr( $key ),
				self::icon( $button[0] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				esc_html( $button[1] ),
				esc_html( $button[2] )
			);
		}

		echo '</div><p class="description">' . esc_html__( 'Each block or shortcode can turn the toolbar, download, print, search and full screen buttons on or off for its own PDF.', 'ultimate-pdf-viewer-embedder' ) . '</p></td></tr>';
		echo '</table>';
	}

	/**
	 * Colors, theme and the live preview.
	 *
	 * @return void
	 */
	public function render_colors() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Theme', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->select(
					'theme',
					array(
						'light' => __( 'Light', 'ultimate-pdf-viewer-embedder' ),
						'dark'  => __( 'Dark', 'ultimate-pdf-viewer-embedder' ),
						'auto'  => __( 'Match the visitor\'s device', 'ultimate-pdf-viewer-embedder' ),
					)
				);
			},
			'upve-theme'
		);

		foreach ( array(
			'color_accent' => __( 'Accent', 'ultimate-pdf-viewer-embedder' ),
			'color_bar'    => __( 'Toolbar', 'ultimate-pdf-viewer-embedder' ),
			'color_text'   => __( 'Icons and text', 'ultimate-pdf-viewer-embedder' ),
			'color_bg'     => __( 'Background', 'ultimate-pdf-viewer-embedder' ),
		) as $key => $label ) {
			$this->row(
				$label,
				function () use ( $key ) {
					$this->color( $key );
				},
				'upve-' . $key
			);
		}

		$this->row(
			__( 'Corners', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->number( 'radius', 0, 24, __( 'px rounding', 'ultimate-pdf-viewer-embedder' ) );
			},
			'upve-radius'
		);

		echo '</table>';
	}

	/**
	 * A small, static copy of the viewer that follows the settings.
	 *
	 * @return void
	 */
	public function render_preview() {
		$s = $this->plugin->get_settings();
		$i = function ( $name, $key = '' ) {
			return '<span class="upve-btn upve-pv-btn"' . ( '' !== $key ? ' data-pv="' . esc_attr( $key ) . '"' : '' ) . '><span class="upve-i">' . self::icon( $name ) . '</span></span>';
		};
		?>
		<div class="upve-preview-wrap">
			<div class="upve upve--viewer upve-preview upve-theme-<?php echo esc_attr( $s['theme'] ); ?> upve-bar-<?php echo esc_attr( $s['bar_position'] ); ?> upve-style-<?php echo esc_attr( $s['bar_style'] ); ?>" aria-hidden="true" style="--upve-h:340px">
				<div class="upve-frame is-ready">
					<div class="upve-bar" data-pv="toolbar">
						<div class="upve-group upve-group-start"><?php echo $i( 'panel-left', 'btn_thumbs' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<div class="upve-group upve-group-nav">
							<?php echo $i( 'chevron-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="upve-page"><span class="upve-page-input upve-pv-input">2</span><span class="upve-page-count"><?php esc_html_e( 'of 12', 'ultimate-pdf-viewer-embedder' ); ?></span></span>
							<?php echo $i( 'chevron-right' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<div class="upve-group upve-group-zoom" data-pv="btn_zoom">
							<?php echo $i( 'zoom-out' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span class="upve-zoom-select upve-pv-input">100%</span>
							<?php echo $i( 'zoom-in' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<div class="upve-group upve-group-end">
							<?php
							echo $i( 'search', 'btn_search' ) . $i( 'rows-3', 'btn_mode' ) . $i( 'link', 'btn_share' ) . $i( 'download', 'btn_download' ) . $i( 'printer', 'btn_print' ) . $i( 'maximize', 'btn_fullscreen' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</div>
					</div>
					<div class="upve-body">
						<div class="upve-stage upve-pv-stage">
							<div class="upve-pv-page">
								<span class="upve-pv-heading"></span>
								<span class="upve-pv-line"></span>
								<span class="upve-pv-line"></span>
								<span class="upve-pv-line is-short"></span>
								<span class="upve-pv-chart"><i></i><i></i><i></i><i></i></span>
								<span class="upve-pv-line"></span>
								<span class="upve-pv-line is-short"></span>
								<span class="upve-pv-mark"><?php echo esc_html( Ultimate_PDF_Viewer_Embedder_Render::watermark_text( (string) $s['watermark_text'] ) ); ?></span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// Protection tab ---------------------------------------------------------------

	/**
	 * Secure mode default and protected files.
	 *
	 * @return void
	 */
	public function render_secure() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Default', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'secure', __( 'Use secure mode for every PDF', 'ultimate-pdf-viewer-embedder' ), __( 'Usually it\'s better to turn it on for the PDFs that need it, in the block\'s Protection panel or with secure="yes" in the shortcode.', 'ultimate-pdf-viewer-embedder' ) );
			}
		);

		$this->row(
			__( 'Protected files', 'ultimate-pdf-viewer-embedder' ),
			function () {
				echo '<p class="upve-tight">' . esc_html__( 'A PDF in the Media Library can still be opened by anyone who knows its address. For PDFs that must stay private, also tick "Hide this PDF\'s address" on the PDF in the Media Library (or "Hide this file\'s address" in the block). The file gets a random name, and WordPress stops showing its address to anyone who can\'t edit it.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
				$count = count( Ultimate_PDF_Viewer_Embedder_Reports::protected_ids() );
				/* translators: %s: number of protected PDFs. */
				echo '<p class="description">' . esc_html( sprintf( _n( '%s PDF is protected.', '%s PDFs are protected.', $count, 'ultimate-pdf-viewer-embedder' ), number_format_i18n( $count ) ) ) . '</p>';
			}
		);

		echo '</table>';
	}

	/**
	 * Who can view by default.
	 *
	 * @return void
	 */
	public function render_access() {
		$access = (string) $this->plugin->get_setting( 'access' );
		$chosen = (array) $this->plugin->get_setting( 'roles' );

		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Default', 'ultimate-pdf-viewer-embedder' ),
			function () use ( $access ) {
				echo '<fieldset class="upve-radios">';
				foreach ( array(
					'all'   => __( 'Everyone', 'ultimate-pdf-viewer-embedder' ),
					'login' => __( 'Logged-in users only', 'ultimate-pdf-viewer-embedder' ),
					'roles' => __( 'Only these roles:', 'ultimate-pdf-viewer-embedder' ),
				) as $value => $label ) {
					printf(
						'<label><input type="radio" name="%1$s" value="%2$s" %3$s data-upve-access> %4$s</label>',
						esc_attr( self::name( 'access' ) ),
						esc_attr( $value ),
						checked( $access, $value, false ),
						esc_html( $label )
					);
				}
				echo '</fieldset>';
			}
		);

		$this->row(
			__( 'Roles', 'ultimate-pdf-viewer-embedder' ),
			function () use ( $chosen ) {
				echo '<div class="upve-roles" data-upve-roles>';
				foreach ( wp_roles()->roles as $key => $role ) {
					printf(
						'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label>',
						esc_attr( self::name( 'roles' ) ),
						esc_attr( $key ),
						checked( in_array( $key, $chosen, true ), true, false ),
						esc_html( translate_user_role( $role['name'] ) )
					);
				}
				echo '</div><p class="description">' . esc_html__( 'Administrators can always view.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			}
		);

		$this->row(
			__( 'Message for others', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->text( 'denied_message', __( 'Log in to view this document.', 'ultimate-pdf-viewer-embedder' ), 'large-text' );
				echo '<p class="description">' . esc_html__( 'Logged-out visitors also get a "Log in" button that brings them back to the page.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			},
			'upve-denied_message'
		);

		echo '</table>';
	}

	/**
	 * Watermark.
	 *
	 * @return void
	 */
	public function render_watermark() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Default', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'watermark', __( 'Watermark every PDF', 'ultimate-pdf-viewer-embedder' ), __( 'Or turn it on for single PDFs in the block\'s Protection panel, or with watermark="yes" in the shortcode.', 'ultimate-pdf-viewer-embedder' ) );
			}
		);

		$this->row(
			__( 'Text', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->text( 'watermark_text', '{site} · {email}', 'regular-text' );
				echo '<p class="upve-chips">';
				foreach ( array(
					'{site}'     => __( 'site name', 'ultimate-pdf-viewer-embedder' ),
					'{email}'    => __( 'viewer\'s email', 'ultimate-pdf-viewer-embedder' ),
					'{name}'     => __( 'viewer\'s name', 'ultimate-pdf-viewer-embedder' ),
					'{username}' => __( 'username', 'ultimate-pdf-viewer-embedder' ),
					'{date}'     => __( 'today\'s date', 'ultimate-pdf-viewer-embedder' ),
				) as $tag => $label ) {
					printf( '<button type="button" class="button button-small upve-chip" data-insert="%1$s"><code>%1$s</code> %2$s</button>', esc_attr( $tag ), esc_html( $label ) );
				}
				echo '</p><p class="description">' . esc_html__( 'Viewer details are filled in for each logged-in visitor when the PDF opens (never stored in cached pages), and left out for visitors who aren\'t logged in.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			},
			'upve-watermark_text'
		);

		$this->row(
			__( 'Layout', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->select(
					'watermark_layout',
					array(
						'diagonal' => __( 'Large, across the middle', 'ultimate-pdf-viewer-embedder' ),
						'tiled'    => __( 'Small, repeated across the page', 'ultimate-pdf-viewer-embedder' ),
						'footer'   => __( 'Small, at the bottom of each page', 'ultimate-pdf-viewer-embedder' ),
					)
				);
			},
			'upve-watermark_layout'
		);

		$this->row(
			__( 'Strength', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->number( 'watermark_opacity', 3, 60, __( '% opacity', 'ultimate-pdf-viewer-embedder' ) );
			},
			'upve-watermark_opacity'
		);

		echo '</table>';
	}

	// SEO & Speed tab --------------------------------------------------------------

	/**
	 * Searchable text.
	 *
	 * @return void
	 */
	public function render_seo() {
		$stats = Ultimate_PDF_Viewer_Embedder_Reports::text_counts();

		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Default', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'seo_text', __( 'Add each PDF\'s text under its viewer', 'ultimate-pdf-viewer-embedder' ), __( 'Never shown for secure or restricted PDFs, since that would give their content away.', 'ultimate-pdf-viewer-embedder' ) );
			}
		);

		$this->row(
			__( 'Section title', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->text( 'seo_label', __( 'Text version: {title}', 'ultimate-pdf-viewer-embedder' ) );
				echo '<p class="description">' . esc_html__( 'Leave empty for "Text version:" and the PDF\'s title.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			},
			'upve-seo_label',
			'seo_text'
		);

		$this->row(
			__( 'Open by default', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'seo_open', __( 'Show the text expanded', 'ultimate-pdf-viewer-embedder' ), __( 'Search engines read it either way.', 'ultimate-pdf-viewer-embedder' ) );
			},
			'',
			'seo_text'
		);

		$this->row(
			__( 'Status', 'ultimate-pdf-viewer-embedder' ),
			function () use ( $stats ) {
				/* translators: 1: PDFs with text, 2: PDFs in the Media Library. */
				echo '<p class="upve-tight">' . esc_html( sprintf( __( '%1$s of %2$s Media Library PDFs have their text saved.', 'ultimate-pdf-viewer-embedder' ), number_format_i18n( $stats['text'] ), number_format_i18n( $stats['total'] ) ) ) . ' <a href="#tools" data-upve-goto="tools">' . esc_html__( 'Read the rest', 'ultimate-pdf-viewer-embedder' ) . '</a></p>';
			}
		);

		echo '</table>';
	}

	/**
	 * Speed.
	 *
	 * @return void
	 */
	public function render_speed() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Lazy loading', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'lazy', __( 'Load each viewer when it scrolls into view', 'ultimate-pdf-viewer-embedder' ), __( 'PDFs further down the page cost nothing until a visitor gets to them.', 'ultimate-pdf-viewer-embedder' ) );
			}
		);

		$this->row(
			__( 'Where files load', 'ultimate-pdf-viewer-embedder' ),
			function () {
				echo '<ul class="upve-checklist upve-tight-list">';
				foreach ( array(
					__( 'Viewer files load only on pages with a PDF, and nowhere else.', 'ultimate-pdf-viewer-embedder' ),
					__( 'The PDF engine (Mozilla\'s PDF.js) loads only when a PDF is about to show.', 'ultimate-pdf-viewer-embedder' ),
					__( 'Everything comes from your own site: no CDN, no third-party requests.', 'ultimate-pdf-viewer-embedder' ),
					__( 'Large PDFs open on their first page while the rest downloads.', 'ultimate-pdf-viewer-embedder' ),
				) as $line ) {
					echo '<li class="is-ok">' . self::icon( 'circle-check', 16 ) . '<span>' . esc_html( $line ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				}
				echo '</ul>';
			}
		);

		echo '</table>';
	}

	/**
	 * Remote PDFs.
	 *
	 * @return void
	 */
	public function render_remote() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Other sites', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'remote', __( 'Allow PDFs from other sites', 'ultimate-pdf-viewer-embedder' ), __( 'Only links an editor puts on a page are fetched. Visitors can\'t make your site download anything else.', 'ultimate-pdf-viewer-embedder' ) );
			}
		);

		$this->row(
			__( 'Keep copies for', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->number( 'remote_hours', 1, 720, __( 'hours', 'ultimate-pdf-viewer-embedder' ) );
				echo '<p class="description">' . esc_html__( 'Changes to the original show up after this long.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			},
			'upve-remote_hours',
			'remote'
		);

		$this->row(
			__( 'Largest file', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->number( 'remote_max_mb', 1, 500, __( 'MB', 'ultimate-pdf-viewer-embedder' ) );
			},
			'upve-remote_max_mb',
			'remote'
		);

		$this->row(
			__( 'Links that work', 'ultimate-pdf-viewer-embedder' ),
			function () {
				echo '<ul class="upve-examples">';
				foreach ( array(
					'https://example.com/files/brochure.pdf',
					'https://drive.google.com/file/d/FILE-ID/view?usp=sharing',
					'https://docs.google.com/document/d/DOC-ID/edit',
					'https://www.dropbox.com/scl/fi/…/menu.pdf?rlkey=…&dl=0',
				) as $example ) {
					echo '<li><code>' . esc_html( $example ) . '</code></li>';
				}
				echo '</ul><p class="description">' . esc_html__( 'Google and Dropbox files must be shared as "Anyone with the link".', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			}
		);

		echo '</table>';
	}

	// Tracking tab -----------------------------------------------------------------

	/**
	 * Tracking settings.
	 *
	 * @return void
	 */
	public function render_tracking() {
		echo '<table class="form-table" role="presentation">';

		$this->row(
			__( 'Tracking', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'track', __( 'Count views, downloads, prints and pages read', 'ultimate-pdf-viewer-embedder' ) );
			}
		);

		$this->row(
			__( 'Your team', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->checkbox( 'track_editors', __( 'Count views by logged-in editors and admins too', 'ultimate-pdf-viewer-embedder' ), __( 'Off by default, so checking your own pages doesn\'t change the numbers. Bots and crawlers are never counted.', 'ultimate-pdf-viewer-embedder' ) );
			},
			'',
			'track'
		);

		$this->row(
			__( 'Keep daily numbers for', 'ultimate-pdf-viewer-embedder' ),
			function () {
				$this->number( 'keep_days', 0, 3650, __( 'days (0 = forever)', 'ultimate-pdf-viewer-embedder' ) );
				echo '<p class="description">' . esc_html__( 'All-time totals and per-page counts are always kept.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			},
			'upve-keep_days',
			'track'
		);

		$this->row(
			__( 'Start over', 'ultimate-pdf-viewer-embedder' ),
			function () {
				printf(
					'<a class="button upve-danger" href="%1$s" data-upve-confirm>%2$s%3$s</a>',
					esc_url( Ultimate_PDF_Viewer_Embedder_Reports::action_url( 'reset' ) ),
					self::icon( 'trash-2', 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
					esc_html__( 'Delete all stats', 'ultimate-pdf-viewer-embedder' )
				);
			}
		);

		echo '</table>';
	}

	/**
	 * Reports panel.
	 *
	 * @return void
	 */
	public function render_reports() {
		Ultimate_PDF_Viewer_Embedder_Reports::render();
	}

	// Tools tab --------------------------------------------------------------------

	/**
	 * Bulk text and cover reader.
	 *
	 * @return void
	 */
	public function render_prepare() {
		$stats = Ultimate_PDF_Viewer_Embedder_Reports::text_counts();
		?>
		<div class="upve-prepare" data-upve-prepare>
			<div class="upve-prepare-stats">
				<span><strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong> <?php esc_html_e( 'PDFs in the Media Library', 'ultimate-pdf-viewer-embedder' ); ?></span>
				<span><strong><?php echo esc_html( number_format_i18n( $stats['text'] ) ); ?></strong> <?php esc_html_e( 'with text', 'ultimate-pdf-viewer-embedder' ); ?></span>
				<span><strong><?php echo esc_html( number_format_i18n( $stats['cover'] ) ); ?></strong> <?php esc_html_e( 'with a cover', 'ultimate-pdf-viewer-embedder' ); ?></span>
			</div>
			<p class="upve-inline">
				<button type="button" class="button button-primary" data-upve-prepare-start><?php echo self::icon( 'wand-sparkles', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Read missing text and covers', 'ultimate-pdf-viewer-embedder' ); ?></button>
				<label class="upve-check"><input type="checkbox" data-upve-prepare-all> <span><?php esc_html_e( 'Read every PDF again', 'ultimate-pdf-viewer-embedder' ); ?></span></label>
			</p>
			<div class="upve-progress" hidden><span class="upve-progress-bar"></span></div>
			<p class="upve-prepare-status" aria-live="polite"></p>
			<ul class="upve-prepare-log"></ul>
		</div>
		<?php
	}

	/**
	 * Embedding help.
	 *
	 * @return void
	 */
	public function render_embed() {
		$examples = array(
			'[pdf_viewer id="123"]'                       => __( 'A PDF from the Media Library (its ID is in its details).', 'ultimate-pdf-viewer-embedder' ),
			'[pdf_viewer url="https://drive.google.com/file/d/…/view"]' => __( 'Any PDF link, including Google Drive, Docs and Dropbox.', 'ultimate-pdf-viewer-embedder' ),
			'[pdf_viewer id="123" mode="flipbook"]'       => __( 'A flipbook with a page-turn effect.', 'ultimate-pdf-viewer-embedder' ),
			'[pdf_viewer id="123" mode="lightbox" label="Read the menu"]' => __( 'A button that opens the PDF in a popup. Add trigger="link" or trigger="thumbnail".', 'ultimate-pdf-viewer-embedder' ),
			'[pdf_viewer id="123" secure="yes" access="login"]' => __( 'Secure mode, for logged-in users only. access="roles" roles="editor,subscriber" limits it to roles.', 'ultimate-pdf-viewer-embedder' ),
			'[pdf_viewer id="123" height="600px" page="3" download="no"]' => __( 'Size, start page and toolbar buttons: download, print, search, fullscreen, toolbar = yes or no.', 'ultimate-pdf-viewer-embedder' ),
			'[pdf_library ids="123,124,125" columns="3"]' => __( 'A grid of PDFs with covers. source="all" or source="attached" lists them for you.', 'ultimate-pdf-viewer-embedder' ),
		);
		?>
		<div class="upve-ways">
			<div class="upve-way">
				<span class="upve-option-icon"><?php echo self::icon( 'file-text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="upve-option-text"><strong><?php esc_html_e( 'Blocks', 'ultimate-pdf-viewer-embedder' ); ?></strong><span><?php esc_html_e( 'Add a "PDF Viewer" or "PDF Library" block. File blocks with a PDF can be turned into a viewer with the block\'s Transform menu.', 'ultimate-pdf-viewer-embedder' ); ?></span></span>
			</div>
			<div class="upve-way">
				<span class="upve-option-icon"><?php echo self::icon( 'image' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="upve-option-text"><strong><?php esc_html_e( 'Classic editor', 'ultimate-pdf-viewer-embedder' ); ?></strong><span><?php esc_html_e( 'Use the "Add PDF" button beside "Add Media". Pick one PDF for a viewer or several for a library.', 'ultimate-pdf-viewer-embedder' ); ?></span></span>
			</div>
			<div class="upve-way">
				<span class="upve-option-icon"><?php echo self::icon( 'code' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="upve-option-text"><strong><?php esc_html_e( 'Shortcodes', 'ultimate-pdf-viewer-embedder' ); ?></strong><span><?php esc_html_e( 'For page builders, widgets and anywhere else. Every PDF in the Media Library shows its own shortcode in its details.', 'ultimate-pdf-viewer-embedder' ); ?></span></span>
			</div>
		</div>

		<table class="widefat striped upve-codes">
			<tbody>
				<?php foreach ( $examples as $code => $what ) : ?>
					<tr>
						<td><code><?php echo esc_html( $code ); ?></code></td>
						<td><?php echo esc_html( $what ); ?></td>
						<td class="upve-copy-cell"><button type="button" class="button button-small" data-upve-copy="<?php echo esc_attr( $code ); ?>"><?php echo self::icon( 'copy', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span><?php esc_html_e( 'Copy', 'ultimate-pdf-viewer-embedder' ); ?></span></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// Sidebar ----------------------------------------------------------------------

	/**
	 * Sidebar: Save, status, and where updates come from.
	 *
	 * @return void
	 */
	private function render_sidebar() {
		$s      = $this->plugin->get_settings();
		$counts = Ultimate_PDF_Viewer_Embedder_Reports::text_counts();
		$status = array(
			array( (bool) $s['toolbar'], $s['toolbar'] ? __( 'Toolbar on', 'ultimate-pdf-viewer-embedder' ) : __( 'Toolbar hidden', 'ultimate-pdf-viewer-embedder' ) ),
			array( (bool) $s['lazy'], $s['lazy'] ? __( 'Lazy loading on', 'ultimate-pdf-viewer-embedder' ) : __( 'Lazy loading off', 'ultimate-pdf-viewer-embedder' ) ),
			array( (bool) $s['seo_text'], $s['seo_text'] ? __( 'Searchable text on', 'ultimate-pdf-viewer-embedder' ) : __( 'Searchable text off', 'ultimate-pdf-viewer-embedder' ) ),
			array( (bool) $s['track'], $s['track'] ? __( 'Tracking views and reading', 'ultimate-pdf-viewer-embedder' ) : __( 'Tracking off', 'ultimate-pdf-viewer-embedder' ) ),
			array( (bool) $s['remote'], $s['remote'] ? __( 'PDFs from other sites allowed', 'ultimate-pdf-viewer-embedder' ) : __( 'Only this site\'s PDFs', 'ultimate-pdf-viewer-embedder' ) ),
			/* translators: 1: PDFs with text, 2: total PDFs. */
			array( $counts['total'] > 0 && $counts['text'] >= $counts['total'], sprintf( __( '%1$s of %2$s PDFs have text', 'ultimate-pdf-viewer-embedder' ), number_format_i18n( $counts['text'] ), number_format_i18n( $counts['total'] ) ) ),
		);
		?>
		<div class="postbox upve-save-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Save', 'ultimate-pdf-viewer-embedder' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p><?php esc_html_e( 'Settings on every tab are saved together. They\'re the defaults; each block or shortcode can change them for its own PDF.', 'ultimate-pdf-viewer-embedder' ); ?></p>
				<?php submit_button( __( 'Save Changes', 'ultimate-pdf-viewer-embedder' ), 'primary large', 'submit', false ); ?>
			</div>
		</div>

		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'circle-check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Status', 'ultimate-pdf-viewer-embedder' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="upve-checklist">
					<?php foreach ( $status as $item ) : ?>
						<li class="<?php echo $item[0] ? 'is-ok' : 'is-off'; ?>"><?php echo self::icon( $item[0] ? 'circle-check' : 'circle-dot', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>

		<?php
		$this->render_updates_box();
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( ULTIMATE_PDF_VIEWER_EMBEDDER_REPO ) );
		$check_url  = Ultimate_PDF_Viewer_Embedder_Updater::check_now_url();
		?>
		<div class="postbox upve-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo self::icon( 'refresh-cw' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'ultimate-pdf-viewer-embedder' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'ultimate-pdf-viewer-embedder' ), '<strong>' . esc_html( ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION ) . '</strong>' );
					?>
				</p>
				<p>
					<?php
					/* translators: %s: PDF.js version. */
					printf( esc_html__( 'PDF engine: Mozilla PDF.js %s', 'ultimate-pdf-viewer-embedder' ), esc_html( Ultimate_PDF_Viewer_Embedder_Assets::PDFJS_VERSION ) );
					?>
				</p>

				<?php if ( ! Ultimate_PDF_Viewer_Embedder_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'ultimate-pdf-viewer-embedder' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( ULTIMATE_PDF_VIEWER_EMBEDDER_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Ultimate_PDF_Viewer_Embedder_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'ultimate-pdf-viewer-embedder' ),
							'<a href="' . esc_url( ULTIMATE_PDF_VIEWER_EMBEDDER_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>ULTIMATE_PDF_VIEWER_EMBEDDER_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'ultimate-pdf-viewer-embedder' ),
							'<a href="' . esc_url( ULTIMATE_PDF_VIEWER_EMBEDDER_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'ultimate-pdf-viewer-embedder' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
