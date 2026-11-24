# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-11-24

### Upgrade notes
- **The Composer package was renamed** from `scalecommerce/videooptimizer` to
  `scalecommerce/videooptimizer-typo3` (the extension key stays `videooptimizer`):
  `composer remove scalecommerce/videooptimizer && composer require scalecommerce/videooptimizer-typo3`.
- **Add the site set** `scalecommerce/videooptimizer` ("VideoOptimizer content elements") to every site
  that uses the content elements. The old static TypoScript (`Configuration/TypoScript/setup.typoscript`)
  is gone; it also no longer defines `page`.
- Run `vendor/bin/typo3 extension:setup` (new `tt_content` columns for the hero options).
- Editors who should upload, edit or delete in the backend module need the new **custom module
  options** (see README → Permissions). Without them the module is read-only for non-admins.
- The API token can now be set in the extension configuration (`apiToken`); `VO_API_TOKEN` keeps working
  as a fallback.
- The extension is now released under the **MIT License**.

### Added
- **Visual library picker** for all content elements: libraries as thumbnail tiles (with filter and
  keyboard support), "All libraries" tile, delivery-only badge.
- **Video picker dialog** for hero, single and detail: thumbnail grid, library filter, title search,
  "ready only" toggle, processing/error badges. Library and video selection stay in sync.
- **Page-module previews** for all five content elements (poster, title, duration, library, status and
  non-default options).
- **Hero options**: eyebrow, subtitle, button label, button action (open the player in a new tab —
  default —, lightbox, link to a page/URL, or no button), overlay strength, height and text alignment.
- **Structured data**: schema.org `VideoObject` (hero, single, detail) and `ItemList` (grid, list) as
  JSON-LD, one entry per video; site setting `videooptimizer.structuredData`.
- **Signed webhook receiver** at `/videooptimizer/webhook` (`video.*` events flush the affected API cache
  entries and pages) and the schedulable command `videooptimizer:cache:refresh` (`--dry-run`, `--warm`)
  as a fallback.
- **Extension configuration** (`apiToken`, `cacheTtl`, `demoVideoUuid`, `webhookSecret`) with
  environment-variable fallbacks.
- **Backend permissions** as custom module options: manage, upload/ingest, delete/reprocess.
- Paginated **Video List** (24 per page) and processing/error placeholders for videos that are not ready.
- **German translations** for the backend (content elements, fields, pickers, module, site set) and the
  frontend.

### Changed
- **TYPO3 14.3 LTS (14.3.12) and PHP 8.4/8.5.** Declared the TYPO3 core dependencies in
  `composer.json`.
- **Backend module** now uses TYPO3's native module layout (doc header, back buttons, standard tables,
  badges and flash messages); every text is translatable; destructive actions confirm in a TYPO3 dialog;
  the auto-refresh while videos encode no longer reloads while you type.
- Content elements read their own record (`currentContentObject`) instead of "the first element of this
  type on the page": several elements of the same type per page now work, and language, workspace,
  visibility and the library setting of grid/list are respected.
- Rendering is registered by the site set; headers come from fluid_styled_content. CSS and the lightbox
  script are only loaded on pages that use them.
- Grid and list fetch a single page from the API instead of the whole catalogue.
- Lightbox rebuilt on the native `<dialog>` element (focus handling, Escape, restores focus).
- API timeouts are 2 s/8 s (uploads 300 s) with at most one retry in the frontend; stale API data is
  served for up to 24 h when the API is unreachable; failures are cached for 30 s.
- Cache invalidation uses cache tags (API cache and page cache); the page-cache lifetime of pages with
  videos is capped at `max(cacheTtl, 900 s)`.
- The HTTP client is created by TYPO3 (proxy and TLS settings apply).

### Fixed
- Video and subtitle uploads failed (removed `Stream::factory()` in guzzlehttp/psr7 3, a PSR-7 stream
  passed to `stream_copy_to_stream()`, `UploadedFile::moveTo()` rejecting the temp path, `fclose()` on a
  stream Guzzle had already closed).
- Every flash message in the backend module crashed (wrong `ContextualFeedbackSeverity` namespace);
  redirects passed their arguments as controller name.
- The tag filter in the backend module led to the login page; its "Clear" link went to the library list.
- Library descriptions could not be cleared.
- Empty-state rows ("No videos in this library yet.") were shown even when the list had entries.
- Several timeouts and `Retry-After` handling bugs (sub-second values were truncated to 0).

### Security
- IDs and language codes are validated before they are used in API paths (a crafted `..` could turn a
  subtitle deletion into a video deletion).
- Only `https://` URLs on `videooptimizer.eu` hosts are rendered (iframe, links, images, lightbox).
- Removed unescaped API HTML (`f:format.raw`) and an unescaped header in TypoScript (stored XSS by
  editors).
- Visitors no longer see raw API error responses; details are shown only to backend users and in the
  Development context, the full response goes to the log.
- Backend module: all changes require `POST` and a form token; server-side checks for permissions and
  media-managed libraries; URL import rejects private/local hosts and credentials; upload size and type
  checks; no retries of non-idempotent requests.
- One strict allowlist (`MediaUrl`) for every API media URL in frontend, pickers, previews and the
  module (rejects backslash/whitespace tricks such as `https://evil.com\.videooptimizer.eu`).
- Pages that show error details to a backend user or developer are not stored in the page cache.
- The picker endpoints require content-editing rights or module access.
- Webhook: replays are detected via the signature (not only the delivery id); rejected requests are
  logged as `notice`.
- The demo console command only runs in the Development context; the seed command (which created an
  admin user) was removed from the extension.

## [1.0.0] - 2026-09-30

### Added
- Initial version: five content elements (hero, single, grid, list, detail) rendering videos from the
  VideoOptimizer REST API, per-element player options, a click-to-play lightbox, cached API client with
  retries, and a System backend module for libraries, videos, thumbnails and subtitles.

[Unreleased]: https://github.com/ScaleCommerce/videooptimizer-typo3/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/ScaleCommerce/videooptimizer-typo3/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ScaleCommerce/videooptimizer-typo3/releases/tag/v1.0.0
