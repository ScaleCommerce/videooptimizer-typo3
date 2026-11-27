<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Controller;

use TYPO3\CMS\Frontend\Cache\CacheInstruction;
use ScaleCommerce\VideoOptimizer\Player\NativePlayerConfig;
use ScaleCommerce\VideoOptimizer\Security\MediaUrl;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerException;
use TYPO3\CMS\Core\Cache\CacheDataCollectorInterface;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

abstract class AbstractVideoController extends ActionController
{
    /**
     * Hosts (and their sub-domains) the API may hand out for embed/poster/thumbnail URLs.
     * Observed: player.videooptimizer.eu (embed), poster.videooptimizer.eu (poster) and
     * <library>.cdn.videooptimizer.eu (thumbnails). Everything else is treated as missing.
     */

    protected const DEFAULT_CACHE_TTL = 300;

    /**
     * The rendered page is cached in the TYPO3 page cache (up to 24 h by default) while API data only lives
     * cacheTtl seconds. We cap the page cache lifetime to the API TTL, but never below this floor so that
     * the page cache does not churn every 5 minutes. A webhook can flush earlier via the vo_* cache tags.
     */
    protected const PAGE_CACHE_FLOOR = 900;

    /** Page cache lifetime used when the API failed, so an outage is not frozen into the page cache. */
    protected const PAGE_CACHE_ERROR_LIFETIME = 60;

    protected const VALIDATION_CURSOR = '/^[A-Za-z0-9_=.:-]{1,512}$/';

    /** @var array<string, string> upload dates from loaded embed configs, by video UUID */
    private array $uploadDates = [];

    public function __construct(
        protected readonly VideoOptimizerClient $client,
    ) {
    }

    // ------------------------------------------------------------------
    // Current content record
    // ------------------------------------------------------------------

