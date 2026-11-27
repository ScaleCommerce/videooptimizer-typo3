<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\EventListener;

use ScaleCommerce\VideoOptimizer\Security\MediaUrl;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use TYPO3\CMS\Backend\View\Event\PageContentPreviewRenderingEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Page module preview for the five VideoOptimizer content elements: thumbnail, title, duration,
 * library, status and the player options set on the record (hero/single/detail), or library name,
 * video count and the first thumbnails (grid/list). Uses the client's cached readers only and never
 * throws: on any failure a text-only preview is rendered.
 */
#[AsEventListener(identifier: 'videooptimizer/content-preview')]
final class ContentPreviewListener implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const VIDEO_TYPES = ['videooptimizer_videohero', 'videooptimizer_videosingle', 'videooptimizer_videodetail'];
    private const COLLECTION_TYPES = ['videooptimizer_videogrid', 'videooptimizer_videolist'];
    private const TEMPLATE = 'EXT:videooptimizer/Resources/Private/Templates/Backend/ContentPreview.html';
    private const CSS = 'EXT:videooptimizer/Resources/Public/Css/backend-preview.css';

    public function __construct(
        private readonly ViewFactoryInterface $viewFactory,
        private readonly VideoOptimizerClient $client,
        private readonly PageRenderer $pageRenderer,
    ) {
    }

    public function __invoke(PageContentPreviewRenderingEvent $event): void
    {
        $type = $event->getRecordType();
        if ($event->getTable() !== 'tt_content' || (!in_array($type, self::VIDEO_TYPES, true) && !in_array($type, self::COLLECTION_TYPES, true))) {
            return;
        }

        try {
            $row = $event->getRecord()->toArray();
            $variables = in_array($type, self::COLLECTION_TYPES, true)
                ? $this->collectionVariables($row)
                : $this->videoVariables($row, $type === 'videooptimizer_videohero');
            $variables['error'] = $variables['error'] ?? false;
        } catch (\Throwable $exception) {
            $this->logger?->warning('VideoOptimizer page module preview failed.', ['error' => $exception->getMessage()]);
            $variables = ['mode' => 'text', 'error' => true];
        }

        try {
            $this->pageRenderer->addCssFile(self::CSS);
            $html = $this->viewFactory
                ->create(new ViewFactoryData(
                    templatePathAndFilename: GeneralUtility::getFileAbsFileName(self::TEMPLATE),
                    request: $event->getPageLayoutContext()->getCurrentRequest(),
                ))
                ->assignMultiple($variables)
                ->render();
        } catch (\Throwable $exception) {
            $this->logger?->warning('VideoOptimizer page module preview template failed.', ['error' => $exception->getMessage()]);

            return;
        }

        $event->setPreviewContent($html);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function videoVariables(array $row, bool $hero): array
    {
        $uuid = $this->scalar($row['vo_video_uuid'] ?? '');
        $demo = false;
        if ($uuid === '') {
            $uuid = $this->client->getDemoVideoUuid();
            $demo = true;
        }

        $variables = [
            'mode' => 'video',
            'demo' => $demo,
            'options' => $this->options($row),
            'hero' => $hero ? $this->heroFields($row) : [],
            'video' => null,
        ];
        if ($uuid === '') {
            return $variables + ['noVideo' => true];
        }

        try {
            $video = $this->client->getVideo($uuid);
            $libraryName = $this->libraryName($this->scalar($video['library_id'] ?? ''));
        } catch (\Throwable $exception) {
            $this->logger?->warning('VideoOptimizer preview: video lookup failed.', ['uuid' => $uuid, 'error' => $exception->getMessage()]);

            return ['error' => true, 'uuid' => $uuid] + $variables;
        }
        if ($video === []) {
            return ['error' => true, 'uuid' => $uuid] + $variables;
        }

        $variables['video'] = $this->summarize($video) + ['library' => $libraryName];

        return $variables;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function collectionVariables(array $row): array
    {
        $libraryId = $this->scalar($row['vo_library_id'] ?? '');
        $variables = ['mode' => 'collection', 'libraryName' => '', 'count' => '', 'videos' => []];

        try {
            if ($libraryId !== '') {
                $variables['libraryName'] = $this->libraryName($libraryId);
            }
            $page = $this->client->listVideosPage(4, null, $libraryId !== '' ? $libraryId : null);
            $items = $page['items'];
            $variables['count'] = count($items) . ($page['nextCursor'] !== null ? '+' : '');
            foreach ($items as $item) {
                $variables['videos'][] = $this->summarize($item);
            }
        } catch (\Throwable $exception) {
            $this->logger?->warning('VideoOptimizer preview: list lookup failed.', ['error' => $exception->getMessage()]);
            $variables['error'] = true;
        }

        return $variables;
    }

    /**
     * @param array<string, mixed> $video
     * @return array<string, mixed>
     */
    private function summarize(array $video): array
    {
        $status = strtolower($this->scalar($video['status'] ?? ''));
        $badge = '';
        if ($this->scalar($video['error'] ?? '') !== '' || in_array($status, ['error', 'failed'], true)) {
            $badge = 'error';
        } elseif (($video['ready'] ?? true) === false || in_array($status, ['processing', 'pending', 'queued', 'encoding', 'uploading'], true)) {
            $badge = 'processing';
        }

        // Videos that are still processing (or failed) have no image yet — their URLs return errors,
        // so show the placeholder instead of a broken image.
        $thumb = '';
        if ($badge === '') {
            $thumb = $this->safeThumbnail($this->scalar($video['thumbnail_url'] ?? ''));
            if ($thumb === '') {
                $thumb = $this->safeThumbnail($this->scalar($video['poster_url'] ?? ''));
            }
        }
        $title = $this->scalar($video['title'] ?? '');

        return [
            'title' => $title !== '' ? $title : $this->scalar($video['uuid'] ?? ''),
            'thumbnail' => $thumb,
            'duration' => $this->duration($video['duration'] ?? null),
            'badge' => $badge,
            'reason' => $badge === 'error' ? VideoOptimizerClient::errorReason($video) : '',
            'badgeClass' => $badge === 'error' ? 'danger' : 'warning',
        ];
    }

    private function libraryName(string $libraryId): string
    {
        if ($libraryId === '') {
            return '';
        }
        foreach ($this->client->listLibraries() as $library) {
            if ($this->scalar($library['uuid'] ?? $library['id'] ?? '') === $libraryId) {
                $name = $this->scalar($library['name'] ?? '');

                return $name !== '' ? $name : $libraryId;
            }
        }

        return $libraryId;
    }

    /**
     * Player options that deviate from the defaults, as {key, value} pairs (key selects a label).
     *
     * @param array<string, mixed> $row
     * @return list<array{key: string, value: string}>
     */
    private function options(array $row): array
    {
        $options = [];
        foreach (['autoplay' => 'vo_autoplay', 'loop' => 'vo_loop', 'muted' => 'vo_muted', 'fit_cover' => 'vo_fit_cover'] as $key => $field) {
            if ((int)$this->scalar($row[$field] ?? 0) === 1) {
                $options[] = ['key' => $key, 'value' => ''];
            }
        }
        if (array_key_exists('vo_controls', $row) && (int)$this->scalar($row['vo_controls']) === 0) {
            $options[] = ['key' => 'controls_off', 'value' => ''];
        }
        $start = (int)$this->scalar($row['vo_start_time'] ?? 0);
        if ($start > 0) {
            $options[] = ['key' => 'start', 'value' => $this->duration($start)];
        }
        $lang = $this->scalar($row['vo_subtitle_lang'] ?? '');
        if ($lang !== '') {
            $options[] = ['key' => 'subtitles', 'value' => $lang];
        }

        return $options;
    }

    /**
     * Generic, defensive view on any vo_hero_* fields present in the row.
     *
     * @param array<string, mixed> $row
     * @return list<array{label: string, value: string}>
     */
    private function heroFields(array $row): array
    {
        $fields = [];
        foreach ($row as $name => $value) {
            if (!is_string($name) || !str_starts_with($name, 'vo_hero_')) {
                continue;
            }
            $text = $this->scalar($value);
            if ($text === '' || $text === '0') {
                continue;
            }
            // Only show what the editor actually changed: values equal to the TCA default
            // (e.g. overlay "medium", height "default") are the stock look, not an option.
            $config = $GLOBALS['TCA']['tt_content']['columns'][$name] ?? [];
            if (isset($config['config']['default']) && (string)$config['config']['default'] === $text) {
                continue;
            }
            $tcaLabel = is_string($config['label'] ?? null) ? $config['label'] : '';
            $translated = $tcaLabel !== '' && isset($GLOBALS['LANG']) ? (string)$GLOBALS['LANG']->sL($tcaLabel) : '';
            $fields[] = [
                'label' => $translated !== '' ? $translated : ucfirst(str_replace('_', ' ', substr($name, 8))),
                'value' => mb_strlen($text) > 80 ? mb_substr($text, 0, 79) . '…' : $text,
            ];
        }

        return $fields;
    }

    /** Only https thumbnails on *.videooptimizer.eu (backend CSP img-src). */
    private function safeThumbnail(string $url): string
    {
        return MediaUrl::safe($url);
    }

    private function duration(mixed $seconds): string
    {
        if (!is_numeric($seconds) || (float)$seconds <= 0) {
            return '';
        }
        $total = (int)round((float)$seconds);

        return sprintf('%d:%02d', intdiv($total, 60), $total % 60);
    }

    private function scalar(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }
}
