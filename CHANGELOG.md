# Changelog

All notable changes to this package. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [Unreleased]

### Upgrade
- Run `extension:setup` (or System > Maintenance > Analyze Database Structure) for the new `tt_content` columns `vo_player_mode` and `vo_presentation`, then `cache:flush`. Existing elements keep the hosted player, shown directly.
- `Js/videooptimizer.js` is an ES module now: custom templates that load it must add `type="module"` (`<f:asset.script ... type="module" />`).

### Added
- Player theme from `GET /embed` applied to the native player: control bar, autoplay (muted), loop, preload and accent color as `--vo-accent`; options set on the element win ([#14](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/14)).
- Native player per content element (`<video>`, hls.js light loaded only when needed, native HLS on Safari, MP4 fallback that keeps position and playing state) next to the hosted iframe ([#13](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/13)).
- Presentation of single/detail elements: direct, click-to-play facade or lightbox; grid, list and the hero button open the native player in the lightbox ([#13](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/13)).
- Native hero: background `<video>` that plays only while visible ([#13](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/13)).
- Frontend CSP mutations for `*.cdn.videooptimizer.eu`, `poster.videooptimizer.eu` and `blob:` media ([#13](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/13)).

## [2.0.0] - 2026-11-27

### Upgrade
- Run `cache:flush` after updating (new backend AJAX routes, wider backend CSP).
- Browser uploads PUT to the platform's object storage; the backend CSP now allows `connect-src https://object-storage.scale.sc`. For a self-hosted platform set the extension configuration `storageOrigin` to its storage origin, then `cache:flush`.

### Added
- Browser upload of videos in the module: presigned multipart straight to storage with progress, retry and cancel; the server-side form stays as fallback ([#10](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/10)).
- Status polling after upload until `ready` or `failed`, showing the API's `error` ([#10](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/10)).
- Custom poster: upload an image, pick one from the TYPO3 file list, remove it ([#10](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/10)).
- Extension configuration `storageOrigin` (default `https://object-storage.scale.sc`) ([#10](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/10)).
- Subtitle download (WebVTT) in the module ([#8](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/8)).
- Metadata editing of existing videos in the module ([#9](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/9)).
- GitHub Actions: PHP syntax, XLF parity, PHPStan and PHPUnit on PHP 8.4/8.5, JavaScript syntax and tests ([#12](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/12)).
- Unit tests for the AJAX endpoints, webhook receiver, URL checks and the content element controllers ([#12](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/12)).

### Changed
- Module video lists load 60 videos per page with "Load more" (was: at most 20 pages of 100); loaded pages survive edits ([#11](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/11)).
- Module tag filter runs on the API: several tags match videos carrying all of them (was: any of them) ([#11](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/11)).
- While videos encode, the module polls only those videos and reloads the list once one has finished ([#11](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/11)).

### Security
- Presigned storage URLs are only accepted on the configured storage origin; changing AJAX routes need module access and the custom option ([#10](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/10)).

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
