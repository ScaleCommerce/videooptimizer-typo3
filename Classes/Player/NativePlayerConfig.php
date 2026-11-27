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
     * @return array{hls: string, mp4: string, poster: string, tracks: list<array{src: string, srclang: string, label: string, kind: string, default: bool}>, options: array<string, mixed>, json: string}|null
     *         null when the video has no playable source (not ready, or unknown to the API)
     *
     * The video's player theme (organization, library and video cascade) supplies the defaults: the
     * control bar, autoplay (muted), loop and preload. An element option that was set explicitly wins
     * (autoplay, loop, muted can only be switched on there, controls only off). A background video
     * (hero) takes only the preload and accent from the theme.
     */
    public static function build(array $embed, array $settings, string $title = '', string $unavailable = '', bool $background = false): ?array
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
        $theme = is_array($embed['theme'] ?? null) ? $embed['theme'] : [];
        $themeFlag = static fn (string $key): bool => !$background && ($theme[$key] ?? false) === true;
        $controls = $settings['controls'] && ($background || ($theme['controls'] ?? true) !== false);
        $autoplay = $settings['autoplay'] || $themeFlag('autoplay');
        $muted = $settings['muted'] || $themeFlag('mutedStart') || $autoplay;
        $loop = $settings['loop'] || $themeFlag('loopButton');
        // The organization theme defaults to "auto"; the native player never loads segments before
        // playback, so "auto" is capped at "metadata" ("none" stays).
        $preload = ($theme['preload'] ?? null) === 'none' ? 'none' : 'metadata';
        $accent = self::cssColor($theme['accentColor'] ?? null);
        $tracks = $background ? [] : self::tracks($embed['tracks'] ?? [], $settings['subtitle_lang']);
        $options = [
            'hls' => $hls,
            'mp4' => $mp4,
            'poster' => $poster,
            'controls' => $controls,
            'autoplay' => $autoplay,
            // Browsers only allow autoplay without sound.
            'muted' => $muted,
            'loop' => $loop,
            'preload' => $preload,
            'accent' => $accent,
            'tracks' => $tracks,
            'start' => $settings['start_time'],
            'cover' => $settings['fit_cover'],
            'title' => $title,
            'unavailable' => $unavailable,
        ];

        return [
            'hls' => $hls,
            'mp4' => $mp4,
            'poster' => $poster,
            'tracks' => $tracks,
            'options' => $options,
            'json' => json_encode($options, self::JSON_FLAGS),
        ];
    }

    /**
     * `srcset` value of the responsive poster sizes (`posterSrcset` of the embed config): "url 480w, ...",
     * every URL on the allowlist, sorted by width. '' when there is none.
     */
    public static function posterSrcset(mixed $raw): string
    {
        $candidates = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $url = MediaUrl::safe($entry['url'] ?? '');
            $width = $entry['width'] ?? null;
            // srcset is comma separated: a URL containing a comma would split into two candidates.
            if ($url === '' || str_contains($url, ',') || !is_int($width) || $width < 1 || $width > 10000) {
                continue;
            }
            $candidates[$width] = $url . ' ' . $width . 'w';
        }
        ksort($candidates);

        return implode(', ', $candidates);
    }

    /**
     * Subtitle tracks for <track> elements: WebVTT URL on the allowlist, BCP 47 language, label, kind.
     * The element's subtitle language decides which track is shown by default ('off' = none, a
     * language = that one when the video has it); without a choice the API's `default` flag applies
     * (first one only).
     *
     * @return list<array{src: string, srclang: string, label: string, kind: string, default: bool}>
     */
    public static function tracks(mixed $raw, string $preferredLanguage = ''): array
    {
        $tracks = [];
        foreach (is_array($raw) ? $raw : [] as $track) {
            if (!is_array($track)) {
                continue;
            }
            $src = MediaUrl::safe($track['src'] ?? '');
            $language = is_string($track['srclang'] ?? null) ? trim($track['srclang']) : '';
            if ($src === '' || preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8}){0,3}$/', $language) !== 1) {
                continue;
            }
            $label = is_string($track['label'] ?? null) ? trim(strip_tags($track['label'])) : '';
            $tracks[] = [
                'src' => $src,
                'srclang' => $language,
                'label' => mb_substr($label !== '' ? $label : $language, 0, 100),
                'kind' => ($track['kind'] ?? '') === 'captions' ? 'captions' : 'subtitles',
                'default' => ($track['default'] ?? false) === true,
            ];
        }
        if ($tracks === [] || $preferredLanguage === '') {
            return self::onlyFirstDefault($tracks);
        }
        if ($preferredLanguage === 'off') {
            return array_map(static fn (array $track): array => array_merge($track, ['default' => false]), $tracks);
        }
        $wanted = strtolower($preferredLanguage);
        $matched = false;
        $chosen = [];
        foreach ($tracks as $track) {
            $track['default'] = !$matched && strtolower($track['srclang']) === $wanted;
            $matched = $matched || $track['default'];
            $chosen[] = $track;
        }

        return $matched ? $chosen : self::onlyFirstDefault($tracks);
    }

    /**
     * @param list<array{src: string, srclang: string, label: string, kind: string, default: bool}> $tracks
     * @return list<array{src: string, srclang: string, label: string, kind: string, default: bool}>
     */
    private static function onlyFirstDefault(array $tracks): array
    {
        $seen = false;
        foreach ($tracks as &$track) {
            $track['default'] = $track['default'] && !$seen;
            $seen = $seen || $track['default'];
        }

        return $tracks;
    }

    /**
     * A CSS color from the theme (hex, rgb(a), hsl(a)); '' for anything else, so it is safe inside a
     * style attribute.
     */
    public static function cssColor(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        $number = '-?\\d{1,3}(?:\\.\\d+)?%?';
        $alpha = '(?:\\s*[,\\/]\\s*\\d*(?:\\.\\d+)?%?)?';
        $pattern = '/^(?:#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})|(?:rgb|hsl)a?\\(\\s*' . $number . '\\s*[,\\s]\\s*' . $number . '\\s*[,\\s]\\s*' . $number . $alpha . '\\s*\\))$/i';

        return preg_match($pattern, $value) === 1 ? $value : '';
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
