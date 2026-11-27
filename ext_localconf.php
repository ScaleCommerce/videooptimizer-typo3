<?php

declare(strict_types=1);

use ScaleCommerce\VideoOptimizer\Controller\VideoChaptersController;
use ScaleCommerce\VideoOptimizer\Controller\VideoDetailController;
use ScaleCommerce\VideoOptimizer\Controller\VideoGridController;
use ScaleCommerce\VideoOptimizer\Controller\VideoHeroController;
use ScaleCommerce\VideoOptimizer\Controller\VideoListController;
use ScaleCommerce\VideoOptimizer\Controller\VideoSplitController;
use ScaleCommerce\VideoOptimizer\Controller\VideoSpotlightController;
use ScaleCommerce\VideoOptimizer\Controller\VideoSingleController;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

/**
 * Action whitelisting for the Extbase plugins, which are rendered via EXTBASEPLUGIN in TypoScript.
 * The backend content elements themselves are registered as tt_content CTypes in
 * Configuration/TCA/Overrides/tt_content.php (insert menu group "video").
 *
 * @var array<string, array{actions: array<class-string, string>}>
 */
$plugins = [
    'VideoHero' => [
        'actions' => [VideoHeroController::class => 'hero'],
    ],
    'VideoSingle' => [
        'actions' => [VideoSingleController::class => 'single'],
    ],
    'VideoGrid' => [
        'actions' => [VideoGridController::class => 'grid'],
    ],
    'VideoList' => [
        'actions' => [VideoListController::class => 'list'],
    ],
    'VideoDetail' => [
        'actions' => [VideoDetailController::class => 'detail'],
    ],
    'VideoSplit' => [
        'actions' => [VideoSplitController::class => 'split'],
    ],
    'VideoChapters' => [
        'actions' => [VideoChaptersController::class => 'chapters'],
    ],
    'VideoSpotlight' => [
        'actions' => [VideoSpotlightController::class => 'spotlight'],
    ],
];

foreach ($plugins as $pluginName => $config) {
    ExtensionUtility::configurePlugin('VideoOptimizer', $pluginName, $config['actions']);
}

// Dedicated cache for VideoOptimizer API responses (lists, single videos, libraries).
// Entries live 24 h; the configurable TTL (ext conf cacheTtl / VO_CACHE_TTL) is a soft TTL so stale
// copies can be served while the API is down. Uses `??=` so installations can override the backend
// (e.g. Redis); the backend must support tags.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['videooptimizer'] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => Typo3DatabaseBackend::class,
    'options' => [
        'defaultLifetime' => 86400,
    ],
    'groups' => ['all'],
];

// Fine-grained backend permissions for the VideoOptimizer module (be_groups > Access Lists > Custom module options).
$GLOBALS['TYPO3_CONF_VARS']['BE']['customPermOptions']['videooptimizer'] = [
    'header' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_mod.xlf:perm.header',
    'items' => [
        'manage' => [
            'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_mod.xlf:perm.manage',
            'videooptimizer-module',
            'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_mod.xlf:perm.manage.description',
        ],
        'upload' => [
            'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_mod.xlf:perm.upload',
            'videooptimizer-module',
            'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_mod.xlf:perm.upload.description',
        ],
        'delete' => [
            'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_mod.xlf:perm.delete',
            'videooptimizer-module',
            'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_mod.xlf:perm.delete.description',
        ],
    ],
];

// FormEngine element: visual video picker for tt_content.vo_video_uuid.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1732440000] = [
    'nodeName' => 'videooptimizerVideoBrowser',
    'priority' => 40,
    'class' => \ScaleCommerce\VideoOptimizer\Backend\Form\Element\VideoBrowserElement::class,
];

// FormEngine element: visual library picker for tt_content.vo_library_id.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1732440100] = [
    'nodeName' => 'videooptimizerLibraryPicker',
    'priority' => 40,
    'class' => \ScaleCommerce\VideoOptimizer\Backend\Form\Element\LibraryPickerElement::class,
];
