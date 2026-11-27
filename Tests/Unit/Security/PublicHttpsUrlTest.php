<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Security\PublicHttpsUrl;

final class PublicHttpsUrlTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function acceptedProvider(): iterable
    {
        yield 'plain' => ['https://example.com/video.mp4'];
        yield 'query and port' => ['https://cdn.example.com:8443/a/b.mp4?x=1&y=2'];
        yield 'public ip' => ['https://93.184.216.34/v.mp4'];
        yield 'trailing dot' => ['https://example.com./v.mp4'];
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'http' => ['http://example.com/v.mp4'];
        yield 'no scheme' => ['example.com/v.mp4'];
        yield 'userinfo' => ['https://user:pw@example.com/v.mp4'];
        yield 'localhost' => ['https://localhost/v.mp4'];
        yield 'sub localhost' => ['https://app.localhost/v.mp4'];
        yield 'internal tld' => ['https://db.internal/v.mp4'];
        yield 'local tld' => ['https://printer.local/v.mp4'];
        yield 'single label' => ['https://intranet/v.mp4'];
        yield 'private ip' => ['https://10.0.0.5/v.mp4'];
        yield 'loopback ip' => ['https://127.0.0.1/v.mp4'];
        yield 'link local' => ['https://169.254.169.254/latest'];
        yield 'ipv6 loopback' => ['https://[::1]/v.mp4'];
        yield 'decimal ip' => ['https://2130706433/v.mp4'];
        yield 'hex ip' => ['https://0x7f.1/v.mp4'];
        yield 'whitespace' => ["https://example.com/a b.mp4"];
        yield 'control char' => ["https://example.com/a\x01.mp4"];
    }

    #[Test]
    #[DataProvider('acceptedProvider')]
    public function acceptsPublicHttpsUrls(string $url): void
    {
        self::assertTrue(PublicHttpsUrl::isAllowed($url));
    }

    #[Test]
    #[DataProvider('rejectedProvider')]
    public function rejectsEverythingElse(string $url): void
    {
        self::assertFalse(PublicHttpsUrl::isAllowed($url));
    }

    #[Test]
    public function rejectsOverlongUrls(): void
    {
        self::assertFalse(PublicHttpsUrl::isAllowed('https://example.com/' . str_repeat('a', 2100)));
    }
}
