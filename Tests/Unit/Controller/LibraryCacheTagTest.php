<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Extbase\Mvc\RequestInterface;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use ScaleCommerce\VideoOptimizer\Controller\AbstractVideoController;
use TYPO3\CMS\Core\Cache\CacheDataCollectorInterface;
use TYPO3\CMS\Core\Cache\CacheTag;

/**
 * Pages showing a video carry the tag of its library, so library flushes reach them.
 */
final class LibraryCacheTagTest extends TestCase
{
    /** @var list<string> */
    private array $tags = [];

    private function controller(): AbstractVideoController
    {
        $collector = $this->createStub(CacheDataCollectorInterface::class);
        $collector->method('addCacheTags')->willReturnCallback(function (CacheTag ...$tags): void {
            foreach ($tags as $tag) {
                $this->tags[] = $tag->name;
            }
        });
        $request = $this->createStub(RequestInterface::class);
        $request->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => $name === 'frontend.cache.collector' ? $collector : null
        );

        $controller = new class((new \ReflectionClass(VideoOptimizerClient::class))->newInstanceWithoutConstructor()) extends AbstractVideoController {
            protected function cacheTtl(): int
            {
                return 300;
            }

            public function register(array $uuids, array $video): void
            {
                $this->registerPageCache($uuids, false, false, $this->libraryIdsOf($video));
            }
        };
        $property = new \ReflectionProperty($controller, 'request');
        $property->setValue($controller, $request);

        return $controller;
    }

    #[Test]
    public function pagesAreTaggedWithVideoAndLibrary(): void
    {
        $this->controller()->register(['abc-1'], ['library_id' => 'lib-9']);

        self::assertSame(['vo_video_abc-1', 'vo_library_lib-9'], $this->tags);
    }

    #[Test]
    public function unknownLibraryAddsNoLibraryTag(): void
    {
        $this->controller()->register(['abc-1'], []);

        self::assertSame(['vo_video_abc-1'], $this->tags);
    }
}
