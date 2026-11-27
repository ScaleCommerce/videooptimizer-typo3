<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Controller;

use Psr\Http\Message\ResponseInterface;
use ScaleCommerce\VideoOptimizer\Player\ElementOptions;

/**
 * Video next to text: the video on one side, eyebrow, headline (the record's header), rich text and an
 * optional button on the other.
 */
class VideoSplitController extends AbstractVideoController
{
    public function splitAction(): ResponseInterface
    {
        $this->assignVideoSurface();

        $row = $this->currentRecord();
        $this->view->assign('split', [
            'side' => ElementOptions::side($row),
            'eyebrow' => ElementOptions::text($row, 'vo_eyebrow', 80),
            'headline' => ElementOptions::text($row, 'header', 255),
            'text' => is_scalar($row['bodytext'] ?? null) ? (string)$row['bodytext'] : '',
            'cta' => ElementOptions::cta($row),
        ]);

        return $this->htmlResponse();
    }
}
