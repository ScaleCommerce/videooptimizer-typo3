<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Api\UserAgent;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\Site;

final class UserAgentTest extends TestCase
{
    /**
     * The regex the VideoOptimizer platform uses to parse the header (kept in sync on purpose).
     */
    private const PLATFORM_PATTERN = '#^VideoOptimizer-TYPO3/([^\s(]+) \(TYPO3 ([^;()]+); PHP ([^;()]+)(?:; \+([^;()]+))?\)$#';

    /**
     * @return list<string> extension version, TYPO3 version, PHP version, site URL ('' when omitted)
     */
    private static function parse(string $header): array
    {
        self::assertSame(1, preg_match(self::PLATFORM_PATTERN, $header, $matches), $header);

        return [$matches[1] ?? '', $matches[2] ?? '', $matches[3] ?? '', $matches[4] ?? ''];
    }

    #[Test]
    public function formatsAllParts(): void
    {
        self::assertSame(
            'VideoOptimizer-TYPO3/1.0.3 (TYPO3 13.4.2; PHP 8.3.14; +https://www.example.com)',
            UserAgent::format('1.0.3', '13.4.2', '8.3.14', 'https://www.example.com')
        );
        self::assertSame(
            ['1.0.3', '13.4.2', '8.3.14', 'https://www.example.com'],
            self::parse(UserAgent::format('1.0.3', '13.4.2', '8.3.14', 'https://www.example.com'))
        );
        self::assertSame(['1.0.3', '14.3.12', '8.5.1', ''], self::parse(UserAgent::format('1.0.3', '14.3.12', '8.5.1')));
    }

    #[Test]
    public function omitsTheSiteUrlPartWhenEmpty(): void
    {
        self::assertSame(
            'VideoOptimizer-TYPO3/1.0.3 (TYPO3 14.3.12; PHP 8.5.1)',
            UserAgent::format('1.0.3', '14.3.12', '8.5.1')
        );
        self::assertSame(
            'VideoOptimizer-TYPO3/1.0.3 (TYPO3 14.3.12; PHP 8.5.1)',
            UserAgent::format('1.0.3', '14.3.12', '8.5.1', " ;()\n")
        );
    }

    #[Test]
    public function fallsBackToUnknownForEmptyVersions(): void
    {
        self::assertSame(
            'VideoOptimizer-TYPO3/unknown (TYPO3 unknown; PHP unknown)',
            UserAgent::format('', '', '')
        );
    }

    #[Test]
    public function sanitizesEveryPart(): void
    {
        $header = UserAgent::format("1.0.3;(x)\r\n", "14.3;\x00", 'PHP)8', "https://evil.example/;) (TYPO3 1; PHP 1\r\nX-Injected: 1");

        self::assertStringNotContainsString("\r", $header);
        self::assertStringNotContainsString("\n", $header);
        self::assertStringNotContainsString("\x00", $header);
        self::assertSame(
            ['1.0.3x', '14.3', 'PHP 8', 'https://evil.example/ TYPO3 1 PHP 1 X-Injected: 1'],
            self::parse($header)
        );
    }

    #[Test]
    public function capsEachPartAt100Characters(): void
    {
        $header = UserAgent::format('1.0.3', '14.3.12', '8.5.1', 'https://example.com/' . str_repeat('a', 300));

        self::assertSame(100, strlen(self::parse($header)[3]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function versionProvider(): array
    {
        return [
            'plain' => ['1.0.3', '1.0.3'],
            'git tag' => ['v1.0.3', '1.0.3'],
            'TYPO3 stability suffix' => ['1.0.3-stable', '1.0.3'],
            'beta stays' => ['1.1.0-beta', '1.1.0-beta'],
            'branch' => ['dev-main', 'dev-main'],
        ];
    }

    #[Test]
    #[DataProvider('versionProvider')]
    public function normalizesTheExtensionVersion(string $input, string $expected): void
    {
        self::assertSame($expected, UserAgent::normalizeVersion($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function siteUrlProvider(): array
    {
        return [
            'trailing slash removed' => ['https://www.example.com/', 'https://www.example.com'],
            'sub path and port kept' => ['http://Example.com:8080/typo3site/', 'http://example.com:8080/typo3site'],
            'credentials, query, fragment dropped' => ['https://user:pw@example.com/?a=1#x', 'https://example.com'],
            'relative base' => ['/', ''],
            'other scheme' => ['ftp://example.com', ''],
            'empty' => ['', ''],
        ];
    }

    #[Test]
    #[DataProvider('siteUrlProvider')]
    public function normalizesTheSiteUrl(string $input, string $expected): void
    {
        self::assertSame($expected, UserAgent::normalizeSiteUrl($input));
    }

    #[Test]
    public function usesTheBaseOfTheRequestSite(): void
    {
        $request = (new ServerRequest('https://www.example.com/page'))
            ->withAttribute('site', new Site('main', 1, ['base' => 'https://www.example.com/']));

        self::assertSame('https://www.example.com', UserAgent::siteUrlFromRequest($request));
    }

    #[Test]
    public function fallsBackToTheNormalizedSiteUrl(): void
    {
        $normalizedParams = $this->createStub(NormalizedParams::class);
        $normalizedParams->method('getSiteUrl')->willReturn('https://backend.example.com/');
        $request = (new ServerRequest('https://backend.example.com/typo3/'))
            ->withAttribute('site', new Site('main', 1, ['base' => '/']))
            ->withAttribute('normalizedParams', $normalizedParams);

        self::assertSame('https://backend.example.com', UserAgent::siteUrlFromRequest($request));
    }

    #[Test]
    public function returnsNothingWithoutSiteOrNormalizedParams(): void
    {
        self::assertSame('', UserAgent::siteUrlFromRequest(new ServerRequest('https://example.com/')));
    }
}
