<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Api;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Api\ApiErrorException;
use ScaleCommerce\VideoOptimizer\Api\UserAgent;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Client\GuzzleClientFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Client behaviour against a mocked HTTP layer (never talks to the live API).
 */
final class VideoOptimizerClientTest extends TestCase
{
    private const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    /**
     * @param list<Response> $responses
     */
    private function client(array $responses): VideoOptimizerClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $http = new Client(['handler' => $stack]);

        $factory = $this->createStub(GuzzleClientFactory::class);
        $factory->method('getClient')->willReturn($http);

        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('get')->willReturn(false);
        $cacheManager = $this->createStub(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($cache);

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

    #[Test]
    public function listThumbnailsReadsTheDocumentedResponseShape(): void
    {
        $client = $this->client([self::json(['data' => ['thumbnails' => [
            ['index' => 0, 'url' => 'https://cdn.videooptimizer.eu/a/0.jpg'],
            ['index' => 1, 'url' => 'https://cdn.videooptimizer.eu/a/1.jpg'],
        ]]])]);

        self::assertSame(
            [
                ['index' => 0, 'url' => 'https://cdn.videooptimizer.eu/a/0.jpg'],
                ['index' => 1, 'url' => 'https://cdn.videooptimizer.eu/a/1.jpg'],
            ],
            $client->listThumbnails(self::UUID)
        );
    }

    #[Test]
    public function listThumbnailsIsEmptyWithoutAThumbnailsList(): void
    {
        self::assertSame([], $this->client([self::json(['data' => []])])->listThumbnails(self::UUID));
        self::assertSame([], $this->client([self::json(['data' => ['thumbnails' => 'x']])])->listThumbnails(self::UUID));
    }

    #[Test]
    public function reprocessSendsForceOnlyWhenRequested(): void
    {
        $client = $this->client([self::json(['data' => ['queued' => 2]]), self::json(['data' => ['queued' => 2]])]);

        $client->reprocessLibrary(self::UUID);
        $client->reprocessLibrary(self::UUID, true);

        self::assertSame('', (string)$this->history[0]['request']->getBody());
        self::assertSame(['force' => true], json_decode((string)$this->history[1]['request']->getBody(), true));
        self::assertSame('/api/v1/libraries/' . self::UUID . '/reprocess', $this->history[1]['request']->getUri()->getPath());
    }

    #[Test]
    public function libraryPayloadKeepsEncodingQuality(): void
    {
        $client = $this->client([self::json(['data' => ['id' => self::UUID, 'encoding_quality' => 'quality']])]);

        $client->updateLibrary(self::UUID, ['encoding_quality' => 'quality']);

        self::assertSame(['encoding_quality' => 'quality'], json_decode((string)$this->history[0]['request']->getBody(), true));
        self::assertSame('PATCH', $this->history[0]['request']->getMethod());
    }

    #[Test]
    public function deletesASubtitleLanguage(): void
    {
        $client = $this->client([self::json(['data' => ['code' => 'de-CH', 'deleted' => true]])]);

        $client->deleteSubtitleLanguage('de-CH');

        self::assertSame('DELETE', $this->history[0]['request']->getMethod());
        self::assertSame('/api/v1/subtitle-languages/de-CH', $this->history[0]['request']->getUri()->getPath());
    }

    #[Test]
    public function rejectsInvalidSubtitleLanguageCodes(): void
    {
        $this->expectException(\ScaleCommerce\VideoOptimizer\Api\InvalidArgumentException::class);
        $this->client([])->deleteSubtitleLanguage('../x');
    }

    #[Test]
    public function readsTheFailureReasonOfAVideo(): void
    {
        self::assertSame('Download failed: HTTP 404', VideoOptimizerClient::errorReason(['status' => 'failed', 'error' => ' Download failed: HTTP 404 ']));
        self::assertSame('Bad file', VideoOptimizerClient::errorReason(['error' => "<b>Bad</b>\n file"]));
        self::assertSame('', VideoOptimizerClient::errorReason(['error' => null]));
        self::assertSame('', VideoOptimizerClient::errorReason(['error' => true]));
        self::assertSame('', VideoOptimizerClient::errorReason([]));
        self::assertSame(300, mb_strlen(VideoOptimizerClient::errorReason(['error' => str_repeat('x', 1000)])));
    }
}
