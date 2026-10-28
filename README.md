# Ultimate PDF Viewer and Embedder

Show PDFs right on your pages in a fast, mobile-friendly viewer, with flipbooks, popups, search, protection and read tracking. Free, with no Pro tier.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## What it does

- **Viewer** (Mozilla's PDF.js, bundled): continuous or one-page scrolling, zoom and fit, pinch to zoom, page box and keyboard shortcuts, thumbnail sidebar, table of contents from bookmarks, search with highlights, text selection, clickable links (including links within the PDF), full screen (with a fallback for iPhones), printing, downloads, and links that open on a page (`#page=12`, or `#pdf-2&page=12` for the second viewer).
- **Flipbook** with a page-turn effect, two-page spreads on wide screens and one page on phones.
- **Popup** (lightbox) from a button, a text link or a cover thumbnail.
- **PDF Library** block: a grid of PDFs with covers that open in a popup.
- **Protection**: secure mode (file loaded through the site, no download/print/copy/right-click), "Hide this PDF's address" for Media Library files, viewing limited to logged-in users or roles, and watermarks.
- **Tracking**: views, downloads, prints, average share read and finish rate per PDF, plus a bar per page. Totals only, nothing about visitors.
- **SEO**: the PDF's text in a collapsed section under the viewer. **Speed**: lazy loading, and files only on pages with a PDF.
- **Embedding**: blocks, `[pdf_viewer]` / `[pdf_library]` shortcodes, an "Add PDF" button in the classic editor, and links from anywhere, including Google Drive, Google Docs and Dropbox.

Settings live under Content Powered > Ultimate PDF Viewer and Embedder. They're site-wide defaults; every block and shortcode can override them.

## How it works

- **Blocks and shortcodes** are drawn on the server (`Render`), so fixes reach every embed without re-saving posts. Each embed carries a config in `data-upve` and a token: its source (`a:<attachment ID>` or `u:<URL>`) and rules, signed with the site's secret keys (`Docs::sign`).
- **The browser** (`assets/viewer.js`, minified to `viewer.min.js` with `bin/build.sh`) starts a viewer when it scrolls into view and loads PDF.js from `assets/pdfjs/` with a dynamic `import()`, from the page's own origin so a CDN host can't break module loading.
- **Secure and restricted embeds** never print the file's address. The viewer asks `POST upve/v1/open` for a link with a two-hour ticket tied to the visitor, and `GET upve/v1/file` checks the token, the ticket and "who can view" before streaming (with byte ranges).
- **PDFs from other sites** are fetched by the server (`Remote`): only addresses an editor embedded (signed), public IPs only on every redirect hop, size-limited, must start with `%PDF-`, cached in `uploads/upve-cache` under unguessable names.
- **Files are read from disk only when the embed's author can edit them** (the token's `f` flag). Otherwise they're fetched over HTTP like a visitor would, so folders the web server protects (paid WooCommerce downloads, membership files) can't be pulled out through a contributor's embed.
- **Text and covers** are read in an editor's browser (`assets/prepare.js`): when a PDF is added in the block editor, or in bulk from the Tools tab. Covers are re-encoded by WordPress's image editor and saved under random names in `uploads/pdf-covers`.
- **Stats** are three tables: `upve_docs` (one row per document, with its text and cover), `upve_daily` and `upve_pages`. `POST upve/v1/track` only counts same-site requests with a valid token, skips bots and (by default) editors.

PDF.js is rebuilt from a `pdfjs-dist` release with `bin/build-pdfjs.sh` (legacy build for older browsers, `.mjs` renamed to `.js`, the viewer component minified, and its `:root { color-scheme }` removed so it can't darken the rest of the page).

## Automatic updates

Updates come from https://github.com/jmsprsns/ultimate-pdf-viewer-embedder through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `ultimate-pdf-viewer-embedder.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `ultimate-pdf-viewer-embedder.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Push a tag that matches that version (or publish a GitHub release with it):

   ```bash
   git tag -a v1.0.1 -m "Release 1.0.1" && git push origin v1.0.1
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'ULTIMATE_PDF_VIEWER_EMBEDDER_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'ULTIMATE_PDF_VIEWER_EMBEDDER_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
ultimate-pdf-viewer-embedder.php   Plugin header, constants, boot
uninstall.php                      Removes settings, tables, covers and cached copies
readme.txt                         WordPress readme; feeds the "View details" popup
includes/
  class-…-plugin.php               Main singleton and settings
  class-…-install.php              Tables and the daily clean-up
  class-…-docs.php                 Sources, signed tokens, tickets, document rows, covers
  class-…-remote.php               Share-link conversion and the safe remote fetch
  class-…-protect.php              Protected (renamed, hidden) Media Library PDFs
  class-…-render.php               Viewer, popup, flipbook, library and text markup; shortcodes
  class-…-assets.php               Front-end files, loaded only where needed
  class-…-rest.php                 open, file, track, doc, prepare, protect, pending
  class-…-block.php                PDF Viewer and PDF Library blocks
  class-…-media.php                "Add PDF" button in the classic editor
  class-…-stats.php                Counters and report queries
  class-…-reports.php              Reports tab, CSV export, reset
  class-…-admin.php                Settings page and Plugins-screen link
  class-…-menu.php                 Shared top-level "Content Powered" menu
  class-…-feedback.php             Shared Submit Feedback and Report a Bug pages
  class-…-updater.php              GitHub update checks (PUC wiring)
  class-…-icons.php                Inline Lucide icon helper
assets/
  viewer.js, viewer.min.js, viewer.css   The viewer on the site
  prepare.js                       Reads text and covers in an editor's browser
  block.js, block-editor.css       Block editor
  media.js                         Classic editor button
  admin.css, admin.js              Settings page
  pdfjs/                           Mozilla PDF.js 6.4.299 (Apache-2.0)
bin/                               build.sh and build-pdfjs.sh (left out of release ZIPs)
icons/                             Lucide icons used by the plugin (plus LICENSE)
plugin-update-checker/             Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-ultimate-pdf-viewer-embedder-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-ultimate-pdf-viewer-embedder-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons it uses in `icons/` (the full set lives in the skeleton; copy in any new icon you start using). Print one inline with:

```php
echo Ultimate_PDF_Viewer_Embedder_Icons::get( 'file-text', 20 );         // name, size in px
echo Ultimate_PDF_Viewer_Embedder_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[PDF.js](https://mozilla.github.io/pdf.js/)** by Mozilla (`assets/pdfjs/`): Apache License 2.0, see `assets/pdfjs/LICENSE`. The image decoders in `assets/pdfjs/wasm/` carry their own licenses beside them.
- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later (combined with PDF.js's Apache-2.0 code, the whole is distributed under GPL-3.0 terms). See `LICENSE`.
