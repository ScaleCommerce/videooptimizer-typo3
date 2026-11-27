<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Controller;

use Psr\Http\Message\ResponseInterface;

class VideoDetailController extends AbstractVideoController
{
    public function detailAction(): ResponseInterface
    {
        $this->assignVideoSurface();

        return $this->htmlResponse();
    }
}
