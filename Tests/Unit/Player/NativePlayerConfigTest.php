<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Player;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Player\NativePlayerConfig;

final class NativePlayerConfigTest extends TestCase
{
    private const SETTINGS = [
        'autoplay' => false, 'loop' => false, 'muted' => false, 'controls' => true,
        'start_time' => 0, 'fit_cover' => false, 'subtitle_lang' => '',
    ];

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function embed(array $override = []): array
    {
        return $override + [
            'sources' => [
                ['src' => 'https://l.cdn.videooptimizer.eu/h/master.m3u8', 'type' => 'application/vnd.apple.mpegurl', 'label' => 'auto'],
                ['src' => 'https://l.cdn.videooptimizer.eu/h/360p.mp4', 'type' => 'video/mp4', 'label' => '360p'],
                ['src' => 'https://l.cdn.videooptimizer.eu/h/720p.mp4', 'type' => 'video/mp4', 'label' => '720p'],
                ['src' => 'https://l.cdn.videooptimizer.eu/h/1080p.mp4', 'type' => 'video/mp4', 'label' => '1080p'],
            ],
            'poster' => 'https://poster.videooptimizer.eu/l/h/thumbnail.jpg',
        ];
    }

    #[Test]
    public function usesTheHlsMasterAndAnMp4UpTo720pAsFallback(): void
    {
        $config = NativePlayerConfig::build(self::embed(), self::SETTINGS, 'Clip', 'Nope');

        self::assertSame('https://l.cdn.videooptimizer.eu/h/master.m3u8', $config['hls']);
        self::assertSame('https://l.cdn.videooptimizer.eu/h/720p.mp4', $config['mp4']);
        self::assertSame('https://poster.videooptimizer.eu/l/h/thumbnail.jpg', $config['poster']);
        self::assertSame('Clip', $config['options']['title']);
        self::assertSame('Nope', $config['options']['unavailable']);
    }

    #[Test]
    public function fallsBackToTheSmallestMp4WhenNoneIsAtOrBelow720p(): void
    {
        $config = NativePlayerConfig::build(['sources' => [
            ['src' => 'https://l.cdn.videooptimizer.eu/h/2160p.mp4', 'type' => 'video/mp4', 'label' => '2160p'],
            ['src' => 'https://l.cdn.videooptimizer.eu/h/1080p.mp4', 'type' => 'video/mp4', 'label' => '1080p'],
        ]], self::SETTINGS);

        self::assertSame('https://l.cdn.videooptimizer.eu/h/1080p.mp4', $config['mp4']);
        self::assertSame('', $config['hls']);
    }

    #[Test]
    public function sourcesOutsideTheAllowlistAreDropped(): void
    {
        $config = NativePlayerConfig::build(self::embed([
            'sources' => [
                ['src' => 'https://evil.example/master.m3u8', 'type' => 'application/vnd.apple.mpegurl'],
                ['src' => 'http://l.cdn.videooptimizer.eu/h/720p.mp4', 'type' => 'video/mp4', 'label' => '720p'],
                ['src' => 'https://l.cdn.videooptimizer.eu/h/480p.mp4', 'type' => 'video/mp4', 'label' => '480p'],
                'garbage',
            ],
            'poster' => 'javascript:alert(1)',
        ]), self::SETTINGS);

        self::assertSame('', $config['hls']);
        self::assertSame('https://l.cdn.videooptimizer.eu/h/480p.mp4', $config['mp4']);
        self::assertSame('', $config['poster']);
    }

    #[Test]
    public function aVideoWithoutPlayableSourcesHasNoNativePlayer(): void
    {
        self::assertNull(NativePlayerConfig::build(['sources' => []], self::SETTINGS));
        self::assertNull(NativePlayerConfig::build([], self::SETTINGS));
        self::assertNull(NativePlayerConfig::build(['sources' => [['src' => 'https://evil.example/a.mp4', 'type' => 'video/mp4']]], self::SETTINGS));
    }

    #[Test]
    public function elementOptionsBecomePlayerOptionsAndAutoplayForcesMuted(): void
    {
        $options = NativePlayerConfig::build(self::embed(), [
            'autoplay' => true, 'loop' => true, 'muted' => false, 'controls' => false,
            'start_time' => 30, 'fit_cover' => true, 'subtitle_lang' => '',
        ])['options'];

        self::assertTrue($options['autoplay']);
        self::assertTrue($options['muted'], 'browsers only autoplay without sound');
        self::assertTrue($options['loop']);
        self::assertFalse($options['controls']);
        self::assertSame(30, $options['start']);
        self::assertTrue($options['cover']);
    }

    #[Test]
    public function theJsonIsSafeInsideAnHtmlAttribute(): void
    {
        $json = NativePlayerConfig::build(self::embed(), self::SETTINGS, '"><script>x</script> & \'')['json'];

        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
        self::assertStringNotContainsString('"><', $json);
        self::assertSame('"><script>x</script> & \'', json_decode($json, true)['title']);
    }

