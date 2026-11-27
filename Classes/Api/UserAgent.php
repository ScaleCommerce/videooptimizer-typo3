<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Api;

use Composer\InstalledVersions;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Builds the User-Agent the extension sends to the VideoOptimizer API, so the platform can show which
 * extension, TYPO3 and PHP versions an organization runs:
 *
 *   VideoOptimizer-TYPO3/1.0.3 (TYPO3 14.3.12; PHP 8.5.1; +https://www.example.com)
 *
 * The platform parses this with a regex — keep the format stable.
 */
final class UserAgent
{
    public const PRODUCT = 'VideoOptimizer-TYPO3';
    private const EXTENSION_KEY = 'videooptimizer';
    private const PACKAGE_NAME = 'scalecommerce/videooptimizer-typo3';
    private const MAX_PART_LENGTH = 100;

    /** @var array{0: string, 1: string, 2: string}|null extension, TYPO3 and PHP version (fixed per process) */
    private ?array $versions = null;
    /** Site URL resolved without a request (CLI/scheduler), looked up once. */
    private ?string $fallbackSiteUrl = null;

    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {
    }

    /**
     * The header value for the current context (request site or first configured site).
     */
    public function get(): string
    {
        $this->versions ??= [self::extensionVersion(), (new Typo3Version())->getVersion(), PHP_VERSION];

        [$extensionVersion, $typo3Version, $phpVersion] = $this->versions;

        return self::format($extensionVersion, $typo3Version, $phpVersion, $this->siteUrl());
    }

    /**
     * Pure formatter (unit-tested): every value is sanitized, an empty site URL drops the `; +…` part.
     */
    public static function format(string $extensionVersion, string $typo3Version, string $phpVersion, string $siteUrl = ''): string
    {
        $comment = [
            'TYPO3 ' . self::sanitize($typo3Version, 'unknown'),
            'PHP ' . self::sanitize($phpVersion, 'unknown'),
        ];
        $siteUrl = self::sanitize($siteUrl);
        if ($siteUrl !== '') {
            $comment[] = '+' . $siteUrl;
        }

        // The product version is a single token: version characters only, no spaces.
        $version = (string)preg_replace('/[^0-9A-Za-z.+\-]+/', '', self::normalizeVersion($extensionVersion));

        return self::PRODUCT . '/' . self::sanitize($version, 'unknown') . ' (' . implode('; ', $comment) . ')';
    }

    /**
     * Removes control characters, `;`, `(`, `)` and whitespace runs, caps the length.
     */
    public static function sanitize(string $value, string $fallback = ''): string
    {
        $value = (string)preg_replace('/[\x{00}-\x{1F}\x{7F}-\x{9F};()]+/u', ' ', $value);
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        if (mb_strlen($value) > self::MAX_PART_LENGTH) {
            $value = rtrim(mb_substr($value, 0, self::MAX_PART_LENGTH));
        }

        return $value !== '' ? $value : $fallback;
    }

    /**
     * `v1.0.3` → `1.0.3`; TYPO3's stability suffix `1.0.3-stable` → `1.0.3`.
     */
    public static function normalizeVersion(string $version): string
    {
        $version = trim($version);
        $version = (string)preg_replace('/^v(?=\d)/i', '', $version);

        return (string)preg_replace('/[-+]stable$/i', '', $version);
    }

    /**
     * Absolute http(s) URL reduced to scheme, host, port and path (no credentials, query or fragment),
     * without a trailing slash. Anything else → ''.
     */
    public static function normalizeSiteUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true) || $parts['host'] === '') {
            return '';
        }

        return rtrim(
            $scheme . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? ''),
            '/'
        );
    }

    /**
     * Base URL of the current request's site; else the request's site URL (e.g. backend);
     * without a request (CLI) the base of the first site with an absolute base.
     */
    public static function siteUrlFromRequest(ServerRequestInterface $request): string
    {
        $site = $request->getAttribute('site');
        if ($site instanceof Site) {
            $url = self::normalizeSiteUrl((string)$site->getBase());
            if ($url !== '') {
                return $url;
            }
        }
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return self::normalizeSiteUrl($normalizedParams->getSiteUrl());
        }

        return '';
    }

    private function siteUrl(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $url = self::siteUrlFromRequest($request);
            if ($url !== '') {
                return $url;
            }
        }

        if ($this->fallbackSiteUrl === null) {
            $this->fallbackSiteUrl = '';
            try {
                foreach ($this->siteFinder->getAllSites() as $site) {
                    $url = self::normalizeSiteUrl((string)$site->getBase());
                    if ($url !== '') {
                        $this->fallbackSiteUrl = $url;
                        break;
                    }
                }
            } catch (\Throwable) {
                // No site configuration available: send the header without a site URL.
            }
        }

        return $this->fallbackSiteUrl;
    }

    private static function extensionVersion(): string
    {
        // Composer mode: the installed package version (`v1.0.3` for a tag). Branch installs
        // (`dev-main`) carry no number → fall back to the version TYPO3 knows (ext_emconf.php).
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
                $version = self::normalizeVersion((string)InstalledVersions::getPrettyVersion(self::PACKAGE_NAME));
                if (preg_match('/^\d+\.\d+/', $version) === 1) {
                    return $version;
                }
            }
        } catch (\Throwable) {
        }

        try {
            $version = self::normalizeVersion(ExtensionManagementUtility::getExtensionVersion(self::EXTENSION_KEY));
            if ($version !== '') {
                return $version;
            }
        } catch (\Throwable) {
        }

        return 'unknown';
    }
}
