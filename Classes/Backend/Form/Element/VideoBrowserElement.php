<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Form\Element;

use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * FormEngine element (renderType `videooptimizerVideoBrowser`) for tt_content.vo_video_uuid: an
 * inline card of the selected video plus a "Choose video" button that opens a visual picker modal
 * (see Resources/Public/JavaScript/video-browser.js). The value lives in a hidden input.
 */
final class VideoBrowserElement extends AbstractFormElement
{
    private const LLL = 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_browser.xlf:';

    private const LABELS = [
        'choose', 'remove', 'empty', 'modalTitle', 'library', 'allLibraries', 'search',
        'searchPlaceholder', 'readyOnly', 'noResults', 'loading', 'error', 'retry',
        'processing', 'errorStatus', 'close', 'selected', 'unknownVideo',
    ];

    public function render(): array
    {
        $resultArray = $this->initializeResultArray();
        $parameterArray = $this->data['parameterArray'];
        $itemName = (string)$parameterArray['itemFormElName'];
        $value = $parameterArray['itemFormElValue'];
        $value = is_scalar($value) ? trim((string)$value) : '';
        $fieldId = 'formengine-vo-video-' . md5($itemName);

        $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
        $languageService = $this->getLanguageService();
        $labels = [];
        foreach (self::LABELS as $key) {
            $labels[$key] = $languageService->sL(self::LLL . $key);
        }

        // Name of the sibling vo_library_id field in the same record.
        $libraryName = preg_replace('/\[vo_video_uuid\]$/', '[vo_library_id]', $itemName);

        $config = [
            'videosUrl' => (string)$uriBuilder->buildUriFromRoute('ajax_videooptimizer_browser_videos'),
            'videoUrl' => (string)$uriBuilder->buildUriFromRoute('ajax_videooptimizer_browser_video'),
            'cssUrl' => $this->publicUrl('EXT:videooptimizer/Resources/Public/Css/video-browser.css'),
            'libraryFieldName' => $libraryName,
            'labels' => $labels,
        ];

        $attributes = GeneralUtility::implodeAttributes([
            'class' => 'vo-browser',
            'data-config' => (string)json_encode($config, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            'data-value' => $value,
        ], true);
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
        $html[] = '<typo3-videooptimizer-video-browser ' . $attributes . '>';
        $html[] = '<input ' . $hidden . '>';
        $html[] = '</typo3-videooptimizer-video-browser>';
        $html[] = '</div>';
        $html[] = '</div>';

        $resultArray['html'] = implode(LF, $html);
        $resultArray['stylesheetFiles'][] = 'EXT:videooptimizer/Resources/Public/Css/video-browser.css';
        $resultArray['javaScriptModules'][] = JavaScriptModuleInstruction::create('@scalecommerce/videooptimizer/video-browser.js');

        return $resultArray;
    }

    private function publicUrl(string $extPath): string
    {
        return PathUtility::getPublicResourceWebPath($extPath);
    }
}
