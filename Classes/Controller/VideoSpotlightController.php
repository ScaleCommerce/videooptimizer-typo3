<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Controller;

use Psr\Http\Message\ResponseInterface;
use ScaleCommerce\VideoOptimizer\Player\ElementOptions;

/**
 * Spotlight: one centered, prominent video with an eyebrow and headline above and a caption below.
 */
class VideoSpotlightController extends AbstractVideoController
{
    public function spotlightAction(): ResponseInterface
    {
        $this->assignVideoSurface();

        $row = $this->currentRecord();
        $this->view->assign('spotlight', [
            'eyebrow' => ElementOptions::text($row, 'vo_eyebrow', 80),
            'headline' => ElementOptions::headline($row),
            'caption' => ElementOptions::text($row, 'vo_caption', 300),
        ]);

        return $this->htmlResponse();
    }
}
