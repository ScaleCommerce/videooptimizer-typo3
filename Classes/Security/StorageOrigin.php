<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Security;

/**
 * The object storage the presigned upload URLs of the platform point to. One value for the backend
 * CSP (`connect-src`) and for the server-side check of presigned URLs; extension configuration
 * `storageOrigin` overrides the default (self-hosted platform).
 */
final class StorageOrigin
{
    public const DEFAULT = 'https://object-storage.scale.sc';

    /** A public https origin (no path, query or credentials), else the default. */
    public static function normalize(string $configured): string
    {
        $configured = rtrim(strtolower(trim($configured)), '/');
        if ($configured === '' || !PublicHttpsUrl::isAllowed($configured)) {
            return self::DEFAULT;
        }
        $parts = parse_url($configured);
        if ($parts === false || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])) {
            return self::DEFAULT;
        }

        return $configured;
    }

    /** Whether $url is a https URL on exactly this origin (host and port). */
    public static function allows(string $origin, string $url): bool
    {
        if (!PublicHttpsUrl::isAllowed($url) || str_contains($url, '\\')) {
            return false;
        }
        $wanted = parse_url($origin);
        $actual = parse_url($url);
        if ($wanted === false || $actual === false) {
            return false;
        }

        return strtolower((string)($actual['host'] ?? '')) === strtolower((string)($wanted['host'] ?? '#'))
            && ($actual['port'] ?? 443) === ($wanted['port'] ?? 443);
    }
}
