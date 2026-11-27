<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Api\ApiErrorException;
use ScaleCommerce\VideoOptimizer\Api\NetworkException;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use ScaleCommerce\VideoOptimizer\Controller\AbstractVideoController;
use TYPO3\CMS\Core\Cache\CacheDataCollectorInterface;
use TYPO3\CMS\Core\Cache\CacheTag;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;

/**
 * Embed URL handling, player options and page cache lifetime of the content element controllers.
 */
final class VideoControllerHelpersTest extends TestCase
{
    /** @var list<string> */
    private array $tags = [];

    /** @var list<int> */
    private array $lifetimes = [];

    /**
     * @param array<string, mixed> $record tt_content row of the element
     */
    private function controller(array $record = [], int $ttl = 300, ?array $embed = null): object
    {
        $collector = $this->createStub(CacheDataCollectorInterface::class);
        $collector->method('addCacheTags')->willReturnCallback(function (CacheTag ...$tags): void {
            foreach ($tags as $tag) {
                $this->tags[] = $tag->name;
            }
        });
        $collector->method('restrictMaximumLifetime')->willReturnCallback(function (int $lifetime) use ($collector): CacheDataCollectorInterface {
            $this->lifetimes[] = $lifetime;

            return $collector;
        });
        $request = $this->createStub(RequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => $name === 'frontend.cache.collector' ? $collector : null
        );

        $controller = new class ((new \ReflectionClass(VideoOptimizerClient::class))->newInstanceWithoutConstructor(), $record, $ttl, $embed) extends AbstractVideoController {
            /**
             * @param array<string, mixed> $record
             */
            /**
             * @param array<string, mixed>|null $embed
             */
            public function __construct(VideoOptimizerClient $client, private array $record, private int $ttl, private ?array $embed)
            {
                parent::__construct($client);
            }

            protected function embedConfig(string $uuid): ?array
            {
                return $this->embed;
            }

            protected function prefetchEmbeds(array $uuids): void
            {
            }

            protected function unavailableLabel(): string
            {
                return 'Unavailable';
            }

            protected function currentRecord(): array
            {
                return $this->record;
            }

            protected function cacheTtl(): int
            {
                return $this->ttl;
            }

            public function call(string $method, mixed ...$arguments): mixed
            {
                return $this->$method(...$arguments);
            }
        };
        (new \ReflectionProperty($controller, 'request'))->setValue($controller, $request);

        return $controller;
    }

    #[Test]
    public function pageCacheLifetimeHasAFloorAndShrinksAfterFailures(): void
    {
        $this->controller([], 300)->call('registerPageCache', ['abc-1'], false);
        $this->controller([], 3600)->call('registerPageCache', ['abc-1'], false);
        $this->controller([], 3600)->call('registerPageCache', ['abc-1'], false, true);

        self::assertSame([900, 3600, 60], $this->lifetimes);
    }

    #[Test]
    public function listsCarryTheListsTagAndMalformedIdsAreDropped(): void
    {
        $this->controller()->call('registerPageCache', ['ok-1', 'bad id', '../x'], true, false, ['lib-1', 'lib-1', 'bad/lib']);

        self::assertSame(['vo_video_ok-1', 'vo_library_lib-1', 'vo_lists'], $this->tags);
    }

    #[Test]
    public function embedParamsAreMergedIntoTheValidatedUrl(): void
    {
        $controller = $this->controller();

        self::assertSame(
            'https://embed.videooptimizer.eu/embed/abc?theme=x&autoplay=1&muted=1#t',
            $controller->call('embedUrlWithParams', 'https://embed.videooptimizer.eu/embed/abc?theme=x#t', ['autoplay' => '1', 'muted' => '1'])
        );
        self::assertSame('', $controller->call('embedUrlWithParams', 'https://evil.example.com/embed/abc', ['autoplay' => '1']));
        self::assertSame('', $controller->call('embedUrlWithParams', 'javascript:alert(1)', []));
        self::assertSame('', $controller->call('embedUrlWithParams', '', []));
    }

    #[Test]
    public function embedDataNeedsAValidUrlAndDerivesTheAspectRatio(): void
    {
        $controller = $this->controller();

        self::assertNull($controller->call('buildEmbed', ['embed_url' => 'https://evil.example.com/x']));
        self::assertSame(
            ['src' => 'https://embed.videooptimizer.eu/embed/abc?autoplay=0&controls=1&loop=0&muted=0', 'title' => 'T', 'aspect' => '1920/1080'],
            $controller->call('buildEmbed', ['embed_url' => 'https://embed.videooptimizer.eu/embed/abc', 'title_label' => 'T', 'resolution' => '1920x1080'])
        );
    }

