<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Data;

use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use TYPO3\CMS\Core\DataHandling\ItemsProcessorContext;
use TYPO3\CMS\Core\DataHandling\ItemsProcessorInterface;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;
use TYPO3\CMS\Core\Schema\Struct\SelectItemCollection;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Populates the per-element "Subtitle language" select (tt_content.vo_subtitle_lang)
 * with the organization's subtitle languages.
 *
 * Must stay zero-arg constructible: TYPO3 builds itemsProcessors via makeInstance() without arguments.
 */
final class VideoSubtitleLanguageItemsProcessor implements ItemsProcessorInterface
{
    private ?VideoOptimizerClient $client = null;

    public function processItems(SelectItemCollection $items, ItemsProcessorContext $context): SelectItemCollection
    {
        try {
            $languages = $this->client()->listSubtitleLanguages();
        } catch (\Throwable $exception) {
            $this->logFailure($exception);

            return $items;
        }

        foreach ($languages as $language) {
            if (!is_array($language)) {
                continue;
            }
            $code = trim((string)($language['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $label = trim((string)($language['label'] ?? ''));

            $items->add(new SelectItem('select', $label !== '' ? $label . ' (' . $code . ')' : $code, $code));
        }

        return $items;
    }

    private function client(): VideoOptimizerClient
    {
        return $this->client ??= GeneralUtility::makeInstance(VideoOptimizerClient::class);
    }

    private function logFailure(\Throwable $exception): void
    {
        GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__)->warning(
            'VideoOptimizer items could not be loaded: ' . $exception->getMessage(),
            ['exception' => $exception]
        );
    }
}
