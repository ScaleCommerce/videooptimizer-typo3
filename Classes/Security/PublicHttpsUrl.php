<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Security;

/**
 * URLs the extension lets a remote party fetch or that it fetches itself (URL import source,
 * presigned storage URLs): https only, no credentials, no localhost, intranet names or
 * non-global IP addresses (incl. numeric/hex/octal IPv4 tricks).
 */
final class PublicHttpsUrl
{
    private const MAX_LENGTH = 2048;

    public static function isAllowed(string $url): bool
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            return false;
        }
        $host = rtrim(strtolower(trim((string)($parts['host'] ?? ''), '[]')), '.');
        if ($host === '') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        } elseif (preg_match('/(^|\.)(0x[0-9a-f]*|[0-9]+)$/', $host) === 1 || !str_contains($host, '.')) {
            return false;
        }

        return !($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.internal') || str_ends_with($host, '.local'));
    }
}
