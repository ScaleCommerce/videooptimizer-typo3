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

    #[Test]
    public function theMiniPlayerIsAnExplicitOptIn(): void
    {
        self::assertFalse(ElementOptions::miniPlayer([]));
        self::assertFalse(ElementOptions::miniPlayer(['vo_mini_player' => 0]));
        self::assertTrue(ElementOptions::miniPlayer(['vo_mini_player' => '1']));
    }

    #[Test]
    public function theHeadlineFollowsTheHeaderPaletteOfTheCoreHeaderPartial(): void
    {
        self::assertNull(ElementOptions::headline([]));
        self::assertNull(ElementOptions::headline(['header' => 'Hi', 'subheader' => 'Sub', 'header_layout' => 100]), 'hidden');

        $default = ElementOptions::headline(['header' => 'Hi', 'subheader' => 'Sub', 'header_layout' => 0]);
        self::assertSame(['text' => 'Hi', 'level' => 2, 'link' => '', 'position' => ''], $default['header']);
        self::assertSame(['text' => 'Sub', 'level' => 3], $default['sub']);

        $h1 = ElementOptions::headline(['header' => 'Hi', 'header_layout' => 1, 'header_link' => 't3://page?uid=2', 'header_position' => 'center', 'subheader' => 'S']);
        self::assertSame(1, $h1['header']['level']);
        self::assertSame('t3://page?uid=2', $h1['header']['link']);
        self::assertSame('center', $h1['header']['position']);
        self::assertSame(2, $h1['sub']['level']);

        $h6 = ElementOptions::headline(['header' => 'Hi', 'header_layout' => 6, 'subheader' => 'S']);
        self::assertSame(6, $h6['header']['level']);
        self::assertSame(3, $h6['sub']['level'], 'like the core: a sub header of an h6 uses the default');

        self::assertNull(ElementOptions::headline(['header' => 'Hi'])['sub']);
        self::assertSame('', ElementOptions::headline(['header' => 'Hi', 'header_position' => 'evil'])['header']['position']);
    }
}
