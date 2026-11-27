<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Backend\Input\VideoPaging;

final class VideoPagingTest extends TestCase
{
    /**
     * @param list<list<string>> $pages uuids per page
     * @return callable(?string): array{items: list<array<string, mixed>>, nextCursor: ?string}
     */
    private function fetcher(array $pages, array &$cursors): callable
    {
        return static function (?string $cursor) use ($pages, &$cursors): array {
            $cursors[] = $cursor;
            $index = $cursor === null ? 0 : (int)substr($cursor, 1);

            return [
                'items' => array_map(static fn(string $id): array => ['uuid' => $id], $pages[$index] ?? []),
                'nextCursor' => isset($pages[$index + 1]) ? 'c' . ($index + 1) : null,
            ];
        };
    }

    #[Test]
    public function loadsTheRequestedNumberOfPages(): void
    {
        $cursors = [];
        $result = VideoPaging::load($this->fetcher([['a', 'b'], ['c'], ['d']], $cursors), 2);

        self::assertSame(['a', 'b', 'c'], array_column($result['items'], 'uuid'));
        self::assertSame('c2', $result['nextCursor']);
        self::assertSame(2, $result['pages']);
        self::assertSame([null, 'c1'], $cursors);
    }

    #[Test]
    public function stopsWhenThereIsNoMore(): void
    {
        $cursors = [];
        $result = VideoPaging::load($this->fetcher([['a'], ['b']], $cursors), 5);

        self::assertSame(['a', 'b'], array_column($result['items'], 'uuid'));
        self::assertNull($result['nextCursor']);
        self::assertSame(2, $result['pages']);
        self::assertCount(2, $cursors);
    }

    #[Test]
    public function dropsDuplicatesAcrossPages(): void
    {
        $cursors = [];
        $result = VideoPaging::load($this->fetcher([['a', 'b'], ['b', 'c']], $cursors), 2);

        self::assertSame(['a', 'b', 'c'], array_column($result['items'], 'uuid'));
    }

    #[Test]
    public function clampsThePageCount(): void
    {
        self::assertSame(1, VideoPaging::clampPages(0));
        self::assertSame(1, VideoPaging::clampPages(-3));
        self::assertSame(3, VideoPaging::clampPages(3));
        self::assertSame(VideoPaging::MAX_PAGES, VideoPaging::clampPages(9999));
    }

    #[Test]
    public function aFailingPageAfterTheFirstKeepsWhatWasLoaded(): void
    {
        $calls = 0;
        $fetch = static function (?string $cursor) use (&$calls): array {
            $calls++;
            if ($calls === 2) {
                throw new \RuntimeException('API down');
            }

            return ['items' => [['uuid' => 'a']], 'nextCursor' => 'c1'];
        };

        $result = VideoPaging::load($fetch, 3);

        self::assertSame(['a'], array_column($result['items'], 'uuid'));
        self::assertSame('c1', $result['nextCursor']);
        self::assertSame(1, $result['pages']);
    }

    #[Test]
    public function theFirstPageFailureIsThrown(): void
    {
        $this->expectException(\RuntimeException::class);
        VideoPaging::load(static function (?string $cursor): array {
            throw new \RuntimeException('API down');
        }, 2);
    }
}
