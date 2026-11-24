<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Form\Element;

use ScaleCommerce\VideoOptimizer\Security\MediaUrl;
use Psr\Log\LoggerInterface;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * FormEngine element (renderType `videooptimizerLibraryPicker`) for tt_content.vo_library_id: a tile
 * grid of all libraries (2x2 thumbnail mosaic, name, video count) rendered server-side, so it is
 * visible instantly. The value (plain library UUID, '' = all libraries) lives in a hidden input;
 * Resources/Public/JavaScript/library-picker.js adds selection, keyboard handling and filtering.
 */
final class LibraryPickerElement extends AbstractFormElement
{
    private const LLL = 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_browser.xlf:';

    /** Show the name filter only above this number of libraries. */
    private const FILTER_THRESHOLD = 8;

    private const MOSAIC_SIZE = 4;

    public function render(): array
    {
        $resultArray = $this->initializeResultArray();
        $parameterArray = $this->data['parameterArray'];
        $itemName = (string)$parameterArray['itemFormElName'];
        $value = $parameterArray['itemFormElValue'];
        $value = is_scalar($value) ? trim((string)$value) : '';
        $fieldId = 'formengine-vo-library-' . md5($itemName);

        $hidden = GeneralUtility::implodeAttributes([
            'type' => 'hidden',
            'name' => $itemName,
            'value' => $value,
            'id' => $fieldId,
            'data-formengine-input-name' => $itemName,
        ], true);

        $html = [];
        $html[] = $this->renderLabel($fieldId);
        $html[] = '<div class="formengine-field-item t3js-formengine-field-item">';
        $html[] = '<div class="form-control-wrap">';
        $html[] = '<typo3-videooptimizer-library-picker class="vo-libpicker" data-value="' . $this->e($value) . '">';
        $html[] = '<input ' . $hidden . '>';
        $html[] = $this->renderPicker($value, $fieldId);
        $html[] = '</typo3-videooptimizer-library-picker>';
        $html[] = '</div>';
        $html[] = '</div>';

        $resultArray['html'] = implode(LF, $html);
        $resultArray['stylesheetFiles'][] = 'EXT:videooptimizer/Resources/Public/Css/library-picker.css';
        $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create('@scalecommerce/videooptimizer/library-picker.js');

        return $resultArray;
    }

