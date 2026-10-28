<?php
/**
 * REST endpoints (namespace upve/v1).
 *
 * Public, used by the viewer on any page:
 * - POST open:  checks "who can view" for an embed and hands back the file
 *               link (with a short-lived ticket for secure and restricted
 *               embeds) and the watermark text for this visitor.
 * - GET  file:  streams the PDF for embeds that hide its address, remote
 *               PDFs, and protected files.
 * - POST track: counts a view, download, print or the pages a view reached.
 * They only act on embed tokens this site signed (see the Docs class).
 *
 * Editors only (checked per document):
 * - GET  doc:     what the block editor needs to preview a PDF.
 * - POST prepare: saves the text and cover image an editor's browser read
 *                 from a PDF (for the searchable text section and libraries).
 * - POST protect: protects or unprotects a Media Library PDF.
 * - GET  pending: PDFs still missing text or a cover (Tools tab).
 *
 * The public endpoints read the login cookie themselves: cached pages can't
 * carry a fresh REST nonce, and none of them change anything a forged
 * request could abuse (tickets and files can't be read across sites).
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
final class Ultimate_PDF_Viewer_Embedder_Rest {

	/**
	 * Namespace.
	 */
	const NS = 'upve/v1';

	/**
	 * Largest tracking request body.
	 */
	const MAX_BODY = 16384;

	/**
	 * Known bots, crawlers and headless browsers (not counted).
	 */
	const BOTS = '/bot\b|bot\/|crawl|spider|slurp|mediapartners|headless|lighthouse|pagespeed|gtmetrix|pingdom|uptime|phantomjs|puppeteer|playwright|selenium|webdriver|python|curl\/|wget|httpclient|okhttp|axios|node-fetch|go-http|java\/|libwww|scrapy|facebookexternalhit|embedly|preview|validator|prerender|semrush|ahrefs|mj12|petalbot|bytespider|yandex|baiduspider|duckduckbot|applebot|amazonbot|gptbot|chatgpt-user|oai-searchbot|claudebot|claude-user|perplexitybot|ccbot|diffbot|dataforseo/i';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function routes() {
		// Public on purpose: they only act on tokens this site signed.
		register_rest_route(
			self::NS,
			'/open',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'open' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/file',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'file' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'track' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/doc',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'doc' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/prepare',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'prepare' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/protect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'protect' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
			)
		);

		register_rest_route(
			self::NS,
			'/pending',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'pending' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Editor endpoints need someone who can write posts (each document is
	 * checked again in the callback).
	 *
	 * @return bool
	 */
	public static function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * The signed-in user: the REST user when the request had a nonce, else
	 * the login cookie.
	 *
	 * @return int
	 */
	public static function user_id() {
		$id = get_current_user_id();

		if ( $id ) {
			return $id;
		}

		$id = wp_validate_auth_cookie( '', 'logged_in' );

		return $id ? (int) $id : 0;
	}

	/**
	 * A JSON error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @param array  $extra   Extra data.
	 * @return WP_REST_Response
	 */
	private static function error( $code, $message, $status, array $extra = array() ) {
		$response = new WP_REST_Response(
			array_merge(
				array(
					'code'    => $code,
					'message' => $message,
				),
				$extra
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	// open ------------------------------------------------------------------------

	/**
	 * Check access and return where to load the file from.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function open( WP_REST_Request $request ) {
		$token = (string) $request->get_param( 't' );
		$rules = Ultimate_PDF_Viewer_Embedder_Docs::verify( $token );

		if ( ! $rules ) {
			return self::error( 'upve_bad_token', __( 'This PDF link isn\'t valid.', 'ultimate-pdf-viewer-embedder' ), 400 );
		}

		$user_id = self::user_id();

		if ( ! Ultimate_PDF_Viewer_Embedder_Docs::can_view( $rules, $user_id ) ) {
			$message = trim( (string) ultimate_pdf_viewer_embedder_plugin()->get_setting( 'denied_message' ) );

			return self::error(
				'upve_denied',
				'' !== $message ? $message : ( $user_id ? __( 'Your account doesn\'t have access to this document.', 'ultimate-pdf-viewer-embedder' ) : __( 'Log in to view this document.', 'ultimate-pdf-viewer-embedder' ) ),
				403,
				array( 'login' => $user_id ? '' : wp_login_url( (string) wp_get_referer() ) )
			);
		}

		$endpoint = add_query_arg( 't', $token, rest_url( self::NS . '/file' ) );

		if ( Ultimate_PDF_Viewer_Embedder_Docs::needs_ticket( $rules ) ) {
			$endpoint = add_query_arg( 'k', Ultimate_PDF_Viewer_Embedder_Docs::ticket( $token, $user_id ), $endpoint );
		}

		list( $type, $value ) = Ultimate_PDF_Viewer_Embedder_Docs::parse( $rules['s'] );
		$src                  = $endpoint;

		// Public, unprotected Media Library files load straight from uploads.
		if ( ! Ultimate_PDF_Viewer_Embedder_Docs::needs_ticket( $rules ) && 'a' === $type && ! Ultimate_PDF_Viewer_Embedder_Protect::is_protected( $value ) ) {
			$src = (string) wp_get_attachment_url( $value );
		}

		$watermark = '';

		if ( $rules['w'] ) {
			$user      = $user_id ? get_userdata( $user_id ) : null;
			$watermark = Ultimate_PDF_Viewer_Embedder_Render::watermark_text( (string) ultimate_pdf_viewer_embedder_plugin()->get_setting( 'watermark_text' ), $user ? $user : null );
		}

		$response = new WP_REST_Response(
			array(
				'src' => $src,
				'fb'  => $src !== $endpoint ? $endpoint : '',
				'dl'  => $rules['d'] && ! $rules['x'] ? add_query_arg( 'dl', 1, $endpoint ) : '',
				'wm'  => $watermark,
			)
		);
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	// file ------------------------------------------------------------------------

	/**
	 * Stream a PDF.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Only on errors; files end the request.
	 */
	public static function file( WP_REST_Request $request ) {
		$user_id = self::user_id();

		// Gated link WordPress hands out for protected files: editors only.
		$protected_id = absint( $request->get_param( 'p' ) );

		if ( $protected_id ) {
			if ( ! Ultimate_PDF_Viewer_Embedder_Protect::is_protected( $protected_id ) || ! user_can( $user_id, 'edit_post', $protected_id ) ) {
				return self::plain_error( __( 'This file is protected.', 'ultimate-pdf-viewer-embedder' ), 403 );
			}

			return self::send_attachment( $protected_id, false, true, true );
		}

		$token = (string) $request->get_param( 't' );
		$rules = Ultimate_PDF_Viewer_Embedder_Docs::verify( $token );

		if ( ! $rules ) {
			return self::plain_error( __( 'This PDF link isn\'t valid.', 'ultimate-pdf-viewer-embedder' ), 400 );
		}

		$ticketed = Ultimate_PDF_Viewer_Embedder_Docs::needs_ticket( $rules );

		if ( $ticketed && ! Ultimate_PDF_Viewer_Embedder_Docs::check_ticket( $request->get_param( 'k' ), $token, $user_id ) ) {
			return self::plain_error( __( 'This link has expired. Reload the page to view the PDF.', 'ultimate-pdf-viewer-embedder' ), 403 );
		}

		if ( ! Ultimate_PDF_Viewer_Embedder_Docs::can_view( $rules, $user_id ) ) {
			return self::plain_error( __( 'You don\'t have access to this document.', 'ultimate-pdf-viewer-embedder' ), 403 );
		}

		$download             = $request->get_param( 'dl' ) && $rules['d'] && ! $rules['x'];
		list( $type, $value ) = Ultimate_PDF_Viewer_Embedder_Docs::parse( $rules['s'] );

		if ( 'a' === $type ) {
			if ( ! Ultimate_PDF_Viewer_Embedder_Docs::is_pdf_attachment( $value ) ) {
				return self::plain_error( __( 'This PDF no longer exists.', 'ultimate-pdf-viewer-embedder' ), 404 );
			}

			return self::send_attachment( $value, $download, $ticketed, $rules['f'] );
		}

		// Same-site addresses outside the Media Library are fetched over
		// HTTP too, never read from disk, so the web server's own rules apply.
		$file = Ultimate_PDF_Viewer_Embedder_Remote::get( $value, Ultimate_PDF_Viewer_Embedder_Remote::is_same_site( $value ) );

		if ( is_wp_error( $file ) ) {
			$data = $file->get_error_data();

			return self::plain_error( $file->get_error_message(), is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502 );
		}

		self::send( $file, Ultimate_PDF_Viewer_Embedder_Docs::filename( $rules['s'] ), $download, $ticketed );
	}

	/**
	 * Stream a Media Library PDF.
	 *
	 * It's read from disk only when the embed's author can edit it (or it's
	 * protected, which needs the same). Otherwise, and for files offloaded to
	 * cloud storage, it's fetched from its address like a visitor would, so
	 * a file the web server guards (a paid WooCommerce download, a members'
	 * folder) can't be pulled out through a contributor's embed.
	 *
	 * @param int  $id       Attachment ID.
	 * @param bool $download Send as a download.
	 * @param bool $private  Never cache.
	 * @param bool $disk     The file may be read from disk.
	 * @return WP_REST_Response Only on errors.
	 */
	private static function send_attachment( $id, $download, $private, $disk ) {
		$file = $disk || Ultimate_PDF_Viewer_Embedder_Protect::is_protected( $id ) ? get_attached_file( $id, true ) : '';

		if ( ! $file || ! file_exists( $file ) ) {
			// Fetch the copy WordPress links to.
			remove_filter( 'wp_get_attachment_url', array( 'Ultimate_PDF_Viewer_Embedder_Protect', 'filter_url' ), 99 );
			$url = (string) wp_get_attachment_url( $id );
			add_filter( 'wp_get_attachment_url', array( 'Ultimate_PDF_Viewer_Embedder_Protect', 'filter_url' ), 99, 2 );

			$file = '' !== $url ? Ultimate_PDF_Viewer_Embedder_Remote::get( $url, true ) : new WP_Error( 'upve_missing', __( 'This PDF no longer exists.', 'ultimate-pdf-viewer-embedder' ) );

			if ( is_wp_error( $file ) ) {
				return self::plain_error( $file->get_error_message(), 404 );
			}
		}

		self::send( $file, Ultimate_PDF_Viewer_Embedder_Docs::filename( 'a:' . $id ), $download, $private );
	}

	/**
	 * A plain-text error (the file endpoint is opened by PDF.js and, now and
	 * then, by people following a link).
	 *
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_REST_Response
	 */
	private static function plain_error( $message, $status ) {
		return self::error( 'upve_file', $message, $status );
	}

	/**
	 * Send a PDF, with byte ranges so large files open on their first page
	 * quickly.
	 *
	 * @param string $file     Path.
	 * @param string $name     File name visitors see.
	 * @param bool   $download Send as a download.
	 * @param bool   $private  Never cache.
	 * @return void
	 */
	private static function send( $file, $name, $download, $private ) {
		$size  = (int) filesize( $file );
		$start = 0;
		$end   = $size - 1;
		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';

		if ( '' !== $range && ! $download && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) && ( '' !== $m[1] || '' !== $m[2] ) ) {
			if ( '' === $m[1] ) {
				$start = max( 0, $size - (int) $m[2] );
			} else {
				$start = (int) $m[1];
				$end   = '' !== $m[2] ? min( (int) $m[2], $size - 1 ) : $size - 1;
			}

			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Byte ranges must not be compressed.
		}

		@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky -- Same.

		$partial = 0 !== $start || $end !== $size - 1;

		status_header( $partial ? 206 : 200 );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		header( 'Accept-Ranges: bytes' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . str_replace( array( '"', '\\' ), '', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
		header( 'Cache-Control: ' . ( $private ? 'private, no-store, max-age=0' : 'public, max-age=3600' ) );

		if ( ! $private ) {
			// The REST API sends no-cache headers for signed-in users.
			header_remove( 'Expires' );
			header_remove( 'Pragma' );
		}

		if ( $partial ) {
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}

		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( $handle ) {
			fseek( $handle, $start );
			$left = $end - $start + 1;

			while ( $left > 0 && ! feof( $handle ) && ! connection_aborted() ) {
				$chunk = fread( $handle, min( 1048576, $left ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread

				if ( false === $chunk || '' === $chunk ) {
					break;
				}

				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary PDF data.
				$left -= strlen( $chunk );
				flush();
			}

			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		exit;
	}

	// track -----------------------------------------------------------------------

	/**
	 * Count a view, download, print, or pages read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function track( WP_REST_Request $request ) {
		$done = new WP_REST_Response( null, 204 );
		$done->header( 'Cache-Control', 'no-store' );

		$plugin = ultimate_pdf_viewer_embedder_plugin();
		$body   = (string) $request->get_body();

		if ( ! $plugin->get_setting( 'track' ) || strlen( $body ) > self::MAX_BODY || ! self::same_origin() || self::is_bot() ) {
			return $done;
		}

		$data = json_decode( $body, true, 6 );

		if ( ! is_array( $data ) || ! isset( $data['e'] ) || ! in_array( $data['e'], array( 'v', 'd', 'r', 'p' ), true ) ) {
			return $done;
		}

		$rules = Ultimate_PDF_Viewer_Embedder_Docs::verify( isset( $data['t'] ) ? $data['t'] : '' );

		if ( ! $rules ) {
			return $done;
		}

		$user_id = self::user_id();

		if ( $user_id && ! $plugin->get_setting( 'track_editors' ) && user_can( $user_id, 'edit_posts' ) ) {
			return $done;
		}

		if ( ! Ultimate_PDF_Viewer_Embedder_Docs::can_view( $rules, $user_id ) ) {
			return $done;
		}

		$doc_id = Ultimate_PDF_Viewer_Embedder_Docs::id_for( $rules['s'] );

		if ( ! $doc_id ) {
			return $done;
		}

		$row   = Ultimate_PDF_Viewer_Embedder_Docs::row( $rules['s'], 'pages, pages_trusted' );
		$count = isset( $data['n'] ) ? max( 0, min( 5000, (int) $data['n'] ) ) : 0;

		if ( $count && $row && ! (int) $row['pages_trusted'] && (int) $row['pages'] !== $count ) {
			Ultimate_PDF_Viewer_Embedder_Stats::page_count( $doc_id, $count );
		}

		// Pages can't go past the real page count (or 5,000 if unknown).
		$cap = $row && (int) $row['pages_trusted'] && (int) $row['pages'] > 0 ? (int) $row['pages'] : ( $count ? $count : 5000 );

		switch ( $data['e'] ) {
			case 'v':
				Ultimate_PDF_Viewer_Embedder_Stats::bump( $doc_id, 'views' );
				break;
			case 'd':
				Ultimate_PDF_Viewer_Embedder_Stats::bump( $doc_id, 'downloads' );
				break;
			case 'r':
				Ultimate_PDF_Viewer_Embedder_Stats::bump( $doc_id, 'prints' );
				break;
			case 'p':
				$pages = array();

				foreach ( array_slice( isset( $data['p'] ) && is_array( $data['p'] ) ? $data['p'] : array(), 0, 500 ) as $page ) {
					$page = is_numeric( $page ) ? (int) $page : 0;

					if ( $page >= 1 && $page <= $cap ) {
						$pages[ $page ] = $page;
					}
				}

				$from = isset( $data['f0'] ) ? max( 0, min( $cap, (int) $data['f0'] ) ) : 0;
				$to   = isset( $data['f1'] ) ? max( 0, min( $cap, (int) $data['f1'] ) ) : 0;

				Ultimate_PDF_Viewer_Embedder_Stats::pages( $doc_id, array_values( $pages ), $from, $to );
				break;
		}

		return $done;
	}

	/**
	 * Whether the request came from a page on this site (browsers send
	 * Origin on POST; Referer as a fallback).
	 *
	 * @return bool
	 */
	private static function same_origin() {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

		if ( '' === $origin ) {
			$origin = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		}

		return '' !== $origin && Ultimate_PDF_Viewer_Embedder_Remote::is_same_site( $origin );
	}

	/**
	 * Whether the request is from a bot, a headless browser or a prefetch.
	 *
	 * @return bool
	 */
	private static function is_bot() {
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( strlen( $agent ) < 20 || preg_match( self::BOTS, $agent ) ) {
			return true;
		}

		$purpose  = isset( $_SERVER['HTTP_SEC_PURPOSE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_PURPOSE'] ) ) : '';
		$purpose .= isset( $_SERVER['HTTP_PURPOSE'] ) ? ' ' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_PURPOSE'] ) ) : '';

		return false !== stripos( $purpose, 'prefetch' );
	}

	// Editor endpoints -------------------------------------------------------------

	/**
	 * Source from an editor request, after checking they may use it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param bool            $write   Whether they'll change the document's data.
	 * @return string|WP_Error
	 */
	private static function editor_source( WP_REST_Request $request, $write ) {
		$source = Ultimate_PDF_Viewer_Embedder_Docs::source( $request->get_param( 'id' ), (string) $request->get_param( 'url' ) );

		if ( is_wp_error( $source ) ) {
			$source->add_data( array( 'status' => 400 ) );
			return $source;
		}

		list( $type, $value ) = Ultimate_PDF_Viewer_Embedder_Docs::parse( $source );

		if ( 'a' === $type ) {
			$cap = $write || Ultimate_PDF_Viewer_Embedder_Protect::is_protected( $value ) ? 'edit_post' : 'read_post';

			if ( ! current_user_can( $cap, $value ) ) {
				return new WP_Error( 'upve_forbidden', __( 'You can\'t use that PDF.', 'ultimate-pdf-viewer-embedder' ), array( 'status' => 403 ) );
			}
		} elseif ( $write && ! current_user_can( 'edit_others_posts' ) ) {
			// Text for an address is shared by every page that embeds it.
			return new WP_Error( 'upve_forbidden', __( 'Only editors can save text for PDFs from other sites.', 'ultimate-pdf-viewer-embedder' ), array( 'status' => 403 ) );
		}

		return $source;
	}

	/**
	 * Where an editor's browser can load a document from.
	 *
	 * @param string $source Source string.
	 * @return string
	 */
	public static function editor_src( $source ) {
		list( $type, $value ) = Ultimate_PDF_Viewer_Embedder_Docs::parse( $source );

		if ( 'a' === $type && ! Ultimate_PDF_Viewer_Embedder_Protect::is_protected( $value ) ) {
			return (string) wp_get_attachment_url( $value );
		}

		if ( 'u' === $type && Ultimate_PDF_Viewer_Embedder_Remote::is_same_site( $value ) ) {
			return $value;
		}

		// Through the endpoint, with a ticket for this editor only.
		$token = Ultimate_PDF_Viewer_Embedder_Docs::sign(
			array(
				's' => $source,
				'x' => 1,
				'f' => 'a' === $type && current_user_can( 'edit_post', $value ) ? 1 : 0,
			)
		);

		return add_query_arg(
			array(
				't' => $token,
				'k' => Ultimate_PDF_Viewer_Embedder_Docs::ticket( $token, get_current_user_id() ),
			),
			rest_url( self::NS . '/file' )
		);
	}

	/**
	 * Preview details for the block editor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function doc( WP_REST_Request $request ) {
		$source = self::editor_source( $request, false );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		list( $type, $value ) = Ultimate_PDF_Viewer_Embedder_Docs::parse( $source );
		$row                  = Ultimate_PDF_Viewer_Embedder_Docs::row( $source, 'pages, cover, text_updated' );

		return new WP_REST_Response(
			array(
				'source'     => $type,
				'id'         => 'a' === $type ? $value : 0,
				'url'        => 'u' === $type ? $value : '',
				'src'        => self::editor_src( $source ),
				'title'      => Ultimate_PDF_Viewer_Embedder_Docs::title( $source ),
				'filename'   => Ultimate_PDF_Viewer_Embedder_Docs::filename( $source ),
				'pages'      => $row ? (int) $row['pages'] : 0,
				'hasText'    => $row && ! empty( $row['text_updated'] ),
				'cover'      => Ultimate_PDF_Viewer_Embedder_Render::cover( $source, $row ),
				'protected'  => 'a' === $type && Ultimate_PDF_Viewer_Embedder_Protect::is_protected( $value ),
				'canProtect' => 'a' === $type && current_user_can( 'edit_post', $value ),
				'canPrepare' => ! is_wp_error( self::editor_source( $request, true ) ),
				'remote'     => 'u' === $type && ! Ultimate_PDF_Viewer_Embedder_Remote::is_same_site( $value ),
			)
		);
	}

	/**
	 * Save text and a cover made in an editor's browser.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function prepare( WP_REST_Request $request ) {
		$source = self::editor_source( $request, true );

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$pages = $request->get_param( 'pages' );
		$count = (int) $request->get_param( 'count' );
		$cover = $request->get_param( 'cover' );
		$done  = array();

		if ( is_array( $pages ) && $count > 0 ) {
			$done['text'] = Ultimate_PDF_Viewer_Embedder_Docs::save_text( $source, $pages, $count );
		}

		if ( is_string( $cover ) && '' !== $cover ) {
			$saved = Ultimate_PDF_Viewer_Embedder_Docs::save_cover( $source, $cover );

			if ( is_wp_error( $saved ) ) {
				$saved->add_data( array( 'status' => 400 ) );
				return $saved;
			}

			$done['cover'] = Ultimate_PDF_Viewer_Embedder_Render::cover( $source );
		}

		return new WP_REST_Response( $done );
	}

	/**
	 * Protect or unprotect a Media Library PDF.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function protect( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'id' ) );

		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'upve_forbidden', __( 'You can\'t change that PDF.', 'ultimate-pdf-viewer-embedder' ), array( 'status' => 403 ) );
		}

		$result = Ultimate_PDF_Viewer_Embedder_Protect::set( $id, (bool) $request->get_param( 'protect' ) );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return new WP_REST_Response( array( 'protected' => Ultimate_PDF_Viewer_Embedder_Protect::is_protected( $id ) ) );
	}

	/**
	 * Media Library PDFs (and viewed remote PDFs) still missing text or a cover.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function pending( WP_REST_Request $request ) {
		global $wpdb;

		$all   = (bool) $request->get_param( 'all' );
		$items = array();
		$ids   = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/pdf',
				'posts_per_page' => 500,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$sources = array();
		foreach ( $ids as $id ) {
			$sources[] = 'a:' . $id;
		}

		$table = Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' );
		$urls  = $wpdb->get_col( "SELECT url FROM {$table} WHERE url <> '' ORDER BY views DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table, no input.

		foreach ( (array) $urls as $url ) {
			$sources[] = 'u:' . $url;
		}

		foreach ( $sources as $source ) {
			$row       = Ultimate_PDF_Viewer_Embedder_Docs::row( $source, 'cover, text_updated' );
			$has_text  = $row && ! empty( $row['text_updated'] );
			$has_cover = '' !== Ultimate_PDF_Viewer_Embedder_Render::cover( $source, $row );

			if ( ! $all && $has_text && $has_cover ) {
				continue;
			}

			list( $type, $value ) = Ultimate_PDF_Viewer_Embedder_Docs::parse( $source );

			$items[] = array(
				'id'       => 'a' === $type ? $value : 0,
				'url'      => 'u' === $type ? $value : '',
				'title'    => Ultimate_PDF_Viewer_Embedder_Docs::title( $source ),
				'src'      => self::editor_src( $source ),
				'hasText'  => $has_text,
				'hasCover' => $has_cover,
			);
		}

		return new WP_REST_Response(
			array(
				'items' => $items,
				'total' => count( $sources ),
			)
		);
	}
}
