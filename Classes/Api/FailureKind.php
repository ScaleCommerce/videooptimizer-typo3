<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Api;

/**
 * Tells an outage ("temporarily unavailable": transport error, 429, 5xx) from a missing video
 * ("not found": 404) and from everything else (token, rights, invalid input), for the message.
 */
final class FailureKind
{
    public static function of(\Throwable $exception): string
    {
        if ($exception instanceof NetworkException) {
            return 'unavailable';
        }
        if ($exception instanceof ApiErrorException) {
            $status = $exception->getHttpStatus();
            if ($status === 404) {
                return 'not_found';
            }
            if ($status === 429 || $status >= 500) {
                return 'unavailable';
            }
        }

        return 'api';
    }
}
