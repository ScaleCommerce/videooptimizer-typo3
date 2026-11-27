<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Controller;

use Psr\Http\Message\ResponseInterface;

class VideoSingleController extends AbstractVideoController
{
    public function singleAction(): ResponseInterface
    {
        $video = null;
        $embed = null;
        $surface = null;
        $errorCode = '';
        $errorDetail = '';

        $uuid = $this->configuredUuid();
        if ($uuid !== '') {
            $video = $this->fetchVideo($uuid, $errorCode, $errorDetail);
            if (is_array($video) && !$video['processing']) {
                $embed = $this->buildEmbed($video);
                $surface = $this->buildSurface($video);
            }
        } else {
            $errorCode = 'no_video';
        }

        $this->addVideoStructuredData($video);
        $this->registerPageCache($uuid !== '' ? [$uuid] : [], false, $video === null, $this->libraryIdsOf($video));

        $this->view->assign('video', $video);
        $this->view->assign('embed', $embed);
        $this->view->assign('surface', $surface);
        $this->view->assign('error', $this->errorForTemplate(
            $errorCode !== '' ? $errorCode : ($video === null ? $this->missingTokenCode() : ''),
            $errorDetail
        ));

        return $this->htmlResponse();
    }
}
