<?php

declare(strict_types=1);

use ScaleCommerce\VideoOptimizer\Backend\Data\VideoSubtitleLanguageItemsProcessor;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'vo_video_uuid' => [
        'label' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_video_uuid.label',
        'description' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_video_uuid.description',
        // Visual picker (thumbnail grid in a modal) instead of a select. A plain `input` is used on
        // purpose: DataHandler validates `select` values against the items list (which depends on the
        // library and may be unavailable when the API is down), whereas `input` just stores the UUID.
        'config' => [
            'type' => 'input',
            'renderType' => 'videooptimizerVideoBrowser',
            'max' => 36,
            'eval' => 'trim',
            'default' => '',
        ],
    ],
    'vo_library_id' => [
        'label' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_library_id.label',
        'description' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_library_id.description',
        // Visual library picker (tile grid). A plain `input` on purpose (see vo_video_uuid): no
        // select-items validation, robust when the API is down. The stored value is the library UUID
        // or '' (all libraries). No reload needed: the video browser filters client-side.
        'config' => [
            'type' => 'input',
            'renderType' => 'videooptimizerLibraryPicker',
            'max' => 64,
            'eval' => 'trim',
            'default' => '',
        ],
    ],
    'vo_autoplay' => [
        'label' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_player.autoplay',
        'config' => [
            'type' => 'check',
            'default' => 0,
        ],
    ],
    'vo_loop' => [
        'label' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_player.loop',
        'config' => [
            'type' => 'check',
            'default' => 0,
        ],
    ],
    'vo_muted' => [
        'label' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_player.muted',
        'config' => [
            'type' => 'check',
            'default' => 0,
        ],
    ],
    'vo_controls' => [
        'label' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_player.controls',
        'config' => [
            'type' => 'check',
            'default' => 1,
        ],
    ],
    'vo_start_time' => [
        'label' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_player.start_time',
        'config' => [
            'type' => 'number',
            'default' => 0,
            'range' => ['lower' => 0, 'upper' => 86400],
        ],
    ],
    'vo_fit_cover' => [
        'label' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_player.fit_cover',
        'config' => [
            'type' => 'check',
            'default' => 0,
        ],
    ],
    'vo_subtitle_lang' => [
        'label' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_player.subtitle_lang',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                [
                    'label' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_player.subtitle_lang.default',
                    'value' => '',
                ],
            ],
            'size' => 1,
            'maxitems' => 1,
            'default' => '',
            'itemsProcessors' => [
                ['class' => VideoSubtitleLanguageItemsProcessor::class],
            ],
        ],
    ],
]);

// Hero/single/detail: pick a library, then one of its videos. Grid/list: show a whole library (or all).
// The General, Language, Access and Notes tabs are appended automatically by TYPO3 (TcaPreparation);
// the DB columns of all vo_* fields are derived from this TCA (no ext_tables.sql needed).
$playerOptions = "
    --div--;LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:vo_player.div,
    vo_autoplay, vo_loop, vo_muted, vo_controls, vo_start_time, vo_fit_cover, vo_subtitle_lang";

$showItemVideo = "
    --palette--;;headers,
    vo_library_id,
    vo_video_uuid,
    $playerOptions,
    --div--;core.form.tabs:appearance,
    --palette--;;frames,
    --div--;core.form.tabs:categories,
    categories";

$showItemLibrary = "
    --palette--;;headers,
    vo_library_id,
    $playerOptions,
    --div--;core.form.tabs:appearance,
    --palette--;;frames,
    --div--;core.form.tabs:categories,
    categories";

$recordTypes = [
    ['value' => 'videooptimizer_videohero', 'icon' => 'videooptimizer-videohero', 'label' => 'vo_ct.videohero', 'showItem' => $showItemVideo],
    ['value' => 'videooptimizer_videosingle', 'icon' => 'videooptimizer-videosingle', 'label' => 'vo_ct.videosingle', 'showItem' => $showItemVideo],
    ['value' => 'videooptimizer_videogrid', 'icon' => 'videooptimizer-videogrid', 'label' => 'vo_ct.videogrid', 'showItem' => $showItemLibrary],
    ['value' => 'videooptimizer_videolist', 'icon' => 'videooptimizer-videolist', 'label' => 'vo_ct.videolist', 'showItem' => $showItemLibrary],
    ['value' => 'videooptimizer_videodetail', 'icon' => 'videooptimizer-videodetail', 'label' => 'vo_ct.videodetail', 'showItem' => $showItemVideo],
];

foreach ($recordTypes as $type) {
    ExtensionManagementUtility::addRecordType(
        [
            'label' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:' . $type['label'] . '.label',
            'description' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:' . $type['label'] . '.description',
            'value' => $type['value'],
            'icon' => $type['icon'],
            'group' => 'video',
        ],
        $type['showItem']
    );
}
