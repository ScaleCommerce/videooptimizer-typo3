<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Api;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Api\ApiErrorException;
use ScaleCommerce\VideoOptimizer\Api\FailureKind;
use ScaleCommerce\VideoOptimizer\Api\NetworkException;
use ScaleCommerce\VideoOptimizer\Api\ReadyPages;

final class ReadyPagesTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $pages API pages: items (ready flag per entry) and the next cursor
     * @return array{callable(?string): array{items: list<array<string, mixed>>, nextCursor: ?string}, \stdClass}
     */
    private static function api(array $pages): array
    {
        $log = new \stdClass();
        $log->cursors = [];
        $log->limits = [];
        $fetch = static function (?string $cursor, int $limit) use ($pages, $log): array {
            $log->cursors[] = $cursor;
            $log->limits[] = $limit;

            return $pages[count($log->cursors) - 1];
        };

        return [$fetch, $log];
    }

    private static function page(string $prefix, int $ready, int $processing, ?string $next): array
    {
        $items = [];
        for ($i = 0; $i < $ready; $i++) {
            $items[] = ['uuid' => $prefix . 'r' . $i, 'ready' => true];
        }
        for ($i = 0; $i < $processing; $i++) {
            $items[] = ['uuid' => $prefix . 'p' . $i, 'ready' => false];
        }

        return ['items' => $items, 'nextCursor' => $next];
    }

    #[Test]
    public function aFullPageOfReadyVideosNeedsOneCall(): void
    {
        [$fetch, $log] = self::api([self::page('a', 4, 0, 'c2')]);
        $result = ReadyPages::collect($fetch, 4, null);

        self::assertCount(4, $result['items']);
        self::assertSame('c2', $result['nextCursor']);
        self::assertSame([null], $log->cursors);
    }

    #[Test]
    public function aPageWithoutReadyVideosIsFilledFromTheNextApiPages(): void
    {
        [$fetch, $log] = self::api([
            self::page('a', 0, 4, 'c2'),
            self::page('b', 1, 3, 'c3'),
            self::page('c', 3, 1, 'c4'),
        ]);
        $result = ReadyPages::collect($fetch, 4, 'c1');

        self::assertSame(['c1', 'c2', 'c3'], $log->cursors);
        self::assertSame([4, 4, 3], $log->limits, 'a follow-up asks only for the missing videos');
        self::assertCount(4, $result['items']);
        self::assertSame('c4', $result['nextCursor'], 'the cursor continues after the last fetched API page');
    }

    #[Test]
    public function aPageNeverExceedsThePageSize(): void
    {
        // The API honors the requested limit, so the pages hold only what was asked for.
        [$fetch] = self::api([self::page('a', 1, 2, 'c2'), self::page('b', 3, 0, 'c3')]);
        $result = ReadyPages::collect($fetch, 4, null);

        self::assertCount(4, $result['items']);
        self::assertSame('c3', $result['nextCursor']);
    }

    #[Test]
    public function atMostThreeApiCallsPerRender(): void
    {
        [$fetch, $log] = self::api([
            self::page('a', 0, 4, 'c2'),
            self::page('b', 0, 4, 'c3'),
            self::page('c', 0, 4, 'c4'),
            self::page('d', 4, 0, 'c5'),
        ]);
        $result = ReadyPages::collect($fetch, 4, null);

        self::assertCount(3, $log->cursors);
        self::assertSame([], $result['items']);
        self::assertSame('c4', $result['nextCursor'], 'older ready videos stay reachable');
    }

    #[Test]
    public function itStopsWhenTheApiHasNoMore(): void
    {
        [$fetch, $log] = self::api([self::page('a', 1, 0, null)]);
        $result = ReadyPages::collect($fetch, 4, null);

        self::assertCount(1, $log->cursors);
        self::assertNull($result['nextCursor']);
    }

    #[Test]
    public function aFailingFurtherCallKeepsWhatWasLoadedAndTheCursorToRetry(): void
    {
        $calls = 0;
        $fetch = static function (?string $cursor, int $limit) use (&$calls): array {
            if (++$calls === 2) {
                throw new NetworkException('down');
            }

            return self::page('a', 1, 3, 'c2');
        };
        $result = ReadyPages::collect($fetch, 4, null);

        self::assertCount(1, $result['items']);
        self::assertSame('c2', $result['nextCursor']);

        $this->expectException(NetworkException::class);
        ReadyPages::collect(static fn (?string $cursor, int $limit): array => throw new NetworkException('down'), 4, null);
    }

    #[Test]
    public function failuresAreToldApartForTheMessage(): void
    {
        self::assertSame('not_found', FailureKind::of(new ApiErrorException('x', 404)));
        self::assertSame('unavailable', FailureKind::of(new ApiErrorException('x', 503)));
        self::assertSame('unavailable', FailureKind::of(new ApiErrorException('x', 429)));
        self::assertSame('unavailable', FailureKind::of(new NetworkException('x')));
        self::assertSame('api', FailureKind::of(new ApiErrorException('x', 403)));
        self::assertSame('api', FailureKind::of(new \InvalidArgumentException('x')));
    }
}
