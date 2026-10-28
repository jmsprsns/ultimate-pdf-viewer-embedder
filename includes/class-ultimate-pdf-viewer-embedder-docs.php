<?php
/**
 * Documents: where a PDF comes from, signed embed tokens, and the per-document
 * row that holds stats, extracted text and the cover image.
 *
 * A source is either "a:<attachment ID>" (a Media Library PDF) or
 * "u:<URL>" (any other address, Google Drive and Dropbox links included).
 *
 * Every embed on a page carries a token: the source plus the embed's rules
 * (secure mode, who can view, watermark, downloads), signed with the site's
 * secret keys. The public endpoints only act on tokens this site signed, so
 * a visitor can't point them at another file, loosen an embed's rules or
 * make the server fetch an address no editor chose.
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Document sources, tokens and rows.
 */
final class Ultimate_PDF_Viewer_Embedder_Docs {

	/**
	 * How long a file ticket (the short-lived key on secure and restricted
	 * file links) stays valid.
	 */
	const TICKET_TTL = 2 * HOUR_IN_SECONDS;

	/**
	 * Longest text kept per document, in bytes.
	 */
	const MAX_TEXT = 2000000;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'delete_attachment', array( __CLASS__, 'delete_for_attachment' ) );
	}

	// Sources --------------------------------------------------------------------

	/**
	 * Work out the source from an attachment ID or a URL.
	 *
	 * A URL that points at a PDF in this site's Media Library is treated as
	 * that attachment, so protection and stats are shared.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $url URL.
	 * @return string|WP_Error "a:<id>" or "u:<url>".
	 */
	public static function source( $id, $url ) {
		$id = absint( $id );

		if ( $id ) {
			return self::is_pdf_attachment( $id ) ? 'a:' . $id : new WP_Error( 'upve_not_pdf', __( 'That Media Library item isn\'t a PDF.', 'ultimate-pdf-viewer-embedder' ) );
		}

		$url = Ultimate_PDF_Viewer_Embedder_Remote::normalize( $url );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		if ( Ultimate_PDF_Viewer_Embedder_Remote::is_same_site( $url ) ) {
			$found = attachment_url_to_postid( $url );

			if ( $found && self::is_pdf_attachment( $found ) ) {
				return 'a:' . $found;
			}
		}

		return 'u:' . $url;
	}

	/**
	 * Split a source into its type and value.
	 *
	 * @param string $source Source string.
	 * @return array{0:string,1:int|string} Type ("a" or "u") and ID or URL; type '' when invalid.
	 */
	public static function parse( $source ) {
		$source = (string) $source;

		if ( 0 === strpos( $source, 'a:' ) ) {
			$id = absint( substr( $source, 2 ) );

			return $id ? array( 'a', $id ) : array( '', 0 );
		}

		if ( 0 === strpos( $source, 'u:' ) ) {
			$url = substr( $source, 2 );

			return '' !== $url ? array( 'u', $url ) : array( '', 0 );
		}

		return array( '', 0 );
	}

	/**
	 * Whether an ID is a PDF in the Media Library.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function is_pdf_attachment( $id ) {
		return $id > 0 && 'attachment' === get_post_type( $id ) && 'application/pdf' === get_post_mime_type( $id );
	}

	/**
	 * Whether the author of the content being shown may put this PDF on a
	 * page. Stops a contributor from previewing a draft that embeds a PDF
	 * they can't otherwise see (one attached to a private post, or a
	 * protected file) and reading its address from the preview.
	 *
	 * Content outside a post (widgets, theme templates) is trusted: only
	 * administrators can edit it.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function author_may_embed( $id ) {
		$post = get_post();

		if ( ! $post || 'attachment' === $post->post_type ) {
			return true;
		}

		$author = (int) $post->post_author;

		if ( Ultimate_PDF_Viewer_Embedder_Protect::is_protected( $id ) ) {
			return $author > 0 && user_can( $author, 'edit_post', $id );
		}

		return $author > 0 && user_can( $author, 'read_post', $id );
	}

	/**
	 * Whether the author of the content being shown can edit this PDF. Only
	 * then may the file endpoint read it from disk; otherwise it's fetched
	 * over HTTP like any visitor would, so folders the web server protects
	 * (paid WooCommerce downloads, membership files) stay protected.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function author_can_edit( $id ) {
		$post = get_post();

		if ( ! $post || 'attachment' === $post->post_type ) {
			return true;
		}

		return (int) $post->post_author > 0 && user_can( (int) $post->post_author, 'edit_post', $id );
	}

	/**
	 * Whether a Media Library PDF can be listed for everyone (its parent post
	 * is published, or it isn't attached to anything).
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function is_public_attachment( $id ) {
		$parent = (int) wp_get_post_parent_id( $id );

		return 0 === $parent || 'publish' === get_post_status( $parent );
	}

	/**
	 * A readable title for a source.
	 *
	 * @param string $source Source string.
	 * @return string
	 */
	public static function title( $source ) {
		list( $type, $value ) = self::parse( $source );

		if ( 'a' === $type ) {
			// Plain text: every caller escapes it for where it goes.
			$title = trim( html_entity_decode( wp_strip_all_tags( get_the_title( $value ) ), ENT_QUOTES, 'UTF-8' ) );

			return '' !== $title ? $title : self::filename( $source );
		}

		return self::filename( $source );
	}

	/**
	 * The file name visitors see when they download.
	 *
	 * @param string $source Source string.
	 * @return string
	 */
	public static function filename( $source ) {
		list( $type, $value ) = self::parse( $source );
		$name                 = '';

		if ( 'a' === $type ) {
			$name = (string) get_post_meta( $value, Ultimate_PDF_Viewer_Embedder_Protect::ORIGINAL_META, true );

			if ( '' === $name ) {
				$name = wp_basename( (string) get_attached_file( $value ) );
			}
		} elseif ( 'u' === $type ) {
			$path = (string) wp_parse_url( $value, PHP_URL_PATH );
			$name = rawurldecode( wp_basename( $path ) );
		}

		$name = sanitize_file_name( $name );

		if ( '' === $name || '.pdf' !== strtolower( substr( $name, -4 ) ) ) {
			$name = ( '' !== $name ? $name : 'document' ) . '.pdf';
		}

		return $name;
	}

	// Tokens -----------------------------------------------------------------------

	/**
	 * Signing key, from the site's secret keys in wp-config.php.
	 *
	 * @return string
	 */
	private static function key() {
		return hash_hmac( 'sha256', 'ultimate-pdf-viewer-embedder', wp_salt( 'secure_auth' ) . wp_salt( 'nonce' ) );
	}

	/**
	 * URL-safe base64.
	 *
	 * @param string $data Bytes.
	 * @return string
	 */
	private static function b64( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Token encoding.
	}

	/**
	 * Sign an embed: its source and rules.
	 *
	 * @param array<string,mixed> $payload s (source), x (secure), a (access), r (roles), w (watermark), d (downloads),
	 *                                     f (the file may be read from disk: the embed's author can edit it).
	 * @return string
	 */
	public static function sign( array $payload ) {
		$body = self::b64( (string) wp_json_encode( $payload ) );

		return $body . '.' . substr( hash_hmac( 'sha256', 'embed|' . $body, self::key() ), 0, 32 );
	}

	/**
	 * Check a token and return what it says.
	 *
	 * @param mixed $token Token from a request.
	 * @return array<string,mixed>|null Null if it wasn't signed by this site.
	 */
	public static function verify( $token ) {
		if ( ! is_string( $token ) || strlen( $token ) > 4000 || ! preg_match( '/^([A-Za-z0-9_-]+)\.([a-f0-9]{32})$/', $token, $m ) ) {
			return null;
		}

		if ( ! hash_equals( substr( hash_hmac( 'sha256', 'embed|' . $m[1], self::key() ), 0, 32 ), $m[2] ) ) {
			return null;
		}

		$json    = base64_decode( strtr( $m[1], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Token decoding.
		$payload = is_string( $json ) ? json_decode( $json, true ) : null;

		if ( ! is_array( $payload ) || empty( $payload['s'] ) || ! is_string( $payload['s'] ) ) {
			return null;
		}

		list( $type ) = self::parse( $payload['s'] );

		if ( '' === $type ) {
			return null;
		}

		return array(
			's' => $payload['s'],
			'x' => ! empty( $payload['x'] ),
			'a' => isset( $payload['a'] ) && in_array( $payload['a'], array( 'all', 'login', 'roles' ), true ) ? $payload['a'] : 'all',
			'r' => isset( $payload['r'] ) && is_array( $payload['r'] ) ? array_values( array_filter( $payload['r'], 'is_string' ) ) : array(),
			'w' => ! empty( $payload['w'] ),
			'd' => ! empty( $payload['d'] ),
			'f' => ! empty( $payload['f'] ),
		);
	}

	/**
	 * Whether an embed's file has to go through a short-lived ticket.
	 *
	 * @param array<string,mixed> $rules Verified token.
	 * @return bool
	 */
	public static function needs_ticket( array $rules ) {
		return $rules['x'] || 'all' !== $rules['a'];
	}

	/**
	 * A ticket for the file link of a secure or restricted embed, tied to
	 * the token and the signed-in user, valid for a couple of hours.
	 *
	 * @param string $token   Embed token.
	 * @param int    $user_id User ID (0 for visitors).
	 * @return string
	 */
	public static function ticket( $token, $user_id ) {
		$expires = time() + self::TICKET_TTL;

		return $expires . '.' . substr( hash_hmac( 'sha256', 'ticket|' . $expires . '|' . (int) $user_id . '|' . $token, self::key() ), 0, 32 );
	}

	/**
	 * Check a ticket.
	 *
	 * @param mixed  $ticket  Ticket from the request.
	 * @param string $token   Embed token.
	 * @param int    $user_id Current user.
	 * @return bool
	 */
	public static function check_ticket( $ticket, $token, $user_id ) {
		if ( ! is_string( $ticket ) || ! preg_match( '/^(\d{9,11})\.([a-f0-9]{32})$/', $ticket, $m ) ) {
			return false;
		}

		if ( (int) $m[1] < time() || (int) $m[1] > time() + self::TICKET_TTL + 60 ) {
			return false;
		}

		return hash_equals( substr( hash_hmac( 'sha256', 'ticket|' . $m[1] . '|' . (int) $user_id . '|' . $token, self::key() ), 0, 32 ), $m[2] );
	}

	/**
	 * Whether a visitor passes an embed's "who can view" rule.
	 *
	 * @param array<string,mixed> $rules   Verified token (or the same keys).
	 * @param int|null            $user_id User to check (default: the current user).
	 * @return bool
	 */
	public static function can_view( array $rules, $user_id = null ) {
		if ( 'all' === $rules['a'] ) {
			return true;
		}

		$user = get_userdata( null === $user_id ? get_current_user_id() : (int) $user_id );

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		if ( 'login' === $rules['a'] || user_can( $user, 'manage_options' ) ) {
			return true;
		}

		return (bool) array_intersect( (array) $user->roles, (array) $rules['r'] );
	}

	// Rows ---------------------------------------------------------------------------

	/**
	 * The document row ID for a source, created on first use.
	 *
	 * @param string $source Source string.
	 * @return int 0 if it couldn't be created.
	 */
	public static function id_for( $source ) {
		global $wpdb;

		$table = Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' );
		$key   = sha1( $source );
		$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_key = %s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.

		if ( $id ) {
			return $id;
		}

		list( $type, $value ) = self::parse( $source );

		if ( '' === $type ) {
			return 0;
		}

		// INSERT IGNORE: two first views at once can't make two rows.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (source_key, attachment_id, url, title, created) VALUES (%s, %d, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table.
				$key,
				'a' === $type ? $value : 0,
				'u' === $type ? $value : '',
				self::clip( self::title( $source ), 250 ),
				current_time( 'mysql', true )
			)
		);

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source_key = %s", $key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * The document row for a source, without creating one.
	 *
	 * @param string $source  Source string.
	 * @param string $columns Columns to read.
	 * @return array<string,mixed>|null
	 */
	public static function row( $source, $columns = 'id, pages, pages_trusted, cover, text_updated' ) {
		global $wpdb;

		$columns = preg_replace( '/[^a-z_, ]/', '', $columns );
		$table   = Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' );
		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE source_key = %s", sha1( $source ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table, columns filtered.

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Rows for several sources at once (library covers), keyed by source.
	 *
	 * @param string[] $sources Source strings.
	 * @return array<string,array<string,mixed>>
	 */
	public static function rows( array $sources ) {
		global $wpdb;

		if ( ! $sources ) {
			return array();
		}

		$keys = array();
		foreach ( $sources as $source ) {
			$keys[ sha1( $source ) ] = $source;
		}

		$table = Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' );
		$in    = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$found = $wpdb->get_results( $wpdb->prepare( "SELECT source_key, id, pages, cover FROM {$table} WHERE source_key IN ({$in})", array_keys( $keys ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery -- Own table, placeholders built above.

		$rows = array();
		foreach ( (array) $found as $row ) {
			$rows[ $keys[ $row['source_key'] ] ] = $row;
		}

		return $rows;
	}

	/**
	 * Saved text, one string per page.
	 *
	 * @param string $source Source string.
	 * @return string[]
	 */
	public static function text( $source ) {
		$row   = self::row( $source, 'text' );
		$pages = $row && is_string( $row['text'] ) ? json_decode( $row['text'], true ) : null;

		return is_array( $pages ) ? array_values( array_filter( $pages, 'is_string' ) ) : array();
	}

	/**
	 * Save the text and page count an editor's browser pulled from the PDF.
	 *
	 * @param string   $source Source string.
	 * @param string[] $pages  Text per page.
	 * @param int      $count  Page count.
	 * @return bool
	 */
	public static function save_text( $source, array $pages, $count ) {
		global $wpdb;

		$id = self::id_for( $source );

		if ( ! $id ) {
			return false;
		}

		$clean = array();
		$total = 0;

		foreach ( array_slice( $pages, 0, 5000 ) as $page ) {
			$page = is_string( $page ) ? self::clean_text( $page ) : '';

			if ( $total + strlen( $page ) > self::MAX_TEXT ) {
				break;
			}

			$total  += strlen( $page );
			$clean[] = $page;
		}

		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' ),
			array(
				'text'          => wp_json_encode( $clean ),
				'text_updated'  => current_time( 'mysql', true ),
				'pages'         => max( 0, min( 100000, (int) $count ) ),
				'pages_trusted' => 1,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Plain text: valid UTF-8, no tags or control characters, tidy spacing.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function clean_text( $text ) {
		$text = wp_check_invalid_utf8( $text, true );
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
		$text = preg_replace( "/[ \t\x{00A0}]+/u", ' ', (string) $text );
		$text = preg_replace( "/ *\n */", "\n", (string) $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Shorten text to a byte length without breaking a character.
	 *
	 * @param string $text Text.
	 * @param int    $max  Bytes.
	 * @return string
	 */
	public static function clip( $text, $max ) {
		$text = (string) $text;

		if ( strlen( $text ) <= $max ) {
			return $text;
		}

		return function_exists( 'mb_strcut' ) ? mb_strcut( $text, 0, $max, 'UTF-8' ) : substr( $text, 0, $max );
	}

	/**
	 * Remove the row, stats and cover of a deleted Media Library PDF.
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	public static function delete_for_attachment( $id ) {
		global $wpdb;

		if ( 'application/pdf' !== get_post_mime_type( $id ) ) {
			return;
		}

		$row = self::row( 'a:' . (int) $id, 'id, cover' );

		if ( ! $row ) {
			return;
		}

		self::delete_cover_file( (string) $row['cover'] );

		$wpdb->delete( Ultimate_PDF_Viewer_Embedder_Install::table( 'daily' ), array( 'doc_id' => (int) $row['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Ultimate_PDF_Viewer_Embedder_Install::table( 'pages' ), array( 'doc_id' => (int) $row['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' ), array( 'id' => (int) $row['id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	// Covers -------------------------------------------------------------------------

	/**
	 * Folder for cover images, inside uploads.
	 *
	 * @return array{dir:string,url:string}|null
	 */
	public static function cover_dir() {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}

		return array(
			'dir' => trailingslashit( $uploads['basedir'] ) . 'pdf-covers',
			'url' => trailingslashit( $uploads['baseurl'] ) . 'pdf-covers',
		);
	}

	/**
	 * Public URL of a document's cover.
	 *
	 * @param string $file Saved cover file name.
	 * @return string
	 */
	public static function cover_url( $file ) {
		$dir = self::cover_dir();

		if ( ! $dir || ! preg_match( '/^[a-z0-9-]+\.jpg$/', (string) $file ) || ! file_exists( $dir['dir'] . '/' . $file ) ) {
			return '';
		}

		return $dir['url'] . '/' . $file;
	}

	/**
	 * Save a cover image (the first page, drawn in an editor's browser).
	 *
	 * The upload is decoded, checked to be a real image of sensible size and
	 * re-encoded as a JPEG by WordPress's image editor, so whatever was sent,
	 * only fresh pixels are written. The file name is random.
	 *
	 * @param string $source Source string.
	 * @param string $data   Base64 image data (with or without a data: prefix).
	 * @return bool|WP_Error
	 */
	public static function save_cover( $source, $data ) {
		global $wpdb;

		$data  = preg_replace( '#^data:image/(?:jpeg|png|webp);base64,#', '', (string) $data );
		$bytes = strlen( $data ) <= 4000000 ? base64_decode( $data, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Image upload.

		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return new WP_Error( 'upve_cover', __( 'The cover image was empty.', 'ultimate-pdf-viewer-embedder' ) );
		}

		$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid images are rejected below.

		if ( ! $info || ! in_array( $info[2], array( IMAGETYPE_JPEG, IMAGETYPE_PNG ), true ) || $info[0] < 20 || $info[1] < 20 || $info[0] > 3000 || $info[1] > 4000 ) {
			return new WP_Error( 'upve_cover', __( 'The cover image wasn\'t a valid picture.', 'ultimate-pdf-viewer-embedder' ) );
		}

		$dir = self::cover_dir();

		if ( ! $dir || ! wp_mkdir_p( $dir['dir'] ) ) {
			return new WP_Error( 'upve_cover', __( 'The uploads folder isn\'t writable.', 'ultimate-pdf-viewer-embedder' ) );
		}

		if ( ! file_exists( $dir['dir'] . '/index.php' ) ) {
			file_put_contents( $dir['dir'] . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		// A temporary copy beside the covers, with a real image extension
		// (wp_tempnam() isn't loaded in REST requests).
		$tmp = $dir['dir'] . '/tmp-' . strtolower( wp_generate_password( 16, false ) ) . ( IMAGETYPE_PNG === $info[2] ? '.png' : '.jpg' );

		if ( false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'upve_cover', __( 'Couldn\'t save the cover image.', 'ultimate-pdf-viewer-embedder' ) );
		}

		$editor = wp_get_image_editor( $tmp, array( 'mime_type' => IMAGETYPE_PNG === $info[2] ? 'image/png' : 'image/jpeg' ) );

		if ( is_wp_error( $editor ) ) {
			wp_delete_file( $tmp );
			return $editor;
		}

		$editor->resize( 900, 1400, false );
		$editor->set_quality( 82 );

		$doc_id = self::id_for( $source );
		$name   = $doc_id . '-' . strtolower( wp_generate_password( 12, false ) ) . '.jpg';
		$saved  = $editor->save( $dir['dir'] . '/' . $name, 'image/jpeg' );

		wp_delete_file( $tmp );

		if ( is_wp_error( $saved ) || ! $doc_id ) {
			return is_wp_error( $saved ) ? $saved : new WP_Error( 'upve_cover', __( 'Couldn\'t save the cover image.', 'ultimate-pdf-viewer-embedder' ) );
		}

		$old = self::row( $source, 'cover' );

		$wpdb->update( Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' ), array( 'cover' => $name ), array( 'id' => $doc_id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( $old && '' !== $old['cover'] && $old['cover'] !== $name ) {
			self::delete_cover_file( (string) $old['cover'] );
		}

		return true;
	}

	/**
	 * Delete a cover file.
	 *
	 * @param string $file Saved cover file name.
	 * @return void
	 */
	public static function delete_cover_file( $file ) {
		$dir = self::cover_dir();

		if ( $dir && preg_match( '/^[a-z0-9-]+\.jpg$/', $file ) && file_exists( $dir['dir'] . '/' . $file ) ) {
			wp_delete_file( $dir['dir'] . '/' . $file );
		}
	}
}
