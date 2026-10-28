<?php
/**
 * Database tables and the daily clean-up job.
 *
 * Three tables:
 * - upve_docs: one row per document (a Media Library PDF or a URL), with
 *   all-time totals, its page count, extracted text and cover image.
 * - upve_daily: views, downloads, prints and pages read per document per day.
 * - upve_pages: per page, how many views reached it (seen) and how many
 *   stopped there (furthest), for the "how far people get" report.
 *
 * Nothing about the visitor is stored: no IPs, user IDs or cookies.
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema and scheduled clean-up.
 */
final class Ultimate_PDF_Viewer_Embedder_Install {

	/**
	 * Bump when the schema changes; dbDelta runs again on the next load.
	 */
	const DB_VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const DB_OPTION = 'ultimate_pdf_viewer_embedder_db';

	/**
	 * Daily clean-up hook.
	 */
	const CRON = 'ultimate_pdf_viewer_embedder_daily';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		self::maybe_upgrade();

		add_action( self::CRON, array( __CLASS__, 'daily' ) );

		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Table names.
	 *
	 * @param string $name docs, daily or pages.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'upve_' . $name;
	}

	/**
	 * Create or update the tables when the schema version changed (plugin
	 * updates replace the files without running the activation hook).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Create the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$docs    = self::table( 'docs' );
		$daily   = self::table( 'daily' );
		$pages   = self::table( 'pages' );

		dbDelta(
			"CREATE TABLE {$docs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_key char(40) NOT NULL,
				attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
				url text NOT NULL,
				title varchar(255) NOT NULL DEFAULT '',
				pages int(10) unsigned NOT NULL DEFAULT 0,
				pages_trusted tinyint(1) unsigned NOT NULL DEFAULT 0,
				views bigint(20) unsigned NOT NULL DEFAULT 0,
				downloads bigint(20) unsigned NOT NULL DEFAULT 0,
				prints bigint(20) unsigned NOT NULL DEFAULT 0,
				text longtext NULL,
				text_updated datetime NULL,
				cover varchar(255) NOT NULL DEFAULT '',
				created datetime NOT NULL,
				last_view datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_key (source_key),
				KEY attachment_id (attachment_id)
			) {$charset};
			CREATE TABLE {$daily} (
				doc_id bigint(20) unsigned NOT NULL,
				day date NOT NULL,
				views int(10) unsigned NOT NULL DEFAULT 0,
				downloads int(10) unsigned NOT NULL DEFAULT 0,
				prints int(10) unsigned NOT NULL DEFAULT 0,
				pages_read bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (doc_id,day),
				KEY day (day)
			) {$charset};
			CREATE TABLE {$pages} (
				doc_id bigint(20) unsigned NOT NULL,
				page int(10) unsigned NOT NULL,
				seen bigint(20) unsigned NOT NULL DEFAULT 0,
				furthest bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (doc_id,page)
			) {$charset};"
		);

		update_option( self::DB_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Daily: drop old daily rows and expired copies of remote PDFs.
	 *
	 * @return void
	 */
	public static function daily() {
		global $wpdb;

		$keep = (int) ultimate_pdf_viewer_embedder_plugin()->get_setting( 'keep_days' );

		if ( $keep > 0 ) {
			$cutoff = gmdate( 'Y-m-d', time() - $keep * DAY_IN_SECONDS );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'daily' ) . ' WHERE day < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Table name from a constant.
		}

		Ultimate_PDF_Viewer_Embedder_Remote::prune();
	}
}
