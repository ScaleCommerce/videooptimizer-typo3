<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Controller;

use Psr\Http\Message\ResponseInterface;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerException;

class VideoListController extends AbstractVideoController
{
    private const PAGE_SIZE = 24;

    /**
     * The "cursor" request argument is part of the cHash (the link is generated via f:link.action),
     * so every list page is cached separately. An invalid cursor falls back to the first page.
     */
    public function listAction(?string $cursor = null): ResponseInterface
    {
        $cursor = is_string($cursor) && preg_match(self::VALIDATION_CURSOR, $cursor) === 1 ? $cursor : null;

        $videos = [];
        $nextCursor = null;
        $errorCode = '';
        $errorDetail = '';

        try {
            $libraryId = $this->configuredLibraryId();
            $page = $this->client->listVideosPage(self::PAGE_SIZE, $cursor, $libraryId !== '' ? $libraryId : null);
            $videos = is_array($page['items'] ?? null) ? $page['items'] : [];
            $next = $page['nextCursor'] ?? null;
            $nextCursor = is_string($next) && preg_match(self::VALIDATION_CURSOR, $next) === 1 ? $next : null;
        } catch (VideoOptimizerException|\InvalidArgumentException $exception) {
            $errorCode = 'api';
            $errorDetail = $exception->getMessage();
        }

        $shaped = $this->withNativeCards($this->shapeVideos($videos));
        $this->addVideoListStructuredData($shaped);
        $this->registerPageCache([], true, $errorCode !== '');

        $this->view->assign('videos', $shaped);
        $this->view->assign('count', count($shaped));
        $this->view->assign('cursor', $cursor);
        $this->view->assign('nextCursor', $nextCursor);
        $this->view->assign('error', $this->errorForTemplate(
            $errorCode !== '' ? $errorCode : ($shaped === [] ? $this->missingTokenCode() : ''),
            $errorDetail
        ));

        return $this->htmlResponse();
    }
}
