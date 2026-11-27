<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Api\EncodingOptions;

final class EncodingOptionsTest extends TestCase
{
    #[Test]
    public function marksLockedAddOnsAsUnavailable(): void
    {
        $options = EncodingOptions::normalize([
            ['key' => 'h264', 'label' => 'H.264', 'access' => 'included', 'available' => true],
            ['key' => 'av1', 'label' => 'AV1', 'access' => 'addon', 'available' => false],
            ['key' => '2160p', 'label' => '4K', 'access' => 'addon', 'available' => true],
        ]);

        self::assertSame(
            [
                ['key' => 'h264', 'label' => 'H.264', 'available' => true, 'addon' => false],
                ['key' => 'av1', 'label' => 'AV1', 'available' => false, 'addon' => true],
                ['key' => '2160p', 'label' => '4K', 'available' => true, 'addon' => true],
            ],
            $options
        );
    }

    #[Test]
    public function toleratesMissingFieldsAndSkipsInvalidEntries(): void
    {
        self::assertSame(
            [['key' => '720p', 'label' => '720p', 'available' => true, 'addon' => false]],
            EncodingOptions::normalize([['key' => '720p'], 'x', ['label' => 'no key'], ['key' => ' ']])
        );
    }
}
