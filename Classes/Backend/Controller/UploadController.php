<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use ScaleCommerce\VideoOptimizer\Api\ApiErrorException;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerException;
use ScaleCommerce\VideoOptimizer\Backend\Fal\PosterFileBrowser;
use ScaleCommerce\VideoOptimizer\Backend\Input\InputException;
use ScaleCommerce\VideoOptimizer\Backend\Input\UploadRequest;
use ScaleCommerce\VideoOptimizer\Backend\Security\ModuleAccess;
use ScaleCommerce\VideoOptimizer\Security\MediaUrl;
use ScaleCommerce\VideoOptimizer\Security\PublicHttpsUrl;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * AJAX endpoints of the browser upload (presigned multipart), the status polling and the
 * custom poster. The API token stays on the server: the browser only gets the presigned
 * storage URLs. The routes carry TYPO3's route token; every call checks the module permissions.
 */
#[AsController]
final class UploadController
{
    private const LLL = 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_mod.xlf:';

    public function __construct(
        private readonly VideoOptimizerClient $client,
        private readonly PosterFileBrowser $posterFiles,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /** POST {libraryId, filename, contentType, fileSize}: start a presigned multipart upload. */
    public function initiateAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded($request, 'upload', function () use ($request): ResponseInterface {
            $input = UploadRequest::initiate($this->body($request));
            $library = $this->client->getLibrary($input['libraryId']);
            if (($library['media_managed'] ?? false) !== true) {
                throw new InputException('flash.upload.notManaged');
            }

            $session = $this->client->initiateUpload($input['libraryId'], $input['filename'], $input['contentType'], $input['fileSize']);
            $parts = [];
            foreach (is_array($session['parts'] ?? null) ? $session['parts'] : [] as $part) {
                $url = is_array($part) ? ($part['url'] ?? null) : null;
                if (!is_string($url) || !PublicHttpsUrl::isAllowed($url)) {
                    throw new VideoOptimizerException('VideoOptimizer upload failed: unexpected API response.');
                }
                $parts[] = ['partNumber' => (int)$part['partNumber'], 'url' => $url];
            }
            if ($parts === [] || (string)($session['uuid'] ?? '') === '' || (int)($session['partSize'] ?? 0) <= 0) {
                throw new VideoOptimizerException('VideoOptimizer upload failed: unexpected API response.');
            }

            return new JsonResponse([
                'uuid' => (string)$session['uuid'],
                'key' => (string)($session['key'] ?? ''),
                'uploadId' => (string)($session['uploadId'] ?? ''),
                'partSize' => (int)$session['partSize'],
                'parts' => $parts,
            ]);
        });
    }