    #[Test]
    public function resolutionsAreParsedStrictly(): void
    {
        $controller = $this->controller();

        self::assertSame('1280/720', $controller->call('aspectRatioFromResolution', ' 1280 x 720 '));
        self::assertSame('', $controller->call('aspectRatioFromResolution', 'big'));
        self::assertSame('', $controller->call('aspectRatioFromResolution', '1920x'));
        self::assertSame(['width' => 640, 'height' => 360], $controller->call('dimensionsFromResolution', '640×360'));
        self::assertNull($controller->call('dimensionsFromResolution', ''));
    }

    #[Test]
    public function playerOptionsComeFromTheContentRecord(): void
    {
        $params = $this->controller([
            'vo_autoplay' => 1, 'vo_loop' => 1, 'vo_muted' => 1, 'vo_controls' => 0,
            'vo_start_time' => 12, 'vo_fit_cover' => 1, 'vo_subtitle_lang' => 'de-CH',
        ])->call('playerQueryParams');

        self::assertSame(['autoplay' => '1', 'controls' => '0', 'loop' => '1', 'muted' => '1', 't' => '12', 'fit' => 'cover', 'cc' => 'de-CH'], $params);
    }

    #[Test]
    public function defaultsShowControlsWithoutAutoplay(): void
    {
        self::assertSame(
            ['autoplay' => '0', 'controls' => '1', 'loop' => '0', 'muted' => '0'],
            $this->controller([])->call('playerQueryParams')
        );
    }

    #[Test]
    public function subtitleLanguagesAcceptPrivateUseSubtags(): void
    {
        self::assertSame('de-x-simple', $this->controller(['vo_subtitle_lang' => 'de-x-simple'])->call('playerQueryParams')['cc']);
        self::assertSame('off', $this->controller(['vo_subtitle_lang' => 'off'])->call('playerQueryParams')['cc']);
        self::assertArrayNotHasKey('cc', $this->controller(['vo_subtitle_lang' => 'de_DE'])->call('playerQueryParams'));
    }

    #[Test]
    public function invalidSubtitleLanguagesAndStartTimesAreIgnored(): void
    {
        $params = $this->controller(['vo_subtitle_lang' => '"><script>', 'vo_start_time' => -5])->call('playerQueryParams');

        self::assertArrayNotHasKey('cc', $params);
        self::assertArrayNotHasKey('t', $params);
    }

    #[Test]
    public function aUntouchedHeroPlaysAsBackgroundVideo(): void
    {
        self::assertSame(
            ['autoplay' => '1', 'controls' => '0', 'loop' => '1', 'muted' => '1', 'fit' => 'cover'],
            $this->controller([])->call('playerQueryParams', true)
        );
        self::assertSame(
            ['autoplay' => '0', 'controls' => '0', 'loop' => '0', 'muted' => '0'],
            $this->controller(['vo_controls' => 0])->call('playerQueryParams', true),
            'a configured hero keeps its own options'
        );
    }

    #[Test]
    public function lightboxPlaysWithSoundAfterTheClick(): void
    {
        $params = $this->controller(['vo_muted' => 1])->call('lightboxQueryParams');

        self::assertSame('1', $params['autoplay']);
        self::assertSame('0', $params['muted']);
    }

    #[Test]
    public function formatsDurations(): void
    {
        $controller = $this->controller();

        self::assertSame('1:05', $controller->call('formatDuration', 65));
        self::assertSame('1:01:01', $controller->call('formatDuration', 3661));
        self::assertSame('', $controller->call('formatDuration', 0));
        self::assertSame('', $controller->call('formatDuration', null));
    }

    #[Test]
    public function shapedVideosOnlyKeepValidatedUrlsAndFlagProcessing(): void
    {
        $shaped = $this->controller()->call('shapeVideos', [[
            'uuid' => 'abcdef12-0000-0000-0000-000000000000',
            'title' => ' Clip ',
            'duration' => 65,
            'resolution' => '1920x1080',
            'poster_url' => 'https://evil.example.com/p.jpg',
            'thumbnail_url' => 'https://poster.videooptimizer.eu/t.jpg',
            'embed_url' => 'https://embed.videooptimizer.eu/embed/abc',
            'ready' => false,
        ]]);

        self::assertSame('Clip', $shaped[0]['title_label']);
        self::assertSame('1:05', $shaped[0]['duration_label']);
        self::assertSame('https://poster.videooptimizer.eu/t.jpg', $shaped[0]['poster_src']);
        self::assertTrue($shaped[0]['processing']);
        self::assertSame(['width' => 1920, 'height' => 1080], $shaped[0]['dimensions']);
        self::assertStringStartsWith('https://embed.videooptimizer.eu/embed/abc?', $shaped[0]['lightbox_url']);
    }

