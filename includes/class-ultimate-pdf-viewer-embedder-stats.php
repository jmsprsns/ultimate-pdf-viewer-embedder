<?php
/**
 * View, download, print and read-depth counters, and the report queries.
 *
 * Everything is a plain counter per document, per day or per page. Nothing
 * about the visitor is stored.
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stats storage.
 */
final class Ultimate_PDF_Viewer_Embedder_Stats {

	/**
	 * Today in the site's time zone.
	 *
	 * @return string Y-m-d.
	 */
	private static function today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Count a view, download or print.
	 *
	 * @param int    $doc_id Document row.
	 * @param string $what   views, downloads or prints.
	 * @return void
	 */
	public static function bump( $doc_id, $what ) {
		global $wpdb;

		if ( ! in_array( $what, array( 'views', 'downloads', 'prints' ), true ) || $doc_id < 1 ) {
			return;
		}

		$docs  = Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' );
		$daily = Ultimate_PDF_Viewer_Embedder_Install::table( 'daily' );
		$last  = 'views' === $what ? ', last_view = %s' : '';
		$args  = 'views' === $what ? array( current_time( 'mysql', true ), $doc_id ) : array( $doc_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own tables; column names from the allow-list above.
		$wpdb->query( $wpdb->prepare( "UPDATE {$docs} SET {$what} = {$what} + 1{$last} WHERE id = %d", $args ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$daily} (doc_id, day, {$what}) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE {$what} = {$what} + 1", $doc_id, self::today() ) );
		// phpcs:enable
	}

	/**
	 * Count pages a view reached.
	 *
	 * @param int   $doc_id Document row.
	 * @param int[] $pages  Pages newly seen in this view.
	 * @param int   $from   Furthest page reported before (0 if none).
	 * @param int   $to     Furthest page now.
	 * @return void
	 */
	public static function pages( $doc_id, array $pages, $from, $to ) {
		global $wpdb;

		$table = Ultimate_PDF_Viewer_Embedder_Install::table( 'pages' );
		$daily = Ultimate_PDF_Viewer_Embedder_Install::table( 'daily' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery -- Own tables; every value goes through the placeholders built here.
		if ( $pages ) {
			$values = array();
			$args   = array();

			foreach ( $pages as $page ) {
				$values[] = '(%d, %d, 1)';
				$args[]   = $doc_id;
				$args[]   = $page;
			}

			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (doc_id, page, seen) VALUES " . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE seen = seen + 1', $args ) );
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$daily} (doc_id, day, pages_read) VALUES (%d, %s, %d) ON DUPLICATE KEY UPDATE pages_read = pages_read + %d", $doc_id, self::today(), count( $pages ), count( $pages ) ) );
		}

		if ( $to > $from ) {
			if ( $from > 0 ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET furthest = furthest - 1 WHERE doc_id = %d AND page = %d AND furthest > 0", $doc_id, $from ) );
			}

			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (doc_id, page, furthest) VALUES (%d, %d, 1) ON DUPLICATE KEY UPDATE furthest = furthest + 1", $doc_id, $to ) );
		}
		// phpcs:enable
	}

	/**
	 * Remember a document's page count when only the viewer has reported it
	 * (an editor's count always wins).
	 *
	 * @param int $doc_id Document row.
	 * @param int $count  Page count.
	 * @return void
	 */
	public static function page_count( $doc_id, $count ) {
		global $wpdb;

		$table = Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' );

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET pages = %d WHERE id = %d AND pages_trusted = 0", $count, $doc_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * First day of a report period.
	 *
	 * @param int $days Period length (0 = all time).
	 * @return string Y-m-d.
	 */
	public static function since( $days ) {
		return $days > 0 ? gmdate( 'Y-m-d', strtotime( self::today() . ' 00:00:00' ) - ( $days - 1 ) * DAY_IN_SECONDS ) : '1970-01-01';
	}

	/**
	 * Report rows: every document with its numbers for the period.
	 *
	 * @param int    $days    Period (0 = all time).
	 * @param string $orderby views, downloads, prints, depth or title.
	 * @param string $order   asc or desc.
	 * @param int    $limit   Rows.
	 * @param int    $offset  Offset.
	 * @return array<int,array<string,mixed>>
	 */
	public static function summary( $days, $orderby = 'views', $order = 'desc', $limit = 50, $offset = 0 ) {
		global $wpdb;

		$docs   = Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' );
		$daily  = Ultimate_PDF_Viewer_Embedder_Install::table( 'daily' );
		$pages  = Ultimate_PDF_Viewer_Embedder_Install::table( 'pages' );
		$sorts  = array(
			'views'     => 'p_views',
			'downloads' => 'p_downloads',
			'prints'    => 'p_prints',
			'depth'     => 'depth',
			'title'     => 'title',
		);
		$sort   = isset( $sorts[ $orderby ] ) ? $sorts[ $orderby ] : 'p_views';
		$dir    = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';
		$cutoff = self::since( $days );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own tables; ORDER BY from the allow-list above.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT d.id, d.attachment_id, d.url, d.title, d.pages, d.views, d.downloads, d.prints, d.last_view,
					COALESCE(s.v, 0) AS p_views,
					COALESCE(s.d, 0) AS p_downloads,
					COALESCE(s.p, 0) AS p_prints,
					COALESCE(s.r, 0) AS p_pages_read,
					CASE WHEN d.pages > 0 AND s.v > 0 THEN s.r / (s.v * d.pages) ELSE 0 END AS depth,
					COALESCE(f.seen, 0) AS first_seen,
					COALESCE(l.seen, 0) AS last_seen
				FROM {$docs} d
				LEFT JOIN (
					SELECT doc_id, SUM(views) v, SUM(downloads) d, SUM(prints) p, SUM(pages_read) r
					FROM {$daily} WHERE day >= %s GROUP BY doc_id
				) s ON s.doc_id = d.id
				LEFT JOIN {$pages} f ON f.doc_id = d.id AND f.page = 1
				LEFT JOIN {$pages} l ON l.doc_id = d.id AND l.page = d.pages
				WHERE d.views > 0 OR d.downloads > 0
				ORDER BY {$sort} {$dir}, d.id DESC
				LIMIT %d OFFSET %d",
				$cutoff,
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		$rows = is_array( $rows ) ? $rows : array();

		// Media Library titles can change: use the current one.
		foreach ( $rows as $i => $row ) {
			if ( (int) $row['attachment_id'] && 'attachment' === get_post_type( (int) $row['attachment_id'] ) ) {
				$rows[ $i ]['title'] = Ultimate_PDF_Viewer_Embedder_Docs::title( 'a:' . (int) $row['attachment_id'] );
			}
		}

		return $rows;
	}

	/**
	 * How many documents have any views or downloads.
	 *
	 * @return int
	 */
	public static function count_docs() {
		global $wpdb;

		$docs = Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$docs} WHERE views > 0 OR downloads > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table, no input.
	}

	/**
	 * Totals for the period, and a views-per-day series.
	 *
	 * @param int $days   Period (0 = all time).
	 * @param int $doc_id One document, or 0 for all.
	 * @return array{views:int,downloads:int,prints:int,pages_read:int,series:array<string,int>}
	 */
	public static function totals( $days, $doc_id = 0 ) {
		global $wpdb;

		$daily  = Ultimate_PDF_Viewer_Embedder_Install::table( 'daily' );
		$cutoff = self::since( $days );
		$where  = $doc_id ? $wpdb->prepare( ' AND doc_id = %d', $doc_id ) : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table; $where prepared above.
		$sum = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE(SUM(views),0) v, COALESCE(SUM(downloads),0) d, COALESCE(SUM(prints),0) p, COALESCE(SUM(pages_read),0) r FROM {$daily} WHERE day >= %s{$where}", $cutoff ), ARRAY_A );
		$by  = $wpdb->get_results( $wpdb->prepare( "SELECT day, SUM(views) v FROM {$daily} WHERE day >= %s{$where} GROUP BY day", $cutoff ), ARRAY_A );
		// phpcs:enable

		$series = array();
		$span   = $days > 0 ? $days : 90;
		$start  = strtotime( self::since( $span ) . ' 00:00:00' );

		for ( $i = 0; $i < $span; $i++ ) {
			$series[ gmdate( 'Y-m-d', $start + $i * DAY_IN_SECONDS ) ] = 0;
		}

		foreach ( (array) $by as $row ) {
			if ( isset( $series[ $row['day'] ] ) ) {
				$series[ $row['day'] ] = (int) $row['v'];
			}
		}

		return array(
			'views'      => (int) $sum['v'],
			'downloads'  => (int) $sum['d'],
			'prints'     => (int) $sum['p'],
			'pages_read' => (int) $sum['r'],
			'series'     => $series,
		);
	}

	/**
	 * One document's row.
	 *
	 * @param int $doc_id Document row.
	 * @return array<string,mixed>|null
	 */
	public static function doc( $doc_id ) {
		global $wpdb;

		$docs = Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT id, attachment_id, url, title, pages, views, downloads, prints, created, last_view FROM {$docs} WHERE id = %d", $doc_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.

		if ( is_array( $row ) && (int) $row['attachment_id'] && 'attachment' === get_post_type( (int) $row['attachment_id'] ) ) {
			$row['title'] = Ultimate_PDF_Viewer_Embedder_Docs::title( 'a:' . (int) $row['attachment_id'] );
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Per-page reach for one document (all time).
	 *
	 * @param int $doc_id Document row.
	 * @param int $pages  Page count.
	 * @return array<int,array{seen:int,furthest:int}>
	 */
	public static function reach( $doc_id, $pages ) {
		global $wpdb;

		$table = Ultimate_PDF_Viewer_Embedder_Install::table( 'pages' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT page, seen, furthest FROM {$table} WHERE doc_id = %d AND page <= %d ORDER BY page", $doc_id, max( 1, min( 2000, (int) $pages ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Own table.

		$out  = array();
		$last = max( 1, min( 2000, (int) $pages ) );

		for ( $i = 1; $i <= $last; $i++ ) {
			$out[ $i ] = array(
				'seen'     => 0,
				'furthest' => 0,
			);
		}

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['page'] ] = array(
				'seen'     => (int) $row['seen'],
				'furthest' => (int) $row['furthest'],
			);
		}

		return $out;
	}

	/**
	 * Delete every stat (the documents' text and covers are kept).
	 *
	 * @return void
	 */
	public static function reset() {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Own tables, no input.
		$wpdb->query( 'TRUNCATE TABLE ' . Ultimate_PDF_Viewer_Embedder_Install::table( 'daily' ) );
		$wpdb->query( 'TRUNCATE TABLE ' . Ultimate_PDF_Viewer_Embedder_Install::table( 'pages' ) );
		$wpdb->query( 'UPDATE ' . Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' ) . ' SET views = 0, downloads = 0, prints = 0, last_view = NULL' );
		// phpcs:enable
	}
}
