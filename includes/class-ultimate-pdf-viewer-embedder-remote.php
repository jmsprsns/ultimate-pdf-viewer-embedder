<?php
/**
 * PDFs from other sites: share-link conversion, and a cached server-side copy.
 *
 * Browsers can't read most other sites' files directly (Google Drive and
 * many hosts don't allow it), so the viewer loads remote PDFs through this
 * site. Only addresses an editor put in an embed can be fetched (the embed
 * token is signed), and every fetch:
 * - goes through wp_safe_remote_get(), which refuses private and loopback
 *   addresses on every redirect, plus a check here for link-local, carrier
 *   NAT and other reserved ranges (cloud metadata services live there);
 * - stops at the size limit in the settings;
 * - must start with "%PDF-", or it's thrown away.
 * Copies are kept in uploads/upve-cache under unguessable names for the
 * hours set on the settings page.
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remote PDF handling.
 */
final class Ultimate_PDF_Viewer_Embedder_Remote {

	/**
	 * Cache folder name inside uploads.
	 */
	const DIR = 'upve-cache';

	/**
	 * Clean up an address and turn share links into direct file links.
	 *
	 * @param mixed $url Address an editor entered.
	 * @return string|WP_Error
	 */
	public static function normalize( $url ) {
		$url = is_string( $url ) ? trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) ) : '';

		if ( '' === $url ) {
			return new WP_Error( 'upve_no_source', __( 'Choose a PDF from the Media Library or paste its address.', 'ultimate-pdf-viewer-embedder' ) );
		}

		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = home_url( $url );
		}

		$url = esc_url_raw( $url, array( 'http', 'https' ) );

		if ( '' === $url || strlen( $url ) > 2000 ) {
			return new WP_Error( 'upve_bad_url', __( 'That doesn\'t look like a web address.', 'ultimate-pdf-viewer-embedder' ) );
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
			return new WP_Error( 'upve_bad_url', __( 'That doesn\'t look like a web address.', 'ultimate-pdf-viewer-embedder' ) );
		}

		$host  = strtolower( $parts['host'] );
		$path  = isset( $parts['path'] ) ? $parts['path'] : '';
		$query = array();

		if ( isset( $parts['query'] ) ) {
			wp_parse_str( $parts['query'], $query );
		}

		// Google Drive: /file/d/{id}/view, /open?id={id}, /uc?id={id}.
		if ( 'drive.google.com' === $host || 'drive.usercontent.google.com' === $host ) {
			$id = '';

			if ( preg_match( '#/file/d/([A-Za-z0-9_-]{10,})#', $path, $m ) ) {
				$id = $m[1];
			} elseif ( isset( $query['id'] ) && is_string( $query['id'] ) && preg_match( '/^[A-Za-z0-9_-]{10,}$/', $query['id'] ) ) {
				$id = $query['id'];
			}

			if ( '' !== $id ) {
				return 'https://drive.usercontent.google.com/download?id=' . $id . '&export=download&confirm=t';
			}
		}

		// Google Docs, Sheets and Slides: export as PDF.
		if ( 'docs.google.com' === $host && preg_match( '#^/(document|spreadsheets|presentation)/d/([A-Za-z0-9_-]{10,})#', $path, $m ) ) {
			if ( 'presentation' === $m[1] ) {
				return 'https://docs.google.com/presentation/d/' . $m[2] . '/export/pdf';
			}

			return 'https://docs.google.com/' . $m[1] . '/d/' . $m[2] . '/export?format=pdf';
		}

		// Dropbox: a shared link downloads the file with dl=1.
		if ( in_array( $host, array( 'www.dropbox.com', 'dropbox.com' ), true ) && ( 0 === strpos( $path, '/s/' ) || 0 === strpos( $path, '/scl/' ) ) ) {
			unset( $query['raw'] );
			$query['dl'] = '1';

			return 'https://www.dropbox.com' . $path . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		return $url;
	}

	/**
	 * Whether an address is on this site (so the browser can load it directly).
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function is_same_site( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		if ( '' === $host ) {
			return false;
		}

		$own = array();
		foreach ( array( home_url(), site_url(), content_url() ) as $base ) {
			$base_host = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );

			if ( '' !== $base_host ) {
				$own[] = preg_replace( '/^www\./', '', $base_host );
			}
		}

		return in_array( preg_replace( '/^www\./', '', $host ), $own, true );
	}

	/**
	 * Whether a host name resolves only to public addresses.
	 *
	 * wp_safe_remote_get() already refuses loopback and private ranges; this
	 * also refuses link-local (169.254.x.x, where cloud metadata services
	 * answer), carrier-grade NAT, and IPv6 ranges it doesn't check.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function is_public_host( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$host = trim( $host, '[]' );

		if ( '' === $host ) {
			return false;
		}

		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : array();

		if ( ! $ips ) {
			$v4  = gethostbynamel( $host );
			$ips = is_array( $v4 ) ? $v4 : array();

			if ( function_exists( 'dns_get_record' ) ) {
				$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Lookup failures just mean no IPv6.
				foreach ( is_array( $v6 ) ? $v6 : array() as $record ) {
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = $record['ipv6'];
					}
				}
			}
		}

		if ( ! $ips ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}

			// Carrier-grade NAT (100.64.0.0/10) isn't covered by the flags above.
			if ( preg_match( '/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $ip ) ) {
				return false;
			}

			// IPv6 unique-local, link-local and IPv4-mapped addresses.
			if ( false !== strpos( $ip, ':' ) && preg_match( '/^(f[cd]|fe[89ab]|::ffff:)/i', $ip ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The cache folder, created on first use.
	 *
	 * @return string Empty if uploads isn't writable.
	 */
	private static function dir() {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::DIR;

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return '';
			}
		}

		// Apache: no direct access. Elsewhere the names are unguessable.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return $dir;
	}

	/**
	 * Cached copy of a remote PDF, fetching it if it's missing or stale.
	 *
	 * @param string $url    Normalized address from a verified token.
	 * @param bool   $own    The site's own Media Library file kept in cloud
	 *                       storage (fetched even with remote PDFs off).
	 * @return string|WP_Error Local file path.
	 */
	public static function get( $url, $own = false ) {
		$plugin = ultimate_pdf_viewer_embedder_plugin();

		if ( ! $own && ! $plugin->get_setting( 'remote' ) ) {
			return new WP_Error( 'upve_remote_off', __( 'PDFs from other sites are switched off in the settings.', 'ultimate-pdf-viewer-embedder' ), array( 'status' => 403 ) );
		}

		$dir = self::dir();

		if ( '' === $dir ) {
			return new WP_Error( 'upve_cache', __( 'The uploads folder isn\'t writable.', 'ultimate-pdf-viewer-embedder' ), array( 'status' => 500 ) );
		}

		$file  = $dir . '/' . hash_hmac( 'sha256', $url, wp_salt( 'secure_auth' ) ) . '.pdf';
		$hours = max( 1, (int) $plugin->get_setting( 'remote_hours' ) );

		if ( file_exists( $file ) && filemtime( $file ) > time() - $hours * HOUR_IN_SECONDS ) {
			return $file;
		}

		$fetched = self::fetch( $url, $file );

		// If the source is down, an older copy beats an error.
		if ( is_wp_error( $fetched ) && file_exists( $file ) ) {
			return $file;
		}

		return $fetched;
	}

	/**
	 * Download a remote PDF into the cache.
	 *
	 * @param string $url  Address.
	 * @param string $file Destination path.
	 * @return string|WP_Error The path.
	 */
	private static function fetch( $url, $file ) {
		$max  = max( 1, (int) ultimate_pdf_viewer_embedder_plugin()->get_setting( 'remote_max_mb' ) ) * MB_IN_BYTES;
		$tmp  = $file . '.' . wp_generate_password( 8, false ) . '.tmp';
		$resp = null;

		// Redirects are followed here, one hop at a time, so every address
		// gets the public-address check (older WordPress versions don't
		// refuse link-local addresses on redirects themselves).
		for ( $hop = 0; $hop <= 5; $hop++ ) {
			if ( ! self::is_public_host( $url ) ) {
				return new WP_Error( 'upve_remote_blocked', __( 'That address isn\'t on the public internet.', 'ultimate-pdf-viewer-embedder' ), array( 'status' => 403 ) );
			}

			$resp = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => 30,
					'redirection'         => 0,
					'stream'              => true,
					'filename'            => $tmp,
					'limit_response_size' => $max + 1,
					'user-agent'          => 'Mozilla/5.0 (compatible; UltimatePDFViewer/' . ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION . '; +' . home_url( '/' ) . ')',
					'headers'             => array( 'Accept' => 'application/pdf,*/*;q=0.5' ),
				)
			);

			$code     = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
			$location = is_wp_error( $resp ) ? '' : wp_remote_retrieve_header( $resp, 'location' );
			$location = is_array( $location ) ? (string) reset( $location ) : (string) $location;

			if ( $code < 300 || $code > 399 || '' === $location ) {
				break;
			}

			$next = esc_url_raw( WP_Http::make_absolute_url( $location, $url ), array( 'http', 'https' ) );

			if ( '' === $next || 5 === $hop ) {
				$resp = new WP_Error( 'upve_redirects', 'Too many redirects.' );
				break;
			}

			$url = $next;
		}

		$error = null;

		if ( is_wp_error( $resp ) ) {
			$error = new WP_Error( 'upve_remote_failed', __( 'The PDF couldn\'t be downloaded from its site.', 'ultimate-pdf-viewer-embedder' ), array( 'status' => 502 ) );
		} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			$error = new WP_Error(
				'upve_remote_failed',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'The PDF\'s site answered with error %d. Check the link is public.', 'ultimate-pdf-viewer-embedder' ), (int) wp_remote_retrieve_response_code( $resp ) ),
				array( 'status' => 502 )
			);
		} elseif ( ! file_exists( $tmp ) || filesize( $tmp ) > $max ) {
			$error = new WP_Error( 'upve_remote_large', __( 'That PDF is bigger than the size limit in the settings.', 'ultimate-pdf-viewer-embedder' ), array( 'status' => 413 ) );
		} elseif ( ! self::looks_like_pdf( $tmp ) ) {
			$error = new WP_Error( 'upve_remote_not_pdf', __( 'That link didn\'t return a PDF. For Google Drive and Dropbox, share it as "Anyone with the link".', 'ultimate-pdf-viewer-embedder' ), array( 'status' => 415 ) );
		}

		if ( $error ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return $error;
		}

		// Rename into place, so a reader never sees half a file.
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace in our own cache folder.
			wp_delete_file( $tmp );
			return new WP_Error( 'upve_cache', __( 'The uploads folder isn\'t writable.', 'ultimate-pdf-viewer-embedder' ), array( 'status' => 500 ) );
		}

		return $file;
	}

	/**
	 * Whether a file starts like a PDF ("%PDF-" in its first kilobyte).
	 *
	 * @param string $file Path.
	 * @return bool
	 */
	public static function looks_like_pdf( $file ) {
		$handle = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return false;
		}

		$head = (string) fread( $handle, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return false !== strpos( $head, '%PDF-' );
	}

	/**
	 * Delete cached copies older than twice the cache time, and stray
	 * half-downloaded files.
	 *
	 * @return void
	 */
	public static function prune() {
		$uploads = wp_upload_dir( null, false );
		$dir     = empty( $uploads['error'] ) ? trailingslashit( $uploads['basedir'] ) . self::DIR : '';

		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}

		$hours  = max( 1, (int) ultimate_pdf_viewer_embedder_plugin()->get_setting( 'remote_hours' ) );
		$cutoff = time() - 2 * $hours * HOUR_IN_SECONDS;

		// Two globs: GLOB_BRACE isn't available on every system.
		foreach ( array_merge( (array) glob( $dir . '/*.pdf' ), (array) glob( $dir . '/*.tmp' ) ) as $file ) {
			$is_tmp = '.tmp' === substr( $file, -4 );

			if ( filemtime( $file ) < ( $is_tmp ? time() - HOUR_IN_SECONDS : $cutoff ) ) {
				wp_delete_file( $file );
			}
		}
	}
}
