# Changelog

All notable changes to this package. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [Unreleased]

## [1.0.5] - 2026-11-27

### Fixed
- Library changes now reach pages and cached videos of single, hero and detail elements via `vo_library_<id>` tags ([#7](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/7)).

## [1.0.4] - 2026-11-27

### Added
- Library encoding quality (performance/quality) and forced reprocess ([#4](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/4)).
- Subtitle languages can be deleted ([#5](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/5)).
- Failure reason of failed videos and URL imports in the module, pickers and page previews ([#2](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/2)).

### Fixed
- Thumbnail picker lists the frames of a video ([#1](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/1)).
- Create-library form disables add-on encodings that are not unlocked ([#3](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/3)).
- Library edit form keeps active resolutions that are no longer offered and preselects only the library's own codec and resolutions ([#3](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/3)).
- URL import explains rejected video platform links such as YouTube ([#6](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/6)).

## [1.0.3] - 2026-11-27

### Changed
- API requests send a `User-Agent` with extension, TYPO3 and PHP versions and site URL.

## [1.0.2] - 2026-11-24

### Fixed
- Content element wizard group is labelled "Video" instead of the raw key.

## [1.0.1] - 2026-11-24

### Removed
- Unused translation labels.

## [1.0.0] - 2026-11-24

Initial release for TYPO3 14.3 LTS and PHP 8.4/8.5.

### Added
- Content elements: Video Hero, Video Single, Video Grid, Video List, Video Detail (site set `scalecommerce/videooptimizer`).
- Visual library picker and video picker dialog.
- Per-element player options; hero options (text, button, overlay, height, alignment).
- Backend module (System → VideoOptimizer): libraries, upload, URL import, tags, thumbnails, subtitles.
- Page-module previews for all elements.
- schema.org `VideoObject`/`ItemList` JSON-LD (`videooptimizer.structuredData`).
- Signed webhook receiver and `videooptimizer:cache:refresh` command for cache invalidation.
- Extension configuration with environment-variable fallbacks (`VO_API_TOKEN`, `VO_CACHE_TTL`, `VO_DEMO_VIDEO_UUID`, `VO_WEBHOOK_SECRET`).
- Backend permissions: manage, upload/ingest, delete/reprocess.
- Stale API cache served for up to 24 h when the API is unreachable; placeholders for videos not ready.
- English and German translations.

### Security
- API token used server-side only, never sent to the browser.
- Media URLs restricted to `https://` on `videooptimizer.eu` hosts; IDs and language codes validated.
- Backend changes require POST, form token and permission; URL import rejects private/local hosts.
- Visitors never see API error details.
- Webhook: HMAC-SHA256 signature, 5-minute replay window, duplicate detection, 1 MiB body limit.
