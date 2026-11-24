<?php

declare(strict_types=1);

use ScaleCommerce\VideoOptimizer\Backend\Module\VideoOptimizerModuleController;

/**
 * Backend module "VideoOptimizer" (System menu): manage the remote libraries
 * of the organization and upload videos into them via the VideoOptimizer API.
 */
return [
    'system_videooptimizer' => [
        'parent' => 'system',
        'access' => 'user',
        'path' => '/module/system/videooptimizer',
        'iconIdentifier' => 'videooptimizer-module',
        'labels' => 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'videooptimizer',
        'controllerActions' => [
            VideoOptimizerModuleController::class => [
                'list',
                'create',
                'updateLibrary',
                'deleteLibrary',
                'reprocessLibrary',
                'videos',
                'upload',
                'ingest',
                'rename',
                'setTags',
                'deleteVideo',
                'thumbnail',
                'setThumbnail',
                'subtitles',
                'uploadSubtitle',
                'deleteSubtitle',
            ],
        ],
    ],
];
