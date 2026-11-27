<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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

        $this->registerPageCache($uuid !== '' ? [$uuid] : [], false, $video === null, $this->libraryIdsOf($video));

        $embedUrl = '';
        $stillUrl = '';
        if (is_array($video) && !$video['processing']) {
            $embedUrl = $this->embedUrlWithParams((string)$video['embed_src'], $this->playerQueryParams(heroMode: true));
            // Shown instead with prefers-reduced-motion (swapped in by the frontend script).
            $stillUrl = $this->embedUrlWithParams($embedUrl, ['autoplay' => '0', 'loop' => '0']);
        }

        $native = is_array($video) ? $this->buildNative($video, heroMode: true) : null;
        $posterSrcset = is_array($video) && !$video['processing'] ? $this->posterSrcset((string)$video['uuid']) : '';
        $this->addVideoStructuredData($video);
        if (is_array($video) && $video['poster_src'] !== '') {
            $this->preloadPoster((string)$video['poster_src'], $posterSrcset);
        }

        $this->view->assign('stillUrl', $stillUrl);
        $this->view->assign('native', $native);
        $this->view->assign('posterSrcset', $posterSrcset);
        $this->view->assign('posterSizes', self::POSTER_SIZES_FULL);
        // The lightbox CTA plays like a click-to-play element, not like the background video.
        $this->view->assign('nativeLightbox', is_array($video) ? $this->buildNative($video) : null);
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

    /**
     * The hero poster is the largest contentful paint: preload it with high priority (the page cache
     * keeps the header data with the page). Values are validated URLs / a srcset built from them and
     * are escaped for the attribute.
     */
    private function preloadPoster(string $poster, string $srcset): void
    {
        $attributes = ['rel' => 'preload', 'as' => 'image', 'href' => $poster, 'fetchpriority' => 'high'];
        if ($srcset !== '') {
            $attributes['imagesrcset'] = $srcset;
            $attributes['imagesizes'] = self::POSTER_SIZES_FULL;
        }
        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        }
        GeneralUtility::makeInstance(PageRenderer::class)->addHeaderData('<link' . $html . ' />');
    }
}
