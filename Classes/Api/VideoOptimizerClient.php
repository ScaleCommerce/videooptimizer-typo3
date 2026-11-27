<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\ApplicationType;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;

final class VideoOptimizerClient implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const BASE_URL = 'https://api.videooptimizer.eu/api/v1';
    private const PAGE_LIMIT = 100;
    private const MAX_PAGES = 20;
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_BASE_DELAY_MS = 250;
    private const FRONTEND_RETRY_ATTEMPTS = 2;
    /** Max total sleep between attempts in a frontend request (seconds). */
    private const FRONTEND_MAX_SLEEP = 1.0;
    private const BACKEND_MAX_RETRY_AFTER = 30.0;

    private const TIMEOUT_JSON = 8;
    private const CONNECT_TIMEOUT_JSON = 2;
    private const TIMEOUT_UPLOAD = 300;
    private const CONNECT_TIMEOUT_UPLOAD = 5;

    /** Hard cache lifetime; the configured TTL is only a soft TTL (stale-while-error). */
    private const CACHE_LIFETIME = 86400;
    private const DEFAULT_SOFT_TTL = 300;
    /** Lifetime of negative entries (remembered failures / empty results). */
    private const NEGATIVE_TTL = 30;
    private const MAX_ERROR_MESSAGE_LENGTH = 200;

    /**
     * Files at or above this size use the presigned multipart flow
     * (only available for media-managed libraries); smaller files go
     * through the plain multipart endpoint.
     */
    private const PRESIGNED_THRESHOLD_BYTES = 100 * 1024 * 1024;

    private ?ClientInterface $httpClient = null;
    private ?FrontendInterface $cache = null;

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly GuzzleClientFactory $guzzleClientFactory,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly UserAgent $userAgent,
    ) {
    }

    // ------------------------------------------------------------------
    // Configuration
    // ------------------------------------------------------------------

    public function hasToken(): bool
    {
        return $this->token() !== '';
    }

    /**
     * Demo video UUID: extension configuration first, then env VO_DEMO_VIDEO_UUID.
     */
    public function getDemoVideoUuid(): string
    {
        $value = $this->extConf('demoVideoUuid');
        if ($value === '') {
            $env = getenv('VO_DEMO_VIDEO_UUID');
            $value = is_string($env) ? trim($env) : '';
        }

        return $value;
    }

    /**
     * Webhook signing secret: extension configuration first, then env VO_WEBHOOK_SECRET.
     * Empty string = webhook receiver disabled.
     */
    public function getWebhookSecret(): string
    {
        $secret = $this->extConf('webhookSecret');
        if ($secret === '') {
            $env = getenv('VO_WEBHOOK_SECRET');
            $secret = is_string($env) ? trim($env) : '';
        }

        return $secret;
    }

    private function extConf(string $key): string
    {
        try {
            $value = $this->extensionConfiguration->get('videooptimizer', $key);
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function token(): string
    {
        $token = $this->extConf('apiToken');
        if ($token === '') {
            $env = getenv('VO_API_TOKEN');
            $token = is_string($env) ? trim($env) : '';
        }

        return $token;
    }

    private function softTtl(): int
    {
        $env = getenv('VO_CACHE_TTL');
        if (is_string($env) && ctype_digit(trim($env)) && (int)$env > 0) {
            return (int)$env;
        }
        $conf = $this->extConf('cacheTtl');
        if ($conf !== '' && ctype_digit($conf) && (int)$conf > 0) {
            return (int)$conf;
        }

        return self::DEFAULT_SOFT_TTL;
    }

    // ------------------------------------------------------------------
    // Input validation (every value that ends up in a URL path / query / cache key)
    // ------------------------------------------------------------------

    private function assertUuid(string $value, string $what = 'ID'): string
    {
        $value = trim($value);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid %s.', $what));
        }

        return $value;
    }

    /** Library IDs are UUIDs in the API. */
    private function assertLibraryId(string $value): string
    {
        return $this->assertUuid($value, 'library ID');
    }

    private function assertLanguageCode(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $value) !== 1 || strlen($value) > 35) {
            throw new InvalidArgumentException('Invalid language code.');
        }

        return $value;
    }

    // ------------------------------------------------------------------
    // Videos
    // ------------------------------------------------------------------

    /**
     * All videos of the organization, newest first.
     *
     * @param array<string, string> $metadataFilters key => value; up to 5, AND-combined
     * @return list<array<string, mixed>>
     */
    public function listAllVideos(array $metadataFilters = []): array
    {
        return $this->fetchList('videos', '/videos', $this->metadataQuery($metadataFilters));
    }

    /**
     * Videos restricted to one library (all videos when the id is empty).
     *
     * @param array<string, string> $metadataFilters key => value; up to 5, AND-combined
     * @return list<array<string, mixed>>
     */
    public function listVideosByLibrary(string $libraryId, array $metadataFilters = []): array
    {
        $libraryId = trim($libraryId);
        $filters = $this->metadataQuery($metadataFilters);
        if ($libraryId === '') {
            return $this->fetchList('videos', '/videos', $filters);
        }

        $libraryId = $this->assertLibraryId($libraryId);

        return $this->fetchList('vlib_' . $libraryId, '/videos', array_merge(['library_id' => $libraryId], $filters), ['vo_lists', 'vo_library_' . $libraryId]);
    }

    /**
     * Convert metadata filters into the API's `metadata[key]` query parameters.
     *
     * @param array<string, string> $filters key => value
     * @return array<string, string>
     */
    private function metadataQuery(array $filters): array
    {
        $result = [];
        foreach ($filters as $key => $value) {
            $key = trim((string)$key);
            $value = trim((string)$value);
            if ($key === '' || $value === '' || mb_strlen($key) > 40 || preg_match('/^[a-z0-9][a-z0-9_.-]{0,39}$/', $key) !== 1) {
                continue;
            }
            $result['metadata[' . $key . ']'] = $value;
            if (count($result) >= 5) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getVideo(string $uuid): array
    {
        $uuid = trim($uuid);
        if ($uuid === '') {
            return [];
        }
        $uuid = $this->assertUuid($uuid, 'video ID');

        return $this->remember('video:' . $uuid, ['vo_video_' . $uuid], function () use ($uuid): array {
            try {
                $body = $this->request('GET', '/videos/' . rawurlencode($uuid));
            } catch (ApiErrorException $exception) {
                if ($exception->getHttpStatus() === 404) {
                    return [];
                }
                throw $exception;
            }

            return is_array($body['data'] ?? null) ? $body['data'] : [];
        });
    }

    /**
     * One page of videos (a single API request), optionally restricted to a library.
     *
     * @return array{items: list<array<string, mixed>>, nextCursor: ?string}
     */
    public function listVideosPage(int $limit = 12, ?string $cursor = null, ?string $libraryId = null): array
    {
        $limit = max(1, min(100, $limit));
        $query = ['limit' => $limit];
        $tags = ['vo_lists'];

        $libraryId = trim((string)$libraryId);
        if ($libraryId !== '') {
            $libraryId = $this->assertLibraryId($libraryId);
            $query['library_id'] = $libraryId;
            $tags[] = 'vo_library_' . $libraryId;
        }
        $cursor = trim((string)$cursor);
        if ($cursor !== '') {
            if (preg_match('/^[A-Za-z0-9_=.:-]{1,512}$/', $cursor) !== 1) {
                throw new InvalidArgumentException('Invalid pagination cursor.');
            }
            $query['cursor'] = $cursor;
        }

        return $this->remember('page|' . md5(serialize($query)), $tags, function () use ($query): array {
            $body = $this->request('GET', '/videos', ['query' => $query]);
            $items = [];
            foreach (is_array($body['data'] ?? null) ? $body['data'] : [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $pagination = is_array($body['pagination'] ?? null) ? $body['pagination'] : [];
            $next = trim((string)($pagination['next_cursor'] ?? ''));

            return [
                'items' => $items,
                'nextCursor' => (($pagination['has_more'] ?? false) === true && $next !== '') ? $next : null,
            ];
        });
    }

    /**
     * Fetch the complete video list from the API, bypassing the soft TTL, and store it in the
     * cache. Unlike the cached readers this throws on API failure (no stale fallback), so callers
     * can tell "unchanged" from "unknown".
     *
     * @return list<array<string, mixed>>
     */
    public function refreshAllVideos(): array
    {
        $items = $this->loadList('/videos', []);
        $this->storeAllVideos($items);

        return $items;
    }

    /**
     * Store an already fetched complete video list as the fresh cache entry of listAllVideos().
     *
     * @param list<array<string, mixed>> $items
     */
    public function storeAllVideos(array $items): void
    {
        $tags = ['vo_lists'];
        $this->getCache()->set(
            $this->key('videos'),
            ['payload' => $items, 'fetchedAt' => time(), 'tags' => $tags],
            $tags,
            self::CACHE_LIFETIME
        );
    }

    // ------------------------------------------------------------------
    // Cache invalidation (webhook receiver / refresh command)
    // ------------------------------------------------------------------

    /**
     * Invalidate everything cached for one video (API cache and page cache).
     */
    public function flushVideo(string $uuid, ?string $libraryId = null): void
    {
        $tags = ['vo_video_' . $this->assertUuid($uuid, 'video ID'), 'vo_lists'];
        $libraryId = trim((string)$libraryId);
        if ($libraryId !== '') {
            $tags[] = 'vo_library_' . $this->assertLibraryId($libraryId);
        }
        $this->flushTags(...$tags);
        $this->flushPageCacheTags($tags);
    }

    /**
     * Invalidate all cached video lists (API cache and page cache).
     */
    public function flushLists(): void
    {
        $this->flushTags('vo_lists');
        $this->flushPageCacheTags(['vo_lists']);
    }

    /**
     * Invalidate a library and the lists (API cache and page cache).
     */
    public function flushLibrary(string $libraryId): void
    {
        $libraryId = $this->assertLibraryId($libraryId);
        $tags = ['vo_libraries', 'vo_library_' . $libraryId, 'vo_lists'];
        $this->flushTags(...$tags);
        $this->flushPageCacheTags($tags);
    }

    /**
     * Invalidate the whole VideoOptimizer API cache and every page carrying VideoOptimizer tags.
     */
    public function flushAll(): void
    {
        try {
            $this->getCache()->flush();
        } catch (\Throwable $exception) {
            $this->logger?->warning('VideoOptimizer cache flush failed.', ['error' => $exception->getMessage()]);
        }
        // Page cache entries carry per-video/library tags that are not enumerable; flush the
        // known aggregate tags the frontend controllers always add.
        $this->flushPageCacheTags(['vo_lists', 'vo_libraries', 'vo_meta']);
    }

    /**
     * @param list<string> $tags
     */
    private function flushPageCacheTags(array $tags): void
    {
        try {
            $this->cacheManager->flushCachesInGroupByTags('pages', array_values(array_unique($tags)));
        } catch (\Throwable $exception) {
            $this->logger?->warning('VideoOptimizer page cache flush failed.', ['tags' => $tags, 'error' => $exception->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // Libraries
    // ------------------------------------------------------------------

    /**
     * All libraries of the organization, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listLibraries(): array
    {
        return $this->fetchList('libraries', '/libraries');
    }

    /**
     * One library by UUID; [] when it does not exist (404).
     *
     * @return array<string, mixed>
     */
    public function getLibrary(string $id): array
    {
        $id = trim($id);
        if ($id === '') {
            return [];
        }
        $id = $this->assertLibraryId($id);

        return $this->remember('library:' . $id, ['vo_library_' . $id, 'vo_libraries'], function () use ($id): array {
            try {
                $body = $this->request('GET', '/libraries/' . rawurlencode($id));
            } catch (ApiErrorException $exception) {
                if ($exception->getHttpStatus() === 404) {
                    return [];
                }
                throw $exception;
            }

            return is_array($body['data'] ?? null) ? $body['data'] : [];
        });
    }

    /**
     * Create a library. `name` is required; the other keys are optional.
     *
     * @param array{name: string, description?: string, resolutions?: string, codec?: string} $data
     * @return array<string, mixed> the created library
     */
    public function createLibrary(array $data): array
    {
        $body = $this->request('POST', '/libraries', ['json' => $data], retry: false);
        $library = is_array($body['data'] ?? null) ? $body['data'] : [];

        if ($library !== []) {
            $this->flushTags('vo_lists');
        }

        return $library;
    }

    /**
     * Update name/description/codec/resolutions of a library.
     *
     * @param array{name?: string, description?: string, codec?: string, resolutions?: string} $data
     * @return array<string, mixed> the updated library
     */
    public function updateLibrary(string $id, array $data): array
    {
        $id = $this->assertLibraryId($id);
        $body = $this->request('PATCH', '/libraries/' . rawurlencode($id), ['json' => $data]);
        $library = is_array($body['data'] ?? null) ? $body['data'] : [];

        if ($library !== []) {
            $this->flushTags('vo_library_' . $id, 'vo_lists');
        }

        return $library;
    }

    public function deleteLibrary(string $id): void
    {
        $id = $this->assertLibraryId($id);
        $this->request('DELETE', '/libraries/' . rawurlencode($id));
        $this->flushTags('vo_library_' . $id, 'vo_lists');
    }

    /**
     * Re-queue encoding for every video in the library.
     *
     * @return array<string, mixed>
     */
    public function reprocessLibrary(string $id): array
    {
        $id = $this->assertLibraryId($id);
        $body = $this->request('POST', '/libraries/' . rawurlencode($id) . '/reprocess', retry: false);
        $this->flushTags('vo_library_' . $id, 'vo_lists');

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    // ------------------------------------------------------------------
    // Encodings / tags / subtitle languages (organization level)
    // ------------------------------------------------------------------

    /**
     * Codecs and resolutions the organization may enable.
     *
     * @return array{codecs: list<array{key: string, label: string, access: string, available: bool}>, resolutions: list<array{key: string, label: string, access: string, available: bool}>}
     */
    public function listEncodings(): array
    {
        return $this->remember('encodings', ['vo_meta'], function (): array {
            // The endpoint returns {codecs, resolutions} directly — no data wrapper.
            $body = $this->request('GET', '/encodings');

            return [
                'codecs' => is_array($body['codecs'] ?? null) ? $body['codecs'] : [],
                'resolutions' => is_array($body['resolutions'] ?? null) ? $body['resolutions'] : [],
            ];
        });
    }

    /**
     * Organization-wide tags with usage counts.
     *
     * @return list<array{name: string, video_count: int}>
     */
    public function listTags(): array
    {
        return $this->remember('tags', ['vo_lists'], function (): array {
            $body = $this->request('GET', '/tags');

            return is_array($body['data'] ?? null) ? array_values(array_filter($body['data'], 'is_array')) : [];
        });
    }

    /**
     * @return list<array{code: string, label: string, video_count: int}>
     */
    public function listSubtitleLanguages(): array
    {
        return $this->remember('sublangs', ['vo_sublangs'], function (): array {
            $body = $this->request('GET', '/subtitle-languages');

            return is_array($body['data'] ?? null) ? array_values(array_filter($body['data'], 'is_array')) : [];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function createSubtitleLanguage(string $code, ?string $label = null): array
    {
        $payload = ['code' => $this->assertLanguageCode($code)];
        if (trim((string)$label) !== '') {
            $payload['label'] = trim($label);
        }

        $body = $this->request('POST', '/subtitle-languages', ['json' => $payload], retry: false);
        $this->flushTags('vo_sublangs');

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    // ------------------------------------------------------------------
    // Video management
    // ------------------------------------------------------------------

    /**
     * Remote-URL ingest: the platform downloads a public https URL server-side.
     * Selfhosted (media-managed) libraries only.
     *
     * @param list<string> $tags
     * @param array<string, string> $metadata key => value (max 10 keys)
     * @return array{uuid: string, status: string}
     */
    public function ingestVideoFromUrl(string $libraryId, string $sourceUrl, ?string $title = null, array $tags = [], array $metadata = []): array
    {
        $payload = [
            'library_id' => $this->assertLibraryId($libraryId),
            'source_url' => trim($sourceUrl),
        ];
        if (trim((string)$title) !== '') {
            $payload['title'] = trim($title);
        }
        $tags = $this->normalizeTags($tags);
        if ($tags !== []) {
            $payload['tags'] = $tags;
        }
        $metadata = $this->normalizeMetadata($metadata);
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        $body = $this->request('POST', '/videos', ['json' => $payload], retry: false);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (!isset($data['uuid'])) {
            throw new ApiErrorException('VideoOptimizer ingest failed: unexpected API response.', 0);
        }

        $this->flushTags('vo_lists', 'vo_libraries');

        return ['uuid' => (string)$data['uuid'], 'status' => (string)($data['status'] ?? 'processing')];
    }

    /**
     * Update title and/or player options and/or tags and/or metadata of a video.
     * Tags replace the complete set; [] clears it.
     * Metadata is merged: keys sent are set; a null value removes that key;
     * metadata === null removes ALL keys.
     *
     * @param array{title?: string, option?: array<string, bool>, tags?: list<string>, metadata?: array<string, string|null>|null} $data
     * @return array<string, mixed> the updated video
     */
    public function updateVideo(string $uuid, array $data): array
    {
        $uuid = $this->assertUuid($uuid, 'video ID');
        $payload = [];
        if (isset($data['title'])) {
            $payload['title'] = (string)$data['title'];
        }
        if (isset($data['option']) && is_array($data['option'])) {
            $payload['option'] = array_intersect_key($data['option'], [
                'responsive' => true, 'autoplay' => true, 'preload' => true, 'loop' => true, 'muted' => true,
            ]);
        }
        if (array_key_exists('tags', $data) && is_array($data['tags'])) {
            $payload['tags'] = $this->normalizeTags($data['tags']);
        }
        if (array_key_exists('metadata', $data)) {
            if ($data['metadata'] === null) {
                $payload['metadata'] = null;
            } elseif (is_array($data['metadata'])) {
                $payload['metadata'] = $this->normalizeMetadata($data['metadata']);
            }
        }
        if ($payload === []) {
            return $this->getVideo($uuid);
        }

        $body = $this->request('PATCH', '/videos/' . rawurlencode($uuid), ['json' => $payload]);
        $video = is_array($body['data'] ?? null) ? $body['data'] : [];

        if ($video !== []) {
            $this->flushTags('vo_video_' . $uuid, 'vo_lists');
        }

        return $video;
    }

    public function deleteVideo(string $uuid): void
    {
        $uuid = $this->assertUuid($uuid, 'video ID');
        $this->request('DELETE', '/videos/' . rawurlencode($uuid));
        $this->flushTags('vo_video_' . $uuid, 'vo_lists', 'vo_libraries');
    }

    /**
     * Auto-generated thumbnail frames of a ready video.
     *
     * @return list<array{index: int, url: string}>
     */
    public function listThumbnails(string $uuid): array
    {
        $uuid = $this->assertUuid($uuid, 'video ID');

        return $this->remember('thumbs:' . $uuid, ['vo_video_' . $uuid], function () use ($uuid): array {
            try {
                $body = $this->request('GET', '/videos/' . rawurlencode($uuid) . '/thumbnails');
            } catch (ApiErrorException $exception) {
                if ($exception->getHttpStatus() === 404) {
                    return [];
                }
                throw $exception;
            }

            return is_array($body['data'] ?? null) ? array_values(array_filter($body['data'], 'is_array')) : [];
        });
    }

    /**
     * Set the active thumbnail frame (0-9).
     *
     * @return array<string, mixed>
     */
    public function setThumbnail(string $uuid, int $index): array
    {
        $uuid = $this->assertUuid($uuid, 'video ID');
        $body = $this->request('POST', '/videos/' . rawurlencode($uuid) . '/thumbnail', [
            'json' => ['thumbnailIndex' => max(0, min(9, $index))],
        ], retry: false);

        $this->flushTags('vo_video_' . $uuid, 'vo_lists');

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    // ------------------------------------------------------------------
    // Subtitles per video
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function listSubtitles(string $uuid): array
    {
        $uuid = $this->assertUuid($uuid, 'video ID');

        return $this->remember('subs:' . $uuid, ['vo_video_' . $uuid], function () use ($uuid): array {
            try {
                $body = $this->request('GET', '/videos/' . rawurlencode($uuid) . '/subtitles');
            } catch (ApiErrorException $exception) {
                if ($exception->getHttpStatus() === 404) {
                    return [];
                }
                throw $exception;
            }

            return is_array($body['data'] ?? null) ? array_values(array_filter($body['data'], 'is_array')) : [];
        });
    }

    /**
     * Upload/replace the subtitle file for one language (SRT or WebVTT, max 2 MB).
     *
     * @return array<string, mixed>
     */
    public function uploadSubtitle(string $uuid, string $filePath, string $language, ?string $kind = null, bool $isDefault = false): array
    {
        $uuid = $this->assertUuid($uuid, 'video ID');
        $language = $this->assertLanguageCode($language);
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new ApiErrorException('VideoOptimizer subtitle upload failed: file not found or unreadable.', 0);
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new ApiErrorException('VideoOptimizer subtitle upload failed: could not open file.', 0);
        }

        $fields = [
            ['name' => 'language', 'contents' => $language],
            ['name' => 'is_default', 'contents' => $isDefault ? 'true' : 'false'],
        ];
        if ($kind !== null && in_array($kind, ['subtitles', 'captions'], true)) {
            $fields[] = ['name' => 'kind', 'contents' => $kind];
        }

        try {
            $body = $this->request('POST', '/videos/' . rawurlencode($uuid) . '/subtitles', [
                'multipart' => array_merge($fields, [[
                    'name' => 'file',
                    'contents' => Utils::streamFor($handle),
                    'filename' => $this->safeFilename(basename($filePath), 'subtitle.vtt'),
                ]]),
            ], retry: false, upload: true);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->flushTags('vo_video_' . $uuid);

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    public function deleteSubtitle(string $uuid, string $language): void
    {
        $uuid = $this->assertUuid($uuid, 'video ID');
        $language = $this->assertLanguageCode($language);
        $this->request('DELETE', '/videos/' . rawurlencode($uuid) . '/subtitles/' . rawurlencode($language));
        $this->flushTags('vo_video_' . $uuid);
    }

    // ------------------------------------------------------------------
    // Savings
    // ------------------------------------------------------------------

    /**
     * Savings compared to serving the original files.
     *
     * @param string|null $from optional first UTC day of the range (YYYY-MM-DD)
     * @param string|null $to optional last UTC day of the range (inclusive, YYYY-MM-DD)
     * @param string|null $libraryId restrict to one library
     * @param string|null $videoUuid restrict to one video
     * @return array<string, mixed> raw savings payload ({traffic_available, compression, range, all_time})
     */
    public function getSavings(?string $from = null, ?string $to = null, ?string $libraryId = null, ?string $videoUuid = null): array
    {
        $query = [];
        $from = trim((string)$from);
        $to = trim((string)$to);
        if ($from !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1) {
                throw new ApiErrorException('VideoOptimizer savings error: "from" must be YYYY-MM-DD.', 0);
            }
            $query['from'] = $from;
        }
        if ($to !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
                throw new ApiErrorException('VideoOptimizer savings error: "to" must be YYYY-MM-DD.', 0);
            }
            $query['to'] = $to;
        }
        $libraryId = trim((string)$libraryId);
        if ($libraryId !== '') {
            $query['library_id'] = $this->assertLibraryId($libraryId);
        }
        $videoUuid = trim((string)$videoUuid);
        if ($videoUuid !== '') {
            $query['video_uuid'] = $this->assertUuid($videoUuid, 'video ID');
        }

        $path = '/savings';
        if ($query !== []) {
            $path .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $body = $this->request('GET', $path);

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    // ------------------------------------------------------------------
    // Uploads
    // ------------------------------------------------------------------

    /**
     * Upload a local file into a library and queue encoding.
     *
     * Uses the presigned multipart flow for large files in media-managed
     * libraries (avoids buffering the whole file through one request);
     * everything else goes through the plain multipart endpoint.
     *
     * @param list<string> $tags
     * @param array<string, string> $metadata key => value (max 10 keys)
     * @return array{uuid: string, status: string}
     */
    public function uploadVideo(string $libraryId, string $filePath, string $filename, ?string $title = null, array $tags = [], array $metadata = []): array
    {
        $libraryId = $this->assertLibraryId($libraryId);
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new ApiErrorException('VideoOptimizer upload failed: file not found or unreadable.', 0);
        }

        $size = (int)filesize($filePath);
        if ($size <= 0) {
            throw new ApiErrorException('VideoOptimizer upload failed: file is empty.', 0);
        }

        $tags = $this->normalizeTags($tags);
        $metadata = $this->normalizeMetadata($metadata);
        $library = $this->getLibrary($libraryId);
        if ($library !== [] && ($library['media_managed'] ?? false) === true && $size >= self::PRESIGNED_THRESHOLD_BYTES) {
            return $this->uploadPresigned($libraryId, $filePath, $filename, $title, $size, $tags, $metadata);
        }

        return $this->uploadMultipart($libraryId, $filePath, $filename, $title, $tags, $metadata);
    }

    /**
     * Begin a presigned multipart upload (selfhosted/media-managed libraries only).
     *
     * @return array<string, mixed> session with uuid, key, uploadId, partSize and parts[]
     */
    public function initiateUpload(string $libraryId, string $filename, string $contentType, int $fileSize): array
    {
        $body = $this->request('POST', '/videos/upload/initiate', ['json' => [
            'libraryId' => $this->assertLibraryId($libraryId),
            'filename' => $this->safeFilename($filename, 'upload.mp4'),
            'contentType' => $contentType,
            'fileSize' => $fileSize,
        ]], retry: false, upload: true);

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /**
     * Finalize a presigned multipart upload (idempotent, safe to retry).
     *
     * @param array<string, mixed> $session the initiate response data
     * @param list<array{partNumber: int, etag: string}> $parts
     * @return array<string, mixed>
     */
    public function completeUpload(string $libraryId, array $session, ?string $title, array $parts, array $tags = [], array $metadata = []): array
    {
        $payload = [
            'libraryId' => $this->assertLibraryId($libraryId),
            'uuid' => (string)($session['uuid'] ?? ''),
            'key' => (string)($session['key'] ?? ''),
            'uploadId' => (string)($session['uploadId'] ?? ''),
            'parts' => $parts,
        ];
        if (is_string($title) && trim($title) !== '') {
            $payload['title'] = trim($title);
        }
        $tags = $this->normalizeTags($tags);
        if ($tags !== []) {
            $payload['tags'] = $tags;
        }
        // In a create payload there is no removal sentinel; drop nulls.
        $metadata = array_filter($this->normalizeMetadata($metadata), fn($value) => $value !== null);
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        $body = $this->request('POST', '/videos/upload/complete', ['json' => $payload], upload: true);

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @param list<string> $tags
     * @param array<string, string> $metadata
     * @return array{uuid: string, status: string}
     */
    private function uploadMultipart(string $libraryId, string $filePath, string $filename, ?string $title, array $tags, array $metadata = []): array
    {
        $headers = ['x-library-id' => $libraryId];
        // Raw non-ASCII header bytes get misread by the API; send plain-ASCII titles in the
        // header and set any other title via PATCH (JSON, UTF-8) after the upload.
        $patchTitle = null;
        $cleanTitle = $title !== null ? trim((string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', $title)) : '';
        if ($cleanTitle !== '') {
            if (preg_match('/^[\x20-\x7E]+$/', $cleanTitle) === 1) {
                $headers['x-title'] = substr($cleanTitle, 0, 200);
            } else {
                $patchTitle = mb_substr($cleanTitle, 0, 200);
            }
        }
        if ($tags !== []) {
            $headers['x-tags'] = implode(',', array_map('rawurlencode', $tags));
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new ApiErrorException('VideoOptimizer upload failed: could not open file.', 0);
        }

        try {
            $body = $this->request('POST', '/videos', [
                'headers' => $headers,
                'multipart' => [[
                    'name' => 'file',
                    'contents' => Utils::streamFor($handle),
                    'filename' => $this->safeFilename($filename, 'upload.mp4'),
                ]],
            ], retry: false, upload: true);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        if (!isset($data['uuid'])) {
            throw new ApiErrorException('VideoOptimizer upload failed: unexpected API response.', 0);
        }

        $uuid = (string)$data['uuid'];

        // The multipart variant has no metadata field; set it via PATCH after the upload.
        $metadata = $this->normalizeMetadata($metadata);
        $metadata = array_filter($metadata, fn($value) => $value !== null);
        $patch = [];
        if ($metadata !== []) {
            $patch['metadata'] = $metadata;
        }
        if ($patchTitle !== null) {
            $patch['title'] = $patchTitle;
        }
        if ($patch !== []) {
            try {
                $this->updateVideo($uuid, $patch);
            } catch (ApiErrorException $exception) {
                $this->flushTags('vo_lists', 'vo_libraries');
                throw new ApiErrorException(
                    'VideoOptimizer upload succeeded (video ' . substr($uuid, 0, 8) . '…) but title/metadata could not be set: ' . $exception->getMessage(),
                    (int)$exception->getCode(),
                    $exception
                );
            }
        }

        $this->flushTags('vo_lists', 'vo_libraries');

        return ['uuid' => $uuid, 'status' => (string)($data['status'] ?? 'processing')];
    }

    /**
     * @param list<string> $tags
     * @param array<string, string> $metadata
     * @return array{uuid: string, status: string}
     */
    private function uploadPresigned(string $libraryId, string $filePath, string $filename, ?string $title, int $size, array $tags, array $metadata = []): array
    {
        $session = $this->initiateUpload($libraryId, $filename, $this->guessContentType($filename), $size);
        if (!isset($session['uuid'], $session['key'], $session['uploadId']) || !is_array($session['parts'] ?? null)) {
            throw new ApiErrorException('VideoOptimizer upload failed: unexpected initiate response.', 0);
        }

        $partSize = (int)($session['partSize'] ?? 0);
        if ($partSize <= 0) {
            throw new ApiErrorException('VideoOptimizer upload failed: missing part size in initiate response.', 0);
        }

        $parts = [];
        foreach ($session['parts'] as $part) {
            $partNumber = (int)($part['partNumber'] ?? 0);
            $url = (string)($part['url'] ?? '');
            if ($partNumber <= 0 || $url === '' || strtolower((string)parse_url($url, PHP_URL_SCHEME)) !== 'https') {
                throw new ApiErrorException('VideoOptimizer upload failed: malformed part in initiate response.', 0);
            }

            $offset = ($partNumber - 1) * $partSize;
            $length = min($partSize, $size - $offset);
            $etag = $this->putPart($url, $filePath, $offset, $length);
            $parts[] = ['partNumber' => $partNumber, 'etag' => $etag];
        }

        $data = $this->completeUpload($libraryId, $session, $title, $parts, $tags, $metadata);
        $uuid = (string)($data['uuid'] ?? $session['uuid']);

        $this->flushTags('vo_lists', 'vo_libraries');

        return [
            'uuid' => $uuid,
            'status' => ($data['success'] ?? false) === true ? 'processing' : 'unknown',
        ];
    }

    private function putPart(string $url, string $filePath, int $offset, int $length): string
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new ApiErrorException('VideoOptimizer upload failed: could not open file.', 0);
        }

        try {
            $stream = Utils::streamFor($handle);
            $chunk = new LimitStream($stream, $length, $offset);
            $response = $this->client()->request('PUT', $url, [
                'body' => $chunk,
                'http_errors' => false,
                'allow_redirects' => false,
                'timeout' => self::TIMEOUT_UPLOAD,
                'connect_timeout' => self::CONNECT_TIMEOUT_UPLOAD,
            ]);
        } catch (GuzzleException $exception) {
            throw new NetworkException(
                'VideoOptimizer upload failed while sending a file part: ' . $exception->getMessage(),
                (int)$exception->getCode(),
                $exception
            );
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new ApiErrorException(
                sprintf('VideoOptimizer upload failed while sending a file part (HTTP %d).', $status),
                $status
            );
        }

        $etag = trim((string)$response->getHeaderLine('ETag'), '"');
        if ($etag === '') {
            throw new ApiErrorException('VideoOptimizer upload failed: no ETag returned for a file part.', 0);
        }

        return $etag;
    }

    private function guessContentType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'mp4' => 'video/mp4',
            'm4v' => 'video/x-m4v',
            'mov' => 'video/quicktime',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            default => 'application/octet-stream',
        };
    }

    /**
     * Cursor-paginated list fetch with caching and de-duplication.
     *
     * @param array<string, scalar> $query
     * @param list<string> $tags
     * @return list<array<string, mixed>>
     */
    private function fetchList(string $keySuffix, string $path, array $query = [], array $tags = ['vo_lists']): array
    {
        $suffix = $query === [] ? $keySuffix : $keySuffix . '|' . md5(serialize($query));

        return $this->remember($suffix, $tags, fn (): array => $this->loadList($path, $query));
    }

    /**
     * Uncached cursor-paginated list load.
     *
     * @param array<string, scalar> $query
     * @return list<array<string, mixed>>
     */
    private function loadList(string $path, array $query): array
    {
        $items = [];
        $seen = [];
        $cursor = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $pageQuery = $query + ['limit' => self::PAGE_LIMIT];
            if ($cursor !== null) {
                $pageQuery['cursor'] = $cursor;
            }

            $body = $this->request('GET', $path, ['query' => $pageQuery]);
            $data = is_array($body['data'] ?? null) ? $body['data'] : [];
            foreach ($data as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (string)($item['uuid'] ?? $item['id'] ?? '');
                if ($id !== '') {
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                }
                $items[] = $item;
            }

            $pagination = is_array($body['pagination'] ?? null) ? $body['pagination'] : [];
            if (($pagination['has_more'] ?? false) !== true || trim((string)($pagination['next_cursor'] ?? '')) === '') {
                break;
            }
            $cursor = (string)$pagination['next_cursor'];
        }

        return $items;
    }

    /**
     * Stale-while-error cache wrapper.
     *
     * Entries live for CACHE_LIFETIME; the configured TTL is a soft TTL. A stale entry is
     * served when the refresh fails (and re-armed for NEGATIVE_TTL so the API is not
     * hammered during an outage). Without any copy, the failure itself is remembered
     * for NEGATIVE_TTL seconds.
     *
     * @param list<string> $tags
     * @param callable(): mixed $loader
     */
    private function remember(string $suffix, array $tags, callable $loader): mixed
    {
        $cache = $this->getCache();
        $key = $this->key($suffix);
        $now = time();
        $entry = $cache->get($key);
        $stale = null;

        if (is_array($entry) && isset($entry['fetchedAt']) && is_int($entry['fetchedAt'])) {
            if (array_key_exists('payload', $entry)) {
                $ttl = isset($entry['ttl']) && is_int($entry['ttl']) ? $entry['ttl'] : $this->softTtl();
                if ($now - $entry['fetchedAt'] < $ttl) {
                    return $entry['payload'];
                }
                $stale = $entry;
            } elseif (is_array($entry['failure'] ?? null) && $now - $entry['fetchedAt'] < self::NEGATIVE_TTL) {
                throw $this->rebuildFailure($entry['failure']);
            }
        }

        try {
            $payload = $loader();
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (VideoOptimizerException $exception) {
            if ($stale !== null) {
                $this->logger?->warning('VideoOptimizer API unavailable, serving stale cache entry.', [
                    'key' => $suffix,
                    'error' => $exception->getMessage(),
                ]);
                $stale['fetchedAt'] = $now;
                $stale['ttl'] = self::NEGATIVE_TTL;
                $cache->set($key, $stale, $stale['tags'] ?? $tags, self::CACHE_LIFETIME);

                return $stale['payload'];
            }
            if ($this->hasToken()) {
                $cache->set($key, [
                    'fetchedAt' => $now,
                    'failure' => [
                        'network' => $exception instanceof NetworkException,
                        'status' => $exception instanceof ApiErrorException ? $exception->getHttpStatus() : 0,
                        'message' => $exception->getMessage(),
                    ],
                ], [], self::NEGATIVE_TTL);
            }
            throw $exception;
        }

        $envelope = ['payload' => $payload, 'fetchedAt' => $now, 'tags' => $tags];
        if ($payload === []) {
            // Empty results (e.g. unknown IDs) are only remembered briefly.
            $envelope['ttl'] = self::NEGATIVE_TTL;
        }
        $cache->set($key, $envelope, $tags, self::CACHE_LIFETIME);

        return $payload;
    }

    /**
     * @param array<string, mixed> $failure
     */
    private function rebuildFailure(array $failure): VideoOptimizerException
    {
        $message = (string)($failure['message'] ?? 'VideoOptimizer API request failed.');

        return ($failure['network'] ?? false) === true
            ? new NetworkException($message)
            : new ApiErrorException($message, (int)($failure['status'] ?? 0));
    }

    private function flushTags(string ...$tags): void
    {
        foreach (array_unique($tags) as $tag) {
            try {
                $this->getCache()->flushByTag($tag);
            } catch (\Throwable $exception) {
                $this->logger?->warning('VideoOptimizer cache flush failed.', ['tag' => $tag, 'error' => $exception->getMessage()]);
            }
        }
    }

    /**
     * basename + strip quotes, control characters and path separators for multipart filenames.
     */
    private function safeFilename(string $filename, string $fallback): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]/u', '', $name) ?? '';
        $name = trim($name);

        return $name !== '' ? mb_substr($name, 0, 200) : $fallback;
    }

    /**
     * Trim, de-duplicate (case-insensitive) and limit tags to the API rules.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        $result = [];
        $seen = [];
        foreach ($tags as $tag) {
            $name = trim((string)$tag);
            if ($name === '' || mb_strlen($name) > 50 || str_contains($name, ',')) {
                continue;
            }
            $needle = strtolower($name);
            if (isset($seen[$needle])) {
                continue;
            }
            $seen[$needle] = true;
            $result[] = $name;
            if (count($result) === 20) {
                break;
            }
        }

        return $result;
    }

    /**
     * Trim, validate and cap metadata key/value pairs to the API rules.
     * A null value is kept as a removal sentinel (only valid in PATCH payloads).
     * Invalid keys/values are dropped silently; count is capped at 10.
     *
     * @param array<string, string|null> $metadata
     * @return array<string, string|null>
     */
    private function normalizeMetadata(array $metadata): array
    {
        $result = [];
        foreach ($metadata as $key => $value) {
            $key = trim((string)$key);
            if ($key === '' || mb_strlen($key) > 40 || preg_match('/^[a-z0-9][a-z0-9_.-]{0,39}$/', $key) !== 1) {
                continue;
            }
            if ($value === null) {
                $result[$key] = null;
                continue;
            }
            $value = trim((string)$value);
            if ($value === '' || mb_strlen($value) > 64) {
                continue;
            }
            $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
            if ($value === '') {
                continue;
            }
            $result[$key] = $value;
            if (count($result) >= 10) {
                break;
            }
        }

        return $result;
    }

    private function client(): ClientInterface
    {
        if ($this->httpClient === null) {
            $this->httpClient = $this->guzzleClientFactory->getClient();
        }

        return $this->httpClient;
    }

    private function isFrontendRequest(): bool
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return false;
        }
        try {
            return ApplicationType::fromRequest($request)->isFrontend();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Perform an API request with retry/backoff for transient failures.
     *
     * Retries: transport errors and HTTP 5xx/429 (never for non-idempotent calls, see $retry).
     * In frontend requests at most 2 attempts are made and the total sleep is capped at 1 s,
     * so a flaky API cannot stall page rendering (callers fall back to stale cache).
     *
     * @param array<string, mixed> $options Guzzle request options (query/json/multipart/headers)
     * @param bool $upload use the long timeout profile (file transfers)
     * @return array<string, mixed> decoded JSON body
     */
    private function request(string $method, string $path, array $options = [], bool $retry = true, bool $upload = false): array
    {
        $token = $this->token();
        if ($token === '') {
            throw new VideoOptimizerException('VideoOptimizer API token is not configured.');
        }

        $options['headers'] = ['Accept' => 'application/json'] + ($options['headers'] ?? []);
        $options['headers']['Authorization'] = 'Bearer ' . $token;
        // Overrides TYPO3's default `User-Agent: TYPO3` ($GLOBALS['TYPO3_CONF_VARS']['HTTP']['headers']).
        $options['headers']['User-Agent'] = $this->userAgent->get();
        $options += [
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => $upload ? self::TIMEOUT_UPLOAD : self::TIMEOUT_JSON,
            'connect_timeout' => $upload ? self::CONNECT_TIMEOUT_UPLOAD : self::CONNECT_TIMEOUT_JSON,
        ];

        $frontend = $this->isFrontendRequest();
        $maxAttempts = $frontend ? self::FRONTEND_RETRY_ATTEMPTS : self::RETRY_ATTEMPTS;
        $slept = 0.0;

        $lastException = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->client()->request($method, self::BASE_URL . $path, $options);
            } catch (GuzzleException $exception) {
                $lastException = $exception;
                if ($retry && $attempt < $maxAttempts && $this->sleepBeforeRetry($attempt, '', $frontend, $slept)) {
                    continue;
                }
                $this->logger?->error('VideoOptimizer API transport error.', [
                    'method' => $method,
                    'path' => $path,
                    'error' => $exception->getMessage(),
                ]);
                throw new NetworkException(
                    'VideoOptimizer API request failed (network error).',
                    (int)$exception->getCode(),
                    $exception
                );
            }

            $status = $response->getStatusCode();
            $raw = (string)$response->getBody();
            $body = json_decode($raw, true);

            if ($status < 400 && is_array($body)) {
                return $body;
            }

            if ($retry && ($status >= 500 || $status === 429) && $attempt < $maxAttempts
                && $this->sleepBeforeRetry($attempt, $response->getHeaderLine('Retry-After'), $frontend, $slept)
            ) {
                continue;
            }

            $this->logger?->log(
                $status === 404 ? 'debug' : ($status >= 500 ? 'error' : 'warning'),
                'VideoOptimizer API error response.',
                ['method' => $method, 'path' => $path, 'status' => $status, 'body' => mb_substr($raw, 0, 4000)]
            );

            $message = sprintf(
                'VideoOptimizer API error (HTTP %d): %s',
                $status,
                $this->extractErrorMessage($raw, $body)
            );
            if ($status === 429) {
                $rateLimitParts = [];
                $retryAfter = trim($response->getHeaderLine('Retry-After'));
                $limit = trim($response->getHeaderLine('X-RateLimit-Limit'));
                $remaining = trim($response->getHeaderLine('X-RateLimit-Remaining'));
                if ($retryAfter !== '') {
                    $rateLimitParts[] = 'Retry-After: ' . $this->cleanText($retryAfter, 20) . 's';
                }
                if ($limit !== '') {
                    $rateLimitParts[] = 'limit: ' . $this->cleanText($limit, 20);
                }
                if ($remaining !== '') {
                    $rateLimitParts[] = 'remaining: ' . $this->cleanText($remaining, 20);
                }
                if ($rateLimitParts !== []) {
                    $message .= ' [' . implode(', ', $rateLimitParts) . ']';
                }
            }

            throw new ApiErrorException($message, $status);
        }

        throw new NetworkException(
            'VideoOptimizer API request failed after retries.',
            0,
            $lastException instanceof \Throwable ? $lastException : null
        );
    }

    /**
     * Short, tag-free message from a decoded error body (never the raw body).
     *
     * @param mixed $decoded
     */
    private function extractErrorMessage(string $raw, mixed $decoded): string
    {
        $text = '';
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_array($error) && is_string($error['message'] ?? null)) {
                $text = $error['message'];
            } elseif (is_string($decoded['message'] ?? null)) {
                $text = $decoded['message'];
            } elseif (is_string($error)) {
                $text = $error;
            }
        }
        $text = $this->cleanText($text, self::MAX_ERROR_MESSAGE_LENGTH);
        if ($text !== '') {
            return $text;
        }

        return trim($raw) === '' ? 'empty response' : 'unexpected response';
    }

    private function cleanText(string $text, int $maxLength): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $text) ?? '';
        $text = trim($text);

        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength - 1) . '…' : $text;
    }

    /**
     * Sleep before the next attempt.
     *
     * @return bool false when the retry must be abandoned (frontend budget exceeded)
     */
    private function sleepBeforeRetry(int $attempt, string $retryAfterHeader, bool $frontend, float &$slept): bool
    {
        $delay = (self::RETRY_BASE_DELAY_MS * (2 ** ($attempt - 1))) / 1000;
        $retryAfter = trim($retryAfterHeader);
        if ($retryAfter !== '' && is_numeric($retryAfter) && (float)$retryAfter > 0) {
            // Honor the server's Retry-After (capped) over our computed backoff.
            $delay = min((float)$retryAfter, self::BACKEND_MAX_RETRY_AFTER);
        }

        if ($frontend) {
            if ($slept + $delay > self::FRONTEND_MAX_SLEEP) {
                return false;
            }
        }

        $slept += $delay;
        usleep((int)round($delay * 1_000_000));

        return true;
    }

    private function getCache(): FrontendInterface
    {
        if ($this->cache === null) {
            $this->cache = $this->cacheManager->getCache('videooptimizer');
        }

        return $this->cache;
    }

    /**
     * Scope cache keys to the configured API token (HMAC with the install's encryption key,
     * so the token cannot be derived from cache keys) so different organizations never collide.
     */
    private function key(string $suffix): string
    {
        $token = $this->token();
        $secret = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');
        $fingerprint = $token !== ''
            ? substr(hash_hmac('sha256', $token, $secret), 0, 12)
            : 'noauth';

        // Cache entry identifiers only allow [a-zA-Z0-9_%\-&]; normalize everything else.
        $raw = 'vo_' . $fingerprint . ':' . $suffix;

        return substr(preg_replace('/[^a-zA-Z0-9_%\-&]/', '_', $raw) ?? $raw, 0, 250);
    }
}
