<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Input;

/**
 * Cursor paging of the module's video list. The page count travels in the URL, so a list
 * keeps everything loaded so far after edits (the pages are re-read from the API).
 */
final class VideoPaging
{
    public const PAGE_SIZE = 60;
    public const MAX_PAGES = 20;
    /** Seconds later pages stay cached (the first page is always read fresh). */
    public const LATER_PAGES_TTL = 30;

    public static function clampPages(int $pages): int
    {
        return max(1, min(self::MAX_PAGES, $pages));
    }

    /**
     * @param callable(?string): array{items: list<array<string, mixed>>, nextCursor: ?string} $fetchPage
     * @return array{items: list<array<string, mixed>>, nextCursor: ?string, pages: int}
     */
    public static function load(callable $fetchPage, int $pages): array
    {
        $pages = self::clampPages($pages);
        $items = [];
        $seen = [];
        $cursor = null;
        $loaded = 0;
        for ($i = 0; $i < $pages; $i++) {
            try {
                $page = $fetchPage($cursor);
            } catch (\Throwable $exception) {
                // Later pages are best effort: keep what is there and offer "load more" again.
                if ($loaded === 0) {
                    throw $exception;
                }
                break;
            }
            $loaded++;
            foreach ($page['items'] as $item) {
                $id = (string)($item['uuid'] ?? '');
                if ($id !== '') {
                    if (isset($seen[$id])) {
                        continue;
                    }
                    $seen[$id] = true;
                }
                $items[] = $item;
            }
            $cursor = $page['nextCursor'];
            if ($cursor === null) {
                break;
            }
        }

        return ['items' => $items, 'nextCursor' => $cursor, 'pages' => $loaded];
    }
}
