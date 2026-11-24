# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.2] - 2026-11-24

### Fixed
- The new-content-element wizard showed the group of the five elements as "video" (the raw key); it is
  now labelled "Video" (English and German).

### Changed
- README: menu paths for TYPO3 14 (System → Settings, Sites → Setup, Content → Layout, System → Maintenance),
  exact field names for the backend user group permissions, and the site set removal step when
  uninstalling.

## [1.0.1] - 2026-11-24

### Removed
- Unused translation labels (English and German): `vo_video_uuid.default` and `vo_library_id.default`
  (both fields use the visual pickers instead of select boxes) and the frontend labels
  `video.unavailable` and `video.play_label`. No visible change.

## [1.0.0] - 2026-11-24

Initial public release for **TYPO3 14.3 LTS** and **PHP 8.4/8.5**.

### Added
- **Five content elements** in the "Video" group: `Video Hero` (full-bleed background video),
  `Video Single`, `Video Grid` (up to 12 videos, lightbox), `Video List` (paginated, 24 per page) and
  `Video Detail` (player plus metadata). Rendering is provided by the site set
  `scalecommerce/videooptimizer` ("VideoOptimizer content elements"); CSS and the lightbox script are
  only loaded on pages that use them.
- **Visual library picker** for all content elements: libraries as thumbnail tiles (with filter and
  keyboard support), "All libraries" tile, delivery-only badge.
- **Video picker dialog** for hero, single and detail: thumbnail grid, library filter, title search,
  "ready only" toggle, processing/error badges. Library and video selection stay in sync.
- **Per-element player options**: autoplay, loop, muted, controls, start position, cover fit, subtitle
  language.
- **Hero options**: eyebrow, subtitle, button label, button action (open the player in a new tab —
  default —, lightbox, link to a page/URL, or no button), overlay strength, height and text alignment.
- **Backend module** (System → VideoOptimizer) in TYPO3's native module layout: create, edit, reprocess and
  delete libraries; upload videos or import them from a public `https://` URL (with title, tags and
  metadata); rename, tag and delete videos; pick thumbnails; upload and delete subtitles (`.vtt`/`.srt`).
  Destructive actions confirm in a TYPO3 dialog.
- **Page-module previews** for all five content elements (poster, title, duration, library, status and
  non-default options).
- **Structured data**: schema.org `VideoObject` (hero, single, detail) and `ItemList` (grid, list) as
  JSON-LD, one entry per video; site setting `videooptimizer.structuredData`.
- **Cache invalidation**: signed webhook receiver at `/videooptimizer/webhook` (`video.*` events flush the
  affected API cache entries and pages) and the schedulable command `videooptimizer:cache:refresh`
  (`--dry-run`, `--warm`) as a fallback.
- **Extension configuration** (`apiToken`, `cacheTtl`, `demoVideoUuid`, `webhookSecret`) with
  environment-variable fallbacks (`VO_API_TOKEN`, `VO_CACHE_TTL`, `VO_DEMO_VIDEO_UUID`, `VO_WEBHOOK_SECRET`).
- **Backend permissions** as custom module options: manage, upload/ingest, delete/reprocess.
- **Resilience**: API responses cached with a soft TTL, stale copies served for up to 24 h when the API is
  unreachable, failures cached for 30 s, frontend timeouts of 2 s/8 s; page-cache tags per video.
- Processing/error placeholders for videos that are not ready yet.
- English and German translations for the backend and the frontend.

### Security
- The API token is only used server-side (extension configuration or environment variable) and never
  reaches the browser.
- One strict allowlist for every media URL from the API (`https://` on `videooptimizer.eu` hosts only).
- IDs and language codes are validated before they are used in API paths.
- Backend module: changes require `POST`, a form token and the matching permission; URL import rejects
  private/local hosts and credentials; upload size and type checks.
- Visitors never see API error details; pages showing them to backend users are not cached.
- Webhook: HMAC-SHA256 signature, 5-minute replay window, duplicate detection, 1 MiB body limit.

[Unreleased]: https://github.com/ScaleCommerce/videooptimizer-typo3/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/ScaleCommerce/videooptimizer-typo3/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/ScaleCommerce/videooptimizer-typo3/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/ScaleCommerce/videooptimizer-typo3/releases/tag/v1.0.0