    /** POST {libraryId, session, parts, title, tags, metadata}: create the video from the uploaded parts. */
    public function completeAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded($request, 'upload', function () use ($request): ResponseInterface {
            $input = UploadRequest::complete($this->body($request));
            $result = $this->client->completeUpload($input['libraryId'], $input['session'], $input['title'], $input['parts'], $input['tags'], $input['metadata']);

            return new JsonResponse(['uuid' => (string)($result['uuid'] ?? $input['session']['uuid']), 'status' => 'processing']);
        });
    }

    /** GET ?uuid=: current state of a video (encoding status, failure reason, poster state). */
    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded($request, null, function () use ($request): ResponseInterface {
            $uuid = UploadRequest::uuid($request->getQueryParams()['uuid'] ?? null, 'flash.poster.badVideo');
            $video = $this->client->getVideoFresh($uuid);
            if ($video === []) {
                return new JsonResponse(['error' => $this->label('flash.poster.badVideo')], 404);
            }

            return new JsonResponse(self::status($video));
        }, 'GET');
    }

    /** POST {uuid, contentType, fileSize}: presigned URL for a custom poster. */
    public function posterInitiateAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded($request, 'manage', function () use ($request): ResponseInterface {
            $input = UploadRequest::posterInitiate($this->body($request));
            $session = $this->client->initiatePosterUpload($input['uuid'], $input['contentType'], $input['fileSize']);
            if (!PublicHttpsUrl::isAllowed($session['uploadUrl'])) {
                throw new VideoOptimizerException('VideoOptimizer poster upload failed: unexpected API response.');
            }

            return new JsonResponse($session);
        });
    }

    /** POST {uuid, key}: the poster is in storage, let the API process it. */
    public function posterCompleteAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded($request, 'manage', function () use ($request): ResponseInterface {
            $body = $this->body($request);
            $uuid = UploadRequest::uuid($body['uuid'] ?? null, 'flash.poster.badVideo');
            $this->client->completePosterUpload($uuid, is_string($body['key'] ?? null) ? $body['key'] : '');

            return new JsonResponse(['success' => true]);
        });
    }

    /** POST {uuid}: remove the custom poster. */
    public function posterRemoveAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded($request, 'manage', function () use ($request): ResponseInterface {
            $uuid = UploadRequest::uuid($this->body($request)['uuid'] ?? null, 'flash.poster.badVideo');
            $this->client->deletePoster($uuid);

            return new JsonResponse(['success' => true]);
        });
    }

    /** GET ?folder=: images and folders of the TYPO3 file list the editor may use as poster. */
    public function posterFilesAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded($request, 'manage', function () use ($request): ResponseInterface {
            $folder = $request->getQueryParams()['folder'] ?? '';

            return new JsonResponse($this->posterFiles->browse($this->backendUser(), is_string($folder) ? $folder : ''));
        }, 'GET');
    }

    /** POST {uuid, file}: upload an image of the file list as poster (the server sends it to storage). */
    public function posterFromFileAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->guarded($request, 'manage', function () use ($request): ResponseInterface {
            $body = $this->body($request);
            $uuid = UploadRequest::uuid($body['uuid'] ?? null, 'flash.poster.badVideo');
            $chosen = $this->posterFiles->resolve($this->backendUser(), is_string($body['file'] ?? null) ? $body['file'] : '');

            $path = $chosen['file']->getStorage()->getFileForLocalProcessing($chosen['file'], false);
            $session = $this->client->initiatePosterUpload($uuid, $chosen['contentType'], $chosen['size']);
            $this->client->putToPresignedUrl($session['uploadUrl'], $path, $chosen['contentType']);
            $this->client->completePosterUpload($uuid, $session['key']);

            return new JsonResponse(['success' => true]);
        });
    }

    /**
     * Slim video state for the polling UI.
     *
     * @param array<string, mixed> $video
     * @return array<string, mixed>
     */
    public static function status(array $video): array
    {
        $poster = is_array($video['poster'] ?? null) ? $video['poster'] : [];
        $customStatus = (string)($poster['custom_status'] ?? 'none');
        $source = (string)($poster['source'] ?? '');

        return [
            'uuid' => (string)($video['uuid'] ?? ''),
            'title' => (string)($video['title'] ?? ''),
            'status' => (string)($video['status'] ?? ''),
            'ready' => ($video['ready'] ?? false) === true,
            'error' => VideoOptimizerClient::errorReason($video),
            'poster' => [
                'source' => in_array($source, ['custom', 'thumbnail'], true) ? $source : '',
                'customStatus' => in_array($customStatus, ['none', 'processing', 'ready', 'failed'], true) ? $customStatus : 'none',
            ],
            'posterUrl' => MediaUrl::safe($video['poster_url'] ?? ''),
        ];
    }

    /**
     * Method, permission and error handling shared by all endpoints.
     *
     * @param callable(): ResponseInterface $action
     */
    private function guarded(ServerRequestInterface $request, ?string $permission, callable $action, string $method = 'POST'): ResponseInterface
    {
        if ($request->getMethod() !== $method) {
            return new JsonResponse(['error' => 'Method not allowed.'], 405);
        }
        $user = $GLOBALS['BE_USER'] ?? null;
        $allowed = $permission === null ? ModuleAccess::mayView($user) : ModuleAccess::mayDo($user, $permission);
        if (!$allowed) {
            return new JsonResponse(['error' => $this->label('flash.noPermission')], 403);
        }

        try {
            return $action();
        } catch (InputException $exception) {
            return new JsonResponse(['error' => $this->label($exception->labelKey, $exception->arguments), 'code' => $exception->labelKey], 400);
        } catch (VideoOptimizerException $exception) {
            $this->logger->warning('VideoOptimizer upload request failed: ' . $exception->getMessage(), ['exception' => $exception]);
            $code = $exception instanceof ApiErrorException ? $exception->getApiCode() : null;

            return new JsonResponse(['error' => $exception->getMessage(), 'code' => $code], 502);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $decoded = json_decode((string)$request->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function backendUser(): BackendUserAuthentication
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new InputException('flash.noPermission');
        }

        return $user;
    }

    /**
     * @param list<string|int> $arguments
     */
    private function label(string $key, array $arguments = []): string
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        $language = $this->languageServiceFactory->createFromUserPreferences($user instanceof BackendUserAuthentication ? $user : null);
        $text = $language->sL(self::LLL . $key);
        if ($text === '') {
            $text = $key;
        }

        return $arguments === [] ? $text : vsprintf($text, $arguments);
    }
}