    private const THEME = [
        'accentColor' => '#C72876', 'controls' => true, 'autoplay' => false, 'mutedStart' => false,
        'loopButton' => false, 'preload' => 'auto',
    ];

    /**
     * @param array<string, mixed> $theme
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function options(array $theme, array $settings = [], bool $background = false, bool $direct = false): array
    {
        return NativePlayerConfig::build(self::embed(['theme' => $theme + self::THEME]), $settings + self::SETTINGS, '', '', $background, $direct)['options'];
    }

    #[Test]
    public function posterSrcsetKeepsAllowedUrlsSortedByWidth(): void
    {
        $srcset = NativePlayerConfig::posterSrcset([
            ['width' => 1280, 'height' => 720, 'url' => 'https://poster.videooptimizer.eu/p/poster_1280.jpg'],
            ['width' => 480, 'height' => 270, 'url' => 'https://poster.videooptimizer.eu/p/poster_480.jpg'],
            ['width' => 640, 'height' => 360, 'url' => 'https://evil.example/t.jpg'],
            ['width' => 800, 'url' => 'https://poster.videooptimizer.eu/a,b.jpg'],
            ['width' => '900', 'url' => 'https://poster.videooptimizer.eu/s.jpg'],
            'junk',
        ]);

        self::assertSame('https://poster.videooptimizer.eu/p/poster_480.jpg 480w, https://poster.videooptimizer.eu/p/poster_1280.jpg 1280w', $srcset);
        self::assertSame('', NativePlayerConfig::posterSrcset('x'));
        self::assertSame('', NativePlayerConfig::posterSrcset([]));
    }

    #[Test]
    public function theThemeSuppliesDefaultsForPreloadAndAccent(): void
    {
        $options = self::options([]);

        self::assertSame('metadata', $options['preload'], '"auto" is capped at "metadata"');
        self::assertSame('#C72876', $options['accent']);
        self::assertTrue($options['controls']);
        self::assertFalse($options['autoplay']);
    }

    #[Test]
    public function withoutAThemePreloadIsMetadataAndThereIsNoAccent(): void
    {
        $options = NativePlayerConfig::build(self::embed(), self::SETTINGS)['options'];

        self::assertSame('metadata', $options['preload']);
        self::assertSame('', $options['accent']);
    }

    #[Test]
    public function themeFlagsTurnOnAutoplayMutedAndLoopButTheElementCannotBeOverriddenOff(): void
    {
        $options = self::options(['autoplay' => true, 'loopButton' => true]);
        self::assertTrue($options['autoplay']);
        self::assertTrue($options['muted'], 'autoplay starts muted');
        self::assertTrue($options['loop']);

        $options = self::options(['mutedStart' => true]);
        self::assertTrue($options['muted']);
        self::assertFalse($options['autoplay']);

        $options = self::options([], ['autoplay' => true, 'loop' => true]);
        self::assertTrue($options['autoplay']);
        self::assertTrue($options['loop']);
    }

    #[Test]
    public function aHiddenControlBarInTheThemeOrTheElementHidesTheControls(): void
    {
        self::assertFalse(self::options(['controls' => false, 'autoplay' => true])['controls'], 'theme without control bar and autoplay');
        self::assertFalse(self::options([], ['controls' => false])['controls']);
        self::assertTrue(self::options([])['controls']);
    }

    #[Test]
    public function aThemeWithoutControlBarOnlyAppliesWhenTheVideoAutoplays(): void
    {
        self::assertTrue(self::options(['controls' => false])['controls'], 'no autoplay: keep the controls');
        self::assertTrue(self::options(['controls' => false, 'mutedStart' => true])['controls']);
        self::assertTrue(self::options(['controls' => false], ['autoplay' => false])['controls']);
        self::assertFalse(self::options(['controls' => false], ['autoplay' => true])['controls'], 'element autoplay counts too');
        self::assertTrue(self::options(['controls' => true, 'autoplay' => true])['controls']);
    }

    #[Test]
    public function aNonInteractiveThemeMakesOnlyADirectAutoplayingPlayerStatic(): void
    {
        $theme = ['interactive' => false, 'autoplay' => true];

        $direct = self::options($theme, [], false, true);
        self::assertTrue($direct['static']);
        self::assertFalse($direct['controls'], 'no focusable controls');

        $behindAClick = self::options($theme);
        self::assertFalse($behindAClick['static']);
        self::assertTrue($behindAClick['controls']);

        $noAutoplay = self::options(['interactive' => false], [], false, true);
        self::assertFalse($noAutoplay['static'], 'a player that does not autoplay stays operable');
        self::assertTrue($noAutoplay['controls']);

        self::assertFalse(self::options(['autoplay' => true], [], false, true)['static']);
    }

    #[Test]
    public function aBackgroundVideoTakesOnlyPreloadAndAccentFromTheTheme(): void
    {
        $options = self::options(['autoplay' => true, 'controls' => false, 'loopButton' => true, 'mutedStart' => true], ['controls' => false], true);

        self::assertFalse($options['autoplay']);
        self::assertFalse($options['loop']);
        self::assertFalse($options['muted']);
        self::assertFalse($options['controls']);
        self::assertSame('metadata', $options['preload']);
        self::assertSame('#C72876', $options['accent']);

        self::assertTrue(self::options(['controls' => false], [], true)['controls'], 'theme controls do not apply to the background');
    }

    #[Test]
    public function onlyPlainCssColorsPassAndUnknownPreloadFallsBack(): void
    {
        foreach (['#fff', '#C72876', '#C72876cc', 'rgb(1,2,3)', 'rgba(0,0,0,0.7)', 'rgb(0 0 0 / 50%)', 'hsl(120, 50%, 50%)'] as $color) {
            self::assertSame($color, NativePlayerConfig::cssColor($color), $color);
        }
        foreach (['red', 'red;}</style>', 'url(x)', '#12', 'rgb(1,2,3);color:red', 'expression(1)', 42, null] as $color) {
            self::assertSame('', NativePlayerConfig::cssColor($color), var_export($color, true));
        }
        self::assertSame('metadata', self::options(['preload' => 'bogus'])['preload']);
        self::assertSame('none', self::options(['preload' => 'none'])['preload']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rawTracks(): array
    {
        return [
            ['src' => 'https://l.cdn.videooptimizer.eu/h/de.vtt?v=2', 'srclang' => 'de', 'label' => 'Deutsch', 'kind' => 'subtitles', 'default' => true],
            ['src' => 'https://l.cdn.videooptimizer.eu/h/en-US.vtt', 'srclang' => 'en-US', 'label' => 'English', 'kind' => 'captions', 'default' => false],
        ];
    }

    #[Test]
    public function subtitleTracksBecomeTrackData(): void
    {
        $config = NativePlayerConfig::build(self::embed(['tracks' => self::rawTracks()]), self::SETTINGS);

        self::assertSame(self::rawTracks(), $config['tracks']);
        self::assertSame(self::rawTracks(), $config['options']['tracks']);
    }

    #[Test]
    public function invalidTracksAreDropped(): void
    {
        $tracks = NativePlayerConfig::tracks([
            ['src' => 'https://evil.example/x.vtt', 'srclang' => 'de'],
            ['src' => 'https://l.cdn.videooptimizer.eu/x.vtt', 'srclang' => '"><x'],
            ['src' => 'https://l.cdn.videooptimizer.eu/ok.vtt', 'srclang' => 'fr', 'label' => '<b>Français</b>', 'kind' => 'weird'],
            'junk',
        ]);

        self::assertCount(1, $tracks);
        self::assertSame('Français', $tracks[0]['label']);
        self::assertSame('subtitles', $tracks[0]['kind']);
        self::assertSame([], NativePlayerConfig::tracks('nope'));
    }

    #[Test]
    public function theElementsSubtitleLanguageDecidesTheDefaultTrack(): void
    {
        $default = static fn (array $tracks): array => array_column(array_filter($tracks, static fn (array $t): bool => $t['default']), 'srclang');

        self::assertSame(['de'], $default(NativePlayerConfig::tracks(self::rawTracks())), 'API default');
        self::assertSame(['en-US'], $default(NativePlayerConfig::tracks(self::rawTracks(), 'en-us')));
        self::assertSame([], $default(NativePlayerConfig::tracks(self::rawTracks(), 'off')));
        self::assertSame(['de'], $default(NativePlayerConfig::tracks(self::rawTracks(), 'fr')), 'unknown language keeps the API default');
    }

    #[Test]
    public function onlyOneTrackIsDefaultAndABackgroundVideoHasNoTracks(): void
    {
        $raw = self::rawTracks();
        $raw[1]['default'] = true;
        $tracks = NativePlayerConfig::tracks($raw);
        self::assertSame([true, false], array_column($tracks, 'default'));

        $config = NativePlayerConfig::build(self::embed(['tracks' => self::rawTracks()]), self::SETTINGS, '', '', true);
        self::assertSame([], $config['tracks']);
    }

    #[Test]
    public function languageTagsFollowBcp47(): void
    {
        foreach (['de', 'de-DE', 'de-x-simple', 'zh-Hant-TW', 'eng'] as $tag) {
            self::assertSame(1, preg_match(NativePlayerConfig::LANGUAGE_PATTERN, $tag), $tag);
            self::assertCount(1, NativePlayerConfig::tracks([['src' => 'https://l.cdn.videooptimizer.eu/x.vtt', 'srclang' => $tag]]), $tag);
        }
        foreach (['d', 'de_DE', 'de-', 'de--x', 'de-DE-', '-de', ''] as $tag) {
            self::assertSame(0, preg_match(NativePlayerConfig::LANGUAGE_PATTERN, $tag), var_export($tag, true));
            self::assertSame(0, preg_match(NativePlayerConfig::LANGUAGE_PATTERN, $tag . "\n"), 'trailing newline');
            self::assertSame([], NativePlayerConfig::tracks([['src' => 'https://l.cdn.videooptimizer.eu/x.vtt', 'srclang' => $tag]]), var_export($tag, true));
        }
    }
}
