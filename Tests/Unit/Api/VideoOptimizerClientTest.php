<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Api;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Api\ApiErrorException;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use ScaleCommerce\VideoOptimizer\Tests\Unit\Support\BuildsClient;

/**
 * Client behaviour against a mocked HTTP layer (never talks to the live API).
 */
final class VideoOptimizerClientTest extends TestCase
{
    use BuildsClient;

    private const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';

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
    public function apiErrorsExposeTheMachineReadableCode(): void
    {
        $client = $this->client([self::json(
            ['statusCode' => 400, 'message' => 'Video platform links are not supported.', 'data' => ['code' => 'source_url_video_platform']],
            400
        )]);

        try {
            $client->ingestVideoFromUrl(self::UUID, 'https://www.youtube.com/watch?v=abc');
            self::fail('Expected an ApiErrorException.');
        } catch (ApiErrorException $exception) {
            self::assertSame(400, $exception->getHttpStatus());
            self::assertSame('source_url_video_platform', $exception->getApiCode());
        }
    }

    #[Test]
    public function apiErrorsWithoutCodeHaveNone(): void
    {
        $client = $this->client([self::json(['message' => 'Nope'], 400)]);

        try {
            $client->ingestVideoFromUrl(self::UUID, 'https://example.com/a.mp4');
            self::fail('Expected an ApiErrorException.');
        } catch (ApiErrorException $exception) {
            self::assertNull($exception->getApiCode());
        }
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
    public function downloadsASubtitleAsRawText(): void
    {
        $vtt = "WEBVTT\n\n00:00.000 --> 00:01.000\nHallo";
        $client = $this->client([new Response(200, ['Content-Type' => 'text/vtt'], $vtt)]);

        self::assertSame($vtt, $client->downloadSubtitle(self::UUID, 'de'));
        self::assertSame('GET', $this->history[0]['request']->getMethod());
        self::assertSame('/api/v1/videos/' . self::UUID . '/subtitles/de', $this->history[0]['request']->getUri()->getPath());
    }

    #[Test]
    public function subtitleDownloadErrorsAreApiErrors(): void
    {
        $client = $this->client([self::json(['message' => 'Subtitle not found'], 404)]);

        $this->expectException(ApiErrorException::class);
        $client->downloadSubtitle(self::UUID, 'de');
    }

    #[Test]
    public function metadataUpdatesMergeAndRemoveKeys(): void
    {
        $client = $this->client([self::json(['data' => ['uuid' => self::UUID, 'metadata' => ['a' => '2']]])]);

        $client->updateVideo(self::UUID, ['metadata' => ['a' => '2', 'gone' => null]]);

        self::assertSame(['metadata' => ['a' => '2', 'gone' => null]], json_decode((string)$this->history[0]['request']->getBody(), true));
        self::assertSame('PATCH', $this->history[0]['request']->getMethod());
        self::assertContains('vo_video_' . self::UUID, $this->store->flushed);
    }

    #[Test]
    public function getVideoFreshBypassesTheCache(): void
    {
        $client = $this->client([
            self::json(['data' => ['uuid' => self::UUID, 'status' => 'processing']]),
            self::json(['data' => ['uuid' => self::UUID, 'status' => 'ready']]),
        ]);

        self::assertSame('processing', $client->getVideoFresh(self::UUID)['status']);
        self::assertSame('ready', $client->getVideoFresh(self::UUID)['status']);
        self::assertCount(2, $this->history);
    }

    #[Test]
    public function getVideoFreshIsEmptyForUnknownVideos(): void
    {
        self::assertSame([], $this->client([self::json(['message' => 'nope'], 404)])->getVideoFresh(self::UUID));
    }

    #[Test]
    public function videoPagesCarryCursorTagsAndLibrary(): void
    {
        $client = $this->client([self::json([
            'data' => [['uuid' => self::UUID]],
            'pagination' => ['has_more' => true, 'next_cursor' => 'abc123'],
        ])]);

        $page = $client->listVideosPage(60, 'prev1', self::UUID, ['Sale', 'new'], true);

        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        self::assertSame(['limit' => '60', 'library_id' => self::UUID, 'tags' => 'Sale,new', 'cursor' => 'prev1'], $query);
        self::assertSame('abc123', $page['nextCursor']);
        self::assertCount(1, $page['items']);
    }

    #[Test]
    public function freshVideoPagesAreNotCached(): void
    {
        $body = ['data' => [['uuid' => self::UUID]], 'pagination' => ['has_more' => false]];
        $client = $this->client([self::json($body), self::json($body)]);

        $client->listVideosPage(60, null, null, [], true);
        $client->listVideosPage(60, null, null, [], true);

        self::assertCount(2, $this->history);
        self::assertSame([], $this->store->entries);
    }

    #[Test]
    public function cachedVideoPagesAreKeyedByTheirFilters(): void
    {
        $body = ['data' => [['uuid' => self::UUID]], 'pagination' => ['has_more' => false]];
        $client = $this->client([self::json($body), self::json($body)]);

        $client->listVideosPage(12, null, null, ['a']);
        $client->listVideosPage(12, null, null, ['a']);
        $client->listVideosPage(12, null, null, ['b']);

        self::assertCount(2, $this->history);
    }

    #[Test]
    public function initiatesAPosterUpload(): void
    {
        $client = $this->client([self::json(['data' => ['key' => 'posters/x.jpg', 'uploadUrl' => 'https://object-storage.scale.sc/put?sig=1']])]);

        $session = $client->initiatePosterUpload(self::UUID, 'image/png', 1234);

        self::assertSame('posters/x.jpg', $session['key']);
        self::assertSame(['contentType' => 'image/png', 'fileSize' => 1234], json_decode((string)$this->history[0]['request']->getBody(), true));
        self::assertSame('/api/v1/videos/' . self::UUID . '/poster/initiate', $this->history[0]['request']->getUri()->getPath());
    }

    #[Test]
    public function rejectsUnsupportedPosterTypes(): void
    {
        $this->expectException(\ScaleCommerce\VideoOptimizer\Api\InvalidArgumentException::class);
        $this->client([])->initiatePosterUpload(self::UUID, 'image/gif', 10);
    }

    #[Test]
    public function completesAPosterUploadAndFlushesTheVideo(): void
    {
        $client = $this->client([self::json(['data' => ['success' => true]])]);

        $client->completePosterUpload(self::UUID, 'posters/x.jpg');

        self::assertSame(['key' => 'posters/x.jpg'], json_decode((string)$this->history[0]['request']->getBody(), true));
        self::assertSame('POST', $this->history[0]['request']->getMethod());
        self::assertContains('vo_video_' . self::UUID, $this->store->flushed);
    }

    #[Test]
    public function removesTheCustomPoster(): void
    {
        $client = $this->client([self::json(['data' => ['success' => true]])]);

        $client->deletePoster(self::UUID);

        self::assertSame('DELETE', $this->history[0]['request']->getMethod());
        self::assertSame('/api/v1/videos/' . self::UUID . '/poster', $this->history[0]['request']->getUri()->getPath());
        self::assertContains('vo_video_' . self::UUID, $this->store->flushed);
    }

    #[Test]
    public function selectsThePosterSource(): void
    {
        $client = $this->client([self::json(['data' => ['success' => true, 'poster_source' => 'thumbnail']])]);

        $client->selectPoster(self::UUID, 'thumbnail', 3);

        self::assertSame(['source' => 'thumbnail', 'thumbnailIndex' => 3], json_decode((string)$this->history[0]['request']->getBody(), true));
    }

    #[Test]
    public function storageUploadsNeverCarryTheApiToken(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vo_test_');
        file_put_contents($file, 'IMG');
        try {
            $sent = null;
            $client = $this->client([static function (\Psr\Http\Message\RequestInterface $request) use (&$sent): Response {
                $sent = (string)$request->getBody();

                return new Response(200);
            }]);
            $client->putToPresignedUrl('https://object-storage.scale.sc/put?sig=1', $file, 'image/jpeg');
        } finally {
            @unlink($file);
        }

        $request = $this->history[0]['request'];
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('image/jpeg', $request->getHeaderLine('Content-Type'));
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertSame('IMG', $sent);
    }

    #[Test]
    public function storageUploadsRefuseInternalUrls(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vo_test_');
        file_put_contents($file, 'IMG');
        try {
            $this->expectException(\ScaleCommerce\VideoOptimizer\Api\InvalidArgumentException::class);
            $this->client([])->putToPresignedUrl('https://169.254.169.254/x', $file, 'image/jpeg');
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function storageUploadsRefuseOtherPublicHosts(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vo_test_');
        file_put_contents($file, 'IMG');
        try {
            $this->expectException(\ScaleCommerce\VideoOptimizer\Api\InvalidArgumentException::class);
            $this->client([])->putToPresignedUrl('https://s3.example.com/put?sig=1', $file, 'image/jpeg');
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function theStorageOriginDefaultsToTheProductionStorage(): void
    {
        self::assertSame('https://object-storage.scale.sc', $this->client([])->getStorageOrigin());
    }

    #[Test]
    public function metadataRemovalsDoNotCountTowardsTheKeyLimit(): void
    {
        $metadata = [];
        for ($i = 1; $i <= 12; $i++) {
            $metadata['set' . $i] = 'v';
        }
        for ($i = 1; $i <= 5; $i++) {
            $metadata['del' . $i] = null;
        }
        $client = $this->client([self::json(['data' => ['uuid' => self::UUID]])]);

        $client->updateVideo(self::UUID, ['metadata' => $metadata]);

        $sent = json_decode((string)$this->history[0]['request']->getBody(), true)['metadata'];
        self::assertCount(10, array_filter($sent, static fn($v) => $v !== null));
        self::assertCount(5, array_filter($sent, static fn($v) => $v === null));
    }

    #[Test]
    public function storageUploadFailuresAreApiErrors(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'vo_test_');
        file_put_contents($file, 'IMG');
        try {
            $this->expectException(ApiErrorException::class);
            $this->client([new Response(403)])->putToPresignedUrl('https://object-storage.scale.sc/put', $file, 'image/jpeg');
        } finally {
            @unlink($file);
        }
    }

    #[Test]
    public function completingAVideoUploadFlushesTheLists(): void
    {
        $client = $this->client([self::json(['data' => ['uuid' => self::UUID, 'success' => true]])]);

        $client->completeUpload(self::UUID, ['uuid' => self::UUID, 'key' => 'k', 'uploadId' => 'u'], 'T', [['partNumber' => 1, 'etag' => 'e']]);

        self::assertContains('vo_lists', $this->store->flushed);
        self::assertContains('vo_libraries', $this->store->flushed);
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
        self::assertSame('Bad file', VideoOptimizerClient::errorReason(['error' => "Bad\n\tfile"]));
        self::assertSame('File too large (<2GB allowed)', VideoOptimizerClient::errorReason(['error' => 'File too large (<2GB allowed)']));
        self::assertSame('', VideoOptimizerClient::errorReason(['error' => null]));
        self::assertSame('', VideoOptimizerClient::errorReason(['error' => true]));
        self::assertSame('', VideoOptimizerClient::errorReason([]));
        self::assertSame(300, mb_strlen(VideoOptimizerClient::errorReason(['error' => str_repeat('x', 1000)])));
    }

    #[Test]
    public function negativeCacheKeepsTheApiErrorCode(): void
    {
        $client = $this->client([self::json(
            ['message' => 'Not for you', 'data' => ['code' => 'some_code']],
            403
        )]);

        foreach ([1, 2] as $attempt) {
            try {
                $client->listSubtitleLanguages();
                self::fail('Expected an ApiErrorException.');
            } catch (ApiErrorException $exception) {
                self::assertSame(403, $exception->getHttpStatus(), 'attempt ' . $attempt);
                self::assertSame('some_code', $exception->getApiCode(), 'attempt ' . $attempt);
            }
        }
        // Only one HTTP call: the second failure came from the negative cache.
        self::assertCount(1, $this->history);
    }

    #[Test]
    public function subtitleChangesFlushTheLanguageUsageCounts(): void
    {
        $client = $this->client([self::json(['data' => ['deleted' => true]])]);

        $client->deleteSubtitle(self::UUID, 'de');

        self::assertContains('vo_sublangs', $this->store->flushed);
        self::assertContains('vo_video_' . self::UUID, $this->store->flushed);
    }

    #[Test]
    public function thumbnailsAreCachedUnderTheNewKeyShape(): void
    {
        $client = $this->client([self::json(['data' => ['thumbnails' => [['index' => 0, 'url' => 'https://cdn.videooptimizer.eu/a.jpg']]]])]);
        // A pre-1.0.4 entry (list shape) must not be served.
        $this->store->entries[$this->legacyKey('thumbs:' . self::UUID)] = ['payload' => [['data' => 'x']], 'fetchedAt' => time(), 'tags' => []];

        self::assertSame([['index' => 0, 'url' => 'https://cdn.videooptimizer.eu/a.jpg']], $client->listThumbnails(self::UUID));
    }

    private function legacyKey(string $suffix): string
    {
        $method = new \ReflectionMethod(VideoOptimizerClient::class, 'key');

        return $method->invoke($this->client([]), $suffix);
    }

    #[Test]
    public function libraryActionsFlushThePageCacheTagsOfTheLibrary(): void
    {
        $client = $this->client([
            self::json(['data' => ['id' => '0a1b2c3d-1111-4222-8333-444455556666']]),
            self::json(['data' => ['queued' => 1]]),
            self::json([], 204),
        ]);

        $client->updateLibrary('0a1b2c3d-1111-4222-8333-444455556666', ['resolutions' => '720p']);
        $client->reprocessLibrary('0a1b2c3d-1111-4222-8333-444455556666', true);
        $client->deleteLibrary('0a1b2c3d-1111-4222-8333-444455556666');

        self::assertCount(3, $this->store->pageFlushed);
        foreach ($this->store->pageFlushed as [$group, $tags]) {
            self::assertSame('pages', $group);
            self::assertContains('vo_library_0a1b2c3d-1111-4222-8333-444455556666', $tags);
            self::assertContains('vo_lists', $tags);
        }
    }

    #[Test]
    public function cachedVideoIsDroppedByLibraryFlushes(): void
    {
        $video = self::json(['data' => ['uuid' => self::UUID, 'library_id' => '0a1b2c3d-1111-4222-8333-444455556666']]);
        $client = $this->client([$video, self::json(['data' => ['id' => '0a1b2c3d-1111-4222-8333-444455556666']])]);

        $client->getVideo(self::UUID);
        $client->getVideo(self::UUID);
        self::assertCount(1, $this->history, 'second read comes from the cache');
        self::assertContains('vo_library_0a1b2c3d-1111-4222-8333-444455556666', $this->store->tagsOf['video_' . self::UUID] ?? []);

        $client->updateLibrary('0a1b2c3d-1111-4222-8333-444455556666', ['resolutions' => '720p']);
        self::assertContains('vo_library_0a1b2c3d-1111-4222-8333-444455556666', $this->store->flushed);
    }

    #[Test]
    public function renamingALibraryDoesNotFlushPages(): void
    {
        $client = $this->client([self::json(['data' => ['id' => '0a1b2c3d-1111-4222-8333-444455556666']])]);

        $client->updateLibrary('0a1b2c3d-1111-4222-8333-444455556666', ['name' => 'Renamed']);

        self::assertSame([], $this->store->pageFlushed);
        self::assertContains('vo_library_0a1b2c3d-1111-4222-8333-444455556666', $this->store->flushed);
    }

    #[Test]
    public function flushVideoLeavesLibraryPagesAlone(): void
    {
        $this->client([])->flushVideo(self::UUID, '0a1b2c3d-1111-4222-8333-444455556666');

        self::assertSame([['pages', ['vo_video_' . self::UUID, 'vo_lists']]], $this->store->pageFlushed);
        self::assertContains('vo_library_0a1b2c3d-1111-4222-8333-444455556666', $this->store->flushed);
    }
}
