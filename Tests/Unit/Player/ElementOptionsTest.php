<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Player;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Player\ElementOptions;

final class ElementOptionsTest extends TestCase
{
    #[Test]
    public function sideDefaultsToLeftAndOnlyRightIsAccepted(): void
    {
        self::assertSame('left', ElementOptions::side([]));
        self::assertSame('left', ElementOptions::side(['vo_split_side' => 'evil']));
        self::assertSame('right', ElementOptions::side(['vo_split_side' => 'right']));
    }

    #[Test]
    public function textIsStrippedTrimmedAndCut(): void
    {
        self::assertSame('Hello', ElementOptions::text(['vo_eyebrow' => " <b>Hello</b>\n"], 'vo_eyebrow', 80));
        self::assertSame('abc', ElementOptions::text(['vo_eyebrow' => 'abcdef'], 'vo_eyebrow', 3));
        self::assertSame('', ElementOptions::text([], 'vo_eyebrow', 80));
    }

    #[Test]
    public function ctaNeedsLabelAndLink(): void
    {
        self::assertNull(ElementOptions::cta(['vo_cta_label' => 'Go']));
        self::assertNull(ElementOptions::cta(['vo_cta_link' => 't3://page?uid=1']));
        self::assertSame(
            ['label' => 'Go', 'link' => 't3://page?uid=1'],
            ElementOptions::cta(['vo_cta_label' => ' Go ', 'vo_cta_link' => ' t3://page?uid=1 '])
        );
    }

    #[Test]
    public function formatIsOneOfTheKnownRatiosElseOriginal(): void
    {
        self::assertSame('original', ElementOptions::format([]));
        self::assertSame('original', ElementOptions::format(['vo_format' => '7-3']));
        self::assertSame('9-16', ElementOptions::format(['vo_format' => '9-16']));
        self::assertSame('', ElementOptions::aspect('original'));
        self::assertSame('21/9', ElementOptions::aspect('21-9'));
        self::assertSame('4/5', ElementOptions::aspect(ElementOptions::format(['vo_format' => '4-5'])));
        self::assertTrue(ElementOptions::isPortrait('9-16'));
        self::assertTrue(ElementOptions::isPortrait('4-5'));
        self::assertFalse(ElementOptions::isPortrait('1-1'));
    }

    #[Test]
    public function focalPointIsClampedToPercentAndDefaultsToTheCenter(): void
    {
        self::assertSame(['x' => 50, 'y' => 50], ElementOptions::focal([]));
        self::assertSame(['x' => 20, 'y' => 80], ElementOptions::focal(['vo_focus_x' => '20', 'vo_focus_y' => 80]));
        self::assertSame(['x' => 0, 'y' => 100], ElementOptions::focal(['vo_focus_x' => -5, 'vo_focus_y' => 900]));
        self::assertSame(['x' => 50, 'y' => 50], ElementOptions::focal(['vo_focus_x' => 'abc', 'vo_focus_y' => null]));
    }
}
