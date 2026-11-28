# Changelog

All notable changes to this package. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning: [SemVer](https://semver.org/).

## [2.2.0] - 2026-11-28

### Upgrade
- Frontend CSP of the site: if you replaced the extension's CSP mutations, allow `img-src https://api.videooptimizer.eu`; `Js/chapters.js` and `Js/mini-player.js` are new sub-modules of `Js/videooptimizer.js` (copy them along when you serve the JavaScript yourself).
- Run `extension:setup` (or System > Maintenance > Analyze Database Structure) for the new `tt_content` columns (`vo_eyebrow`, `vo_caption`, `vo_split_side`, `vo_cta_label`, `vo_cta_link`, `vo_scene_count`, `vo_scene_titles`, `vo_format`, `vo_focus_x`, `vo_focus_y`, `vo_scroll_play`, `vo_mini_player`), then `cache:flush`. New columns get 50 (focal point) and 5 (scenes) as default through `ext_tables.sql`. Re-run `extension:setup` if the Install Tool's database analyzer still lists the new columns.

### Changed
- Autoplaying native players (hero, single, ...) now pause once less than 25 % of them is visible (was: only when completely out of view) ([#21](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/21)).
- The video list shows ready videos only and fills a page from up to three API calls (each asks only for the missing number), so it is never empty while older ready videos exist and never exceeds the page size; the cursor stays exact ([#18](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/18)).
- The list cursor from the URL is validated strictly (base64 charset, at most 200 characters); an invalid one shows the first page ([#18](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/18)).
- During an API outage elements say "temporarily unavailable" instead of "not found" (visible to developers and backend users) ([#18](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/18)).

### Added
- Floating mini player (single, split, spotlight, chapters; direct presentation): after the visitor started playback (any play, unmute or seek that the plugin did not make itself, which includes the native control bar, or a press on a scene button; never autoplay or play while scrolling) and the player scrolls out of view it is pinned bottom right (320 px, from 600 px viewport width) with a close button (Escape works too, focus returns to the player); it steps aside while a lightbox is open; native closing pauses, hosted closing stops the iframe and restores it on return; a placeholder keeps the page height and nothing is moved in the DOM; with play while scrolling the video is pinned instead of paused once the visitor took over ([#22](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/22)).
- Play while scrolling (single, split, spotlight, chapters; native player shown directly): starts muted at 50 % visible, pauses below it, a pause by the visitor sticks, hls.js attaches only then; not with reduced motion or data saver ([#21](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/21)).
- Format option (original, 16:9, 9:16, 1:1, 4:5, 21:9) with focal point on single, split and spotlight: the surface takes the ratio and the video/poster fills it (`object-fit: cover`, `object-position`; hosted `fit=cover`); 9:16 and 4:5 are capped at 420 px wide; with a format the player sits in a `.vo-media` wrapper ([#20](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/20)).
- Split, spotlight and chapters follow the header palette of the record: header type (100 hides it), header link, sub header and position, like the core Header partials ([#18](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/18)).
- Content element "Video Chapters": the player with a row of scene previews (3-10 of the video's ten thumbnails, start times from the exact duration, optional titles); a click jumps to the scene and plays, native and hosted ([#19](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/19)).
- Frontend CSP: `img-src https://api.videooptimizer.eu` for the scene previews, which the API redirects to `*.cdn.videooptimizer.eu` ([#19](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/19)).
- Content elements "Video Split" (video next to eyebrow, headline, rich text and button, left or right) and "Video Spotlight" (centered video with eyebrow, headline and caption) ([#18](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/18)).

## [2.1.0] - 2026-11-28

### Upgrade
- Run `extension:setup` (or System > Maintenance > Analyze Database Structure) for the new `tt_content` columns `vo_player_mode` and `vo_presentation`, then `cache:flush`. Existing elements keep the hosted player, shown directly.
- `Js/videooptimizer.js` is an ES module now: custom templates that load it must add `type="module"` (`<f:asset.script ... type="module" />`).
- The 2.0.0 rule `@media (prefers-reduced-motion) { .vo-hero__media { display: none } }` is gone. Overridden `Hero.html` templates must switch the hosted iframe to `data-vo-src` plus a `<noscript>` iframe, add the `data-vo-toggle` button and load `Js/videooptimizer.js` as a module with `data-vo-version="{assetVersion}"` ([#17](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/17)).

### Changed
- Hosted heroes and the hosted facade/lightbox now also call `GET /embed` (poster `srcset`, cached with the video) ([#16](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/16)).
- The hosted hero iframe is loaded by `Js/videooptimizer.js` (a `<noscript>` iframe plays without JavaScript); with reduced motion it loads only after play ([#17](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/17)).
- Theme `controls: false` only applies to directly shown, muted autoplaying videos (not to facade, lightbox or cards), `interactive: false` only to directly shown autoplaying players ([#14](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/14)).

### Added
- Accessibility: pause/play button on every autoplaying native player (hero and single/detail) and on the hosted hero (swaps the iframe for the poster); prefers-reduced-motion and data saver keep them still; lightbox `<dialog>` closes with Escape and starts on the close button, the page behind is inert; ([#17](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/17)).
- Responsive posters: `posterSrcset` as `srcset` + `sizes` on the hero and facade poster; the hero preloads its poster with `fetchpriority="high"` ([#16](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/16)).
- JSON-LD `uploadDate` from the embed config when it was loaded for the element, else `created_at` ([#16](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/16)).
- Subtitle tracks of the video as `<track>` elements (language, label, kind; `default` from the API or the element's subtitle language) with `crossorigin="anonymous"` on the native player ([#15](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/15)).
- Player theme from `GET /embed` applied to the native player: control bar, autoplay (muted), loop, preload (`auto` capped at `metadata`) and accent color as `--vo-accent`; options set on the element win ([#14](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/14)).
- Native player per content element (`<video>`, hls.js light loaded only when needed, native HLS on Safari, MP4 fallback that keeps position and playing state) next to the hosted iframe ([#13](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/13)).
- Presentation of single/detail elements: direct, click-to-play facade or lightbox; grid, list and the hero button open the native player in the lightbox ([#13](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/13)).
- Native hero: background `<video>` that plays only while visible ([#13](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/13)).
- Frontend CSP mutations (effective with the site's frontend CSP): `img-src`/`media-src`/`connect-src` `https://*.cdn.videooptimizer.eu`, `img-src https://poster.videooptimizer.eu`, `media-src blob:`, `frame-src https://*.videooptimizer.eu` ([#13](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/13)).
- hls.js light 1.7.4 bundled as `Js/vendor/hls.light-1.7.4.min.js` (Apache-2.0, license in `Js/vendor/`); a failed load logs a console warning and falls back to MP4 ([#13](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/13)).
- Grid/list cards load their embed configs in parallel (max 8); after a transport error, 429 or 5xx the rest of the request and the next minute fall back to the hosted player; such a page is cached for 60 s at most ([#13](https://github.com/ScaleCommerce/videooptimizer-typo3/issues/13)).

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