    /**
     * The tt_content record this plugin is rendered for (language/workspace/visibility already resolved
     * by the frontend rendering).
     *
     * @return array<string, mixed>
     */
    protected function currentRecord(): array
    {
        $data = $this->request->getAttribute('currentContentObject')?->data ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * The video selected for this content element, falling back to the demo video.
     */
    protected function configuredUuid(): string
    {
        $uuid = trim((string)($this->currentRecord()['vo_video_uuid'] ?? ''));

        return $uuid !== '' ? $uuid : $this->demoUuid();
    }

    /**
     * The library selected for this content element. '' means "all libraries".
     */
    protected function configuredLibraryId(): string
    {
        return trim((string)($this->currentRecord()['vo_library_id'] ?? ''));
    }

    /**
     * Library of a shaped video (empty when unknown).
     *
     * @param array<string, mixed>|null $video
     * @return list<string>
     */
    protected function libraryIdsOf(?array $video): array
    {
        $libraryId = is_array($video) ? trim((string)($video['library_id'] ?? '')) : '';

        return $libraryId !== '' ? [$libraryId] : [];
    }

    /**
     * Demo video: extension configuration first, then the environment variable VO_DEMO_VIDEO_UUID
     * (single source of truth in the client, as documented in ext_conf_template.txt).
     */
    protected function demoUuid(): string
    {
        return $this->client->getDemoVideoUuid();
    }

    protected function cacheTtl(): int
    {
        $env = getenv('VO_CACHE_TTL');
        $value = is_string($env) ? trim($env) : '';
        if ($value === '') {
            $value = $this->extensionSetting('cacheTtl');
        }

        return ctype_digit($value) && (int)$value > 0 ? (int)$value : self::DEFAULT_CACHE_TTL;
    }

    private function extensionSetting(string $key): string
    {
        try {
            $value = $this->extensionConfiguration()->get('videooptimizer', $key);
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function extensionConfiguration(): ExtensionConfiguration
    {
        return GeneralUtility::makeInstance(ExtensionConfiguration::class);
    }

    /**
     * Load and shape the video; null on any failure (the reason lands in $errorCode/$errorDetail).
     * An empty/unknown API response counts as "not found".
     */
    protected function fetchVideo(string $uuid, string &$errorCode, string &$errorDetail): ?array
    {
        try {
            $raw = $this->client->getVideo($uuid);
        } catch (VideoOptimizerException|\InvalidArgumentException $exception) {
            $errorCode = 'api';
            $errorDetail = $exception->getMessage();

            return null;
        }
        if (trim((string)($raw['uuid'] ?? '')) === '') {
            $errorCode = 'api';
            $errorDetail = 'Video not found.';

            return null;
        }

        return $this->shapeVideos([$raw])[0] ?? null;
    }

    // ------------------------------------------------------------------
    // Errors (only visible to developers / logged-in backend users)
    // ------------------------------------------------------------------

    protected function mayShowErrors(): bool
    {
        if (Environment::getContext()->isDevelopment()) {
            return true;
        }

        return (bool)GeneralUtility::makeInstance(Context::class)
            ->getPropertyFromAspect('backend.user', 'isLoggedIn', false);
    }

    /**
     * Error descriptor for the template, or null for normal visitors (who get an empty element).
     * $code selects the localized text (error.<code>), $detail is the raw exception message.
     *
     * @return array{code: string, detail: string}|null
     */
    protected function errorForTemplate(string $code, string $detail = ''): ?array
    {
        if ($code === '' || !$this->mayShowErrors()) {
            return null;
        }

        // Error details are meant for this developer/editor only: never let them into the shared
        // page cache, where anonymous visitors would get them for the next minute.
        $cacheInstruction = $this->request->getAttribute('frontend.cache.instruction');
        if ($cacheInstruction instanceof CacheInstruction) {
            $cacheInstruction->disableCache('VideoOptimizer: error details shown to a backend user/developer');
        }

        return ['code' => $code, 'detail' => $detail];
    }

    /**
     * Error code to show when nothing could be loaded and no API token is configured.
     */
    protected function missingTokenCode(): string
    {
        return $this->client->hasToken() ? '' : 'no_token';
    }

    // ------------------------------------------------------------------
    // Page cache coordination
    // ------------------------------------------------------------------

    /**
     * Tag the page cache entry and cap its lifetime to the API cache TTL (see PAGE_CACHE_FLOOR).
     *
     * @param list<string> $videoUuids
     * @param list<string> $libraryIds libraries of the shown videos, so library flushes reach this page
     */
    protected function registerPageCache(array $videoUuids, bool $isList, bool $failed = false, array $libraryIds = []): void
    {
        $collector = $this->request->getAttribute('frontend.cache.collector');
        if (!$collector instanceof CacheDataCollectorInterface) {
            return;
        }

        $tags = [];
        foreach ($videoUuids as $uuid) {
            if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $uuid) === 1) {
                $tags[] = new CacheTag('vo_video_' . $uuid);
            }
        }
        foreach (array_unique($libraryIds) as $libraryId) {
            if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $libraryId) === 1) {
                $tags[] = new CacheTag('vo_library_' . $libraryId);
            }
        }
        if ($isList) {
            $tags[] = new CacheTag('vo_lists');
        }
        if ($tags !== []) {
            $collector->addCacheTags(...$tags);
        }

