<?php

declare(strict_types=1);

use ScaleCommerce\VideoOptimizer\Middleware\WebhookMiddleware;

return [
    'frontend' => [
        // Runs before site resolution / page resolving: the receiver needs no page record.
        'scalecommerce/videooptimizer/webhook' => [
            'target' => WebhookMiddleware::class,
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
            'before' => [
                'typo3/cms-frontend/site',
            ],
        ],
    ],
];