    private const EMBED = [
        'sources' => [
            ['src' => 'https://l.cdn.videooptimizer.eu/h/master.m3u8', 'type' => 'application/vnd.apple.mpegurl', 'label' => 'auto'],
            ['src' => 'https://l.cdn.videooptimizer.eu/h/720p.mp4', 'type' => 'video/mp4', 'label' => '720p'],
        ],
        'poster' => 'https://poster.videooptimizer.eu/l/h/thumbnail.jpg',
    ];

    private const VIDEO = [
        'uuid' => 'abcdef12-0000-0000-0000-000000000000',
        'title_label' => 'Clip',
        'processing' => false,
        'resolution' => '1920x1080',
        'poster_src' => 'https://poster.videooptimizer.eu/t.jpg',
        'embed_url' => 'https://player.videooptimizer.eu/embed/abc',
        'lightbox_url' => 'https://player.videooptimizer.eu/embed/abc?autoplay=1',
    ];

    #[Test]
    public function playerModeAndPresentationDefaultToHostedAndDirect(): void
    {
        self::assertSame('hosted', $this->controller([])->call('playerMode'));
        self::assertSame('hosted', $this->controller(['vo_player_mode' => 'bogus'])->call('playerMode'));
        self::assertSame('native', $this->controller(['vo_player_mode' => 'native'])->call('playerMode'));
        self::assertSame('direct', $this->controller([])->call('presentation'));
        self::assertSame('direct', $this->controller(['vo_presentation' => '"><x'])->call('presentation'));
        self::assertSame('facade', $this->controller(['vo_presentation' => 'facade'])->call('presentation'));
        self::assertSame('lightbox', $this->controller(['vo_presentation' => 'lightbox'])->call('presentation'));
    }

    #[Test]
    public function hostedElementsNeverBuildNativeSettings(): void
    {
        self::assertNull($this->controller([], 300, self::EMBED)->call('buildNative', self::VIDEO));
    }

    #[Test]
    public function nativeElementsBuildSettingsFromTheEmbedConfig(): void
    {
        $native = $this->controller(['vo_player_mode' => 'native', 'vo_loop' => 1], 300, self::EMBED)->call('buildNative', self::VIDEO);

        self::assertSame('https://l.cdn.videooptimizer.eu/h/master.m3u8', $native['hls']);
        self::assertSame('https://l.cdn.videooptimizer.eu/h/720p.mp4', $native['mp4']);
        self::assertTrue($native['options']['loop']);
        self::assertSame('Clip', $native['options']['title']);
        self::assertSame('Unavailable', $native['options']['unavailable']);
    }

    #[Test]
    public function nativeFallsBackToHostedWhenTheEmbedConfigIsMissingOrTheVideoStillProcesses(): void
    {
        $controller = $this->controller(['vo_player_mode' => 'native'], 300, null);
        self::assertNull($controller->call('buildNative', self::VIDEO));
        $surface = $controller->call('buildSurface', self::VIDEO);
        self::assertNull($surface['native']);
        self::assertNotNull($surface['embed']);

        self::assertNull($this->controller(['vo_player_mode' => 'native'], 300, self::EMBED)
            ->call('buildNative', ['processing' => true] + self::VIDEO));
    }

    #[Test]
    public function theHeroUsesBackgroundSettingsAndTheLightboxCtaClickToPlaySettings(): void
    {
        $controller = $this->controller(['vo_player_mode' => 'native'], 300, self::EMBED);

        $hero = $controller->call('buildNative', self::VIDEO, true);
        self::assertFalse($hero['options']['controls']);
        self::assertTrue($hero['options']['autoplay']);
        self::assertTrue($hero['options']['cover']);

        $lightbox = $controller->call('buildNative', self::VIDEO, false);
        self::assertTrue($lightbox['options']['controls']);
        self::assertFalse($lightbox['options']['autoplay']);
    }

