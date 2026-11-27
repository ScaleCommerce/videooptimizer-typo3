<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Controller;

use Psr\Http\Message\ResponseInterface;
use ScaleCommerce\VideoOptimizer\Player\ElementOptions;
use ScaleCommerce\VideoOptimizer\Player\Scenes;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Video with chapters: the player (shown directly) and a row of scene previews below it. A click on a
 * scene jumps to its start time and plays.
 */
class VideoChaptersController extends AbstractVideoController
{
    public function chaptersAction(): ResponseInterface
    {
        $video = $this->assignVideoSurface();
        $scenes = [];
        if (is_array($video) && ($video['processing'] ?? true) === false) {
            $uuid = (string)($video['uuid'] ?? '');
            $embed = $this->embedConfig($uuid);
            $duration = (int)round((float)($embed['duration'] ?? 0));
            if ($duration <= 0) {
                $duration = (int)round((float)($video['duration'] ?? 0));
            }
            $row = $this->currentRecord();
            $scenes = Scenes::build(
                $uuid,
                $duration,
                Scenes::count($row),
                Scenes::titles($row),
                (string)LocalizationUtility::translate('LLL:EXT:videooptimizer/Resources/Private/Language/locallang_frontend.xlf:scene.label') ?: 'Scene %d'
            );
        }
        $this->view->assign('scenes', $scenes);
        $this->view->assign('headline', ElementOptions::text($this->currentRecord(), 'header', 255));

        return $this->htmlResponse();
    }

    /** Chapters always show the player directly: the scene row sits under it. */
    protected function presentation(): string
    {
        return 'direct';
    }
}
