<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Cache invalidation, push variant: receiver for VideoOptimizer webhooks.
 *
 * Answers only `POST /videooptimizer/webhook` (everything else passes through untouched).
 * Create the webhook in the VideoOptimizer app (Organization -> Webhooks) with the URL
 * https://<your-site>/videooptimizer/webhook and put its signing secret into the extension
 * configuration `webhookSecret` (or env VO_WEBHOOK_SECRET). Without a secret the endpoint
 * answers 503 (fail closed).
 *
 * Contract (docs/WEBHOOKS.md of the platform): headers X-VideoOptimizer-Timestamp,
 * X-VideoOptimizer-Signature (`sha256=` + hex HMAC-SHA256(secret, "{timestamp}.{rawBody}")),
 * X-VideoOptimizer-Delivery-Id; body {"event": ..., "data": {...}}. Deliveries older than
 * 300 s are rejected (401), repeated delivery ids are acknowledged without being processed
 * (kept 24 h in the `videooptimizer` cache).
 *
 * Events: `video.*` (documented: video.ready, video.failed) flush the API cache and the page cache
 * by tags vo_video_<uuid>, vo_lists and vo_library_<library_id>; `library.*` flush vo_libraries,
 * vo_library_<id> and vo_lists; `webhook.test` answers {ok:true}. Installs without webhooks can use
 * the console command `videooptimizer:cache:refresh` (schedulable) instead.
 */
final class WebhookMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const PATH = '/videooptimizer/webhook';
    private const TOLERANCE = 300;
    private const MAX_BODY = 1048576;
    private const DEDUPE_LIFETIME = 86400;

    public function __construct(
        private readonly VideoOptimizerClient $client,
        private readonly CacheManager $cacheManager,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'POST' || rtrim($request->getUri()->getPath(), '/') !== self::PATH) {
            return $handler->handle($request);
        }

        $secret = $this->client->getWebhookSecret();
        if ($secret === '') {
            return $this->json(['error' => 'Webhook secret not configured.'], 503);
        }

        $stream = $request->getBody();
        if ($stream->getSize() !== null && $stream->getSize() > self::MAX_BODY) {
            return $this->json(['error' => 'Payload too large.'], 413);
        }
        $raw = (string)$stream;
        if (strlen($raw) > self::MAX_BODY) {
            return $this->json(['error' => 'Payload too large.'], 413);
        }

        $timestamp = $request->getHeaderLine('X-VideoOptimizer-Timestamp');
        $signature = $request->getHeaderLine('X-VideoOptimizer-Signature');
        if (!self::verify($secret, $timestamp, $raw, $signature, time())) {
            // notice, not warning: anyone can POST here, so this line must not be a cheap way to flood
            // the warning log.
            $this->logger?->notice('VideoOptimizer webhook rejected: invalid signature or timestamp.');

            return $this->json(['error' => 'Invalid signature.'], 401);
        }

        // Dedupe on the signed part (timestamp + signature), not only on the unsigned delivery-id
        // header: a captured request replayed within the 300 s window with a new delivery id is
        // still recognised as a duplicate.
        $delivery = (string)preg_replace('/[^A-Za-z0-9_-]/', '', $request->getHeaderLine('X-VideoOptimizer-Delivery-Id'));
        $cache = $this->cacheManager->getCache('videooptimizer');
        $dedupeKeys = ['wh_sig_' . hash('sha256', $timestamp . '.' . $signature)];
        if ($delivery !== '') {
            $dedupeKeys[] = 'wh_' . substr(md5($delivery), 0, 32);
        }
        foreach ($dedupeKeys as $dedupeKey) {
            if ($cache->has($dedupeKey)) {
                return $this->json(['duplicate' => true]);
            }
        }
        foreach ($dedupeKeys as $dedupeKey) {
            $cache->set($dedupeKey, 1, [], self::DEDUPE_LIFETIME);
        }

        $payload = json_decode($raw, true);
        $event = is_array($payload) && is_string($payload['event'] ?? null) ? $payload['event'] : '';
        $data = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : [];

        if ($event === 'webhook.test') {
            $this->logger?->info('VideoOptimizer webhook received.', ['event' => $event, 'delivery' => $delivery]);

            return $this->json(['ok' => true]);
        }

        $uuid = is_string($data['uuid'] ?? null) ? $data['uuid'] : '';
        $flushed = false;
        try {
            if (str_starts_with($event, 'video.')) {
                $libraryId = is_string($data['library_id'] ?? null) ? $data['library_id'] : null;
                if ($uuid !== '') {
                    $this->client->flushVideo($uuid, $libraryId);
                } else {
                    $this->client->flushLists();
                }
                $flushed = true;
            } elseif (str_starts_with($event, 'library.')) {
                $libraryId = is_string($data['uuid'] ?? null) ? $data['uuid'] : (is_string($data['id'] ?? null) ? $data['id'] : '');
                if ($libraryId !== '') {
                    $this->client->flushLibrary($libraryId);
                } else {
                    $this->client->flushLists();
                }
                $flushed = true;
            }
        } catch (\Throwable $exception) {
            // e.g. an invalid id in an authenticated payload: fall back to the coarse flush.
            $this->logger?->warning('VideoOptimizer webhook flush failed, flushing lists.', ['event' => $event, 'error' => $exception->getMessage()]);
            $this->client->flushLists();
            $flushed = true;
        }

        $this->logger?->info('VideoOptimizer webhook processed.', [
            'event' => $event !== '' ? $event : 'unknown',
            'uuid' => $uuid,
            'delivery' => $delivery,
            'flushed' => $flushed,
        ]);

        return $this->json(['received' => true, 'flushed' => $flushed]);
    }

    public static function verify(string $secret, string $timestamp, string $rawBody, string $signature, int $now): bool
    {
        if ($secret === '' || $timestamp === '' || !ctype_digit($timestamp) || abs($now - (int)$timestamp) > self::TOLERANCE) {
            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret), $signature);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body, int $status = 200): ResponseInterface
    {
        return new JsonResponse($body, $status, ['Cache-Control' => 'no-store']);
    }
}
