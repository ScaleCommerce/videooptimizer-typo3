<?php

declare(strict_types=1);

use ScaleCommerce\VideoOptimizer\Backend\Controller\VideoBrowserController;

/**
 * AJAX routes of the visual video picker in the content element form (login required).
 */
return [
    'videooptimizer_browser_videos' => [
        'path' => '/videooptimizer/browser/videos',
        'methods' => ['GET'],
        'target' => VideoBrowserController::class . '::videosAction',
    ],
    'videooptimizer_browser_video' => [
        'path' => '/videooptimizer/browser/video',
        'methods' => ['GET'],
        'target' => VideoBrowserController::class . '::videoAction',
    ],
];
