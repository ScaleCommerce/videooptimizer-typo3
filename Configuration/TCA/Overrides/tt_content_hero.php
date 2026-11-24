<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die();

// Hero-only options. Loaded after tt_content.php (alphabetical order of the Overrides files).
// The allowed values here are mirrored (and enforced, unknown -> default) in VideoHeroController.
$lll = 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang.xlf:';

$select = static fn (string $label, array $items, string $default, array $extra = []): array => $extra + [
    'label' => $lll . $label . '.label',
    'config' => [
        'type' => 'select',
        'renderType' => 'selectSingle',
        'items' => array_map(
            static fn (string $value): array => ['label' => $lll . $label . '.' . $value, 'value' => $value],
            $items
        ),
        'default' => $default,
    ],
];

ExtensionManagementUtility::addTCAcolumns('tt_content', [
    'vo_hero_eyebrow' => [
        'label' => $lll . 'vo_hero_eyebrow.label',
        'config' => [
            'type' => 'input',
            'max' => 80,
            'default' => '',
            'placeholder' => 'VideoOptimizer',
        ],
    ],
    'vo_hero_subtitle' => [
        'label' => $lll . 'vo_hero_subtitle.label',
        'description' => $lll . 'vo_hero_subtitle.description',
        'config' => [
            'type' => 'text',
            'rows' => 3,
            'max' => 300,
            'default' => '',
        ],
    ],
    'vo_hero_cta_label' => [
        'label' => $lll . 'vo_hero_cta_label.label',
        'config' => [
            'type' => 'input',
            'max' => 50,
            'default' => '',
            'placeholder' => $lll . 'vo_hero_cta_label.placeholder',
        ],
    ],
    'vo_hero_cta_mode' => $select('vo_hero_cta_mode', ['newtab', 'lightbox', 'link', 'none'], 'newtab', ['onChange' => 'reload']),
    'vo_hero_cta_link' => [
        'label' => $lll . 'vo_hero_cta_link.label',
        'displayCond' => 'FIELD:vo_hero_cta_mode:=:link',
        'config' => [
            'type' => 'link',
            'allowedTypes' => ['page', 'url', 'record'],
            'default' => '',
        ],
    ],
    'vo_hero_overlay' => $select('vo_hero_overlay', ['none', 'light', 'medium', 'strong'], 'medium'),
    'vo_hero_height' => $select('vo_hero_height', ['compact', 'default', 'full'], 'default'),
    'vo_hero_align' => $select('vo_hero_align', ['left', 'center'], 'left'),
]);

ExtensionManagementUtility::addToAllTCAtypes(
    'tt_content',
    '--div--;' . $lll . 'vo_hero.div,
    vo_hero_eyebrow, vo_hero_subtitle, vo_hero_cta_mode, vo_hero_cta_label, vo_hero_cta_link,
    vo_hero_overlay, vo_hero_height, vo_hero_align',
    'videooptimizer_videohero',
    'after:vo_subtitle_lang'
);
