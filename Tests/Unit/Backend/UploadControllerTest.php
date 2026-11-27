<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Backend;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use ScaleCommerce\VideoOptimizer\Backend\Controller\UploadController;
use ScaleCommerce\VideoOptimizer\Backend\Fal\PosterFileBrowser;
use ScaleCommerce\VideoOptimizer\Tests\Unit\Support\BuildsClient;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Resource\ResourceFactory;

final class UploadControllerTest extends TestCase
{
    use BuildsClient;

    private const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';
    private const LIBRARY = '0f74529e-1f8b-4722-9942-0db916a9245d';

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }

    /**
     * @param list<Response|callable> $responses
     */
    private function controller(array $responses, bool $admin = true): UploadController
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('check')->willReturn(false);
        $GLOBALS['BE_USER'] = $user;

        $language = $this->createStub(LanguageService::class);
        $language->method('sL')->willReturnCallback(static fn(string $key): string => substr($key, (int)strrpos($key, ':') + 1));
        $languageFactory = $this->createStub(LanguageServiceFactory::class);
        $languageFactory->method('createFromUserPreferences')->willReturn($language);

        return new UploadController(
            $this->client($responses),
            new PosterFileBrowser($this->createStub(ResourceFactory::class)),
            $languageFactory,
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function post(array $body): ServerRequest
    {
        return new ServerRequest('POST', '/typo3/ajax/x', ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function session(string $partUrl = 'https://storage.videooptimizer.eu/part1?sig=abc'): array
    {
        return ['data' => [
            'uuid' => self::UUID, 'key' => 'uploads/x.mp4', 'uploadId' => 'up1', 'partSize' => 8388608, 'partCount' => 1,
            'parts' => [['partNumber' => 1, 'url' => $partUrl]],
        ]];
    }

    private const INITIATE = ['libraryId' => self::LIBRARY, 'filename' => 'clip.mp4', 'contentType' => 'video/mp4', 'fileSize' => 1000];

    #[Test]
    public function initiateReturnsThePresignedPartsButNeverTheToken(): void
    {
        $controller = $this->controller([
            self::json(['data' => ['id' => self::LIBRARY, 'media_managed' => true]]),
            self::json(self::session()),
        ]);

        $response = $controller->initiateAction(self::post(self::INITIATE));

        self::assertSame(200, $response->getStatusCode());
        $data = self::data($response);
        self::assertSame(self::UUID, $data['uuid']);
        self::assertSame('https://storage.videooptimizer.eu/part1?sig=abc', $data['parts'][0]['url']);
        self::assertSame(8388608, $data['partSize']);
        self::assertStringNotContainsString('test-token', (string)$response->getBody());
        // The token went to the API, in the header of the API call only.
        self::assertSame('Bearer test-token', $this->history[1]['request']->getHeaderLine('Authorization'));
        self::assertSame(
            ['libraryId' => self::LIBRARY, 'filename' => 'clip.mp4', 'contentType' => 'video/mp4', 'fileSize' => 1000],
            json_decode((string)$this->history[1]['request']->getBody(), true)
        );
    }

    #[Test]
    public function initiateRefusesLibrariesWithoutManagedMedia(): void
    {
        $controller = $this->controller([self::json(['data' => ['id' => self::LIBRARY, 'media_managed' => false]])]);

        $response = $controller->initiateAction(self::post(self::INITIATE));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('flash.upload.notManaged', self::data($response)['code']);
    }

    #[Test]
    public function initiateRefusesUnsafeStorageUrls(): void
    {
        $controller = $this->controller([
            self::json(['data' => ['id' => self::LIBRARY, 'media_managed' => true]]),
            self::json(self::session('http://169.254.169.254/part')),
        ]);

        self::assertSame(502, $controller->initiateAction(self::post(self::INITIATE))->getStatusCode());
    }

    #[Test]
    public function initiateValidatesTheFile(): void
    {
        $controller = $this->controller([]);

        $response = $controller->initiateAction(self::post(['filename' => 'virus.exe'] + self::INITIATE));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('flash.upload.badType', self::data($response)['code']);
        self::assertSame([], $this->history);
    }

    #[Test]
    public function mutationsNeedThePermissionAndPost(): void
    {
        $denied = $this->controller([], false);
        self::assertSame(403, $denied->initiateAction(self::post(self::INITIATE))->getStatusCode());
        self::assertSame(403, $denied->posterRemoveAction(self::post(['uuid' => self::UUID]))->getStatusCode());
        self::assertSame(403, $denied->statusAction((new ServerRequest('GET', '/x'))->withQueryParams(['uuid' => self::UUID]))->getStatusCode());

        $controller = $this->controller([]);
        self::assertSame(405, $controller->initiateAction(new ServerRequest('GET', '/x'))->getStatusCode());
        self::assertSame(405, $controller->statusAction(new ServerRequest('POST', '/x'))->getStatusCode());
        self::assertSame([], $this->history);
    }

    #[Test]
    public function completeCreatesTheVideoFromTheParts(): void
    {
        $controller = $this->controller([self::json(['data' => ['uuid' => self::UUID, 'success' => true]])]);

        $response = $controller->completeAction(self::post([
            'libraryId' => self::LIBRARY,
            'session' => ['uuid' => self::UUID, 'key' => 'uploads/x.mp4', 'uploadId' => 'up1'],
            'parts' => [['partNumber' => 1, 'etag' => '"abc"']],
            'title' => 'Clip',
            'tags' => 'a,b',
            'metadata' => 'k: v',
        ]));

        self::assertSame(['uuid' => self::UUID, 'status' => 'processing'], self::data($response));
        $sent = json_decode((string)$this->history[0]['request']->getBody(), true);
        self::assertSame('/api/v1/videos/upload/complete', $this->history[0]['request']->getUri()->getPath());
        self::assertSame([['partNumber' => 1, 'etag' => '"abc"']], $sent['parts']);
        self::assertSame(['a', 'b'], $sent['tags']);
        self::assertSame(['k' => 'v'], $sent['metadata']);
        self::assertSame('Clip', $sent['title']);
    }

    #[Test]
    public function completeRejectsIncompleteParts(): void
    {
        $controller = $this->controller([]);

        $response = $controller->completeAction(self::post(['libraryId' => self::LIBRARY, 'session' => ['uuid' => self::UUID, 'key' => 'k', 'uploadId' => 'u'], 'parts' => []]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('flash.upload.badParts', self::data($response)['code']);
    }

    #[Test]
    public function apiFailuresAreReportedWithTheApiMessage(): void
    {
        $controller = $this->controller([self::json(['message' => 'Video limit of the plan reached', 'data' => ['code' => 'video_limit']], 403)]);

        $response = $controller->completeAction(self::post([
            'libraryId' => self::LIBRARY,
            'session' => ['uuid' => self::UUID, 'key' => 'k', 'uploadId' => 'u'],
            'parts' => [['partNumber' => 1, 'etag' => 'e']],
        ]));

        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('Video limit of the plan reached', self::data($response)['error']);
        self::assertSame('video_limit', self::data($response)['code']);
    }

    #[Test]
    public function statusExposesEncodingStateErrorAndPoster(): void
    {
        $controller = $this->controller([self::json(['data' => [
            'uuid' => self::UUID, 'title' => 'Clip', 'status' => 'failed', 'ready' => false,
            'error' => "Unsupported\ncodec",
            'poster' => ['source' => 'thumbnail', 'custom_status' => 'failed'],
            'poster_url' => 'https://evil.example.com/p.jpg',
            'hls_url' => 'https://api.videooptimizer.eu/x.m3u8',
        ]])]);

        $data = self::data($controller->statusAction((new ServerRequest('GET', '/x'))->withQueryParams(['uuid' => self::UUID])));

        self::assertSame('failed', $data['status']);
        self::assertFalse($data['ready']);
        self::assertSame('Unsupported codec', $data['error']);
        self::assertSame(['source' => 'thumbnail', 'customStatus' => 'failed'], $data['poster']);
        self::assertSame('', $data['posterUrl']);
        self::assertArrayNotHasKey('hls_url', $data);
    }

    #[Test]
    public function statusOfAnUnknownVideoIs404(): void
    {
        $controller = $this->controller([self::json(['message' => 'nope'], 404)]);

        self::assertSame(404, $controller->statusAction((new ServerRequest('GET', '/x'))->withQueryParams(['uuid' => self::UUID]))->getStatusCode());
    }

    #[Test]
    public function statusRejectsMalformedIds(): void
    {
        $controller = $this->controller([]);

        self::assertSame(400, $controller->statusAction((new ServerRequest('GET', '/x'))->withQueryParams(['uuid' => '../x']))->getStatusCode());
    }

    #[Test]
    public function posterFlowInitiatesCompletesAndRemoves(): void
    {
        $controller = $this->controller([
            self::json(['data' => ['key' => 'posters/p.png', 'uploadUrl' => 'https://storage.videooptimizer.eu/put?sig=1']]),
            self::json(['data' => ['success' => true]]),
            self::json(['data' => ['success' => true]]),
        ]);

        $init = self::data($controller->posterInitiateAction(self::post(['uuid' => self::UUID, 'contentType' => 'image/png', 'fileSize' => 2000])));
        self::assertSame('https://storage.videooptimizer.eu/put?sig=1', $init['uploadUrl']);
        self::assertSame(200, $controller->posterCompleteAction(self::post(['uuid' => self::UUID, 'key' => 'posters/p.png']))->getStatusCode());
        self::assertSame(200, $controller->posterRemoveAction(self::post(['uuid' => self::UUID]))->getStatusCode());
        self::assertSame(['POST', 'POST', 'DELETE'], array_map(fn(array $h): string => $h['request']->getMethod(), $this->history));
    }

    #[Test]
    public function posterInitiateRefusesBadImagesAndUnsafeUrls(): void
    {
        $controller = $this->controller([self::json(['data' => ['key' => 'k', 'uploadUrl' => 'http://localhost/put']])]);

        self::assertSame(400, $controller->posterInitiateAction(self::post(['uuid' => self::UUID, 'contentType' => 'image/gif', 'fileSize' => 10]))->getStatusCode());
        self::assertSame(502, $controller->posterInitiateAction(self::post(['uuid' => self::UUID, 'contentType' => 'image/png', 'fileSize' => 10]))->getStatusCode());
    }

    #[Test]
    public function posterFromFileNeedsAFileFromTheFileList(): void
    {
        $controller = $this->controller([]);

        $response = $controller->posterFromFileAction(self::post(['uuid' => self::UUID, 'file' => '1:/etc/passwd']));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('flash.poster.fileNotFound', self::data($response)['code']);
        self::assertSame([], $this->history);
    }
}