    #[Test]
    public function surfaceCarriesPresentationAspectAndHostedClickUrl(): void
    {
        $surface = $this->controller(['vo_presentation' => 'facade'])->call('buildSurface', self::VIDEO);

        self::assertSame('facade', $surface['presentation']);
        self::assertSame('1920/1080', $surface['aspect']);
        self::assertSame('https://player.videooptimizer.eu/embed/abc?autoplay=1', $surface['clickSrc']);
        self::assertSame('https://poster.videooptimizer.eu/t.jpg', $surface['poster']);
        self::assertNull($surface['native']);
    }

    #[Test]
    public function nativeCardsGetSettingsOnlyForNativeElements(): void
    {
        $videos = [self::VIDEO];

        self::assertArrayNotHasKey('native_json', $this->controller([])->call('withNativeCards', $videos)[0]);

        $cards = $this->controller(['vo_player_mode' => 'native'], 300, self::EMBED)->call('withNativeCards', $videos);
        self::assertStringContainsString('"hls":"https:\/\/', str_replace('/', '\/', $cards[0]['native_json']));

        $missing = $this->controller(['vo_player_mode' => 'native'], 300, null)->call('withNativeCards', $videos);
        self::assertSame('', $missing[0]['native_json']);
    }

    #[Test]
    public function jsonLdUsesTheEmbedUploadDateWhenLoadedAndCreatedAtOtherwise(): void
    {
        $controller = $this->controller();
        $video = self::VIDEO + ['created_at' => '2026-01-02T03:04:05+00:00', 'ready' => true];

        self::assertSame('2026-01-02T03:04:05+00:00', $controller->call('videoObjectData', $video, '')['uploadDate']);

        $controller->call('rememberUploadDate', self::VIDEO['uuid'], ['uploadDate' => '2025-12-01T10:00:00Z']);
        self::assertSame('2025-12-01T10:00:00+00:00', $controller->call('videoObjectData', $video, '')['uploadDate']);

        $other = $this->controller();
        $other->call('rememberUploadDate', self::VIDEO['uuid'], ['uploadDate' => null]);
        self::assertSame('2026-01-02T03:04:05+00:00', $other->call('videoObjectData', $video, '')['uploadDate']);
    }

    #[Test]
    public function facadePostersCarryTheSrcsetOnlyBehindAClick(): void
    {
        $embed = self::EMBED + ['posterSrcset' => [['width' => 480, 'url' => 'https://poster.videooptimizer.eu/p/poster_480.jpg']]];

        $facade = $this->controller(['vo_presentation' => 'facade'], 300, $embed)->call('buildSurface', self::VIDEO);
        self::assertSame('https://poster.videooptimizer.eu/p/poster_480.jpg 480w', $facade['posterSrcset']);
        self::assertNotSame('', $facade['posterSizes']);

        self::assertSame('', $this->controller([], 300, $embed)->call('buildSurface', self::VIDEO)['posterSrcset']);
        self::assertSame('', $this->controller(['vo_presentation' => 'facade'], 300, null)->call('buildSurface', self::VIDEO)['posterSrcset']);
    }

    #[Test]
    public function aFormatSetsTheAspectRatioFocalPointAndCoverFit(): void
    {
        $record = ['vo_format' => '9-16', 'vo_focus_x' => 30, 'vo_focus_y' => 70, 'vo_player_mode' => 'native'];
        $surface = $this->controller($record, 300, self::EMBED)->call('buildSurface', self::VIDEO);

        self::assertSame('9/16', $surface['aspect']);
        self::assertStringContainsString('aspect-ratio: 9/16', $surface['style']);
        self::assertStringContainsString('--vo-focus: 30% 70%', $surface['style']);
        self::assertTrue($surface['native']['options']['cover']);
        self::assertStringContainsString('vo-media--portrait', $surface['media']['class']);

        $hosted = $this->controller(['vo_format' => '1-1'])->call('buildSurface', self::VIDEO);
        self::assertSame('1/1', $hosted['embed']['aspect']);
        self::assertStringContainsString('fit=cover', $hosted['embed']['src']);
        self::assertStringNotContainsString('portrait', $hosted['media']['class']);
    }

    #[Test]
    public function theOriginalFormatKeepsTheVideoRatioAndTheMarkup(): void
    {
        $surface = $this->controller([])->call('buildSurface', self::VIDEO);

        self::assertSame('1920/1080', $surface['aspect']);
        self::assertNull($surface['media']);
        self::assertStringNotContainsString('--vo-focus', $surface['style']);
    }

