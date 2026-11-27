<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use ScaleCommerce\VideoOptimizer\Api\UserAgent;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * A real VideoOptimizerClient on a mocked HTTP layer and an in-memory cache
 * (the tests never talk to the live API).
 */
trait BuildsClient
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /** In-memory stand-in for the extension cache. */
    private \stdClass $store;

    protected function setUp(): void
    {
        $this->store = new \stdClass();
        $this->store->entries = [];
        $this->store->flushed = [];
        $this->store->pageFlushed = [];
    }

    /**
     * @param list<Response|callable> $responses
     */
    private function client(array $responses): VideoOptimizerClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $http = new Client(['handler' => $stack]);

        $factory = $this->createStub(GuzzleClientFactory::class);
        $factory->method('getClient')->willReturn($http);

        $store = $this->store;
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(static fn(string $id): mixed => $store->entries[$id] ?? false);
        $cache->method('set')->willReturnCallback(static function (string $id, mixed $data, array $tags = []) use ($store): void {
            $store->entries[$id] = $data;
            $store->tagsOf[preg_replace('/^.*?(video_.*)$/', '$1', $id) ?? $id] = $tags;
        });
        $cache->method('flushByTag')->willReturnCallback(static function (string $tag) use ($store): void {
            $store->flushed[] = $tag;
        });
        $cache->method('flushByTags')->willReturnCallback(static function (array $tags) use ($store): void {
            array_push($store->flushed, ...$tags);
        });
        $cacheManager = $this->createStub(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);
        $cacheManager->method('flushCachesInGroupByTags')->willReturnCallback(static function (string $group, array $tags) use ($store): void {
            $store->pageFlushed[] = [$group, $tags];
        });

        $extensionConfiguration = $this->createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn('test-token');

        $siteFinder = $this->createStub(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn([]);

        return new VideoOptimizerClient($cacheManager, $factory, $extensionConfiguration, new UserAgent($siteFinder));
    }

    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
