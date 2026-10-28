<?php
/**
 * Protected files: Media Library PDFs whose address is kept secret.
 *
 * A normal upload lives at a guessable address (uploads/2026/10/price-list.pdf)
 * that works for anyone, whatever the viewer's rules say. Protecting a PDF:
 * - renames it (and its preview images) with a random 20-character suffix,
 *   so the address can't be guessed, and links to the old one stop working;
 * - hides the new address from everyone who can't edit the file: in pages,
 *   the REST API, the media modal and attachment pages, WordPress hands out
 *   a link to this plugin's endpoint instead, which only serves the file to
 *   editors and to viewers that pass the embed's rules.
 * Unprotecting renames it back. Deactivating the plugin leaves the files
 * where they are, at their random names, so nothing breaks.
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Protected file handling.
 */
final class Ultimate_PDF_Viewer_Embedder_Protect {

	/**
	 * Post meta flag on protected PDFs.
	 */
	const META = '_upve_protected';

	/**
	 * Post meta with the file name visitors see on download.
	 */
	const ORIGINAL_META = '_upve_original_name';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'filter_url' ), 99, 2 );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'filter_image_src' ), 99, 2 );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_fields' ), 10, 2 );
	}

	/**
	 * Whether a PDF is protected.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	public static function is_protected( $id ) {
		return (bool) get_post_meta( (int) $id, self::META, true );
	}

	/**
	 * Whether the current user may see a protected file's real address.
	 *
	 * @param int $id Attachment ID.
	 * @return bool
	 */
	private static function can_see( $id ) {
		return current_user_can( 'edit_post', $id );
	}

	/**
	 * Link handed out instead of a protected file's real address.
	 *
	 * @param int $id Attachment ID.
	 * @return string
	 */
	public static function gated_url( $id ) {
		return add_query_arg( 'p', (int) $id, rest_url( Ultimate_PDF_Viewer_Embedder_Rest::NS . '/file' ) );
	}

	/**
	 * Swap a protected file's address for the gated link.
	 *
	 * @param string $url Attachment URL.
	 * @param int    $id  Attachment ID.
	 * @return string
	 */
	public static function filter_url( $url, $id ) {
		if ( self::is_protected( $id ) && ! self::can_see( $id ) ) {
			return self::gated_url( $id );
		}

		return $url;
	}

	/**
	 * No preview images of protected files for people who can't edit them.
	 *
	 * @param array|false $image Image data.
	 * @param int         $id    Attachment ID.
	 * @return array|false
	 */
	public static function filter_image_src( $image, $id ) {
		if ( $image && self::is_protected( $id ) && ! self::can_see( $id ) ) {
			return false;
		}

		return $image;
	}

	/**
	 * Protect or unprotect a PDF.
	 *
	 * @param int  $id      Attachment ID.
	 * @param bool $protect True to protect.
	 * @return true|WP_Error
	 */
	public static function set( $id, $protect ) {
		$id = (int) $id;

		if ( ! Ultimate_PDF_Viewer_Embedder_Docs::is_pdf_attachment( $id ) ) {
			return new WP_Error( 'upve_not_pdf', __( 'That Media Library item isn\'t a PDF.', 'ultimate-pdf-viewer-embedder' ) );
		}

		if ( self::is_protected( $id ) === (bool) $protect ) {
			return true;
		}

		$file = get_attached_file( $id, true );

		if ( ! $file || ! file_exists( $file ) ) {
			return new WP_Error( 'upve_not_local', __( 'This PDF isn\'t stored on this server (it may be offloaded to cloud storage), so it can\'t be protected here.', 'ultimate-pdf-viewer-embedder' ) );
		}

		$dir  = dirname( $file );
		$base = wp_basename( $file, '.pdf' );

		if ( $protect ) {
			$original = wp_basename( $file );
			$new_base = self::strip_suffix( $base ) . '-' . strtolower( wp_generate_password( 20, false ) );
		} else {
			$original = (string) get_post_meta( $id, self::ORIGINAL_META, true );
			$wanted   = '' !== $original ? $original : self::strip_suffix( $base ) . '.pdf';
			$new_base = wp_basename( wp_unique_filename( $dir, sanitize_file_name( $wanted ) ), '.pdf' );
		}

		$moved = self::rename_files( $id, $file, $base, $new_base );

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		if ( $protect ) {
			update_post_meta( $id, self::META, 1 );
			update_post_meta( $id, self::ORIGINAL_META, sanitize_file_name( $original ) );
		} else {
			delete_post_meta( $id, self::META );
			delete_post_meta( $id, self::ORIGINAL_META );
		}

		clean_post_cache( $id );

		return true;
	}

	/**
	 * Remove a suffix this class added ("-" plus 20 lowercase letters/digits).
	 *
	 * @param string $base File name without extension.
	 * @return string
	 */
	private static function strip_suffix( $base ) {
		return preg_replace( '/-[a-z0-9]{20}$/', '', $base );
	}

	/**
	 * Rename a PDF and its preview images, and update WordPress's records.
	 *
	 * @param int    $id       Attachment ID.
	 * @param string $file     Current full path.
	 * @param string $base     Current name without extension.
	 * @param string $new_base New name without extension.
	 * @return true|WP_Error
	 */
	private static function rename_files( $id, $file, $base, $new_base ) {
		$dir     = dirname( $file );
		$target  = $dir . '/' . $new_base . '.pdf';
		$renames = array( $file => $target );
		$meta    = wp_get_attachment_metadata( $id );

		// PDF previews WordPress made (name-pdf.jpg, name-pdf-150x150.jpg...).
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size => $info ) {
				if ( empty( $info['file'] ) || 0 !== strpos( $info['file'], $base ) ) {
					continue;
				}

				$new_file = $new_base . substr( $info['file'], strlen( $base ) );

				if ( file_exists( $dir . '/' . $info['file'] ) ) {
					$renames[ $dir . '/' . $info['file'] ] = $dir . '/' . $new_file;
				}

				$meta['sizes'][ $size ]['file'] = $new_file;
			}
		}

		foreach ( $renames as $to ) {
			if ( file_exists( $to ) ) {
				return new WP_Error( 'upve_exists', __( 'A file with the new name already exists.', 'ultimate-pdf-viewer-embedder' ) );
			}
		}

		$done = array();

		foreach ( $renames as $from => $to ) {
			if ( ! @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Renaming an upload in place.
				// Put back whatever already moved.
				foreach ( $done as $moved_from => $moved_to ) {
					@rename( $moved_to, $moved_from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
				}

				return new WP_Error( 'upve_rename', __( 'The file couldn\'t be renamed. Check the uploads folder is writable.', 'ultimate-pdf-viewer-embedder' ) );
			}

			$done[ $from ] = $to;
		}

		update_attached_file( $id, $target );

		if ( is_array( $meta ) ) {
			if ( ! empty( $meta['file'] ) ) {
				$meta['file'] = _wp_relative_upload_path( $target );
			}
			wp_update_attachment_metadata( $id, $meta );
		}

		return true;
	}

	/**
	 * "Protect this PDF" checkbox and the shortcode in the media modal and on
	 * the Edit Media screen.
	 *
	 * @param array   $fields Fields.
	 * @param WP_Post $post   Attachment.
	 * @return array
	 */
	public static function fields( $fields, $post ) {
		if ( ! $post instanceof WP_Post || 'application/pdf' !== $post->post_mime_type ) {
			return $fields;
		}

		$fields['upve_shortcode'] = array(
			'label' => __( 'PDF viewer', 'ultimate-pdf-viewer-embedder' ),
			'input' => 'html',
			'html'  => '<input type="text" readonly class="widefat upve-shortcode-field" value="' . esc_attr( '[pdf_viewer id="' . $post->ID . '"]' ) . '" onclick="this.select()">',
			'helps' => __( 'Paste this shortcode where you want the PDF to show, or use the PDF Viewer block.', 'ultimate-pdf-viewer-embedder' ),
		);

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}

		$name = 'attachments[' . $post->ID . '][upve_protect]';

		$fields['upve_protect'] = array(
			'label' => __( 'Protect file', 'ultimate-pdf-viewer-embedder' ),
			'input' => 'html',
			'html'  => '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"><label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( self::is_protected( $post->ID ), true, false ) . '> ' . esc_html__( 'Hide this PDF\'s address', 'ultimate-pdf-viewer-embedder' ) . '</label>',
			'helps' => __( 'Gives the file a random name and keeps its address out of your pages, so only the viewer can open it. Links to the current address stop working.', 'ultimate-pdf-viewer-embedder' ),
		);

		return $fields;
	}

	/**
	 * Save the checkbox.
	 *
	 * @param array $post       Post data.
	 * @param array $attachment Submitted fields.
	 * @return array
	 */
	public static function save_fields( $post, $attachment ) {
		$id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;

		if ( ! $id || ! isset( $attachment['upve_protect'] ) || ! current_user_can( 'edit_post', $id ) ) {
			return $post;
		}

		$result = self::set( $id, '1' === (string) $attachment['upve_protect'] );

		if ( is_wp_error( $result ) ) {
			$post['errors']['upve_protect']['errors'][] = $result->get_error_message();
		}

		return $post;
	}
}
