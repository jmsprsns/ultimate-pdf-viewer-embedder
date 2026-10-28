=== Ultimate PDF Viewer and Embedder ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: pdf, pdf viewer, pdf embed, flipbook, document
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show PDFs right on your pages in a fast, mobile-friendly viewer, with flipbooks, popups, search, protection and read tracking. Free, with no Pro tier.

== Description ==

Display PDFs directly on your pages in a clean, fast viewer instead of sending visitors to a download link or a separate browser tab. It works on desktop and mobile, keeps visitors on your site, and includes the features the popular PDF plugins charge for.

= Viewer =

* Fits the screen on phones and tablets, with pinch to zoom and a full screen mode (iPhones included)
* Continuous scrolling, or one page at a time
* Clickable links inside the PDF, including links to other pages of the same document
* Zoom buttons, fit to width and fit to page
* Page number box, next and previous buttons, and keyboard shortcuts
* Thumbnail sidebar to jump to any page
* Table of contents from the PDF's bookmarks
* Search inside the PDF with highlighted results
* Text selection, so visitors can copy text
* Flipbook mode with a page-turn effect, for brochures, catalogs, menus and magazines
* Popup (lightbox) mode that opens the PDF from a button, a text link or a cover thumbnail
* Links that open on a given page: add #page=12 to the address, or use the "Copy link to this page" button

= Toolbar and look =

* Download and print buttons, each on or off per PDF or for the whole site
* Toolbar at the top or bottom, as a full-width bar or floating over the pages
* Your own colors, rounded corners, and light, dark or automatic themes
* Any width and height per PDF, and it never gets taller than the screen
* PDF Library block: a grid of documents with cover images, for menus, reports and resource pages

= Protection =

* Secure mode loads the PDF through your site instead of its file address and turns off downloading, printing, copying and right-click. It makes copies harder to get; no viewer can stop someone determined from saving what's on their screen.
* "Hide this PDF's address" gives a Media Library PDF a random file name and keeps its address out of your pages, the REST API and attachment pages
* Show a PDF only to logged-in users or to chosen roles; everyone else gets a "Log in" message
* Watermarks with the site name, the viewer's email or name, or the date, stamped on every page and printout

= Tracking =

* Views, downloads and prints for every PDF, by day
* How far people read: the share of each PDF a view reaches, the share who finish, and a bar for every page
* No IP addresses, cookies or visitor details are stored

= SEO and speed =

* Searchable text: the PDF's text is added under the viewer in a collapsed section, so search engines index it and screen readers can read it
* Lazy loading: a viewer only loads when it scrolls into view
* Viewer files load only on pages with a PDF, and nowhere else
* Everything comes from your own site: Mozilla's PDF.js is bundled, with no CDN or third-party requests

= Adding PDFs =

* PDF Viewer and PDF Library blocks (a File block holding a PDF can be turned into a viewer)
* [pdf_viewer] and [pdf_library] shortcodes, for page builders and widgets
* An "Add PDF" button in the classic editor, beside "Add Media"
* PDFs from any address, including Google Drive, Google Docs and Dropbox share links

Find the settings under Content Powered > Ultimate PDF Viewer and Embedder in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Shortcodes =

* `[pdf_viewer id="123"]` shows a Media Library PDF.
* `[pdf_viewer url="https://example.com/file.pdf"]` shows a PDF from any address.
* Options: `mode` (viewer, flipbook, lightbox), `trigger` (button, link, thumbnail), `label`, `title`, `width`, `height`, `page`, `zoom` (auto, page-width, page-fit or a percentage), `scroll` (continuous, page), `sidebar` (closed, thumbnails, outline), `theme` (light, dark, auto), and yes/no for `toolbar`, `download`, `print`, `search`, `fullscreen`, `secure`, `watermark`, `text` and `lazy`. `access` is all, login or roles, with `roles="editor,subscriber"`.
* `[pdf_library ids="12,13,14" columns="3"]` shows a grid. `source="all"` lists every public PDF in the Media Library and `source="attached"` the ones uploaded to the page. Also `limit`, `orderby` (selected, title, date), `open` (lightbox, tab), `mode` (viewer, flipbook), `search` and `titles` (yes/no).

= Credits =

* PDF rendering by [PDF.js](https://mozilla.github.io/pdf.js/) by Mozilla, Apache License 2.0. The license is in `assets/pdfjs/LICENSE`, and the image decoder licenses are in `assets/pdfjs/wasm/`.
* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

== Installation ==

1. Upload the `ultimate-pdf-viewer-embedder` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Add a "PDF Viewer" block to a page, or use the `[pdf_viewer]` shortcode.
4. Change the site-wide defaults under Content Powered > Ultimate PDF Viewer and Embedder.

To fill in the searchable text and covers for PDFs already on your site, run Tools & Embedding > "Read missing text and covers" once.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Does it work on phones? =

Yes. The viewer fits the width of the screen, never gets taller than the screen, moves extra buttons into a menu when space is tight, and supports pinch to zoom and full screen.

= Can people still download a secure PDF? =

Secure mode hides the file's address and removes the download, print, copy and right-click options, which stops casual copying. A determined visitor can always capture what's on their screen, so for files that must stay private, also hide the file's address and limit who can view it.

= Can I embed PDFs from Google Drive or Dropbox? =

Yes. Paste the share link (shared as "Anyone with the link"). Your site fetches a copy, keeps it for the hours you choose and loads it from there.

= Where does the searchable text come from? =

When you add a PDF in the block editor, your browser reads its text and first page and saves them. For PDFs already on your site, run Tools & Embedding > "Read missing text and covers". No PDF tools are needed on the server.

= Does tracking need a cookie banner? =

It stores only totals per PDF, per day and per page. No cookies, IP addresses or visitor details are kept.

== Changelog ==

= 1.0.1 =
* Searching inside a PDF no longer scrolls the rest of the page.
* Diagonal watermarks are sized to fit each page, however long the text.
* Flipbooks on phones use the full screen width, with the page arrows underneath.
* The popup viewer shows its "More" button only when the toolbar needs it.
* The editor can read text and covers for PDFs whose address the browser can't load directly.

= 1.0.0 =
* First release.

See https://github.com/jmsprsns/ultimate-pdf-viewer-embedder/releases for the full release history.