    #[Test]
    public function aLightboxKeepsTheVideoUncroppedAndOnlyTheFacadeTakesTheFormat(): void
    {
        $record = ['vo_format' => '9-16', 'vo_presentation' => 'lightbox', 'vo_player_mode' => 'native'];
        $surface = $this->controller($record, 300, self::EMBED)->call('buildSurface', self::VIDEO);

        self::assertSame('9/16', $surface['aspect']);
        self::assertFalse($surface['native']['options']['cover']);
        self::assertStringNotContainsString('fit=cover', $surface['clickSrc']);
    }

    /**
     * @param list<\Throwable|array<string, mixed>> $results one entry per loadEmbed() call
     */
    private function brokenEmbedController(array $results): object
    {
        $controller = new class ((new \ReflectionClass(VideoOptimizerClient::class))->newInstanceWithoutConstructor()) extends AbstractVideoController {
            /** @var list<\Throwable|array<string, mixed>> */
            public array $results = [];
            /** @var list<string> */
            public array $calls = [];
            /** @var list<list<string>> */
            public array $prefetched = [];
            public int $degraded = 0;

            protected function currentRecord(): array
            {
                return ['vo_player_mode' => 'native'];
            }

            protected function loadEmbed(string $uuid): array
            {
                $this->calls[] = $uuid;
                $result = array_shift($this->results) ?? [];
                if ($result instanceof \Throwable) {
                    throw $result;
                }

                return $result;
            }

            protected function prefetchEmbeds(array $uuids): void
            {
                $this->prefetched[] = $uuids;
            }

            protected function degradePageCache(): void
            {
                $this->degraded++;
            }

            protected function unavailableLabel(): string
            {
                return '';
            }

            public function call(string $method, mixed ...$arguments): mixed
            {
                return $this->$method(...$arguments);
            }

            /**
             * @return array{calls: list<string>, prefetched: list<list<string>>, degraded: int}
             */
            public function state(): array
            {
                return ['calls' => $this->calls, 'prefetched' => $this->prefetched, 'degraded' => $this->degraded];
            }
        };
        $controller->results = $results;

        return $controller;
    }

    #[Test]
    public function aFailedEmbedLookupFallsBackToHostedAndShortensThePageCache(): void
    {
        $controller = $this->brokenEmbedController([new NetworkException('down'), self::EMBED]);

        self::assertNull($controller->call('embedConfig', 'a'));
        self::assertSame(1, $controller->state()['degraded']);

        self::assertNotNull($controller->call('embedConfig', 'b'), 'a later (cached) lookup is not blocked by the earlier failure');
        self::assertSame(1, $controller->state()['degraded']);
        self::assertSame(['a', 'b'], $controller->state()['calls']);
    }

    #[Test]
    public function aMissingVideoIsNotADegradedPage(): void
    {
        $controller = $this->brokenEmbedController([[]]);

        self::assertNull($controller->call('embedConfig', 'a'));
        self::assertSame(0, $controller->state()['degraded']);
    }

    #[Test]
    public function theDegradedPageLifetimeIsCappedAtSixtySeconds(): void
    {
        $controller = $this->controller([], 3600);
        $controller->call('degradePageCache');
        $controller->call('registerPageCache', ['abc-1'], false);

        self::assertSame([60, 3600], $this->lifetimes, 'the collector keeps the smaller value');
    }

    #[Test]
    public function nativeCardsPrefetchOnceAndOnlyTheFailedOnesFallBackToHosted(): void
    {
        $controller = $this->brokenEmbedController([new NetworkException('down'), self::EMBED, self::EMBED]);
        $videos = [
            ['uuid' => 'a', 'processing' => false, 'title_label' => 'A'],
            ['uuid' => 'b', 'processing' => true, 'title_label' => 'B'],
            ['uuid' => 'c', 'processing' => false, 'title_label' => 'C'],
            ['uuid' => 'd', 'processing' => false, 'title_label' => 'D'],
        ];

        $cards = $controller->call('withNativeCards', $videos);

        self::assertSame([['a', 'c', 'd']], $controller->state()['prefetched']);
        self::assertSame('', $cards[0]['native_json'], 'the failed card falls back to hosted');
        self::assertSame('', $cards[1]['native_json'], 'processing');
        self::assertStringContainsString('master.m3u8', $cards[2]['native_json']);
        self::assertStringContainsString('master.m3u8', $cards[3]['native_json'], 'cached cards stay native');
        self::assertSame(1, $controller->state()['degraded']);
    }
}
