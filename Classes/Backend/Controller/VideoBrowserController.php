<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Controller;

use ScaleCommerce\VideoOptimizer\Security\MediaUrl;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * JSON data for the visual video picker (FormEngine element VideoBrowserElement).
 * The API has no search parameter: searching and "ready only" filtering happen client-side.
 */
#[AsController]
final class VideoBrowserController
{
    public function __construct(
        private readonly VideoOptimizerClient $client,
        private readonly LoggerInterface $logger,
    ) {}

    /** GET ?library=<uuid|empty>: all libraries plus the (library-filtered) video list. */
    public function videosAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->mayBrowse()) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }
        $libraryId = trim((string)($request->getQueryParams()['library'] ?? ''));
        try {
            $libraries = $this->libraries();
            $videos = $libraryId !== ''
                ? $this->client->listVideosByLibrary($libraryId)
                : $this->client->listAllVideos();
            $items = [];
            foreach ($videos as $video) {
                if (is_array($video) && ($item = $this->slim($video, $libraries)) !== null) {
                    $items[] = $item;
                }
            }

            return new JsonResponse([
                'libraries' => array_map(
                    static fn(string $id, string $name): array => ['id' => $id, 'name' => $name],
                    array_keys($libraries),
                    array_values($libraries)
                ),
                'videos' => $items,
            ]);
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    /** GET ?uuid=<uuid>: one video (for the inline card of the current selection). */
    public function videoAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->mayBrowse()) {
            return new JsonResponse(['error' => 'Access denied.'], 403);
        }
        $uuid = trim((string)($request->getQueryParams()['uuid'] ?? ''));
        try {
            $video = $uuid !== '' ? $this->client->getVideo($uuid) : [];
            $item = $video !== [] ? $this->slim($video, $this->libraries()) : null;

            return new JsonResponse(['video' => $item]);
        } catch (\Throwable $exception) {
            return $this->error($exception);
        }
    }

    /**
     * @return array<string, string> library id => name
     */
    private function libraries(): array
    {
        $names = [];
        foreach ($this->client->listLibraries() as $library) {
            if (!is_array($library)) {
                continue;
            }
            $id = trim((string)($library['id'] ?? ''));
            if ($id !== '') {
                $names[$id] = trim((string)($library['name'] ?? '')) ?: $id;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $video
     * @param array<string, string> $libraries
     * @return array<string, mixed>|null
     */
    private function slim(array $video, array $libraries): ?array
    {
        $uuid = trim((string)($video['uuid'] ?? ''));
        if ($uuid === '') {
            return null;
        }
        $libraryId = trim((string)($video['library_id'] ?? ''));
        $duration = $video['duration'] ?? null;

        return [
            'uuid' => $uuid,
            'title' => trim((string)($video['title'] ?? '')),
            'duration' => is_numeric($duration) ? (float)$duration : null,
            'ready' => ($video['ready'] ?? true) !== false,
            'error' => !empty($video['error']),
            'thumbnail_url' => $this->safeUrl($video['thumbnail_url'] ?? null),
            'poster_url' => $this->safeUrl($video['poster_url'] ?? null),
            'library_id' => $libraryId,
            'library_name' => $libraries[$libraryId] ?? '',
        ];
    }

    /** Only https on videooptimizer.eu or *.videooptimizer.eu (matches the backend CSP img-src). */
    private function safeUrl(mixed $url): ?string
    {
        $safe = MediaUrl::safe($url);

        return $safe !== '' ? $safe : null;
    }

    private function error(\Throwable $exception): ResponseInterface
    {
        $this->logger->warning('VideoOptimizer video browser request failed: ' . $exception->getMessage(), ['exception' => $exception]);

        return new JsonResponse(['error' => 'The video library could not be loaded.'], 502);
    }

    /**
     * The pickers list the whole organization's videos: only for admins, editors who may modify
     * content elements, or users with access to the VideoOptimizer module.
     */
    private function mayBrowse(): bool
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            return false;
        }

        return $user->isAdmin()
            || $user->check('tables_modify', 'tt_content')
            || $user->check('modules', 'system_videooptimizer');
    }
}
