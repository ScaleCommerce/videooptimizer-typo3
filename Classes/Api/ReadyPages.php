<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Api;

/**
 * Lists show ready videos only. A page of the API can hold few or none (videos still processing or
 * failed), so further API pages are fetched, at most MAX_CALLS per render, until a page worth of ready
 * videos is there. A follow-up call asks only for the missing number (`limit = size - ready so far`;
 * the cursor API accepts a different limit per call), so a page never exceeds $size videos and the
 * next cursor is exact: everything before it was either shown or not ready.
 */
final class ReadyPages
{
    public const MAX_CALLS = 3;

    /**
     * @param callable(?string, int): array{items: list<array<string, mixed>>, nextCursor: ?string} $fetchPage
     * @return array{items: list<array<string, mixed>>, nextCursor: ?string}
     */
    public static function collect(callable $fetchPage, int $size, ?string $cursor): array
    {
        $ready = [];
        $next = $cursor;
        for ($call = 0; $call < self::MAX_CALLS; $call++) {
            try {
                $page = $fetchPage($next, max(1, $size - count($ready)));
            } catch (VideoOptimizerException|\InvalidArgumentException $exception) {
                if ($call === 0) {
                    throw $exception;
                }
                // Keep what is there; the cursor of the last good page lets the visitor continue.
                break;
            }
            foreach ($page['items'] as $video) {
                if (self::isReady($video)) {
                    $ready[] = $video;
                }
            }
            $next = $page['nextCursor'];
            if ($next === null || count($ready) >= $size) {
                break;
            }
        }

        return ['items' => $ready, 'nextCursor' => $next];
    }

    /**
     * @param array<string, mixed> $video
     */
    public static function isReady(array $video): bool
    {
        return ($video['ready'] ?? true) !== false && trim((string)($video['error'] ?? '')) === '';
    }
}
