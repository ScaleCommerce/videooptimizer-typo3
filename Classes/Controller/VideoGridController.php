<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Controller;

use Psr\Http\Message\ResponseInterface;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerException;

class VideoGridController extends AbstractVideoController
{
    private const GRID_LIMIT = 12;

    public function gridAction(): ResponseInterface
    {
        $videos = [];
        $errorCode = '';
        $errorDetail = '';

        try {
            $libraryId = $this->configuredLibraryId();
            $page = $this->client->listVideosPage(self::GRID_LIMIT, null, $libraryId !== '' ? $libraryId : null);
            $videos = is_array($page['items'] ?? null) ? $page['items'] : [];
        } catch (VideoOptimizerException|\InvalidArgumentException $exception) {
            $errorCode = 'api';
            $errorDetail = $exception->getMessage();
        }

        $shaped = $this->withNativeCards($this->shapeVideos($videos));
        $this->addVideoListStructuredData($shaped);
        $this->registerPageCache([], true, $errorCode !== '');

        $this->view->assign('videos', $shaped);
        $this->view->assign('count', count($shaped));
        $this->view->assign('error', $this->errorForTemplate(
            $errorCode !== '' ? $errorCode : ($shaped === [] ? $this->missingTokenCode() : ''),
            $errorDetail
        ));

        return $this->htmlResponse();
    }
}
