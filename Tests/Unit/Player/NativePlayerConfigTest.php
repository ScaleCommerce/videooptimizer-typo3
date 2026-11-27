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
    private static function options(array $theme, array $settings = [], bool $background = false): array
    {
        return NativePlayerConfig::build(self::embed(['theme' => $theme + self::THEME]), $settings + self::SETTINGS, '', '', $background)['options'];
    }

    #[Test]
    public function theThemeSuppliesDefaultsForPreloadAndAccent(): void
    {
        $options = self::options([]);

        self::assertSame('auto', $options['preload']);
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
        self::assertFalse(self::options(['controls' => false])['controls']);
        self::assertFalse(self::options([], ['controls' => false])['controls']);
        self::assertTrue(self::options([])['controls']);
    }

    #[Test]
    public function aBackgroundVideoTakesOnlyPreloadAndAccentFromTheTheme(): void
    {
        $options = self::options(['autoplay' => true, 'controls' => false, 'loopButton' => true, 'mutedStart' => true], ['controls' => false], true);

        self::assertFalse($options['autoplay']);
        self::assertFalse($options['loop']);
        self::assertFalse($options['muted']);
        self::assertFalse($options['controls']);
        self::assertSame('auto', $options['preload']);
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
    }
}
