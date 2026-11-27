<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Controller;

use Psr\Http\Message\ResponseInterface;

class VideoSingleController extends AbstractVideoController
{
    public function singleAction(): ResponseInterface
    {
        $this->assignVideoSurface();

        return $this->htmlResponse();
    }
}