    private function renderPicker(string $value, string $fieldId): string
    {
        try {
            $client = GeneralUtility::makeInstance(VideoOptimizerClient::class);
            $libraries = $client->listLibraries();
        } catch (\Throwable $exception) {
            GeneralUtility::makeInstance(LoggerInterface::class)->warning(
                'VideoOptimizer libraries could not be loaded for the library picker: ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return $this->renderUnavailable($value);
        }

        // Thumbnails are optional: tiles fall back to placeholders when the video list fails.
        try {
            $videos = $client->listAllVideos();
        } catch (\Throwable $exception) {
            $videos = [];
        }

        $thumbs = [];
        $counted = [];
        $allThumbs = [];
        foreach ($videos as $video) {
            if (!is_array($video)) {
                continue;
            }
            $libraryId = trim((string)($video['library_id'] ?? ''));
            $counted[$libraryId] = ($counted[$libraryId] ?? 0) + 1;
            if (($video['ready'] ?? true) === false || !empty($video['error'])) {
                continue;
            }
            $src = $this->safeUrl($video['poster_url'] ?? null) ?? $this->safeUrl($video['thumbnail_url'] ?? null);
            if ($src === null) {
                continue;
            }
            if (count($thumbs[$libraryId] ?? []) < self::MOSAIC_SIZE) {
                $thumbs[$libraryId][] = $src;
            }
            // "All libraries" mosaic: one video per library first, so the mix is visible.
            if (count($allThumbs) < self::MOSAIC_SIZE && count($thumbs[$libraryId]) === 1) {
                $allThumbs[] = $src;
            }
        }

        $tiles = [];
        $total = 0;
        $found = $value === '';
        foreach ($libraries as $library) {
            if (!is_array($library)) {
                continue;
            }
            $id = trim((string)($library['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $count = isset($library['video_count']) && is_numeric($library['video_count'])
                ? (int)$library['video_count']
                : ($counted[$id] ?? 0);
            $total += $count;
            $found = $found || $id === $value;
            $tiles[] = [
                'id' => $id,
                'name' => trim((string)($library['name'] ?? '')) ?: $id,
                'count' => $count,
                'deliveryOnly' => ($library['media_managed'] ?? true) === false,
                'thumbs' => $thumbs[$id] ?? [],
            ];
        }

        $items = [$this->renderTile('', $this->label('libAll'), $total, false, $allThumbs, $value === '', true)];
        foreach ($tiles as $tile) {
            $items[] = $this->renderTile(
                $tile['id'],
                $tile['name'],
                $tile['count'],
                $tile['deliveryOnly'],
                $tile['thumbs'],
                $tile['id'] === $value,
                false
            );
        }

        $out = [];
        if (count($tiles) > self::FILTER_THRESHOLD) {
            $filterId = $fieldId . '-filter';
            $out[] = '<div class="vo-libpicker-filter">';
            $out[] = '<label class="visually-hidden" for="' . $this->e($filterId) . '">' . $this->e($this->label('libFilter')) . '</label>';
            $out[] = '<input type="search" class="form-control form-control-sm" id="' . $this->e($filterId)
                . '" data-vo-libpicker-filter autocomplete="off" placeholder="' . $this->e($this->label('libFilter')) . '">';
            $out[] = '</div>';
        }
        $out[] = '<div class="vo-libpicker-grid" role="radiogroup" aria-label="' . $this->e($this->label('libLabel')) . '">';
        $out = array_merge($out, $items);
        $out[] = '</div>';
        $out[] = '<p class="vo-libpicker-empty text-body-secondary" role="status" hidden>' . $this->e($this->label('libNoMatch')) . '</p>';
        if (!$found) {
            // The stored library is gone (deleted?): keep the value, make it visible.
            $out[] = '<p class="vo-libpicker-note text-body-secondary">' . $this->e($this->label('libUnknown')) . ' <code>' . $this->e($value) . '</code></p>';
        }

        return implode(LF, $out);
    }

    /**
     * @param list<string> $thumbs
     */
    private function renderTile(string $id, string $name, int $count, bool $deliveryOnly, array $thumbs, bool $selected, bool $isAll): string
    {
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
        $countLabel = sprintf($this->label($count === 1 ? 'libVideo' : 'libVideos'), $count);

        $mosaic = [];
        if ($thumbs === []) {
            $mosaic[] = '<span class="vo-lib-placeholder" aria-hidden="true">'
                . $iconFactory->getIcon($isAll ? 'apps-pagetree-folder-default' : 'mimetypes-media-video', IconSize::LARGE)->render()
                . '</span>';
        } else {
            foreach ($thumbs as $src) {
                $mosaic[] = '<img src="' . $this->e($src) . '" alt="" loading="lazy" decoding="async">';
            }
        }

        $ariaLabel = $name . ', ' . $countLabel . ($deliveryOnly ? ', ' . $this->label('libDeliveryOnly') : '');
        $classes = 'vo-lib-tile' . ($selected ? ' is-selected' : '') . ($isAll ? ' vo-lib-tile--all' : '');

        return '<button type="button" class="' . $classes . '" role="radio" aria-checked="' . ($selected ? 'true' : 'false')
            . '" tabindex="' . ($selected ? '0' : '-1') . '" data-library-id="' . $this->e($id)
            . '" data-library-name="' . $this->e($name) . '" aria-label="' . $this->e($ariaLabel) . '">'
            . '<span class="vo-lib-mosaic vo-lib-mosaic--' . min(count($thumbs), self::MOSAIC_SIZE) . '">' . implode('', $mosaic) . '</span>'
            . '<span class="vo-lib-check" aria-hidden="true">' . $iconFactory->getIcon('actions-check', IconSize::SMALL)->render() . '</span>'
            . '<span class="vo-lib-name">' . $this->e($name) . '</span>'
            . '<span class="vo-lib-meta"><span class="vo-lib-count">' . $this->e($countLabel) . '</span>'
            . ($deliveryOnly ? '<span class="badge badge-info vo-lib-badge">' . $this->e($this->label('libDeliveryOnly')) . '</span>' : '')
            . '</span></button>';
    }

    /** API down: warning plus the stored id; the hidden input keeps the value untouched. */
    private function renderUnavailable(string $value): string
    {
        $html = '<div class="alert alert-warning vo-libpicker-warning" role="alert">' . $this->e($this->label('libError'));
        if ($value !== '') {
            $html .= ' ' . $this->e($this->label('libUnknown')) . ' <code>' . $this->e($value) . '</code>';
        }

        return $html . '</div>';
    }

    private function label(string $key): string
    {
        return $this->getLanguageService()->sL(self::LLL . $key);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Only https on videooptimizer.eu or *.videooptimizer.eu (matches the backend CSP img-src). */
    private function safeUrl(mixed $url): ?string
    {
        $safe = MediaUrl::safe($url);

        return $safe !== '' ? $safe : null;
    }
}
