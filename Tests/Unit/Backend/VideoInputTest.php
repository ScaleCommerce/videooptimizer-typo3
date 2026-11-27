<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Backend\Input\VideoInput;

final class VideoInputTest extends TestCase
{
    #[Test]
    public function parsesTagsUniquelyAndCapped(): void
    {
        self::assertSame(['Sale', 'new'], VideoInput::parseTags(' Sale, sale ,new,, '));
        self::assertSame([], VideoInput::parseTags(str_repeat('x', 51)));
        self::assertCount(20, VideoInput::parseTags(implode(',', range(1, 40))));
    }

    #[Test]
    public function parsesMetadataLines(): void
    {
        $input = "Campaign: summer-2026\r\ncms.page=42\nnot a line\n: novalue\nempty:\nBad Key: x\n";

        self::assertSame(['campaign' => 'summer-2026', 'cms.page' => '42'], VideoInput::parseMetadata($input));
    }

    #[Test]
    public function metadataLimitsAreEnforced(): void
    {
        $lines = [];
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = 'k' . $i . ': v';
        }
        $result = VideoInput::parseMetadataDetailed(implode("\n", $lines));
        self::assertCount(10, $result['metadata']);
        self::assertSame(['k11: v', 'k12: v'], $result['invalid'], 'keys beyond the API limit are reported');
        self::assertSame([], VideoInput::parseMetadataDetailed('a: ' . str_repeat('ä', 64))['invalid']);
        self::assertSame(['a: ' . str_repeat('ä', 57)], VideoInput::parseMetadataDetailed('a: ' . str_repeat('ä', 65))['invalid'], 'overlong values are reported, not cut');
    }

    #[Test]
    public function invalidLinesAreReported(): void
    {
        $result = VideoInput::parseMetadataDetailed("ok: 1\nnot a line\n: novalue\nempty:\nBad Key: x");

        self::assertSame(['ok' => '1'], $result['metadata']);
        self::assertSame(['not a line', ': novalue', 'empty:', 'Bad Key: x'], $result['invalid']);
    }

    #[Test]
    public function theFirstSeparatorWins(): void
    {
        self::assertSame(['url' => 'https://example.com/a?b=c'], VideoInput::parseMetadata('url=https://example.com/a?b=c'));
        self::assertSame(['note' => 'a=b'], VideoInput::parseMetadata('note: a=b'));
        self::assertSame(['k' => 'v:w'], VideoInput::parseMetadata('K = v:w'));
    }

    #[Test]
    public function formatsMetadataForTheEditor(): void
    {
        self::assertSame("a: 1\nb: two", VideoInput::metadataToLines(['a' => '1', 'b' => 'two', 'c' => '']));
        self::assertSame('', VideoInput::metadataToLines([]));
    }

    #[Test]
    public function patchSetsChangedAndRemovesMissingKeys(): void
    {
        $current = ['keep' => '1', 'change' => 'old', 'drop' => 'x'];
        $new = ['keep' => '1', 'change' => 'new', 'add' => 'y'];

        self::assertSame(
            ['change' => 'new', 'add' => 'y', 'drop' => null],
            VideoInput::metadataPatch($current, $new)
        );
    }

    #[Test]
    public function patchIsEmptyWhenNothingChanged(): void
    {
        self::assertSame([], VideoInput::metadataPatch(['a' => '1'], ['a' => '1']));
        self::assertSame([], VideoInput::metadataPatch([], []));
    }

    #[Test]
    public function patchClearsEverythingForEmptyInput(): void
    {
        self::assertSame(['a' => null, 'b' => null], VideoInput::metadataPatch(['a' => '1', 'b' => '2'], []));
    }
}
