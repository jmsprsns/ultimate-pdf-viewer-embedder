<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * Settings, stats tables, saved text and covers, and cached copies of
 * remote PDFs are deleted. Protected PDFs stay where they are, at their
 * random file names, so links in the Media Library keep working.
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

delete_option( 'ultimate_pdf_viewer_embedder_settings' );
delete_option( 'ultimate_pdf_viewer_embedder_db' );

foreach ( array( 'docs', 'daily', 'pages' ) as $ultimate_pdf_viewer_embedder_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'upve_' . $ultimate_pdf_viewer_embedder_table ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Own tables.
}

delete_post_meta_by_key( '_upve_protected' );
delete_post_meta_by_key( '_upve_original_name' );

wp_clear_scheduled_hook( 'ultimate_pdf_viewer_embedder_daily' );

// Cover images and cached copies of PDFs from other sites.
$ultimate_pdf_viewer_embedder_uploads = wp_upload_dir( null, false );

if ( empty( $ultimate_pdf_viewer_embedder_uploads['error'] ) ) {
	foreach ( array( 'pdf-covers', 'upve-cache' ) as $ultimate_pdf_viewer_embedder_dir ) {
		$ultimate_pdf_viewer_embedder_path = trailingslashit( $ultimate_pdf_viewer_embedder_uploads['basedir'] ) . $ultimate_pdf_viewer_embedder_dir;

		if ( ! is_dir( $ultimate_pdf_viewer_embedder_path ) ) {
			continue;
		}

		// Two globs: GLOB_BRACE isn't available on every system.
		foreach ( array_merge( (array) glob( $ultimate_pdf_viewer_embedder_path . '/*' ), (array) glob( $ultimate_pdf_viewer_embedder_path . '/.htaccess' ) ) as $ultimate_pdf_viewer_embedder_file ) {
			if ( is_file( $ultimate_pdf_viewer_embedder_file ) ) {
				wp_delete_file( $ultimate_pdf_viewer_embedder_file );
			}
		}

		@rmdir( $ultimate_pdf_viewer_embedder_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Only removed when empty.
	}
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-ultimate-pdf-viewer-embedder' );
