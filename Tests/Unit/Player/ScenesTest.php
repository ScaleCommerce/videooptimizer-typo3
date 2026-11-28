<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Player;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Player\Scenes;

final class ScenesTest extends TestCase
{
    private const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';

    #[Test]
    public function indicesAreSpreadEvenlyOverTheTenThumbnails(): void
    {
        self::assertSame([0, 9], Scenes::indices(2));
        self::assertSame([0, 5, 9], Scenes::indices(3));
        self::assertSame([0, 2, 5, 7, 9], Scenes::indices(5));
        self::assertSame([0, 1, 2, 3, 4, 5, 6, 7, 8, 9], Scenes::indices(10));
        self::assertSame(Scenes::indices(10), Scenes::indices(50));
    }

    #[Test]
    public function startTimeIsHowTheEncoderSamplesTheFrames(): void
    {
        self::assertSame(5, Scenes::start(60, 0));
        self::assertSame(54, Scenes::start(60, 9));
        self::assertSame(10, Scenes::start(120, 0));
        // The float duration is not rounded first: 65.9 / 11 = 5.99 (rounding to 66 would give 6).
        self::assertSame(5, Scenes::start(65.9, 0));
        self::assertSame('0:05', Scenes::build(self::UUID, 65.9, 3, [], 'Scene %d')[0]['time']);
    }

    #[Test]
    public function timesAreFormattedWithAndWithoutHours(): void
    {
        self::assertSame('0:05', Scenes::formatTime(5));
        self::assertSame('1:05', Scenes::formatTime(65));
        self::assertSame('1:01:05', Scenes::formatTime(3665));
    }

    #[Test]
    public function buildMakesScenesWithThumbnailsTimesAndLabels(): void
    {
        $scenes = Scenes::build(self::UUID, 110, 3, ['Intro', '', 'x'], 'Scene %d');

        self::assertCount(3, $scenes);
        self::assertSame(0, $scenes[0]['index']);
        self::assertSame(10, $scenes[0]['start']);
        self::assertSame('0:10', $scenes[0]['time']);
        self::assertSame('https://api.videooptimizer.eu/api/v1/videos/' . self::UUID . '/thumbnails/0', $scenes[0]['thumb']);
        self::assertSame('Intro', $scenes[0]['label']);
        self::assertSame('Intro · 0:10', $scenes[0]['aria']);
        self::assertSame('Scene 2', $scenes[1]['label']);
        self::assertSame('Scene 2 · 1:00', $scenes[1]['aria']);
        self::assertSame('x', $scenes[2]['label']);
    }

    #[Test]
    public function noScenesWithoutADurationOrWithAnInvalidUuid(): void
    {
        self::assertSame([], Scenes::build(self::UUID, 0, 5, [], 'Scene %d'));
        self::assertSame([], Scenes::build('../x', 100, 5, [], 'Scene %d'));
    }

    #[Test]
    public function sceneCountAndTitlesAreValidated(): void
    {
        self::assertSame(5, Scenes::count([]));
        self::assertSame(3, Scenes::count(['vo_scene_count' => 1]));
        self::assertSame(10, Scenes::count(['vo_scene_count' => 99]));
        self::assertSame(7, Scenes::count(['vo_scene_count' => '7']));
        self::assertSame(['A', 'B'], Scenes::titles(['vo_scene_titles' => "A\r\n<b>B</b>\n"]));
        self::assertCount(10, Scenes::titles(['vo_scene_titles' => str_repeat("x\n", 30)]));
    }
}
