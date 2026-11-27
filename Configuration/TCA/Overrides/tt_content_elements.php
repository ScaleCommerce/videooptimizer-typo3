<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

// Media split, spotlight and chapters. Loaded after tt_content.php (alphabetical order of the Overrides
// files), which defines the shared vo_* player columns. Values are mirrored (and enforced, unknown ->
// default) in Classes/Player/ElementOptions.php.
$lll = 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:';

ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'vo_eyebrow' => [
        'label' => $lll . 'vo_eyebrow.label',
        'config' => [
            'type' => 'input',
            'max' => 80,
            'default' => '',
        ],
    ],
    'vo_caption' => [
        'label' => $lll . 'vo_caption.label',
        'config' => [
            'type' => 'text',
            'rows' => 2,
            'max' => 300,
            'default' => '',
        ],
    ],
    'vo_split_side' => [
        'label' => $lll . 'vo_split_side.label',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                ['label' => $lll . 'vo_split_side.left', 'value' => 'left'],
                ['label' => $lll . 'vo_split_side.right', 'value' => 'right'],
            ],
            'default' => 'left',
        ],
    ],
    'vo_cta_label' => [
        'label' => $lll . 'vo_cta_label.label',
        'config' => [
            'type' => 'input',
            'max' => 50,
            'default' => '',
        ],
    ],
    'vo_cta_link' => [
        'label' => $lll . 'vo_cta_link.label',
        'config' => [
            'type' => 'link',
            'allowedTypes' => ['page', 'url', 'record'],
            'default' => '',
        ],
    ],
]);

$playerOptions = "
    --div--;{$lll}vo_player.div,
    vo_player_mode, vo_presentation, vo_autoplay, vo_loop, vo_muted, vo_controls, vo_start_time, vo_fit_cover, vo_subtitle_lang";

$showItemSplit = "
    --palette--;;headers,
    vo_eyebrow,
    bodytext,
    vo_cta_label, vo_cta_link,
    vo_split_side,
    vo_library_id,
    vo_video_uuid,
    $playerOptions,
    --div--;core.form.tabs:appearance,
    --palette--;;frames,
    --div--;core.form.tabs:categories,
    categories";

$showItemSpotlight = "
    --palette--;;headers,
    vo_eyebrow,
    vo_caption,
    vo_library_id,
    vo_video_uuid,
    $playerOptions,
    --div--;core.form.tabs:appearance,
    --palette--;;frames,
    --div--;core.form.tabs:categories,
    categories";

$recordTypes = [
    ['value' => 'videooptimizer_videosplit', 'icon' => 'videooptimizer-videosplit', 'label' => 'vo_ct.videosplit', 'showItem' => $showItemSplit,
        // The text is edited with the rich text editor of the site.
        'additional' => ['columnsOverrides' => ['bodytext' => ['config' => ['enableRichtext' => true]]]]],
    ['value' => 'videooptimizer_videospotlight', 'icon' => 'videooptimizer-videospotlight', 'label' => 'vo_ct.videospotlight', 'showItem' => $showItemSpotlight, 'additional' => []],
];

foreach ($recordTypes as $type) {
    ExtensionManagementUtility::addRecordType(
        [
            'label' => $lll . $type['label'] . '.label',
            'description' => $lll . $type['label'] . '.description',
            'value' => $type['value'],
            'icon' => $type['icon'],
            'group' => 'video',
        ],
        $type['showItem'],
        $type['additional']
    );
}
