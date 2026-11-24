<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Security;

/**
 * Single allowlist check for every media URL that comes from the VideoOptimizer API and ends up in
 * the browser (iframe src, links, images, lightbox): https only, host videooptimizer.eu or a
 * sub-domain, no credentials, no port other than 443, and no characters browsers normalize
 * differently than PHP (backslash, whitespace, control characters, quotes, angle brackets) —
 * e.g. "https://evil.com\.videooptimizer.eu/x" parses as a videooptimizer.eu host in PHP but loads
 * from evil.com in a browser.
 */
final class MediaUrl
{
    public const ALLOWED_HOST = 'videooptimizer.eu';

    /** The URL when it passes the allowlist, else ''. */
    public static function safe(mixed $url): string
    {
        if (!is_string($url)) {
            return '';
        }
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f<>"\'\\\\]/', $url) === 1) {
            return '';
        }
        $parts = parse_url($url);
        if ($parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
        ) {
            return '';
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host !== self::ALLOWED_HOST && !str_ends_with($host, '.' . self::ALLOWED_HOST)) {
            return '';
        }

        return $url;
    }
}
