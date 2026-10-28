<?php
/**
 * Plugin Name:       Ultimate PDF Viewer and Embedder
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Show PDFs right on your pages in a fast, mobile-friendly viewer, with flipbooks, lightboxes, search, protection and read tracking.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ultimate-pdf-viewer-embedder
 * Update URI:        https://github.com/jmsprsns/ultimate-pdf-viewer-embedder
 *
 * @package Ultimate_PDF_Viewer_Embedder
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$ultimate_pdf_viewer_embedder_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'ULTIMATE_PDF_VIEWER_EMBEDDER_VERSION', $ultimate_pdf_viewer_embedder_headers['version'] );
define( 'ULTIMATE_PDF_VIEWER_EMBEDDER_REPO', $ultimate_pdf_viewer_embedder_headers['repo'] );
define( 'ULTIMATE_PDF_VIEWER_EMBEDDER_FILE', __FILE__ );
define( 'ULTIMATE_PDF_VIEWER_EMBEDDER_DIR', plugin_dir_path( __FILE__ ) );
define( 'ULTIMATE_PDF_VIEWER_EMBEDDER_URL', plugin_dir_url( __FILE__ ) );

unset( $ultimate_pdf_viewer_embedder_headers );

require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-icons.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-updater.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-menu.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-feedback.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-install.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-docs.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-remote.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-protect.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-stats.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-render.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-assets.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-rest.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-block.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-media.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-reports.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-admin.php';
require_once ULTIMATE_PDF_VIEWER_EMBEDDER_DIR . 'includes/class-ultimate-pdf-viewer-embedder-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Ultimate_PDF_Viewer_Embedder_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Ultimate_PDF_Viewer_Embedder_Plugin
 */
function ultimate_pdf_viewer_embedder_plugin() {
	return Ultimate_PDF_Viewer_Embedder_Plugin::instance();
}
add_action( 'plugins_loaded', 'ultimate_pdf_viewer_embedder_plugin' );

/**
 * Create the tables on activation (updates that skip activation are caught
 * by the version check in Ultimate_PDF_Viewer_Embedder_Install::maybe_upgrade).
 *
 * @return void
 */
function ultimate_pdf_viewer_embedder_activate() {
	Ultimate_PDF_Viewer_Embedder_Install::install();
}
register_activation_hook( ULTIMATE_PDF_VIEWER_EMBEDDER_FILE, 'ultimate_pdf_viewer_embedder_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings, stats and protected files stay put (uninstall.php removes the
 * data; protected files keep working at their random names).
 *
 * @return void
 */
function ultimate_pdf_viewer_embedder_deactivate() {
	wp_clear_scheduled_hook( Ultimate_PDF_Viewer_Embedder_Install::CRON );
}
register_deactivation_hook( ULTIMATE_PDF_VIEWER_EMBEDDER_FILE, 'ultimate_pdf_viewer_embedder_deactivate' );
