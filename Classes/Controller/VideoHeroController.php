<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Controller;

use Psr\Http\Message\ResponseInterface;

class VideoHeroController extends AbstractVideoController
{
    private const DEFAULT_EYEBROW = 'VideoOptimizer';

    /** Allowed values per option; the first entry is NOT necessarily the default, see heroOptions(). */
    private const CTA_MODES = ['newtab', 'lightbox', 'link', 'none'];
    private const OVERLAYS = ['none', 'light', 'medium', 'strong'];
    private const HEIGHTS = ['compact', 'default', 'full'];
    private const ALIGNMENTS = ['left', 'center'];

    public function heroAction(): ResponseInterface
    {
        $video = null;
        $errorCode = '';
        $errorDetail = '';

        $uuid = $this->configuredUuid();
        if ($uuid !== '') {
            $video = $this->fetchVideo($uuid, $errorCode, $errorDetail);
        } else {
            $errorCode = 'no_video';
        }

        $this->addVideoStructuredData($video);
        $this->registerPageCache($uuid !== '' ? [$uuid] : [], false, $video === null);

        $embedUrl = '';
        if (is_array($video) && !$video['processing']) {
            $embedUrl = $this->embedUrlWithParams((string)$video['embed_src'], $this->playerQueryParams(heroMode: true));
        }

        $this->view->assign('elementUid', (int)($this->currentRecord()['uid'] ?? 0));
        $this->view->assign('hero', $this->heroOptions());
        $this->view->assign('video', $video);
        $this->view->assign('embedUrl', $embedUrl);
        $this->view->assign('error', $this->errorForTemplate(
            $errorCode !== '' ? $errorCode : ($video === null ? $this->missingTokenCode() : ''),
            $errorDetail
        ));

        return $this->htmlResponse();
    }

    /**
     * Hero options of the current record. Every value is validated against its allow-list, unknown or
     * missing values fall back to the default (= the look before these options existed).
     *
     * @return array{eyebrow: string, subtitle: string, ctaLabel: string, ctaMode: string, ctaLink: string, overlay: string, height: string, align: string}
     */
    private function heroOptions(): array
    {
        $row = $this->currentRecord();
        $text = static fn (string $key, int $max): string => mb_substr(trim(strip_tags((string)($row[$key] ?? ''))), 0, $max);
        $choice = static fn (string $key, array $allowed, string $default): string
            => in_array($row[$key] ?? null, $allowed, true) ? (string)$row[$key] : $default;

        $mode = $choice('vo_hero_cta_mode', self::CTA_MODES, 'newtab');
        $link = trim((string)($row['vo_hero_cta_link'] ?? ''));
        if ($mode === 'link' && $link === '') {
            // A link button without a target would be a dead end.
            $mode = 'none';
        }

        $eyebrow = $text('vo_hero_eyebrow', 80);

        return [
            'eyebrow' => $eyebrow !== '' ? $eyebrow : self::DEFAULT_EYEBROW,
            'subtitle' => $text('vo_hero_subtitle', 300),
            'ctaLabel' => $text('vo_hero_cta_label', 50),
            'ctaMode' => $mode,
            'ctaLink' => $mode === 'link' ? $link : '',
            'overlay' => $choice('vo_hero_overlay', self::OVERLAYS, 'medium'),
            'height' => $choice('vo_hero_height', self::HEIGHTS, 'default'),
            'align' => $choice('vo_hero_align', self::ALIGNMENTS, 'left'),
        ];
    }
}
