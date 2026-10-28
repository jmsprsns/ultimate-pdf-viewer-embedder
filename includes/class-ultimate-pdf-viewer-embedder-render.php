<?php
/**
 * Front-end markup: the viewer, the popup (lightbox) trigger, the flipbook,
 * the PDF library grid and the searchable text section, plus the
 * [pdf_viewer] and [pdf_library] shortcodes.
 *
 * Blocks and shortcodes both end up in viewer() and library() with the same
 * options, so they behave the same. Empty options fall back to the site
 * defaults on the settings page. Every option is checked against a fixed
 * list or pattern before it reaches the HTML or CSS.
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Markup builder.
 */
final class Ultimate_PDF_Viewer_Embedder_Render {

	/**
	 * Viewers on this page so far (for unique IDs).
	 *
	 * @var int
	 */
	private static $count = 0;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_shortcode( 'pdf_viewer', array( __CLASS__, 'shortcode_viewer' ) );
		add_shortcode( 'pdf_library', array( __CLASS__, 'shortcode_library' ) );
	}

	/**
	 * Every viewer option, empty meaning "site default".
	 *
	 * @return array<string,mixed>
	 */
	public static function viewer_defaults() {
		return array(
			'id'         => 0,
			'url'        => '',
			'mode'       => 'viewer',
			'lightbox'   => '',
			'width'      => '',
			'height'     => '',
			'page'       => 1,
			'zoom'       => '',
			'scroll'     => '',
			'sidebar'    => '',
			'toolbar'    => '',
			'download'   => '',
			'print'      => '',
			'search'     => '',
			'fullscreen' => '',
			'theme'      => '',
			'secure'     => '',
			'access'     => '',
			'roles'      => '',
			'watermark'  => '',
			'text'       => '',
			'lazy'       => '',
			'trigger'    => 'button',
			'label'      => '',
			'title'      => '',
			'anchor'     => '',
			'class'      => '',
			'align'      => '',
		);
	}

	/**
	 * Every library option.
	 *
	 * @return array<string,mixed>
	 */
	public static function library_defaults() {
		return array(
			'ids'     => '',
			'source'  => 'selected',
			'limit'   => 12,
			'columns' => 3,
			'open'    => 'lightbox',
			'mode'    => 'viewer',
			'search'  => '',
			'titles'  => 'yes',
			'orderby' => 'selected',
			'class'   => '',
			'align'   => '',
		);
	}

	// Shortcodes -------------------------------------------------------------------

	/**
	 * [pdf_viewer id="12"] or [pdf_viewer url="https://..."].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode_viewer( $atts ) {
		$atts = shortcode_atts( self::viewer_defaults(), is_array( $atts ) ? $atts : array(), 'pdf_viewer' );

		return self::viewer( $atts );
	}

	/**
	 * [pdf_library ids="12,13,14"].
	 *
	 * @param array|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode_library( $atts ) {
		$atts = shortcode_atts( self::library_defaults(), is_array( $atts ) ? $atts : array(), 'pdf_library' );

		return self::library( $atts );
	}

	// Option helpers -------------------------------------------------------------

	/**
	 * Three-way option: null (site default), true or false.
	 *
	 * @param mixed $value yes/no, on/off, true/false, 1/0 or ''.
	 * @return bool|null
	 */
	public static function tri( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$value = strtolower( trim( (string) $value ) );

		if ( in_array( $value, array( 'yes', 'on', 'true', '1', 'show' ), true ) ) {
			return true;
		}

		if ( in_array( $value, array( 'no', 'off', 'false', '0', 'hide' ), true ) ) {
			return false;
		}

		return null;
	}

	/**
	 * A CSS length, or '' if it isn't one.
	 *
	 * @param mixed $value e.g. 800px, 80vh, 100%, 40em; a bare number means px.
	 * @param bool  $auto  Whether "auto" is allowed.
	 * @return string
	 */
	public static function length( $value, $auto = false ) {
		$value = strtolower( trim( (string) $value ) );

		if ( $auto && 'auto' === $value ) {
			return 'auto';
		}

		if ( preg_match( '/^\d{1,4}(?:\.\d{1,2})?$/', $value ) ) {
			$value .= 'px';
		}

		return preg_match( '/^\d{1,4}(?:\.\d{1,2})?(?:px|%|vh|vw|svh|dvh|em|rem)$/', $value ) ? $value : '';
	}

	/**
	 * A zoom value: auto, page-fit, page-width, or a percentage (10-400).
	 *
	 * @param mixed $value Value.
	 * @return string '' if invalid.
	 */
	public static function zoom( $value ) {
		$value = strtolower( trim( (string) $value ) );

		if ( in_array( $value, array( 'auto', 'page-fit', 'page-width' ), true ) ) {
			return $value;
		}

		$value = rtrim( $value, '%' );

		return is_numeric( $value ) && $value >= 10 && $value <= 400 ? (string) (int) $value : '';
	}

	/**
	 * Role keys that exist on this site.
	 *
	 * @param mixed $roles Comma-separated string or array.
	 * @return string[]
	 */
	public static function roles( $roles ) {
		if ( is_string( $roles ) ) {
			$roles = explode( ',', $roles );
		}

		$known = array_keys( wp_roles()->roles );
		$clean = array();

		foreach ( (array) $roles as $role ) {
			$role = sanitize_key( (string) $role );

			if ( in_array( $role, $known, true ) ) {
				$clean[] = $role;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Merge an embed's options with the site defaults into its final settings.
	 *
	 * @param array<string,mixed> $atts Options.
	 * @return array<string,mixed>
	 */
	public static function resolve( array $atts ) {
		$s    = ultimate_pdf_viewer_embedder_plugin()->get_settings();
		$atts = wp_parse_args( $atts, self::viewer_defaults() );
		$pick = function ( $value, array $allowed, $fallback ) {
			$value = strtolower( trim( (string) $value ) );

			return in_array( $value, $allowed, true ) ? $value : $fallback;
		};
		$flag = function ( $value, $fallback ) {
			$tri = self::tri( $value );

			return null === $tri ? (bool) $fallback : $tri;
		};

		$mode     = strtolower( (string) $atts['mode'] );
		$lightbox = $flag( $atts['lightbox'], 'lightbox' === $mode );
		$mode     = 'flipbook' === $mode ? 'flipbook' : 'viewer';

		$access = $pick( $atts['access'], array( 'all', 'login', 'roles' ), $pick( $s['access'], array( 'all', 'login', 'roles' ), 'all' ) );
		$roles  = '' !== $atts['access'] ? self::roles( $atts['roles'] ) : self::roles( $s['roles'] );

		if ( 'roles' === $access && ! $roles ) {
			$access = 'login';
		}

		$secure    = $flag( $atts['secure'], $s['secure'] );
		$watermark = $flag( $atts['watermark'], $s['watermark'] );
		$download  = $flag( $atts['download'], $s['btn_download'] ) && ! $secure && ! $watermark;
		$print     = $flag( $atts['print'], $s['btn_print'] ) && ! $secure;

		return array(
			'mode'       => $mode,
			'lightbox'   => $lightbox,
			'width'      => self::length( $atts['width'] ) ? self::length( $atts['width'] ) : ( self::length( $s['width'] ) ? self::length( $s['width'] ) : '100%' ),
			'height'     => self::length( $atts['height'], true ) ? self::length( $atts['height'], true ) : ( self::length( $s['height'], true ) ? self::length( $s['height'], true ) : '800px' ),
			'page'       => max( 1, min( 100000, absint( $atts['page'] ) ) ),
			'zoom'       => self::zoom( $atts['zoom'] ) ? self::zoom( $atts['zoom'] ) : ( self::zoom( $s['zoom'] ) ? self::zoom( $s['zoom'] ) : 'auto' ),
			'scroll'     => $pick( $atts['scroll'], array( 'continuous', 'page' ), $pick( $s['scroll'], array( 'continuous', 'page' ), 'continuous' ) ),
			'sidebar'    => $pick( $atts['sidebar'], array( 'closed', 'thumbnails', 'outline' ), $pick( $s['sidebar'], array( 'closed', 'thumbnails', 'outline' ), 'closed' ) ),
			'toolbar'    => $flag( $atts['toolbar'], $s['toolbar'] ),
			'download'   => $download,
			'print'      => $print,
			'search'     => $flag( $atts['search'], $s['btn_search'] ),
			'fullscreen' => $flag( $atts['fullscreen'], $s['btn_fullscreen'] ),
			'theme'      => $pick( $atts['theme'], array( 'light', 'dark', 'auto' ), $pick( $s['theme'], array( 'light', 'dark', 'auto' ), 'light' ) ),
			'secure'     => $secure,
			'access'     => $access,
			'roles'      => 'roles' === $access ? $roles : array(),
			'watermark'  => $watermark,
			'text'       => $flag( $atts['text'], $s['seo_text'] ) && ! $secure && 'all' === $access,
			'lazy'       => $flag( $atts['lazy'], $s['lazy'] ),
			'trigger'    => $pick( $atts['trigger'], array( 'button', 'link', 'thumbnail' ), 'button' ),
			'label'      => sanitize_text_field( (string) $atts['label'] ),
			'title'      => sanitize_text_field( (string) $atts['title'] ),
			'anchor'     => sanitize_html_class( (string) $atts['anchor'] ),
			'class'      => implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY ) ) ),
			'align'      => $pick( $atts['align'], array( 'wide', 'full', 'left', 'right', 'center' ), '' ),
		);
	}

	// Viewer -------------------------------------------------------------------------

	/**
	 * A message only people who can edit the page see (visitors get nothing).
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private static function editor_notice( $message ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		return '<div class="upve-notice">' . esc_html( $message ) . '</div>';
	}

	/**
	 * The viewer (inline, flipbook or popup).
	 *
	 * @param array<string,mixed> $atts Options.
	 * @return string
	 */
	public static function viewer( array $atts ) {
		$atts   = wp_parse_args( $atts, self::viewer_defaults() );
		$source = Ultimate_PDF_Viewer_Embedder_Docs::source( $atts['id'], $atts['url'] );

		if ( is_wp_error( $source ) ) {
			return self::editor_notice( __( 'PDF viewer: ', 'ultimate-pdf-viewer-embedder' ) . $source->get_error_message() );
		}

		list( $type, $value ) = Ultimate_PDF_Viewer_Embedder_Docs::parse( $source );

		if ( 'a' === $type && ! Ultimate_PDF_Viewer_Embedder_Docs::author_may_embed( $value ) ) {
			return self::editor_notice( __( 'PDF viewer: this page\'s author can\'t use that PDF (it\'s protected, or attached to a private post).', 'ultimate-pdf-viewer-embedder' ) );
		}

		if ( 'u' === $type && ! Ultimate_PDF_Viewer_Embedder_Remote::is_same_site( $value ) && ! ultimate_pdf_viewer_embedder_plugin()->get_setting( 'remote' ) ) {
			return self::editor_notice( __( 'PDF viewer: PDFs from other sites are switched off in Content Powered > Ultimate PDF Viewer and Embedder.', 'ultimate-pdf-viewer-embedder' ) );
		}

		$o     = self::resolve( $atts );
		$title = '' !== $o['title'] ? $o['title'] : Ultimate_PDF_Viewer_Embedder_Docs::title( $source );

		self::$count++;
		$dom_id = '' !== $o['anchor'] ? $o['anchor'] : 'pdf-' . self::$count;

		Ultimate_PDF_Viewer_Embedder_Assets::enqueue();

		if ( ! Ultimate_PDF_Viewer_Embedder_Docs::can_view( array( 'a' => $o['access'], 'r' => $o['roles'] ) ) ) {
			return self::denied( $dom_id, $title, $o );
		}

		$config = self::config( $source, $title, $o );
		$html   = $o['lightbox'] ? self::lightbox_html( $dom_id, $source, $title, $config, $o ) : self::inline_html( $dom_id, $title, $config, $o );

		if ( $o['text'] && ! ( $o['lightbox'] && 'link' === $o['trigger'] ) ) {
			$html .= self::text_html( $source, $title );
		}

		return $html;
	}

	/**
	 * What the browser gets: where to load the PDF from and how to show it.
	 *
	 * @param string              $source Source string.
	 * @param string              $title  Title.
	 * @param array<string,mixed> $o      Resolved options.
	 * @return array<string,mixed>
	 */
	public static function config( $source, $title, array $o ) {
		$s                    = ultimate_pdf_viewer_embedder_plugin()->get_settings();
		list( $type, $value ) = Ultimate_PDF_Viewer_Embedder_Docs::parse( $source );

		$token = Ultimate_PDF_Viewer_Embedder_Docs::sign(
			array_filter(
				array(
					's' => $source,
					'x' => $o['secure'] ? 1 : 0,
					'a' => 'all' !== $o['access'] ? $o['access'] : '',
					'r' => $o['roles'],
					'w' => $o['watermark'] ? 1 : 0,
					'd' => $o['download'] ? 1 : 0,
					'f' => 'a' === $type && Ultimate_PDF_Viewer_Embedder_Docs::author_can_edit( $value ) ? 1 : 0,
				)
			)
		);

		$rules    = Ultimate_PDF_Viewer_Embedder_Docs::verify( $token );
		$ticketed = Ultimate_PDF_Viewer_Embedder_Docs::needs_ticket( $rules );
		$endpoint = add_query_arg( 't', $token, rest_url( Ultimate_PDF_Viewer_Embedder_Rest::NS . '/file' ) );
		$src      = '';
		$fallback = '';
		$download = '';

		if ( ! $ticketed ) {
			if ( 'a' === $type && ! Ultimate_PDF_Viewer_Embedder_Protect::is_protected( $value ) ) {
				$src      = (string) wp_get_attachment_url( $value );
				$fallback = $endpoint;
				$download = $o['download'] ? $src : '';
			} elseif ( 'u' === $type && Ultimate_PDF_Viewer_Embedder_Remote::is_same_site( $value ) ) {
				$src      = $value;
				$download = $o['download'] ? $src : '';
			} else {
				$src      = $endpoint;
				$download = $o['download'] ? add_query_arg( 'dl', 1, $endpoint ) : '';
			}
		}

		$watermark = null;

		if ( $o['watermark'] ) {
			$text      = self::watermark_text( (string) $s['watermark_text'] );
			$watermark = array(
				't' => $text,
				'o' => max( 3, min( 60, (int) $s['watermark_opacity'] ) ),
				'l' => in_array( $s['watermark_layout'], array( 'diagonal', 'tiled', 'footer' ), true ) ? $s['watermark_layout'] : 'diagonal',
			);
		}

		return array(
			't'      => $token,
			'src'    => $src,
			'fb'     => $fallback,
			'open'   => $ticketed || ( $o['watermark'] && self::has_user_placeholders( (string) $s['watermark_text'] ) ),
			'dl'     => $download,
			// Only for downloads: with the upload month, a file name can hint at its address.
			'name'   => $o['download'] ? Ultimate_PDF_Viewer_Embedder_Docs::filename( $source ) : '',
			'title'  => $title,
			'mode'   => $o['mode'],
			'page'   => $o['page'],
			'zoom'   => $o['zoom'],
			'scroll' => $o['scroll'],
			'side'   => $o['sidebar'],
			'h'      => $o['height'],
			'bar'    => $o['toolbar'] ? 1 : 0,
			'btn'    => array(
				'dl' => $o['download'] ? 1 : 0,
				'pr' => $o['print'] ? 1 : 0,
				'fs' => $o['fullscreen'] ? 1 : 0,
				'se' => $o['search'] && 'flipbook' !== $o['mode'] ? 1 : 0,
				'th' => $s['btn_thumbs'] ? 1 : 0,
				'zm' => $s['btn_zoom'] && 'flipbook' !== $o['mode'] ? 1 : 0,
				'md' => $s['btn_mode'] && 'flipbook' !== $o['mode'] ? 1 : 0,
				'sh' => $s['btn_share'] && $s['deep_links'] ? 1 : 0,
			),
			'sel'    => $s['text_select'] && ! $o['secure'] ? 1 : 0,
			'nt'     => $s['new_tab_links'] ? 1 : 0,
			'keys'   => $s['keyboard'] ? 1 : 0,
			'deep'   => $s['deep_links'] ? 1 : 0,
			'sec'    => $o['secure'] ? 1 : 0,
			'wm'     => $watermark,
			'track'  => $s['track'] ? 1 : 0,
			'cover'  => $s['flip_cover'] ? 1 : 0,
			'lazy'   => $o['lazy'] ? 1 : 0,
			'theme'  => $o['theme'],
			'pos'    => 'bottom' === $s['bar_position'] ? 'bottom' : 'top',
			'style'  => 'floating' === $s['bar_style'] ? 'floating' : 'bar',
		);
	}

	/**
	 * Whether the watermark mentions the viewer (so it has to be filled in
	 * per visitor, never in cached HTML).
	 *
	 * @param string $text Watermark template.
	 * @return bool
	 */
	public static function has_user_placeholders( $text ) {
		return (bool) preg_match( '/\{(email|name|username)\}/', $text );
	}

	/**
	 * Fill in a watermark template. {date} is left for the browser (cached
	 * pages would show a stale date).
	 *
	 * @param string       $text Template.
	 * @param WP_User|null $user Viewer whose details fill {email}, {name} and
	 *                           {username}; null leaves them out.
	 * @return string
	 */
	public static function watermark_text( $text, $user = null ) {
		$known = $user instanceof WP_User && $user->exists();
		$text  = strtr(
			sanitize_text_field( $text ),
			array(
				'{site}'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{email}'    => $known ? $user->user_email : '',
				'{name}'     => $known ? $user->display_name : '',
				'{username}' => $known ? $user->user_login : '',
			)
		);

		// Tidy separators left by empty placeholders: "Site ·  · " -> "Site".
		$text = preg_replace( '/(\s*[·|\-–—,]\s*){2,}/u', ' · ', (string) $text );
		$text = preg_replace( '/^[\s·|\-–—,]+|[\s·|\-–—,]+$/u', '', (string) $text );

		return Ultimate_PDF_Viewer_Embedder_Docs::clip( (string) $text, 200 );
	}

	/**
	 * Wrapper attributes shared by every layout.
	 *
	 * @param string              $dom_id  Element ID.
	 * @param string              $kind    viewer, flipbook or lightbox.
	 * @param array<string,mixed> $o       Resolved options.
	 * @param array<string,mixed> $config  Browser config.
	 * @param string              $style   Inline style.
	 * @return string
	 */
	private static function root_attrs( $dom_id, $kind, array $o, array $config, $style = '' ) {
		$s       = ultimate_pdf_viewer_embedder_plugin()->get_settings();
		$classes = array(
			'upve',
			'upve--' . $kind,
			'upve-theme-' . $o['theme'],
			'upve-bar-' . ( 'bottom' === $s['bar_position'] ? 'bottom' : 'top' ),
			'upve-style-' . ( 'floating' === $s['bar_style'] ? 'floating' : 'bar' ),
		);

		if ( $o['secure'] ) {
			$classes[] = 'upve-secure';
		}

		if ( '' !== $o['align'] ) {
			$classes[] = 'align' . $o['align'];
		}

		if ( '' !== $o['class'] ) {
			$classes[] = $o['class'];
		}

		return sprintf(
			' id="%1$s" class="%2$s" data-upve="%3$s"%4$s',
			esc_attr( $dom_id ),
			esc_attr( implode( ' ', $classes ) ),
			esc_attr( (string) wp_json_encode( $config ) ),
			'' !== $style ? ' style="' . esc_attr( $style ) . '"' : ''
		);
	}

	/**
	 * Inline viewer or flipbook.
	 *
	 * @param string              $dom_id Element ID.
	 * @param string              $title  Title.
	 * @param array<string,mixed> $config Browser config.
	 * @param array<string,mixed> $o      Resolved options.
	 * @return string
	 */
	private static function inline_html( $dom_id, $title, array $config, array $o ) {
		$style = '--upve-w:' . $o['width'] . ';--upve-h:' . ( 'auto' === $o['height'] ? '75vh' : $o['height'] ) . ';';
		$kind  = 'flipbook' === $o['mode'] ? 'flipbook' : 'viewer';

		$html  = '<div' . self::root_attrs( $dom_id, $kind, $o, $config, $style ) . '>';
		$html .= '<div class="upve-frame" role="region" aria-label="' . esc_attr( sprintf( /* translators: %s: document title. */ __( 'PDF: %s', 'ultimate-pdf-viewer-embedder' ), $title ) ) . '">';
		$html .= self::loading_html( $title, $config );
		$html .= '</div></div>';

		return $html;
	}

	/**
	 * Placeholder shown until the viewer starts (and without JavaScript).
	 *
	 * @param string              $title  Title.
	 * @param array<string,mixed> $config Browser config.
	 * @return string
	 */
	private static function loading_html( $title, array $config ) {
		$html  = '<div class="upve-status" aria-live="polite">';
		$html .= '<span class="upve-status-icon">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'file-text', 28 ) . '</span>';
		$html .= '<span class="upve-status-title">' . esc_html( $title ) . '</span>';
		$html .= '<span class="upve-status-text">' . esc_html__( 'Loading PDF…', 'ultimate-pdf-viewer-embedder' ) . '</span>';

		// Without JavaScript, a plain link (never for secure or restricted files).
		$link = '' !== $config['dl'] ? $config['dl'] : $config['src'];
		if ( '' !== $link && empty( $config['sec'] ) ) {
			$html .= '<noscript><a class="upve-status-link" href="' . esc_url( $link ) . '">' . esc_html__( 'Open the PDF', 'ultimate-pdf-viewer-embedder' ) . '</a></noscript>';
		}

		return $html . '</div>';
	}

	/**
	 * Popup trigger: a button, a text link or a cover thumbnail.
	 *
	 * @param string              $dom_id Element ID.
	 * @param string              $source Source string.
	 * @param string              $title  Title.
	 * @param array<string,mixed> $config Browser config.
	 * @param array<string,mixed> $o      Resolved options.
	 * @return string
	 */
	private static function lightbox_html( $dom_id, $source, $title, array $config, array $o ) {
		$s     = ultimate_pdf_viewer_embedder_plugin()->get_settings();
		$label = '' !== $o['label'] ? $o['label'] : ( '' !== trim( (string) $s['lightbox_label'] ) ? sanitize_text_field( $s['lightbox_label'] ) : __( 'View PDF', 'ultimate-pdf-viewer-embedder' ) );
		$tag   = 'link' === $o['trigger'] ? 'span' : 'div';
		$open  = sprintf(
			/* translators: %s: document title. */
			__( 'Open %s', 'ultimate-pdf-viewer-embedder' ),
			$title
		);

		$html = '<' . $tag . self::root_attrs( $dom_id, 'lightbox upve-trigger-' . $o['trigger'], $o, $config ) . '>';

		if ( 'link' === $o['trigger'] ) {
			$html .= '<a href="#' . esc_attr( $dom_id ) . '" class="upve-open" role="button" aria-haspopup="dialog">' . esc_html( $label ) . '</a>';
		} elseif ( 'thumbnail' === $o['trigger'] ) {
			$cover = self::cover( $source );
			$html .= '<button type="button" class="upve-open upve-open-thumb" aria-haspopup="dialog" aria-label="' . esc_attr( $open ) . '">';
			$html .= '<span class="upve-cover">' . ( '' !== $cover ? '<img src="' . esc_url( $cover ) . '" alt="" loading="lazy" decoding="async">' : '<span class="upve-cover-blank">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'file-text', 40 ) . '</span>' ) . '<span class="upve-cover-zoom">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'maximize-2', 18 ) . '</span></span>';
			$html .= '<span class="upve-open-label">' . esc_html( '' !== $o['label'] ? $o['label'] : $title ) . '</span>';
			$html .= '</button>';
		} else {
			$html .= '<button type="button" class="upve-open upve-open-button" aria-haspopup="dialog">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'flipbook' === $o['mode'] ? 'book-open' : 'file-text', 18 ) . '<span>' . esc_html( $label ) . '</span></button>';
		}

		return $html . '</' . $tag . '>';
	}

	/**
	 * Message for visitors the embed's rules leave out.
	 *
	 * @param string              $dom_id Element ID.
	 * @param string              $title  Title.
	 * @param array<string,mixed> $o      Resolved options.
	 * @return string
	 */
	private static function denied( $dom_id, $title, array $o ) {
		$message = trim( (string) ultimate_pdf_viewer_embedder_plugin()->get_setting( 'denied_message' ) );

		if ( '' === $message ) {
			$message = is_user_logged_in() ? __( 'Your account doesn\'t have access to this document.', 'ultimate-pdf-viewer-embedder' ) : __( 'Log in to view this document.', 'ultimate-pdf-viewer-embedder' );
		}

		$html  = '<div id="' . esc_attr( $dom_id ) . '" class="upve upve--denied upve-theme-' . esc_attr( $o['theme'] ) . '">';
		$html .= '<span class="upve-denied-icon">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'lock', 22 ) . '</span>';
		$html .= '<span class="upve-denied-body"><strong>' . esc_html( $title ) . '</strong><span>' . esc_html( $message ) . '</span></span>';

		if ( ! is_user_logged_in() ) {
			$here  = is_singular() ? get_permalink() : home_url( '/' );
			$html .= '<a class="upve-denied-login" href="' . esc_url( wp_login_url( (string) $here ) ) . '">' . esc_html__( 'Log in', 'ultimate-pdf-viewer-embedder' ) . '</a>';
		}

		return $html . '</div>';
	}

	/**
	 * Cover image for a document: the one made in the editor, else the
	 * preview WordPress made when the PDF was uploaded.
	 *
	 * @param string $source Source string.
	 * @param array  $row    Document row, if already loaded.
	 * @return string URL, or ''.
	 */
	public static function cover( $source, $row = null ) {
		$row = null === $row ? Ultimate_PDF_Viewer_Embedder_Docs::row( $source, 'cover' ) : $row;

		if ( $row && '' !== (string) $row['cover'] ) {
			$url = Ultimate_PDF_Viewer_Embedder_Docs::cover_url( (string) $row['cover'] );

			if ( '' !== $url ) {
				return $url;
			}
		}

		list( $type, $value ) = Ultimate_PDF_Viewer_Embedder_Docs::parse( $source );

		if ( 'a' === $type && ! Ultimate_PDF_Viewer_Embedder_Protect::is_protected( $value ) ) {
			$image = wp_get_attachment_image_src( $value, 'medium_large' );

			if ( ! $image ) {
				$image = wp_get_attachment_image_src( $value, 'medium' );
			}

			// A PDF with no preview gives back WordPress's generic file icon.
			if ( $image && ! empty( $image[0] ) && false === strpos( $image[0], '/wp-includes/images/media/' ) ) {
				return $image[0];
			}
		}

		return '';
	}

	/**
	 * The PDF's text in a collapsible section, for search engines and
	 * screen readers.
	 *
	 * @param string $source Source string.
	 * @param string $title  Title.
	 * @return string
	 */
	private static function text_html( $source, $title ) {
		$pages = Ultimate_PDF_Viewer_Embedder_Docs::text( $source );

		if ( ! $pages || '' === trim( implode( '', $pages ) ) ) {
			return '';
		}

		$s     = ultimate_pdf_viewer_embedder_plugin()->get_settings();
		$label = '' !== trim( (string) $s['seo_label'] ) ? sanitize_text_field( $s['seo_label'] ) : sprintf( /* translators: %s: document title. */ __( 'Text version: %s', 'ultimate-pdf-viewer-embedder' ), $title );

		/**
		 * Most characters of PDF text printed on the page.
		 *
		 * @param int    $max    Characters (bytes).
		 * @param string $source Document source.
		 */
		$max   = (int) apply_filters( 'ultimate_pdf_viewer_embedder_text_max', 100000, $source );
		$total = 0;
		$body  = '';

		foreach ( $pages as $i => $text ) {
			$text = trim( $text );

			if ( '' === $text ) {
				continue;
			}

			if ( $total + strlen( $text ) > $max ) {
				$text = Ultimate_PDF_Viewer_Embedder_Docs::clip( $text, max( 0, $max - $total ) ) . '…';
			}

			$total += strlen( $text );
			$body  .= '<div class="upve-text-page"><p class="upve-text-num">' . esc_html( sprintf( /* translators: %d: page number. */ __( 'Page %d', 'ultimate-pdf-viewer-embedder' ), $i + 1 ) ) . '</p>';

			foreach ( preg_split( "/\n{2,}/", $text ) as $para ) {
				$body .= '<p>' . nl2br( esc_html( $para ), false ) . '</p>';
			}

			$body .= '</div>';

			if ( $total >= $max ) {
				break;
			}
		}

		return '<details class="upve-text"' . ( $s['seo_open'] ? ' open' : '' ) . '><summary>' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'scan-text', 18 ) . '<span>' . esc_html( $label ) . '</span></summary><div class="upve-text-body">' . $body . '</div></details>';
	}

	// Library --------------------------------------------------------------------

	/**
	 * The PDFs a library shows.
	 *
	 * @param array<string,mixed> $atts Library options.
	 * @return int[] Attachment IDs.
	 */
	public static function library_ids( array $atts ) {
		$source = in_array( $atts['source'], array( 'selected', 'attached', 'all' ), true ) ? $atts['source'] : 'selected';
		$limit  = max( 1, min( 200, absint( $atts['limit'] ) ) );
		$order  = in_array( $atts['orderby'], array( 'selected', 'title', 'date', 'menu_order' ), true ) ? $atts['orderby'] : 'selected';

		if ( 'selected' === $source ) {
			$ids = is_array( $atts['ids'] ) ? $atts['ids'] : explode( ',', (string) $atts['ids'] );
			$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
			$ids = array_values( array_filter( $ids, array( 'Ultimate_PDF_Viewer_Embedder_Docs', 'is_pdf_attachment' ) ) );

			if ( 'title' === $order ) {
				usort(
					$ids,
					function ( $a, $b ) {
						return strcasecmp( get_the_title( $a ), get_the_title( $b ) );
					}
				);
			} elseif ( 'date' === $order ) {
				usort(
					$ids,
					function ( $a, $b ) {
						return strcmp( get_post_field( 'post_date', $b ), get_post_field( 'post_date', $a ) );
					}
				);
			}

			return array_slice( $ids, 0, 200 );
		}

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'application/pdf',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'title' === $order ? 'title' : ( 'menu_order' === $order ? 'menu_order' : 'date' ),
			'order'          => 'title' === $order || 'menu_order' === $order ? 'ASC' : 'DESC',
			// Protected files are only listed when picked one by one.
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Small, capped query.
				array(
					'key'     => Ultimate_PDF_Viewer_Embedder_Protect::META,
					'compare' => 'NOT EXISTS',
				),
			),
		);

		if ( 'attached' === $source ) {
			$post = get_post();

			if ( ! $post ) {
				return array();
			}

			$args['post_parent'] = $post->ID;
		}

		$ids = get_posts( $args );

		// "All" lists only files everyone may see.
		if ( 'all' === $source ) {
			$ids = array_values( array_filter( $ids, array( 'Ultimate_PDF_Viewer_Embedder_Docs', 'is_public_attachment' ) ) );
		}

		return array_map( 'intval', $ids );
	}

	/**
	 * A grid of PDFs with covers.
	 *
	 * @param array<string,mixed> $atts Library options.
	 * @return string
	 */
	public static function library( array $atts ) {
		$atts    = wp_parse_args( $atts, self::library_defaults() );
		$ids     = self::library_ids( $atts );
		$ids     = array_values( array_filter( $ids, array( 'Ultimate_PDF_Viewer_Embedder_Docs', 'author_may_embed' ) ) );
		$columns = max( 1, min( 6, absint( $atts['columns'] ) ) );
		$open    = 'tab' === $atts['open'] ? 'tab' : 'lightbox';
		$titles  = false !== self::tri( $atts['titles'] );
		$search  = true === self::tri( $atts['search'] ) || ( null === self::tri( $atts['search'] ) && count( $ids ) > 8 );
		$align   = in_array( $atts['align'], array( 'wide', 'full' ), true ) ? ' align' . $atts['align'] : '';
		$extra   = implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY ) ) );

		if ( ! $ids ) {
			return self::editor_notice( __( 'PDF library: no PDFs to show yet. Pick some in the block settings.', 'ultimate-pdf-viewer-embedder' ) );
		}

		Ultimate_PDF_Viewer_Embedder_Assets::enqueue();

		$sources = array();
		foreach ( $ids as $id ) {
			$sources[ $id ] = 'a:' . $id;
		}
		$rows = Ultimate_PDF_Viewer_Embedder_Docs::rows( array_values( $sources ) );
		$s    = ultimate_pdf_viewer_embedder_plugin()->get_settings();

		$html  = '<div class="upve-library upve-cols-' . $columns . $align . ( '' !== $extra ? ' ' . esc_attr( $extra ) : '' ) . '" style="--upve-cols:' . $columns . '">';
		$html .= $search ? '<label class="upve-library-search">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'search', 18 ) . '<input type="search" placeholder="' . esc_attr__( 'Search documents', 'ultimate-pdf-viewer-embedder' ) . '" aria-label="' . esc_attr__( 'Search documents', 'ultimate-pdf-viewer-embedder' ) . '"></label>' : '';
		$html .= '<ul class="upve-library-grid">';

		foreach ( $ids as $id ) {
			$source = $sources[ $id ];
			$row    = isset( $rows[ $source ] ) ? $rows[ $source ] : null;
			$title  = Ultimate_PDF_Viewer_Embedder_Docs::title( $source );
			$cover  = self::cover( $source, $row ? $row : array( 'cover' => '' ) );
			$o      = self::resolve(
				array(
					'id'       => $id,
					'mode'     => 'flipbook' === $atts['mode'] ? 'flipbook' : 'viewer',
					'lightbox' => 'yes',
				)
			);
			$pages  = $row ? (int) $row['pages'] : 0;
			$meta   = $pages ? sprintf( /* translators: %s: number of pages. */ _n( '%s page', '%s pages', $pages, 'ultimate-pdf-viewer-embedder' ), number_format_i18n( $pages ) ) : __( 'PDF', 'ultimate-pdf-viewer-embedder' );
			$cover  = '' !== $cover ? '<img src="' . esc_url( $cover ) . '" alt="" loading="lazy" decoding="async">' : '<span class="upve-cover-blank">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'file-text', 40 ) . '</span>';

			$html .= '<li class="upve-card" data-title="' . esc_attr( function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title ) ) . '">';

			if ( ! Ultimate_PDF_Viewer_Embedder_Docs::can_view( array( 'a' => $o['access'], 'r' => $o['roles'] ) ) ) {
				$html .= '<a class="upve-card-link is-locked" href="' . esc_url( wp_login_url( (string) get_permalink() ) ) . '">';
				$html .= '<span class="upve-cover">' . $cover . '<span class="upve-cover-lock">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'lock', 18 ) . '</span></span>';
			} else {
				$config = self::config( $source, $title, $o );
				$href   = 'tab' === $open && ! $o['secure'] && '' !== $config['src'] ? $config['src'] : '#pdf-doc-' . $id;
				$html  .= '<a class="upve-card-link" href="' . esc_url( $href ) . '" data-upve="' . esc_attr( (string) wp_json_encode( $config ) ) . '" data-open="' . esc_attr( '#' === $href[0] ? 'lightbox' : 'tab' ) . '"' . ( '#' !== $href[0] ? ' target="_blank" rel="noopener"' : ' aria-haspopup="dialog"' ) . '>';
				$html  .= '<span class="upve-cover">' . $cover . '<span class="upve-cover-zoom">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'flipbook' === $o['mode'] ? 'book-open' : 'maximize-2', 18 ) . '</span></span>';
			}

			if ( $titles ) {
				$html .= '<span class="upve-card-title">' . esc_html( $title ) . '</span>';
			} else {
				$html .= '<span class="screen-reader-text">' . esc_html( $title ) . '</span>';
			}

			$html .= '<span class="upve-card-meta">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'file-text', 14 ) . esc_html( $meta ) . '</span>';
			$html .= '</a></li>';
		}

		$html .= '</ul>';
		$html .= $search ? '<p class="upve-library-empty" hidden>' . esc_html__( 'No documents match your search.', 'ultimate-pdf-viewer-embedder' ) . '</p>' : '';

		return $html . '</div>';
	}
}
