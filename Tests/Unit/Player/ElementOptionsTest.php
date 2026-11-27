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
}
