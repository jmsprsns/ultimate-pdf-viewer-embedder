<?php
/**
 * The Reports tab: which PDFs get read and how far people get, plus the
 * CSV export and "Delete all stats".
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports, export and reset.
 */
final class Ultimate_PDF_Viewer_Embedder_Reports {

	/**
	 * admin-post action prefix.
	 */
	const ACTION = 'upve_stats_';

	/**
	 * Rows per report page.
	 */
	const PER_PAGE = 50;

	/**
	 * Report periods in days (0 = all time).
	 */
	const PERIODS = array( 7, 30, 90, 365, 0 );

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION . 'reset', array( __CLASS__, 'reset' ) );
		add_action( 'admin_post_' . self::ACTION . 'export', array( __CLASS__, 'export' ) );
	}

	/**
	 * Link that runs an action (with a nonce).
	 *
	 * @param string $action reset or export.
	 * @param array  $args   Extra query args.
	 * @return string
	 */
	public static function action_url( $action, array $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => self::ACTION . $action ), $args ), admin_url( 'admin-post.php' ) ), self::ACTION . $action );
	}

	/**
	 * Back to the settings page.
	 *
	 * @param array  $args Query args.
	 * @param string $tab  Tab to open.
	 * @return string
	 */
	public static function page_url( array $args = array(), $tab = 'reports' ) {
		return add_query_arg( array_merge( array( 'page' => Ultimate_PDF_Viewer_Embedder_Admin::PAGE ), $args ), admin_url( 'admin.php' ) ) . '#' . $tab;
	}

	/**
	 * "Delete all stats".
	 *
	 * @return void
	 */
	public static function reset() {
		check_admin_referer( self::ACTION . 'reset' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ultimate-pdf-viewer-embedder' ), 403 );
		}

		Ultimate_PDF_Viewer_Embedder_Stats::reset();

		wp_safe_redirect( self::page_url( array( 'upve_notice' => 'reset' ), 'tracking' ) );
		exit;
	}

	/**
	 * CSV of the report for a period.
	 *
	 * @return void
	 */
	public static function export() {
		check_admin_referer( self::ACTION . 'export' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ultimate-pdf-viewer-embedder' ), 403 );
		}

		$days = self::period();
		$rows = Ultimate_PDF_Viewer_Embedder_Stats::summary( $days, 'views', 'desc', 100000, 0 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pdf-views-' . ( $days ? $days . '-days' : 'all-time' ) . '-' . current_time( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		fputcsv( $out, array( 'Document', 'Source', 'Pages', 'Views', 'Downloads', 'Prints', 'Average share read', 'Finished', 'All-time views', 'Last viewed (UTC)' ) );

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					self::csv_safe( $row['title'] ),
					self::csv_safe( (int) $row['attachment_id'] ? 'Media Library #' . (int) $row['attachment_id'] : $row['url'] ),
					(int) $row['pages'],
					(int) $row['p_views'],
					(int) $row['p_downloads'],
					(int) $row['p_prints'],
					round( 100 * min( 1, (float) $row['depth'] ) ) . '%',
					self::finished( $row ),
					(int) $row['views'],
					(string) $row['last_view'],
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Stop spreadsheet apps treating a cell as a formula.
	 *
	 * @param string $value Cell.
	 * @return string
	 */
	private static function csv_safe( $value ) {
		$value = (string) $value;

		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Admin notices after an action.
	 *
	 * @return void
	 */
	public static function notices() {
		$notice = isset( $_GET['upve_notice'] ) ? sanitize_key( wp_unslash( $_GET['upve_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.

		if ( 'reset' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All stats were deleted.', 'ultimate-pdf-viewer-embedder' ) . '</p></div>';
		}
	}

	/**
	 * IDs of protected PDFs.
	 *
	 * @return int[]
	 */
	public static function protected_ids() {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'post_mime_type' => 'application/pdf',
					'posts_per_page' => 1000,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => Ultimate_PDF_Viewer_Embedder_Protect::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin page only.
				)
			)
		);
	}

	/**
	 * How many Media Library PDFs there are, and how many have text and a cover.
	 *
	 * @return array{total:int,text:int,cover:int}
	 */
	public static function text_counts() {
		global $wpdb;

		static $counts = null;

		if ( null !== $counts ) {
			return $counts;
		}

		$docs  = Ultimate_PDF_Viewer_Embedder_Install::table( 'docs' );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = %s", 'application/pdf' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT SUM(d.text_updated IS NOT NULL) AS t, SUM(d.cover <> '') AS c FROM {$docs} d INNER JOIN {$wpdb->posts} p ON p.ID = d.attachment_id AND p.post_type = 'attachment'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names only.
			ARRAY_A
		);

		$counts = array(
			'total' => $total,
			'text'  => $row ? (int) $row['t'] : 0,
			'cover' => $row ? (int) $row['c'] : 0,
		);

		return $counts;
	}

	/**
	 * The chosen report period.
	 *
	 * @return int Days (0 = all time).
	 */
	private static function period() {
		$days = isset( $_GET['period'] ) ? absint( $_GET['period'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.

		return in_array( $days, self::PERIODS, true ) ? $days : 30;
	}

	/**
	 * "Finished" share: views that reached the last page.
	 *
	 * @param array<string,mixed> $row Summary row.
	 * @return string
	 */
	private static function finished( array $row ) {
		if ( (int) $row['pages'] < 1 || (int) $row['first_seen'] < 1 ) {
			return '—';
		}

		return round( 100 * min( 1, (int) $row['last_seen'] / (int) $row['first_seen'] ) ) . '%';
	}

	/**
	 * Period name.
	 *
	 * @param int $days Days.
	 * @return string
	 */
	private static function period_label( $days ) {
		/* translators: %d: number of days. */
		return $days ? sprintf( __( 'Last %d days', 'ultimate-pdf-viewer-embedder' ), $days ) : __( 'All time', 'ultimate-pdf-viewer-embedder' );
	}

	/**
	 * The report.
	 *
	 * @return void
	 */
	public static function render() {
		$days   = self::period();
		$doc_id = isset( $_GET['doc'] ) ? absint( $_GET['doc'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view.
		$plugin = ultimate_pdf_viewer_embedder_plugin();

		echo '<div class="upve-report">';
		echo '<div class="upve-report-top">';
		echo '<div class="upve-periods" role="group" aria-label="' . esc_attr__( 'Period', 'ultimate-pdf-viewer-embedder' ) . '">';

		foreach ( self::PERIODS as $period ) {
			$args = array( 'period' => $period );

			if ( $doc_id ) {
				$args['doc'] = $doc_id;
			}

			printf(
				'<a href="%1$s" class="%2$s"%3$s>%4$s</a>',
				esc_url( self::page_url( $args ) ),
				$period === $days ? 'is-active' : '',
				$period === $days ? ' aria-current="true"' : '',
				esc_html( $period ? sprintf( /* translators: %d: days. */ __( '%d days', 'ultimate-pdf-viewer-embedder' ), $period ) : __( 'All time', 'ultimate-pdf-viewer-embedder' ) )
			);
		}

		echo '</div>';
		printf(
			'<a class="button" href="%1$s">%2$s%3$s</a>',
			esc_url( self::action_url( 'export', array( 'period' => $days ) ) ),
			Ultimate_PDF_Viewer_Embedder_Icons::get( 'file-down', 16 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			esc_html__( 'Export CSV', 'ultimate-pdf-viewer-embedder' )
		);
		echo '</div>';

		if ( ! $plugin->get_setting( 'track' ) ) {
			echo '<p class="upve-callout is-warning">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'info', 16 ) . '<span>' . esc_html__( 'Tracking is switched off on the Tracking tab, so nothing new is being counted.', 'ultimate-pdf-viewer-embedder' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
		}

		if ( $doc_id ) {
			self::render_doc( $doc_id, $days );
		} else {
			self::render_summary( $days );
		}

		echo '</div>';
	}

	/**
	 * Number tiles.
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $tiles Icon, number, label.
	 * @return void
	 */
	private static function tiles( array $tiles ) {
		echo '<div class="upve-stats">';

		foreach ( $tiles as $tile ) {
			printf(
				'<div class="upve-stat"><span class="upve-stat-icon">%1$s</span><span class="upve-stat-num">%2$s</span><span class="upve-stat-label">%3$s</span></div>',
				Ultimate_PDF_Viewer_Embedder_Icons::get( $tile[0], 18 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
				esc_html( $tile[1] ),
				esc_html( $tile[2] )
			);
		}

		echo '</div>';
	}

	/**
	 * Views per day as columns.
	 *
	 * @param array<string,int> $series Day => views.
	 * @return void
	 */
	private static function chart( array $series ) {
		$max   = max( 1, max( $series ) );
		$count = count( $series );

		echo '<div class="upve-chart" style="--upve-bars:' . (int) $count . '">';
		echo '<div class="upve-chart-bars" role="img" aria-label="' . esc_attr__( 'Views per day', 'ultimate-pdf-viewer-embedder' ) . '">';

		foreach ( $series as $day => $views ) {
			$label = date_i18n( get_option( 'date_format' ), strtotime( $day ) );
			printf(
				'<span class="upve-chart-bar%1$s" style="height:%2$s%%" title="%3$s"></span>',
				$views ? '' : ' is-zero',
				esc_attr( (string) max( 2, round( 100 * $views / $max, 1 ) ) ),
				esc_attr( sprintf( /* translators: 1: date, 2: views. */ _n( '%1$s: %2$s view', '%1$s: %2$s views', $views, 'ultimate-pdf-viewer-embedder' ), $label, number_format_i18n( $views ) ) )
			);
		}

		echo '</div><div class="upve-chart-axis">';
		$days = array_keys( $series );
		echo '<span>' . esc_html( date_i18n( 'M j', strtotime( reset( $days ) ) ) ) . '</span><span>' . esc_html( date_i18n( 'M j', strtotime( end( $days ) ) ) ) . '</span>';
		echo '</div></div>';
	}

	/**
	 * All documents.
	 *
	 * @param int $days Period.
	 * @return void
	 */
	private static function render_summary( $days ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only sorting and paging.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'views';
		$order   = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$totals = Ultimate_PDF_Viewer_Embedder_Stats::totals( $days );
		$count  = Ultimate_PDF_Viewer_Embedder_Stats::count_docs();
		$rows   = Ultimate_PDF_Viewer_Embedder_Stats::summary( $days, $orderby, $order, self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE );
		$depths = array();

		foreach ( $rows as $row ) {
			if ( (int) $row['p_views'] > 0 && (int) $row['pages'] > 0 ) {
				$depths[] = min( 1, (float) $row['depth'] );
			}
		}

		self::tiles(
			array(
				array( 'eye', number_format_i18n( $totals['views'] ), __( 'Views', 'ultimate-pdf-viewer-embedder' ) ),
				array( 'download', number_format_i18n( $totals['downloads'] ), __( 'Downloads', 'ultimate-pdf-viewer-embedder' ) ),
				array( 'printer', number_format_i18n( $totals['prints'] ), __( 'Prints', 'ultimate-pdf-viewer-embedder' ) ),
				array( 'book-open', $depths ? round( 100 * array_sum( $depths ) / count( $depths ) ) . '%' : '—', __( 'Average share read', 'ultimate-pdf-viewer-embedder' ) ),
			)
		);

		self::chart( $totals['series'] );

		if ( ! $rows ) {
			echo '<p class="upve-empty">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'chart-column', 18 ) . '<span>' . esc_html__( 'No views yet. Numbers show up here once visitors open a PDF (your own visits as an editor aren\'t counted).', 'ultimate-pdf-viewer-embedder' ) . '</span></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}

		$sort = function ( $key, $label ) use ( $orderby, $order, $days ) {
			$next = $orderby === $key && 'desc' === $order ? 'asc' : 'desc';
			$mark = $orderby === $key ? ( 'desc' === $order ? ' ↓' : ' ↑' ) : '';

			return '<a href="' . esc_url( self::page_url( array( 'period' => $days, 'orderby' => $key, 'order' => $next ) ) ) . '">' . esc_html( $label . $mark ) . '</a>';
		};
		?>
		<table class="widefat striped upve-table upve-report-table">
			<thead>
				<tr>
					<th scope="col"><?php echo $sort( 'title', __( 'Document', 'ultimate-pdf-viewer-embedder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure. ?></th>
					<th scope="col" class="num"><?php echo $sort( 'views', __( 'Views', 'ultimate-pdf-viewer-embedder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
					<th scope="col" class="num"><?php echo $sort( 'downloads', __( 'Downloads', 'ultimate-pdf-viewer-embedder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
					<th scope="col" class="num"><?php echo $sort( 'prints', __( 'Prints', 'ultimate-pdf-viewer-embedder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
					<th scope="col"><?php echo $sort( 'depth', __( 'Average share read', 'ultimate-pdf-viewer-embedder' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Finished', 'ultimate-pdf-viewer-embedder' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $depth = (int) $row['pages'] > 0 && (int) $row['p_views'] > 0 ? round( 100 * min( 1, (float) $row['depth'] ) ) : null; ?>
					<tr>
						<td>
							<a class="upve-doc-link" href="<?php echo esc_url( self::page_url( array( 'period' => $days, 'doc' => (int) $row['id'] ) ) ); ?>">
								<?php echo Ultimate_PDF_Viewer_Embedder_Icons::get( (int) $row['attachment_id'] ? 'file-text' : 'globe', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
								<strong><?php echo esc_html( '' !== $row['title'] ? $row['title'] : __( '(no title)', 'ultimate-pdf-viewer-embedder' ) ); ?></strong>
							</a>
							<span class="upve-muted">
								<?php
								echo esc_html(
									(int) $row['pages']
										/* translators: %s: page count. */
										? sprintf( _n( '%s page', '%s pages', (int) $row['pages'], 'ultimate-pdf-viewer-embedder' ), number_format_i18n( (int) $row['pages'] ) )
										: __( 'Pages not known yet', 'ultimate-pdf-viewer-embedder' )
								);
								?>
								&middot;
								<?php
								/* translators: %s: all-time views. */
								echo esc_html( sprintf( __( '%s all time', 'ultimate-pdf-viewer-embedder' ), number_format_i18n( (int) $row['views'] ) ) );
								?>
							</span>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['p_views'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['p_downloads'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['p_prints'] ) ); ?></td>
						<td>
							<?php if ( null === $depth ) : ?>
								<span class="upve-muted">—</span>
							<?php else : ?>
								<span class="upve-meter" title="<?php echo esc_attr( $depth . '%' ); ?>"><span style="width:<?php echo esc_attr( (string) $depth ); ?>%"></span></span>
								<span class="upve-meter-num"><?php echo esc_html( $depth . '%' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="num"><?php echo esc_html( self::finished( $row ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $count / self::PER_PAGE );

		if ( $pages > 1 ) {
			echo '<div class="upve-pager">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				printf(
					'<a class="button%1$s" href="%2$s">%3$d</a>',
					$i === $paged ? ' button-primary' : '',
					esc_url( self::page_url( array( 'period' => $days, 'orderby' => $orderby, 'order' => $order, 'paged' => $i ) ) ),
					(int) $i
				);
			}
			echo '</div>';
		}

		echo '<p class="description upve-report-note">' . esc_html__( '"Average share read" is the part of each PDF a view reached, on average. "Finished" is the share of readers who got to the last page. Both count from when tracking started.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
	}

	/**
	 * One document: views per day and how far people read.
	 *
	 * @param int $doc_id Document row.
	 * @param int $days   Period.
	 * @return void
	 */
	private static function render_doc( $doc_id, $days ) {
		$doc = Ultimate_PDF_Viewer_Embedder_Stats::doc( $doc_id );

		echo '<p class="upve-back"><a href="' . esc_url( self::page_url( array( 'period' => $days ) ) ) . '">' . Ultimate_PDF_Viewer_Embedder_Icons::get( 'chevron-left', 16 ) . esc_html__( 'All documents', 'ultimate-pdf-viewer-embedder' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.

		if ( ! $doc ) {
			echo '<p class="upve-empty">' . esc_html__( 'That document has no stats.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			return;
		}

		$totals = Ultimate_PDF_Viewer_Embedder_Stats::totals( $days, $doc_id );
		$pages  = (int) $doc['pages'];
		$reach  = $pages ? Ultimate_PDF_Viewer_Embedder_Stats::reach( $doc_id, $pages ) : array();
		$base   = $reach ? max( 1, $reach[1]['seen'] ) : 1;
		$source = (int) $doc['attachment_id'] ? get_edit_post_link( (int) $doc['attachment_id'] ) : $doc['url'];

		echo '<h3 class="upve-doc-title">' . Ultimate_PDF_Viewer_Embedder_Icons::get( (int) $doc['attachment_id'] ? 'file-text' : 'globe', 18 ) . '<span>' . esc_html( $doc['title'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.

		if ( $source ) {
			echo ' <a class="upve-doc-source" href="' . esc_url( $source ) . '"' . ( (int) $doc['attachment_id'] ? '' : ' target="_blank" rel="noopener noreferrer"' ) . '>' . esc_html( (int) $doc['attachment_id'] ? __( 'Edit in Media Library', 'ultimate-pdf-viewer-embedder' ) : __( 'Open the link', 'ultimate-pdf-viewer-embedder' ) ) . '</a>';
		}

		echo '</h3>';

		$depth = $pages && $totals['views'] ? round( 100 * min( 1, $totals['pages_read'] / ( $totals['views'] * $pages ) ) ) . '%' : '—';

		self::tiles(
			array(
				array( 'eye', number_format_i18n( $totals['views'] ), self::period_label( $days ) . ': ' . __( 'views', 'ultimate-pdf-viewer-embedder' ) ),
				array( 'download', number_format_i18n( $totals['downloads'] ), __( 'Downloads', 'ultimate-pdf-viewer-embedder' ) ),
				array( 'printer', number_format_i18n( $totals['prints'] ), __( 'Prints', 'ultimate-pdf-viewer-embedder' ) ),
				array( 'book-open', $depth, __( 'Average share read', 'ultimate-pdf-viewer-embedder' ) ),
			)
		);

		self::chart( $totals['series'] );

		if ( ! $reach || ! $reach[1]['seen'] ) {
			echo '<p class="upve-empty">' . esc_html__( 'Page-by-page numbers appear after the first visitor reads this PDF.', 'ultimate-pdf-viewer-embedder' ) . '</p>';
			return;
		}

		// Furthest page half the readers reached.
		$stopped = 0;
		$half    = 0;
		$total   = array_sum( wp_list_pluck( $reach, 'furthest' ) );

		foreach ( $reach as $page => $row ) {
			$stopped += $row['furthest'];

			if ( ! $half && $total && $stopped >= $total / 2 ) {
				$half = $page;
			}
		}

		echo '<h4 class="upve-subhead">' . esc_html__( 'How far people read', 'ultimate-pdf-viewer-embedder' ) . '</h4>';

		if ( $half ) {
			/* translators: 1: page number, 2: page count. */
			echo '<p class="upve-intro">' . esc_html( sprintf( __( 'Half of the readers stopped by page %1$d of %2$d. Each bar is the share of readers who saw that page (all time).', 'ultimate-pdf-viewer-embedder' ), $half, $pages ) ) . '</p>';
		}

		echo '<div class="upve-reach" style="--upve-bars:' . (int) count( $reach ) . '">';

		foreach ( $reach as $page => $row ) {
			$share = round( 100 * min( 1, $row['seen'] / $base ) );
			printf(
				'<span class="upve-reach-bar" title="%1$s"><span style="height:%2$s%%"></span><em>%3$d</em></span>',
				esc_attr( sprintf( /* translators: 1: page, 2: percent, 3: readers. */ __( 'Page %1$d: %2$d%% of readers (%3$s)', 'ultimate-pdf-viewer-embedder' ), $page, $share, number_format_i18n( $row['seen'] ) ) ),
				esc_attr( (string) max( 1, $share ) ),
				(int) $page
			);
		}

		echo '</div>';
	}
}
