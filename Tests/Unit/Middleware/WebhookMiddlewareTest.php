<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Middleware;

use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ScaleCommerce\VideoOptimizer\Middleware\WebhookMiddleware;
use ScaleCommerce\VideoOptimizer\Tests\Unit\Support\BuildsClient;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Webhook receiver: signature, replay protection and cache invalidation. The signing secret in
 * these tests is the stubbed extension configuration value ("test-token").
 */
final class WebhookMiddlewareTest extends TestCase
{
    use BuildsClient;

    private const SECRET = 'test-token';
    private const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';
    private const LIBRARY = '0f74529e-1f8b-4722-9942-0db916a9245d';

    private function middleware(): WebhookMiddleware
    {
        $client = $this->client([]);

        return new WebhookMiddleware($client, $this->cacheManager);
    }

    private function handler(?bool &$called = null): RequestHandlerInterface
    {
        return new class ($called) implements RequestHandlerInterface {
            public function __construct(private ?bool &$called) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;

                return new JsonResponse(['passthrough' => true]);
            }
        };
    }

    /**
     * @param array<string, mixed> $body
     */
    private function signed(array $body, ?int $timestamp = null, string $delivery = 'd-1', ?string $secret = null): ServerRequest
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $raw, $secret ?? self::SECRET);

        return new ServerRequest('POST', 'https://site.example' . WebhookMiddleware::PATH, [
            'X-VideoOptimizer-Timestamp' => (string)$timestamp,
            'X-VideoOptimizer-Signature' => $signature,
            'X-VideoOptimizer-Delivery-Id' => $delivery,
        ], $raw);
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function otherRequestsPassThrough(): void
    {
        $called = false;
        $this->middleware()->process(new ServerRequest('GET', 'https://site.example' . WebhookMiddleware::PATH), $this->handler($called));
        self::assertTrue($called);

        $called = false;
        $this->middleware()->process(new ServerRequest('POST', 'https://site.example/other'), $this->handler($called));
        self::assertTrue($called);
    }

    #[Test]
    public function verifiesTheSignatureAndTheTimestamp(): void
    {
        $raw = '{"event":"webhook.test"}';
        $now = 1_800_000_000;
        $good = 'sha256=' . hash_hmac('sha256', $now . '.' . $raw, 'secret');

        self::assertTrue(WebhookMiddleware::verify('secret', (string)$now, $raw, $good, $now));
        self::assertTrue(WebhookMiddleware::verify('secret', (string)$now, $raw, $good, $now + 300));
        self::assertFalse(WebhookMiddleware::verify('secret', (string)$now, $raw, $good, $now + 301), 'too old');
        self::assertFalse(WebhookMiddleware::verify('secret', (string)$now, $raw, $good, $now - 301), 'too new');
        self::assertFalse(WebhookMiddleware::verify('other', (string)$now, $raw, $good, $now), 'wrong secret');
        self::assertFalse(WebhookMiddleware::verify('secret', (string)$now, $raw . ' ', $good, $now), 'tampered body');
        self::assertFalse(WebhookMiddleware::verify('secret', 'abc', $raw, $good, $now), 'non numeric timestamp');
        self::assertFalse(WebhookMiddleware::verify('', (string)$now, $raw, $good, $now), 'no secret');
    }

    #[Test]
    public function rejectsABadSignature(): void
    {
        $response = $this->middleware()->process($this->signed(['event' => 'video.ready'], null, 'd-1', 'wrong-secret'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([], $this->store->flushed);
    }

    #[Test]
    public function rejectsAStaleDelivery(): void
    {
        $response = $this->middleware()->process($this->signed(['event' => 'video.ready'], time() - 1000), $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function answersTheTestEvent(): void
    {
        $response = $this->middleware()->process($this->signed(['event' => 'webhook.test']), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['ok' => true], self::data($response));
        self::assertSame([], $this->store->flushed);
    }

    #[Test]
    public function videoEventsFlushTheVideoItsLibraryAndTheLists(): void
    {
        $response = $this->middleware()->process(
            $this->signed(['event' => 'video.ready', 'data' => ['uuid' => self::UUID, 'library_id' => self::LIBRARY]]),
            $this->handler()
        );

        self::assertSame(['received' => true, 'flushed' => true], self::data($response));
        self::assertContains('vo_video_' . self::UUID, $this->store->flushed);
        self::assertContains('vo_lists', $this->store->flushed);
        self::assertContains('vo_library_' . self::LIBRARY, $this->store->flushed);
        self::assertNotSame([], $this->store->pageFlushed, 'the page cache is flushed too');
    }

    #[Test]
    public function libraryEventsFlushTheLibrary(): void
    {
        $this->middleware()->process($this->signed(['event' => 'library.updated', 'data' => ['id' => self::LIBRARY]]), $this->handler());

        self::assertContains('vo_library_' . self::LIBRARY, $this->store->flushed);
        self::assertContains('vo_libraries', $this->store->flushed);
    }

    #[Test]
    public function aMalformedIdFallsBackToFlushingTheLists(): void
    {
        $response = $this->middleware()->process($this->signed(['event' => 'video.ready', 'data' => ['uuid' => '../x']]), $this->handler());

        self::assertSame(['received' => true, 'flushed' => true], self::data($response));
        self::assertContains('vo_lists', $this->store->flushed);
    }

    #[Test]
    public function aRepeatedDeliveryIsAcknowledgedWithoutProcessing(): void
    {
        $middleware = $this->middleware();
        $request = $this->signed(['event' => 'video.ready', 'data' => ['uuid' => self::UUID]]);

        $middleware->process($request, $this->handler());
        $flushedOnce = $this->store->flushed;
        $replay = $middleware->process($request, $this->handler());

        self::assertSame(['duplicate' => true], self::data($replay));
        self::assertSame($flushedOnce, $this->store->flushed);
    }

    #[Test]
    public function aReplayWithANewDeliveryIdIsStillADuplicate(): void
    {
        $middleware = $this->middleware();
        $timestamp = time();
        $first = $this->signed(['event' => 'video.ready', 'data' => ['uuid' => self::UUID]], $timestamp, 'd-1');
        $replayed = $first->withHeader('X-VideoOptimizer-Delivery-Id', 'd-2');

        $middleware->process($first, $this->handler());

        self::assertSame(['duplicate' => true], self::data($middleware->process($replayed, $this->handler())));
    }

    #[Test]
    public function refusesOversizedPayloads(): void
    {
        $request = new ServerRequest('POST', 'https://site.example' . WebhookMiddleware::PATH, [], str_repeat('x', 1048577));

        self::assertSame(413, $this->middleware()->process($request, $this->handler())->getStatusCode());
    }
}
