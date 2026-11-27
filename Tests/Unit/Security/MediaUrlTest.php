<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Security\MediaUrl;

final class MediaUrlTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function acceptedProvider(): iterable
    {
        yield 'apex' => ['https://videooptimizer.eu/x.jpg'];
        yield 'subdomain' => ['https://poster.videooptimizer.eu/a/b.jpg?w=480'];
        yield 'deep subdomain' => ['https://lib.cdn.videooptimizer.eu/embed/abc'];
        yield 'explicit 443' => ['https://api.videooptimizer.eu:443/x'];
        yield 'upper case host' => ['https://CDN.VideoOptimizer.EU/x'];
    }

    /** @return iterable<string, array{mixed}> */
    public static function rejectedProvider(): iterable
    {
        yield 'http' => ['http://poster.videooptimizer.eu/x.jpg'];
        yield 'other host' => ['https://evil.example.com/x.jpg'];
        yield 'suffix trick' => ['https://notvideooptimizer.eu/x.jpg'];
        yield 'prefix trick' => ['https://videooptimizer.eu.evil.com/x.jpg'];
        yield 'userinfo' => ['https://user:pw@poster.videooptimizer.eu/x'];
        yield 'other port' => ['https://poster.videooptimizer.eu:8443/x'];
        yield 'backslash host trick' => ['https://evil.com\\.videooptimizer.eu/x'];
        yield 'whitespace' => ['https://poster.videooptimizer.eu/a b'];
        yield 'quote' => ['https://poster.videooptimizer.eu/a"onerror="x'];
        yield 'angle bracket' => ['https://poster.videooptimizer.eu/<x>'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'protocol relative' => ['//poster.videooptimizer.eu/x'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'array' => [['https://poster.videooptimizer.eu/x']];
    }

    #[Test]
    #[DataProvider('acceptedProvider')]
    public function acceptsPlatformUrls(string $url): void
    {
        self::assertSame($url, MediaUrl::safe($url));
    }

    #[Test]
    #[DataProvider('rejectedProvider')]
    public function rejectsEverythingElse(mixed $url): void
    {
        self::assertSame('', MediaUrl::safe($url));
    }

    #[Test]
    public function rejectsOverlongUrls(): void
    {
        self::assertSame('', MediaUrl::safe('https://poster.videooptimizer.eu/' . str_repeat('a', 2100)));
    }
}
