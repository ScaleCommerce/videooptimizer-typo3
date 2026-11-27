<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Player;

use ScaleCommerce\VideoOptimizer\Security\MediaUrl;

/**
 * Turns the public player configuration (`GET /embed/{uuid}`) and the content element's player options
 * into the validated settings of the native player. Every URL passes the MediaUrl allowlist; the result
 * is JSON-encoded for a data attribute (the Fluid templates escape it on output).
 */
final class NativePlayerConfig
{
    private const HLS_TYPE = 'application/vnd.apple.mpegurl';

    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param array<string, mixed> $embed payload of GET /embed/{uuid}
     * @param array{autoplay: bool, loop: bool, muted: bool, controls: bool, start_time: int, fit_cover: bool, subtitle_lang: string} $settings
     * @return array{hls: string, mp4: string, poster: string, options: array<string, mixed>, json: string}|null
     *         null when the video has no playable source (not ready, or unknown to the API)
     */
    public static function build(array $embed, array $settings, string $title = '', string $unavailable = ''): ?array
    {
        $hls = '';
        $mp4Sources = [];
        foreach (is_array($embed['sources'] ?? null) ? $embed['sources'] : [] as $source) {
            if (!is_array($source)) {
                continue;
            }
            $url = MediaUrl::safe($source['src'] ?? '');
            if ($url === '') {
                continue;
            }
            $type = (string)($source['type'] ?? '');
            if ($type === self::HLS_TYPE && $hls === '') {
                $hls = $url;
            } elseif ($type === 'video/mp4') {
                $mp4Sources[] = ['url' => $url, 'height' => (int)preg_replace('/\D/', '', (string)($source['label'] ?? ''))];
            }
        }
        $mp4 = self::pickMp4($mp4Sources);
        if ($hls === '' && $mp4 === '') {
            return null;
        }

        $poster = MediaUrl::safe($embed['poster'] ?? '');
        $options = [
            'hls' => $hls,
            'mp4' => $mp4,
            'poster' => $poster,
            'controls' => $settings['controls'],
            'autoplay' => $settings['autoplay'],
            // Browsers only allow autoplay without sound.
            'muted' => $settings['muted'] || $settings['autoplay'],
            'loop' => $settings['loop'],
            'start' => $settings['start_time'],
            'cover' => $settings['fit_cover'],
            'title' => $title,
            'unavailable' => $unavailable,
        ];

        return [
            'hls' => $hls,
            'mp4' => $mp4,
            'poster' => $poster,
            'options' => $options,
            'json' => json_encode($options, self::JSON_FLAGS),
        ];
    }

    /**
     * The MP4 used when HLS cannot play: the best rendition up to 720p, else the smallest one.
     *
     * @param list<array{url: string, height: int}> $sources
     */
    private static function pickMp4(array $sources): string
    {
        $best = null;
        foreach ($sources as $source) {
            if ($source['height'] > 0 && $source['height'] <= 720 && ($best === null || $source['height'] > $best['height'])) {
                $best = $source;
            }
        }
        if ($best === null) {
            foreach ($sources as $source) {
                if ($best === null || $source['height'] < $best['height']) {
                    $best = $source;
                }
            }
        }

        return $best['url'] ?? '';
    }
}