        $collector->restrictMaximumLifetime(
            $failed ? self::PAGE_CACHE_ERROR_LIFETIME : max($this->cacheTtl(), self::PAGE_CACHE_FLOOR)
        );
    }

    // ------------------------------------------------------------------
    // URL safety
    // ------------------------------------------------------------------

    /**
     * Return the URL when it is https on videooptimizer.eu (or a sub-domain), else ''.
     */
    protected function safeUrl(mixed $url): string
    {
        return MediaUrl::safe($url);
    }

    // ------------------------------------------------------------------
    // Embed
    // ------------------------------------------------------------------

    /**
     * Data for the Player partial: validated player src (with this element's player options), accessible
     * title and aspect ratio. No HTML is built here; the partial escapes everything.
     *
     * @param array<string, mixed> $video shaped video
     * @return array{src: string, title: string, aspect: string}|null
     */
    protected function buildEmbed(array $video): ?array
    {
        $src = $this->embedUrlWithParams((string)($video['embed_url'] ?? ''), $this->playerQueryParams());
        if ($src === '') {
            return null;
        }

        return [
            'src' => $src,
            'title' => (string)($video['title_label'] ?? ''),
            'aspect' => $this->aspectRatioFromResolution((string)($video['resolution'] ?? '')),
        ];
    }

    /**
     * Player of this element: `hosted` (iframe, default) or `native` (<video>, hls.js on demand).
     */
    protected function playerMode(): string
    {
        return ($this->currentRecord()['vo_player_mode'] ?? '') === 'native' ? 'native' : 'hosted';
    }

    /**
     * How the player appears: `direct` (default), `facade` (poster + play button, player in place) or
     * `lightbox` (poster + play button, player in a dialog).
     */
    protected function presentation(): string
    {
        $value = $this->currentRecord()['vo_presentation'] ?? '';

        return in_array($value, ['facade', 'lightbox'], true) ? $value : 'direct';
    }

    /**
     * Public player configuration of a video; null when it cannot be loaded (callers fall back to the
     * hosted player).
     *
     * @return array<string, mixed>|null
     */
    protected function embedConfig(string $uuid): ?array
    {
        try {
            $config = $this->client->getEmbed($uuid);
        } catch (VideoOptimizerException|\InvalidArgumentException) {
            return null;
        }
        $this->rememberUploadDate($uuid, $config);

        return $config !== [] ? $config : null;
    }

    /** `sizes` of posters in the column width of single/detail elements and full-bleed heroes. */
    protected const POSTER_SIZES_CONTENT = '(min-width: 1080px) 1032px, 100vw';
    protected const POSTER_SIZES_FULL = '100vw';

    /**
     * Remembers the embed config's `uploadDate` for the JSON-LD of this request (no extra API call).
     *
     * @param array<string, mixed> $config
     */
    protected function rememberUploadDate(string $uuid, array $config): void
    {
        if (is_string($config['uploadDate'] ?? null) && trim($config['uploadDate']) !== '') {
            $this->uploadDates[$uuid] = $config['uploadDate'];
        }
    }

    /**
     * Responsive poster candidates of a video (`posterSrcset` of the embed config) for the <img> of
     * facade and hero. '' when the video has none or the embed config cannot be loaded; the plain
     * poster stays the fallback.
     */
    protected function posterSrcset(string $uuid): string
    {
        $embed = $this->embedConfig($uuid);

        return $embed === null ? '' : NativePlayerConfig::posterSrcset($embed['posterSrcset'] ?? []);
    }

    /**
     * Native player settings for a shaped video; null for hosted elements, processing videos and
     * videos without playable sources.
     *
     * @param array<string, mixed> $video shaped video
     * @return array{hls: string, mp4: string, poster: string, tracks: list<array{src: string, srclang: string, label: string, kind: string, default: bool}>, options: array<string, mixed>, json: string}|null
     */
    protected function buildNative(array $video, bool $heroMode = false): ?array
    {
        if ($this->playerMode() !== 'native' || ($video['processing'] ?? true) !== false) {
            return null;
        }
        $embed = $this->embedConfig((string)($video['uuid'] ?? ''));
        if ($embed === null) {
            return null;
        }

        return NativePlayerConfig::build(
            $embed,
            $this->effectivePlayerSettings($heroMode),
            (string)($video['title_label'] ?? ''),
            $this->unavailableLabel(),
            $heroMode
        );
    }

    protected function unavailableLabel(): string
    {
        return (string)LocalizationUtility::translate(
            'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_frontend.xlf:video.unavailable'
        );
    }

    /**
     * Grid/list cards: adds `native_json` (settings of the native player for the lightbox) to every
     * playable video when the element uses the native player. Cards without sources keep the hosted
     * lightbox.
     *
     * @param list<array<string, mixed>> $videos shaped videos
     * @return list<array<string, mixed>>
     */
    protected function withNativeCards(array $videos): array
    {
        if ($this->playerMode() !== 'native') {
            return $videos;
        }
        foreach ($videos as &$video) {
            $video['native_json'] = $this->buildNative($video)['json'] ?? '';
        }

        return $videos;
    }

    /**
     * Everything the Surface partial needs for single/detail.
     *
     * @param array<string, mixed> $video shaped video
     * @return array{presentation: string, posterSrcset: string, posterSizes: string, aspect: string, style: string, title: string, poster: string, clickSrc: string, embed: array{src: string, title: string, aspect: string}|null, native: array<string, mixed>|null}
     */
    protected function buildSurface(array $video): array
    {
        $embed = $this->buildEmbed($video);
        $native = $this->buildNative($video);
        $aspect = $this->aspectRatioFromResolution((string)($video['resolution'] ?? ''));
        // Aspect ratio (digits only) and the theme's accent color (validated), for the style attribute.
        $style = implode('; ', array_filter([
            $aspect !== '' ? 'aspect-ratio: ' . $aspect : '',
            ($native['options']['accent'] ?? '') !== '' ? '--vo-accent: ' . $native['options']['accent'] : '',
        ]));

        $presentation = $this->presentation();

        return [
            'presentation' => $presentation,
            // The poster of the facade only; direct players show no poster image.
            'posterSrcset' => $presentation !== 'direct' ? $this->posterSrcset((string)($video['uuid'] ?? '')) : '',
            'posterSizes' => self::POSTER_SIZES_CONTENT,
            'aspect' => $aspect,
            'style' => $style,
            'title' => (string)($video['title_label'] ?? ''),
            'poster' => (string)($video['poster_src'] ?? ''),
            // Hosted facade/lightbox: the visitor clicked play, so autoplay with sound is allowed.
            'clickSrc' => (string)($video['lightbox_url'] ?? ''),
            'embed' => $embed,
            'native' => $native,
        ];
    }

    /**
     * "1920x1080" -> "1920/1080" for a CSS aspect-ratio value. '' when missing or not in WxH form.
     */
    protected function aspectRatioFromResolution(string $resolution): string
    {
        if (preg_match('/^(\d{2,5})\s*[x×]\s*(\d{2,5})$/u', trim($resolution), $m) === 1) {
            return $m[1] . '/' . $m[2];
        }

        return '';
    }

    /**
     * @return array{width: int, height: int}|null
     */
    protected function dimensionsFromResolution(string $resolution): ?array
    {
        if (preg_match('/^(\d{2,5})\s*[x×]\s*(\d{2,5})$/u', trim($resolution), $m) === 1) {
            return ['width' => (int)$m[1], 'height' => (int)$m[2]];
        }

        return null;
    }

    /**
     * Merge $params into the query of an already validated embed URL (existing query and fragment kept).
     *
     * @param array<string, string> $params
     */
    protected function embedUrlWithParams(string $embedUrl, array $params): string
    {
        $embedUrl = $this->safeUrl($embedUrl);
        if ($embedUrl === '') {
            return '';
        }
        $parts = parse_url($embedUrl);
        if ($parts === false || !isset($parts['host'])) {
            return '';
        }

        $query = [];
        parse_str((string)($parts['query'] ?? ''), $query);
        $query = array_merge($query, $params);

        return 'https://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '')
            . ($query !== [] ? '?' . http_build_query($query) : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /**
     * Player options of this content element as query params. A hero element whose options were never
     * touched renders as a classic background video (autoplay, muted, loop, no controls, cover).
     *
     * @return array<string, string>
     */
    protected function playerQueryParams(bool $heroMode = false): array
    {
        return $this->buildPlayerParams($this->effectivePlayerSettings($heroMode));
    }

    /**
     * The element's player options; a hero whose options were never touched plays as a background video.
     *
     * @return array{autoplay: bool, loop: bool, muted: bool, controls: bool, start_time: int, fit_cover: bool, subtitle_lang: string}
     */
    protected function effectivePlayerSettings(bool $heroMode = false): array
    {
        $settings = $this->playerSettings();

        if ($heroMode && !$settings['autoplay'] && !$settings['loop'] && !$settings['muted']
            && $settings['controls'] && $settings['start_time'] === 0 && !$settings['fit_cover']
        ) {
            $settings = [
                'autoplay' => true,
                'loop' => true,
                'muted' => true,
                'controls' => false,
                'start_time' => 0,
                'fit_cover' => true,
                'subtitle_lang' => $settings['subtitle_lang'],
            ];
        }

        return $settings;
    }

    /**
     * Params for the grid/list lightbox: click-to-play, so autoplay is on and muted off
     * (browsers allow sound after a user gesture).
     *
     * @return array<string, string>
     */
    protected function lightboxQueryParams(): array
    {
        $settings = $this->playerSettings();
        $settings['autoplay'] = true;
        $settings['muted'] = false;

        return $this->buildPlayerParams($settings);
    }

    /**
     * The hosted player only overrides the video's theme for params that are present, so all four
     * playback flags are sent explicitly (deterministic output).
     *
     * @param array{autoplay: bool, loop: bool, muted: bool, controls: bool, start_time: int, fit_cover: bool, subtitle_lang: string} $settings
     * @return array<string, string>
     */
    private function buildPlayerParams(array $settings): array
    {
        $params = [
            'autoplay' => $settings['autoplay'] ? '1' : '0',
            'controls' => $settings['controls'] ? '1' : '0',
            'loop' => $settings['loop'] ? '1' : '0',
            'muted' => $settings['muted'] ? '1' : '0',
        ];
        if ($settings['start_time'] > 0) {
            $params['t'] = (string)$settings['start_time'];
        }
        if ($settings['fit_cover']) {
            $params['fit'] = 'cover';
        }
        if ($settings['subtitle_lang'] !== '') {
            $params['cc'] = $settings['subtitle_lang'];
        }

        return $params;
    }

    /**
     * The player options stored on the current content record (TCA vo_* fields), validated.
     *
     * @return array{autoplay: bool, loop: bool, muted: bool, controls: bool, start_time: int, fit_cover: bool, subtitle_lang: string}
     */
    protected function playerSettings(): array
    {
        $row = $this->currentRecord();

        $lang = trim((string)($row['vo_subtitle_lang'] ?? ''));
        if (preg_match('/^([a-z]{2,3}(-[A-Za-z0-9]{2,8})?|off)$/', $lang) !== 1) {
            $lang = '';
        }

        return [
            'autoplay' => (int)($row['vo_autoplay'] ?? 0) === 1,
            'loop' => (int)($row['vo_loop'] ?? 0) === 1,
            'muted' => (int)($row['vo_muted'] ?? 0) === 1,
            'controls' => !array_key_exists('vo_controls', $row) || (int)$row['vo_controls'] !== 0,
            'start_time' => max(0, min(86400, (int)($row['vo_start_time'] ?? 0))),
            'fit_cover' => (int)($row['vo_fit_cover'] ?? 0) === 1,
            'subtitle_lang' => $lang,
        ];
    }

    // ------------------------------------------------------------------
    // Structured data (schema.org VideoObject, JSON-LD)
    // ------------------------------------------------------------------

    private const JSON_LD_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** File extensions accepted as a direct video file for contentUrl (never the HLS playlist). */
    private const CONTENT_URL_EXTENSIONS = ['mp4', 'webm', 'ogv', 'mov', 'm4v'];

    /**
     * Site setting videooptimizer.structuredData (Site Set VideoOptimizer, default true).
     */
    protected function structuredDataEnabled(): bool
    {
        $site = $this->request->getAttribute('site');
        if (!$site instanceof SiteInterface) {
            return true;
        }

        return (bool)$site->getSettings()->get('videooptimizer.structuredData', true);
    }

    /**
     * Emit one VideoObject per video (hero/single/detail). The AssetCollector state is stored with the
     * page cache entry, so the JSON-LD is served from the cache like the rest of the page. The identifier
     * contains the UUID: the same video shown by several elements is emitted once.
     *
     * @param array<string, mixed>|null $video shaped video
     */
    protected function addVideoStructuredData(?array $video): void
    {
        if ($video === null || !$this->structuredDataEnabled()) {
            return;
        }
        $object = $this->videoObjectData($video, trim((string)($this->currentRecord()['header'] ?? '')));
        if ($object === null) {
            return;
        }
        $this->addJsonLd('videooptimizer-jsonld-' . $video['uuid'], ['@context' => 'https://schema.org'] + $object);
    }

    /**
     * Emit an ItemList of VideoObjects for the videos actually shown (grid/list).
     *
     * @param list<array<string, mixed>> $videos shaped videos
     */
    protected function addVideoListStructuredData(array $videos): void
    {
        if (!$this->structuredDataEnabled()) {
            return;
        }
        $items = [];
        $uuids = [];
        foreach ($videos as $video) {
            if (($video['embed_src'] ?? '') === '') {
                continue;
            }
            $object = $this->videoObjectData($video, '');
            if ($object === null) {
                continue;
            }
            $items[] = [
                '@type' => 'ListItem',
                'position' => count($items) + 1,
                'item' => $object,
            ];
            $uuids[] = $video['uuid'];
        }
        if ($items === []) {
            return;
        }
        $this->addJsonLd('videooptimizer-jsonld-list-' . md5(implode(',', $uuids)), [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => count($items),
            'itemListElement' => $items,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function addJsonLd(string $identifier, array $data): void
    {
        if (preg_match('/^videooptimizer-jsonld-[A-Za-z0-9-]{1,80}$/', $identifier) !== 1) {
            return;
        }
        try {
            $json = json_encode($data, self::JSON_LD_FLAGS);
        } catch (\JsonException) {
            return;
        }
        GeneralUtility::makeInstance(AssetCollector::class)
            ->addInlineJavaScript($identifier, $json, ['type' => 'application/ld+json']);
    }

    /**
     * VideoObject for one shaped video, null for videos that are not ready, failed or have no UUID.
     *
     * @param array<string, mixed> $video
     * @return array<string, mixed>|null
     */
    protected function videoObjectData(array $video, string $fallbackDescription): ?array
    {
        $uuid = (string)($video['uuid'] ?? '');
        if (preg_match('/^[A-Za-z0-9-]{1,64}$/', $uuid) !== 1 || ($video['processing'] ?? true) !== false) {
            return null;
        }

        $name = (string)($video['title_label'] ?? '');
        $metadata = is_array($video['metadata'] ?? null) ? $video['metadata'] : [];
        $description = '';
        foreach ([$metadata['description'] ?? '', $fallbackDescription, $name] as $candidate) {
            $candidate = is_scalar($candidate) ? trim(strip_tags((string)$candidate)) : '';
            if ($candidate !== '') {
                $description = mb_substr($candidate, 0, 5000);
                break;
            }
        }

        $thumbnails = array_values(array_unique(array_filter([
            $this->safeUrl($video['poster_url'] ?? ''),
            $this->safeUrl($video['thumbnail_url'] ?? ''),
        ])));

        $object = [
            '@type' => 'VideoObject',
            'name' => $name,
            'description' => $description,
        ];
        if ($thumbnails !== []) {
            $object['thumbnailUrl'] = count($thumbnails) === 1 ? $thumbnails[0] : $thumbnails;
        }
        $uploadDate = $this->isoDate($this->uploadDates[$uuid] ?? null);
        if ($uploadDate === '') {
            $uploadDate = $this->isoDate($video['created_at'] ?? null);
        }
        if ($uploadDate !== '') {
            $object['uploadDate'] = $uploadDate;
        }
        $duration = $this->isoDuration($video['duration'] ?? 0);
        if ($duration !== '') {
            $object['duration'] = $duration;
        }
        $embedUrl = $this->safeUrl($video['embed_url'] ?? '');
        if ($embedUrl !== '') {
            $object['embedUrl'] = $embedUrl;
        }
        $contentUrl = $this->directContentUrl($video);
        if ($contentUrl !== '') {
            $object['contentUrl'] = $contentUrl;
        }

        return $object;
    }

    /**
     * First validated URL that points at a direct video file. The HLS playlist is never used. The API
     * currently exposes none (only hls_url), so this stays empty until it adds e.g. an mp4 rendition URL.
     *
     * @param array<string, mixed> $video
     */
    private function directContentUrl(array $video): string
    {
        $candidates = [$video['content_url'] ?? '', $video['mp4_url'] ?? '', $video['download_url'] ?? ''];
        foreach (is_array($video['renditions'] ?? null) ? $video['renditions'] : [] as $rendition) {
            if (is_array($rendition)) {
                $candidates[] = $rendition['url'] ?? '';
            }
        }
        foreach ($candidates as $candidate) {
            $url = $this->safeUrl($candidate);
            if ($url === '') {
                continue;
            }
            $extension = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (in_array($extension, self::CONTENT_URL_EXTENSIONS, true)) {
                return $url;
            }
        }

        return '';
    }

    private function isoDate(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Seconds -> ISO 8601 duration, e.g. 65 -> PT1M5S.
     */
    private function isoDuration(mixed $seconds): string
    {
        $total = (int)$seconds;
        if ($total <= 0) {
            return '';
        }

        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        return 'PT' . ($hours > 0 ? $hours . 'H' : '') . ($minutes > 0 ? $minutes . 'M' : '')
            . ($secs > 0 ? $secs . 'S' : '');
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    /**
     * Format a duration in seconds as m:ss or h:mm:ss.
     */
    protected function formatDuration(mixed $seconds): string
    {
        $total = (int)$seconds;
        if ($total <= 0) {
            return '';
        }

        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * Add presentation fields to each video: title_label, duration_label, poster_src (validated),
     * embed_src (validated), lightbox_url (validated, with click-to-play params), processing flag, dimensions.
     * Videos without a valid embed URL keep embed_src = ''.
     *
     * @param list<array<string, mixed>> $videos
     * @return list<array<string, mixed>>
     */
    protected function shapeVideos(array $videos): array
    {
        $lightboxParams = $this->lightboxQueryParams();
        $shaped = [];
        foreach ($videos as $video) {
            if (!is_array($video)) {
                continue;
            }

            $uuid = (string)($video['uuid'] ?? '');
            $title = trim((string)($video['title'] ?? ''));
            $video['title_label'] = $title !== '' ? $title : (string)LocalizationUtility::translate(
                'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_frontend.xlf:video.fallback_title',
                null,
                [substr($uuid, 0, 8)]
            );
            $video['duration_label'] = $this->formatDuration($video['duration'] ?? 0);

            // poster_url is the stable ACTIVE poster; fall back to the plain thumbnail frame.
            $poster = $this->safeUrl($video['poster_url'] ?? '');
            $video['poster_src'] = $poster !== '' ? $poster : $this->safeUrl($video['thumbnail_url'] ?? '');

            $embed = $this->safeUrl($video['embed_url'] ?? '');
            $video['embed_src'] = $embed;
            $video['lightbox_url'] = $this->embedUrlWithParams($embed, $lightboxParams);

            $video['processing'] = ($video['ready'] ?? true) === false
                || trim((string)($video['error'] ?? '')) !== '';
            $video['dimensions'] = $this->dimensionsFromResolution((string)($video['resolution'] ?? ''));

            $shaped[] = $video;
        }

        return $shaped;
    }
}
