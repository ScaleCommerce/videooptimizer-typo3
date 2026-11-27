<?php

declare(strict_types=1);

use ScaleCommerce\VideoOptimizer\Backend\Controller\UploadController;
use ScaleCommerce\VideoOptimizer\Backend\Controller\VideoBrowserController;

/**
 * AJAX routes (login and TYPO3's route token required): the visual video picker in the content element
 * form, and the browser upload / status polling / custom poster of the backend module.
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
    'videooptimizer_upload_initiate' => [
        'path' => '/videooptimizer/upload/initiate',
        'methods' => ['POST'],
        'target' => UploadController::class . '::initiateAction',
    ],
    'videooptimizer_upload_complete' => [
        'path' => '/videooptimizer/upload/complete',
        'methods' => ['POST'],
        'target' => UploadController::class . '::completeAction',
    ],
    'videooptimizer_video_status' => [
        'path' => '/videooptimizer/video/status',
        'methods' => ['GET'],
        'target' => UploadController::class . '::statusAction',
    ],
    'videooptimizer_poster_initiate' => [
        'path' => '/videooptimizer/poster/initiate',
        'methods' => ['POST'],
        'target' => UploadController::class . '::posterInitiateAction',
    ],
    'videooptimizer_poster_complete' => [
        'path' => '/videooptimizer/poster/complete',
        'methods' => ['POST'],
        'target' => UploadController::class . '::posterCompleteAction',
    ],
    'videooptimizer_poster_select' => [
        'path' => '/videooptimizer/poster/select',
        'methods' => ['POST'],
        'target' => UploadController::class . '::posterSelectAction',
    ],
    'videooptimizer_poster_remove' => [
        'path' => '/videooptimizer/poster/remove',
        'methods' => ['POST'],
        'target' => UploadController::class . '::posterRemoveAction',
    ],
    'videooptimizer_poster_files' => [
        'path' => '/videooptimizer/poster/files',
        'methods' => ['GET'],
        'target' => UploadController::class . '::posterFilesAction',
    ],
    'videooptimizer_poster_from_file' => [
        'path' => '/videooptimizer/poster/from-file',
        'methods' => ['POST'],
        'target' => UploadController::class . '::posterFromFileAction',
    ],
];
