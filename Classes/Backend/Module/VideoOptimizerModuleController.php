<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Module;

use ScaleCommerce\VideoOptimizer\Backend\Http\SubtitleDownload;
use ScaleCommerce\VideoOptimizer\Backend\Input\UploadRequest;
use ScaleCommerce\VideoOptimizer\Backend\Input\VideoInput;
use ScaleCommerce\VideoOptimizer\Backend\Input\VideoPaging;
use ScaleCommerce\VideoOptimizer\Backend\Security\ModuleAccess;
use ScaleCommerce\VideoOptimizer\Security\MediaUrl;
use ScaleCommerce\VideoOptimizer\Security\PublicHttpsUrl;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Http\Message\UploadedFileInterface;
use ScaleCommerce\VideoOptimizer\Api\ApiErrorException;
use ScaleCommerce\VideoOptimizer\Api\EncodingOptions;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerException;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend module "VideoOptimizer" (System menu): manage the organization's
 * libraries, videos, thumbnails and subtitles via the API.
 */
final class VideoOptimizerModuleController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const MODULE_IDENTIFIER = 'system_videooptimizer';
    private const CONTROLLER_ALIAS = 'VideoOptimizerModule';

    private const MAX_VIDEO_BYTES = UploadRequest::MAX_VIDEO_BYTES;
    private const MAX_SUBTITLE_BYTES = 2 * 1024 * 1024;
    private const VIDEO_EXTENSIONS = UploadRequest::VIDEO_EXTENSIONS;
    private const ENCODING_QUALITIES = ['performance', 'quality'];

    /** Label names (after the prefix) the browser script needs. */
    private const JS_LABELS = [
        'js.upload.' => ['preparing', 'progress', 'retry', 'finishing', 'processing', 'ready', 'failed', 'failedNoReason', 'error', 'cancel', 'cancelled', 'noEtag', 'serverFallback', 'showList', 'pollLost', 'noFile'],
        'js.poster.' => ['badType', 'uploading', 'processing', 'ready', 'failed', 'error', 'removeConfirm', 'removed', 'filesTitle', 'filesEmpty', 'filesUp', 'filesRoot', 'filesError'],
    ];

    private const LLL = 'LLL:EXT:videooptimizer/Resources/Private/Language/locallang_mod.xlf:';

    public function __construct(
        private readonly VideoOptimizerClient $client,
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ComponentFactory $componentFactory,
        private readonly PageRenderer $pageRenderer,
    ) {
    }

    // ------------------------------------------------------------------
    // Libraries
    // ------------------------------------------------------------------

    public function listAction(): ResponseInterface
    {
        $libraries = [];
        $savings = [];
        $encodings = ['codecs' => [], 'resolutions' => []];
        $errorMessage = '';
        try {
            $libraries = $this->client->listLibraries();
        } catch (VideoOptimizerException $exception) {
            $errorMessage = $exception->getMessage();
        }

        try {
            $savings = $this->client->getSavings();
        } catch (VideoOptimizerException) {
            $savings = [];
        }

        try {
            $encodings = $this->client->listEncodings();
        } catch (VideoOptimizerException) {
            $encodings = ['codecs' => [], 'resolutions' => []];
        }
        $encodingChoices = [
            'codecs' => $this->encodingChoices($encodings['codecs']),
            'resolutions' => $this->encodingChoices($encodings['resolutions']),
        ];

        $libraryRows = [];
        foreach ($libraries as $library) {
            if (!is_array($library)) {
                continue;
            }
            $id = (string)($library['id'] ?? '');
            $resolutionKeys = array_values(array_filter(array_map('trim', explode(',', (string)($library['resolutions'] ?? '')))));
            $resolutionOptions = EncodingOptions::editChoices((array)($library['available_resolutions'] ?? []), $resolutionKeys);
            $libraryRows[] = [
                'id' => $id,
                'name' => (string)($library['name'] ?? ''),
                'description' => (string)($library['description'] ?? ''),
                'codec' => (string)($library['codec'] ?? ''),
                'encodingQuality' => in_array($library['encoding_quality'] ?? '', self::ENCODING_QUALITIES, true) ? (string)$library['encoding_quality'] : '',
                'resolutions' => implode(', ', $resolutionKeys),
                'resolutionKeys' => $resolutionKeys,
                'resolutionOptions' => $resolutionOptions,
                'availableCodecs' => array_map('strval', (array)($library['available_codecs'] ?? [])),
                'availableResolutions' => array_map('strval', (array)($library['available_resolutions'] ?? [])),
                'video_count' => (int)($library['video_count'] ?? 0),
                'media_managed' => (bool)($library['media_managed'] ?? false),
                'updateUrl' => $this->moduleUrl('updateLibrary', ['libraryId' => $id]),
                'deleteUrl' => $this->moduleUrl('deleteLibrary', ['libraryId' => $id]),
                'reprocessUrl' => $this->moduleUrl('reprocessLibrary', ['libraryId' => $id]),
                'videosUrl' => $this->moduleUrl('videos', ['libraryId' => $id]),
                'updateToken' => $this->formToken('updateLibrary', $id),
                'deleteToken' => $this->formToken('deleteLibrary', $id),
                'reprocessToken' => $this->formToken('reprocessLibrary', $id),
            ];
        }

        $view = $this->createView();
        $view->assign('libraryRows', $libraryRows);
        $view->assign('savings', $savings);
        $view->assign('encodings', $encodingChoices);
        $view->assign('errorMessage', $errorMessage);
        $view->assign('createUrl', $this->moduleUrl('create'));
        $view->assign('createToken', $this->formToken('create'));

        return $this->renderView($view, 'List', 'list.title', '');
    }

    /**
     * @param list<string> $resolutions
     */
    public function createAction(
        string $formToken = '',
        string $name = '',
        string $description = '',
        array $resolutions = [],
        string $codec = '',
        string $encodingQuality = '',
    ): ResponseInterface {
        if (($denied = $this->guardMutation('manage', 'list', [])) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'create')) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('list');
        }

        $name = trim($name);
        if ($name === '') {
            $this->flash('flash.library.nameRequired', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('list');
        }

        try {
            $payload = ['name' => $name];
            foreach (['description' => trim($description), 'resolutions' => implode(',', array_filter(array_map('strval', $resolutions))), 'codec' => trim($codec)] as $key => $value) {
                if ($value !== '') {
                    $payload[$key] = $value;
                }
            }
            if (in_array($encodingQuality, self::ENCODING_QUALITIES, true)) {
                $payload['encoding_quality'] = $encodingQuality;
            }

            $library = $this->client->createLibrary($payload);
            $id = substr((string)($library['id'] ?? ''), 0, 8);
            $this->flash('flash.library.created', ContextualFeedbackSeverity::OK, [$name, $id]);
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('list');
    }

    /**
     * @param list<string> $resolutions
     */
    public function updateLibraryAction(
        string $libraryId = '',
        string $formToken = '',
        string $name = '',
        string $description = '',
        array $resolutions = [],
        string $codec = '',
        string $encodingQuality = '',
    ): ResponseInterface {
        if (($denied = $this->guardMutation('manage', 'list', [])) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'updateLibrary', $libraryId)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('list');
        }

        try {
            $payload = [];
            foreach (['name' => trim($name), 'resolutions' => implode(',', array_filter(array_map('strval', $resolutions))), 'codec' => trim($codec)] as $key => $value) {
                if ($value !== '') {
                    $payload[$key] = $value;
                }
            }
            if (in_array($encodingQuality, self::ENCODING_QUALITIES, true)) {
                $payload['encoding_quality'] = $encodingQuality;
            }
            // The description is optional, so a submitted empty field means "clear it".
            $parsedBody = $this->request->getParsedBody();
            if (is_array($parsedBody) && array_key_exists('description', $parsedBody)) {
                $payload['description'] = trim($description);
            }
            if ($payload === []) {
                $this->flash('flash.library.nothingToUpdate', ContextualFeedbackSeverity::WARNING);

                return $this->redirect('list');
            }

            $library = $this->client->updateLibrary($libraryId, $payload);
            $this->flash('flash.library.updated', ContextualFeedbackSeverity::OK, [(string)($library['name'] ?? $libraryId)]);
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('list');
    }

    public function deleteLibraryAction(string $libraryId = '', string $formToken = ''): ResponseInterface
    {
        if (($denied = $this->guardMutation('delete', 'list', [])) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'deleteLibrary', $libraryId)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('list');
        }

        try {
            $this->client->deleteLibrary($libraryId);
            $this->flash('flash.library.deleted', ContextualFeedbackSeverity::OK, [substr($libraryId, 0, 8)]);
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('list');
    }

    public function reprocessLibraryAction(string $libraryId = '', string $formToken = '', string $force = ''): ResponseInterface
    {
        if (($denied = $this->guardMutation('delete', 'list', [])) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'reprocessLibrary', $libraryId)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('list');
        }

        if (!$this->isMediaManaged($libraryId)) {
            $this->flash('flash.library.reprocessNotManaged', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('list');
        }

        try {
            $result = $this->client->reprocessLibrary($libraryId, $force === '1');
            $queued = (int)($result['queued'] ?? 0);
            if ($queued > 0) {
                $this->flash('flash.library.reprocessQueued', ContextualFeedbackSeverity::OK, [$queued]);
            } else {
                $this->flash('flash.library.reprocessStarted', ContextualFeedbackSeverity::OK);
            }
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('list');
    }

    // ------------------------------------------------------------------
    // Videos of a library
    // ------------------------------------------------------------------

    public function videosAction(string $libraryId = '', string $tagFilter = '', int $pages = 1): ResponseInterface
    {
        $libraryId = trim($libraryId);
        if ($libraryId === '') {
            return $this->redirect('list');
        }
        $pages = VideoPaging::clampPages($pages);
        $tagFilter = trim($tagFilter);

        $library = [];
        $videos = [];
        $allTags = [];
        $nextCursor = null;
        $loadedPages = $pages;
        $errorMessage = '';
        try {
            $library = $this->client->getLibrary($libraryId);
            $filterTags = VideoInput::parseTags($tagFilter);
            // Always the current state (no cache): the list shows encoding progress.
            $result = VideoPaging::load(
                fn(?string $cursor): array => $this->client->listVideosPage(VideoPaging::PAGE_SIZE, $cursor, $libraryId, $filterTags, true),
                $pages,
            );
            $videos = $result['items'];
            $nextCursor = $result['nextCursor'];
            $loadedPages = $result['pages'];
        } catch (VideoOptimizerException $exception) {
            $errorMessage = $exception->getMessage();
        }
        if ($library === [] && $errorMessage === '') {
            $errorMessage = $this->label('videos.error.libraryNotFound');
        }

        try {
            $allTags = $this->client->listTags();
        } catch (VideoOptimizerException) {
            $allTags = [];
        }

        $hasProcessing = false;
        foreach ($videos as $video) {
            if ((string)($video['status'] ?? '') === 'processing') {
                $hasProcessing = true;
                break;
            }
        }

        $view = $this->createView();
        $view->assign('library', $library);
        $view->assign('libraryId', $libraryId);
        $view->assign('videoRows', $this->formatVideoRows($videos, $libraryId));
        $view->assign('count', $tagFilter === '' && isset($library['video_count']) ? (int)$library['video_count'] : count($videos));
        $view->assign('loaded', count($videos));
        $view->assign('pages', $loadedPages);
        $view->assign('hasMore', $nextCursor !== null);
        $view->assign('loadMoreUrl', $nextCursor !== null ? $this->moduleUrl('videos', ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $loadedPages + 1]) : '');
        $view->assign('errorMessage', $errorMessage);
        $view->assign('tagFilter', $tagFilter);
        $view->assign('allTags', array_map(static fn(array $t) => (string)($t['name'] ?? ''), array_filter($allTags, 'is_array')));
        $view->assign('hasProcessing', $hasProcessing);
        $view->assign('autoRefreshUrl', $hasProcessing ? $this->moduleUrl('videos', ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $loadedPages]) : '');
        $view->assign('backUrl', $this->moduleUrl('list'));
        $view->assign('videosUrl', $this->moduleUrl('videos', ['libraryId' => $libraryId]));
        // A GET form drops the action URL's query string, so carry the route token as a hidden field.
        parse_str((string)parse_url($this->moduleUrl('videos'), PHP_URL_QUERY), $routeQuery);
        $view->assign('routeToken', (string)($routeQuery['token'] ?? ''));
        $view->assign('uploadUrl', $this->moduleUrl('upload', ['libraryId' => $libraryId]));
        $view->assign('ingestUrl', $this->moduleUrl('ingest', ['libraryId' => $libraryId]));
        $view->assign('uploadToken', $this->formToken('upload', $libraryId));
        $view->assign('ingestToken', $this->formToken('ingest', $libraryId));
        $view->assign('uploadConfig', $this->browserConfig($libraryId, '', [
            'initiate' => 'videooptimizer_upload_initiate',
            'complete' => 'videooptimizer_upload_complete',
            'status' => 'videooptimizer_video_status',
        ], 'js.upload.'));

        $this->addBackButton($view, $this->moduleUrl('list'), 'nav.backToLibraries');
        return $this->renderView($view, 'Videos', 'list.title', (string)($library['name'] ?? ''));
    }

    public function uploadAction(
        string $libraryId = '',
        string $formToken = '',
        string $title = '',
        string $tags = '',
        string $metadata = '',
    ): ResponseInterface {
        if (($denied = $this->guardMutation('upload', 'videos', ['libraryId' => trim($libraryId)])) !== null) {
            return $denied;
        }
        $libraryId = trim($libraryId);

        if (!$this->validateFormToken($formToken, 'upload', $libraryId)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId]);
        }

        $uploadedFile = $this->request->getUploadedFiles()['file'] ?? null;
        if (!$uploadedFile instanceof UploadedFileInterface || $uploadedFile->getError() !== \UPLOAD_ERR_OK) {
            $this->flash('flash.upload.noFile', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId]);
        }

        if (!$this->isMediaManaged($libraryId)) {
            $this->flash('flash.upload.notManaged', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId]);
        }

        $filename = basename(str_replace('\\', '/', (string)$uploadedFile->getClientFilename()));
        if ($filename === '') {
            $filename = 'upload.mp4';
        }
        if (!in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::VIDEO_EXTENSIONS, true)) {
            $this->flash('flash.upload.badType', ContextualFeedbackSeverity::ERROR, [implode(', ', self::VIDEO_EXTENSIONS)]);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId]);
        }
        $size = $uploadedFile->getSize();
        if ($size === null || $size <= 0 || $size > self::MAX_VIDEO_BYTES) {
            $this->flash('flash.upload.badSize', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId]);
        }

        try {
            // The client needs a seekable file path (size checks + presigned part reads).
            [$tmpPath, $ownsTmp] = $this->stageUpload($uploadedFile, 'vo_upload_');

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
            if (!is_string($mime) || !str_starts_with($mime, 'video/')) {
                $this->flash('flash.upload.notVideo', ContextualFeedbackSeverity::ERROR, [$mime === false ? $this->label('flash.upload.unknownType') : $mime]);

                return $this->redirect('videos', null, null, ['libraryId' => $libraryId]);
            }

            $result = $this->client->uploadVideo(
                $libraryId,
                $tmpPath,
                $filename,
                trim($title) !== '' ? trim($title) : null,
                VideoInput::parseTags($tags),
                VideoInput::parseMetadata($metadata),
            );
            $this->flash('flash.upload.done', ContextualFeedbackSeverity::OK, [$filename, substr((string)$result['uuid'], 0, 8)]);
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        } catch (\RuntimeException $exception) {
            $this->logger?->error('VideoOptimizer: staging an uploaded file failed', ['exception' => $exception]);
            $this->flash('flash.upload.staging', ContextualFeedbackSeverity::ERROR);
        } finally {
            if (($ownsTmp ?? false) && isset($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        return $this->redirect('videos', null, null, ['libraryId' => $libraryId]);
    }

    public function ingestAction(
        string $libraryId = '',
        string $formToken = '',
        string $sourceUrl = '',
        string $title = '',
        string $tags = '',
        string $metadata = '',
    ): ResponseInterface {
        if (($denied = $this->guardMutation('upload', 'videos', ['libraryId' => trim($libraryId)])) !== null) {
            return $denied;
        }
        $libraryId = trim($libraryId);

        if (!$this->validateFormToken($formToken, 'ingest', $libraryId)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId]);
        }

        $sourceUrl = trim($sourceUrl);
        if (($urlError = $this->validateSourceUrl($sourceUrl)) !== null) {
            $this->flashText($urlError, ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId]);
        }

        if (!$this->isMediaManaged($libraryId)) {
            $this->flash('flash.ingest.notManaged', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId]);
        }

        try {
            $result = $this->client->ingestVideoFromUrl(
                $libraryId,
                $sourceUrl,
                trim($title) !== '' ? trim($title) : null,
                VideoInput::parseTags($tags),
                VideoInput::parseMetadata($metadata),
            );
            $this->flash('flash.ingest.done', ContextualFeedbackSeverity::OK, [substr((string)$result['uuid'], 0, 8)]);
        } catch (ApiErrorException $exception) {
            if ($exception->getApiCode() === 'source_url_video_platform') {
                $this->flash('flash.ingest.videoPlatform', ContextualFeedbackSeverity::ERROR);
            } else {
                $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
            }
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('videos', null, null, ['libraryId' => $libraryId]);
    }

    public function renameAction(
        string $libraryId = '',
        string $videoUuid = '',
        string $formToken = '',
        string $title = '',
        string $tagFilter = '',
        int $pages = 1,
    ): ResponseInterface {
        if (($denied = $this->guardMutation('manage', 'videos', ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $pages])) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'rename', $videoUuid)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $pages]);
        }

        $title = trim($title);
        if ($title === '') {
            $this->flash('flash.video.titleRequired', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $pages]);
        }

        try {
            $this->client->updateVideo($videoUuid, ['title' => $title]);
            $this->flash('flash.video.renamed', ContextualFeedbackSeverity::OK, [$title]);
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('videos', null, null, ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $pages]);
    }

    public function setTagsAction(
        string $libraryId = '',
        string $videoUuid = '',
        string $formToken = '',
        string $tags = '',
        string $tagFilter = '',
        int $pages = 1,
    ): ResponseInterface {
        if (($denied = $this->guardMutation('manage', 'videos', ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $pages])) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'setTags', $videoUuid)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $pages]);
        }

        try {
            $this->client->updateVideo($videoUuid, ['tags' => VideoInput::parseTags($tags)]);
            $this->flash('flash.video.tagsUpdated', ContextualFeedbackSeverity::OK);
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('videos', null, null, ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $pages]);
    }

    public function setMetadataAction(
        string $libraryId = '',
        string $videoUuid = '',
        string $formToken = '',
        string $metadata = '',
        string $tagFilter = '',
        int $pages = 1,
    ): ResponseInterface {
        $redirectArguments = ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $pages];
        if (($denied = $this->guardMutation('manage', 'videos', $redirectArguments)) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'setMetadata', $videoUuid)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, $redirectArguments);
        }

        try {
            $parsed = VideoInput::parseMetadataDetailed($metadata);
            if ($parsed['invalid'] !== []) {
                // Nothing is saved: a mistyped line would otherwise delete its key.
                $this->flash('flash.video.metadataInvalid', ContextualFeedbackSeverity::ERROR, [implode(' | ', array_slice($parsed['invalid'], 0, 5))]);

                return $this->redirect('videos', null, null, $redirectArguments);
            }
            // The API merges metadata: compare with the current state so removed lines delete their keys.
            $current = (array)($this->client->getVideoFresh($videoUuid)['metadata'] ?? []);
            $patch = VideoInput::metadataPatch($current, $parsed['metadata']);
            if ($patch === []) {
                $this->flash('flash.video.metadataUnchanged', ContextualFeedbackSeverity::INFO);
            } else {
                $this->client->updateVideo($videoUuid, ['metadata' => $patch]);
                $this->flash('flash.video.metadataUpdated', ContextualFeedbackSeverity::OK);
            }
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('videos', null, null, $redirectArguments);
    }

    public function deleteVideoAction(
        string $libraryId = '',
        string $videoUuid = '',
        string $formToken = '',
        string $tagFilter = '',
        int $pages = 1,
    ): ResponseInterface {
        if (($denied = $this->guardMutation('delete', 'videos', ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $pages])) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'deleteVideo', $videoUuid)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('videos', null, null, ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $pages]);
        }

        try {
            $this->client->deleteVideo($videoUuid);
            $this->flash('flash.video.deleted', ContextualFeedbackSeverity::OK, [substr($videoUuid, 0, 8)]);
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('videos', null, null, ['libraryId' => $libraryId, 'tagFilter' => $tagFilter, 'pages' => $pages]);
    }

    // ------------------------------------------------------------------
    // Thumbnails of a video
    // ------------------------------------------------------------------

    public function thumbnailAction(string $libraryId = '', string $videoUuid = ''): ResponseInterface
    {
        if ($libraryId === '' || $videoUuid === '') {
            return $this->redirect('list');
        }

        $video = [];
        $thumbnails = [];
        $errorMessage = '';
        try {
            $video = $this->client->getVideo($videoUuid);
            $thumbnails = $this->client->listThumbnails($videoUuid);
        } catch (VideoOptimizerException $exception) {
            $errorMessage = $exception->getMessage();
        }

        $videoTitle = trim((string)($video['title'] ?? '')) !== '' ? (string)$video['title'] : $this->label('videos.fallbackTitle', [substr($videoUuid, 0, 8)]);
        $view = $this->createView();
        $view->assign('libraryId', $libraryId);
        $view->assign('videoUuid', $videoUuid);
        $view->assign('videoTitle', $videoTitle);
        $view->assign('currentThumbnail', MediaUrl::safe($video['poster_url'] ?? '') ?: MediaUrl::safe($video['thumbnail_url'] ?? ''));
        $view->assign('thumbnails', array_map(
            static fn(array $t): array => ['index' => (int)($t['index'] ?? 0), 'url' => MediaUrl::safe($t['url'] ?? '')],
            array_filter($thumbnails, 'is_array'),
        ));
        $view->assign('errorMessage', $errorMessage);
        $view->assign('backUrl', $this->moduleUrl('videos', ['libraryId' => $libraryId]));
        $view->assign('setThumbnailUrl', $this->moduleUrl('setThumbnail', ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]));
        $view->assign('setThumbnailToken', $this->formToken('setThumbnail', $videoUuid));
        $poster = is_array($video['poster'] ?? null) ? $video['poster'] : [];
        $customStatus = in_array($poster['custom_status'] ?? '', ['none', 'processing', 'ready', 'failed'], true) ? (string)$poster['custom_status'] : 'none';
        $view->assign('posterManaged', $video !== [] && $this->isMediaManaged((string)($video['library_id'] ?? '') ?: $libraryId));
        $view->assign('posterCustomStatus', $customStatus);
        $view->assign('posterStatusLabel', $this->label('poster.status.' . $customStatus));
        $view->assign('posterConfig', $this->browserConfig($libraryId, $videoUuid, [
            'initiate' => 'videooptimizer_poster_initiate',
            'complete' => 'videooptimizer_poster_complete',
            'select' => 'videooptimizer_poster_select',
            'remove' => 'videooptimizer_poster_remove',
            'status' => 'videooptimizer_video_status',
            'files' => 'videooptimizer_poster_files',
            'fromFile' => 'videooptimizer_poster_from_file',
        ], 'js.poster.', ['customStatus' => $customStatus, 'source' => (string)($poster['source'] ?? ''), 'posterUrl' => MediaUrl::safe($video['poster_url'] ?? '')]));

        $this->addBackButton($view, $this->moduleUrl('videos', ['libraryId' => $libraryId]), 'nav.backToVideos');
        return $this->renderView($view, 'Thumbnail', 'thumbnail.docTitle', $videoTitle);
    }

    public function setThumbnailAction(
        string $libraryId = '',
        string $videoUuid = '',
        string $formToken = '',
        int $index = 0,
    ): ResponseInterface {
        if (($denied = $this->guardMutation('manage', 'thumbnail', ['libraryId' => $libraryId, 'videoUuid' => $videoUuid])) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'setThumbnail', $videoUuid)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('thumbnail', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
        }

        try {
            $video = $this->client->getVideo($videoUuid);
            $ownerLibrary = trim((string)($video['library_id'] ?? '')) !== '' ? (string)$video['library_id'] : $libraryId;
            if (!$this->isMediaManaged($ownerLibrary)) {
                $this->flash('flash.thumbnail.notManaged', ContextualFeedbackSeverity::ERROR);

                return $this->redirect('thumbnail', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
            }
            $this->client->setThumbnail($videoUuid, $index);
            $this->flash('flash.thumbnail.set', ContextualFeedbackSeverity::OK, [$index]);
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('thumbnail', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
    }

    // ------------------------------------------------------------------
    // Subtitles of a video
    // ------------------------------------------------------------------

    public function subtitlesAction(string $libraryId = '', string $videoUuid = ''): ResponseInterface
    {
        if ($libraryId === '' || $videoUuid === '') {
            return $this->redirect('list');
        }

        $video = [];
        $tracks = [];
        $languages = [];
        $errorMessage = '';
        try {
            $video = $this->client->getVideo($videoUuid);
            $tracks = $this->client->listSubtitles($videoUuid);
        } catch (VideoOptimizerException $exception) {
            $errorMessage = $exception->getMessage();
        }

        try {
            $languages = $this->client->listSubtitleLanguages();
        } catch (VideoOptimizerException) {
            $languages = [];
        }

        $videoTitle = trim((string)($video['title'] ?? '')) !== '' ? (string)$video['title'] : $this->label('videos.fallbackTitle', [substr($videoUuid, 0, 8)]);
        $view = $this->createView();
        $view->assign('libraryId', $libraryId);
        $view->assign('videoUuid', $videoUuid);
        $view->assign('videoTitle', $videoTitle);
        $view->assign('tracks', array_values(array_filter($tracks, 'is_array')));
        $view->assign('languages', array_map(
            static fn(array $l): array => [
                'code' => (string)($l['code'] ?? ''),
                'label' => (string)($l['label'] ?? ''),
                'videoCount' => (int)($l['video_count'] ?? 0),
            ],
            array_filter($languages, 'is_array'),
        ));
        $view->assign('errorMessage', $errorMessage);
        $view->assign('backUrl', $this->moduleUrl('videos', ['libraryId' => $libraryId]));
        $view->assign('downloadSubtitleUrl', $this->moduleUrl('downloadSubtitle', ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]));
        $view->assign('uploadSubtitleUrl', $this->moduleUrl('uploadSubtitle', ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]));
        $view->assign('deleteSubtitleUrl', $this->moduleUrl('deleteSubtitle', ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]));
        $view->assign('uploadSubtitleToken', $this->formToken('uploadSubtitle', $videoUuid));
        $view->assign('deleteSubtitleToken', $this->formToken('deleteSubtitle', $videoUuid));
        $view->assign('deleteLanguageUrl', $this->moduleUrl('deleteSubtitleLanguage', ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]));
        $view->assign('deleteLanguageToken', $this->formToken('deleteSubtitleLanguage', $videoUuid));

        $this->addBackButton($view, $this->moduleUrl('videos', ['libraryId' => $libraryId]), 'nav.backToVideos');
        return $this->renderView($view, 'Subtitles', 'subtitles.docTitle', $videoTitle);
    }

    public function uploadSubtitleAction(
        string $libraryId = '',
        string $videoUuid = '',
        string $formToken = '',
        string $language = '',
        string $kind = '',
        bool $isDefault = false,
    ): ResponseInterface {
        if (($denied = $this->guardMutation('manage', 'subtitles', ['libraryId' => $libraryId, 'videoUuid' => $videoUuid])) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'uploadSubtitle', $videoUuid)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('subtitles', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
        }

        $language = trim($language);
        if ($language === '') {
            $this->flash('flash.subtitle.languageRequired', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('subtitles', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
        }

        $uploadedFile = $this->request->getUploadedFiles()['file'] ?? null;
        if (!$uploadedFile instanceof UploadedFileInterface || $uploadedFile->getError() !== \UPLOAD_ERR_OK) {
            $this->flash('flash.subtitle.noFile', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('subtitles', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
        }

        $filename = (string)$uploadedFile->getClientFilename();
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ['srt', 'vtt'], true)) {
            $this->flash('flash.subtitle.badType', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('subtitles', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
        }

        $size = $uploadedFile->getSize();
        if ($size === null || $size <= 0 || $size > self::MAX_SUBTITLE_BYTES) {
            $this->flash('flash.subtitle.badSize', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('subtitles', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
        }

        try {
            [$tmpPath, $ownsTmp] = $this->stageUpload($uploadedFile, 'vo_sub_');

            $kindValue = in_array($kind, ['subtitles', 'captions'], true) ? $kind : null;
            $this->client->uploadSubtitle($videoUuid, $tmpPath, $language, $kindValue, $isDefault);
            $this->flash('flash.subtitle.uploaded', ContextualFeedbackSeverity::OK, [$language]);
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        } catch (\RuntimeException $exception) {
            $this->logger?->error('VideoOptimizer: staging an uploaded file failed', ['exception' => $exception]);
            $this->flash('flash.upload.staging', ContextualFeedbackSeverity::ERROR);
        } finally {
            if (($ownsTmp ?? false) && isset($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        return $this->redirect('subtitles', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
    }

    /**
     * Download the stored WebVTT file of one language (read-only, GET).
     */
    public function downloadSubtitleAction(string $libraryId = '', string $videoUuid = '', string $language = ''): ResponseInterface
    {
        $language = trim($language);
        if ($language === '' || $videoUuid === '') {
            $this->flash('flash.subtitle.languageMissing', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('subtitles', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
        }

        try {
            $vtt = $this->client->downloadSubtitle($videoUuid, $language);
            $title = (string)($this->client->getVideo($videoUuid)['title'] ?? '');
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);

            return $this->redirect('subtitles', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
        }

        return SubtitleDownload::response($vtt, $title, $language);
    }

    public function deleteSubtitleAction(
        string $libraryId = '',
        string $videoUuid = '',
        string $formToken = '',
        string $language = '',
    ): ResponseInterface {
        if (($denied = $this->guardMutation('delete', 'subtitles', ['libraryId' => $libraryId, 'videoUuid' => $videoUuid])) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'deleteSubtitle', $videoUuid)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('subtitles', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
        }

        if (trim($language) === '') {
            $this->flash('flash.subtitle.languageMissing', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('subtitles', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
        }

        try {
            $this->client->deleteSubtitle($videoUuid, trim($language));
            $this->flash('flash.subtitle.deleted', ContextualFeedbackSeverity::OK, [trim($language)]);
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('subtitles', null, null, ['libraryId' => $libraryId, 'videoUuid' => $videoUuid]);
    }

    /**
     * Remove a subtitle language of the organization (refused by the API while videos still use it).
     */
    public function deleteSubtitleLanguageAction(
        string $libraryId = '',
        string $videoUuid = '',
        string $formToken = '',
        string $language = '',
    ): ResponseInterface {
        $redirectArguments = ['libraryId' => $libraryId, 'videoUuid' => $videoUuid];
        if (($denied = $this->guardMutation('delete', 'subtitles', $redirectArguments)) !== null) {
            return $denied;
        }
        if (!$this->validateFormToken($formToken, 'deleteSubtitleLanguage', $videoUuid)) {
            $this->flash('flash.invalidToken', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('subtitles', null, null, $redirectArguments);
        }

        $language = trim($language);
        if ($language === '') {
            $this->flash('flash.subtitle.languageMissing', ContextualFeedbackSeverity::ERROR);

            return $this->redirect('subtitles', null, null, $redirectArguments);
        }

        try {
            $this->client->deleteSubtitleLanguage($language);
            $this->flash('flash.subtitleLanguage.deleted', ContextualFeedbackSeverity::OK, [$language]);
        } catch (ApiErrorException $exception) {
            if ($exception->getHttpStatus() === 409 || $exception->getApiCode() === 'subtitle_language_in_use') {
                $this->flash('flash.subtitleLanguage.inUse', ContextualFeedbackSeverity::ERROR, [$language]);
            } else {
                $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
            }
        } catch (VideoOptimizerException $exception) {
            $this->flashText($exception->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return $this->redirect('subtitles', null, null, $redirectArguments);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Module frame + translation helpers
    // ------------------------------------------------------------------

    private function createView(): ModuleTemplate
    {
        return $this->moduleTemplateFactory->create($this->request);
    }

    private function renderView(ModuleTemplate $view, string $template, string $titleKey, string $titleContext = ''): ResponseInterface
    {
        $view->setTitle($this->label($titleKey), $titleContext);
        $this->pageRenderer->addCssFile('EXT:videooptimizer/Resources/Public/Css/backend-module.css');
        $this->pageRenderer->loadJavaScriptModule('@scalecommerce/videooptimizer/backend-module.js');
        $this->pageRenderer->loadJavaScriptModule('@scalecommerce/videooptimizer/upload.js');

        return $view->renderResponse('VideoOptimizerModule/' . $template);
    }

    /**
     * JSON for the browser upload / poster script (data attribute): AJAX URLs (with TYPO3's route
     * token), ids, and the translated labels (`js.upload.*` / `js.poster.*`). No secrets.
     *
     * @param array<string, string> $routes key => AJAX route name
     * @param array<string, mixed> $state
     */
    private function browserConfig(string $libraryId, string $videoUuid, array $routes, string $labelPrefix, array $state = []): string
    {
        $uriBuilder = GeneralUtility::makeInstance(BackendUriBuilder::class);
        $urls = [];
        foreach ($routes as $key => $route) {
            $urls[$key] = (string)$uriBuilder->buildUriFromRoute('ajax_' . $route);
        }
        $labels = [];
        foreach (self::JS_LABELS[$labelPrefix] ?? [] as $name) {
            $labels[$name] = $this->label($labelPrefix . $name);
        }

        return (string)json_encode([
            'libraryId' => $libraryId,
            'videoUuid' => $videoUuid,
            'urls' => $urls,
            'labels' => $labels,
            'state' => $state,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function addBackButton(ModuleTemplate $view, string $url, string $labelKey): void
    {
        $button = $this->componentFactory->createBackButton($url)->setTitle($this->label($labelKey));
        $view->addButtonToButtonBar($button, ButtonBar::BUTTON_POSITION_LEFT, 1);
    }

    /**
     * @param list<string|int> $arguments sprintf arguments for the label
     */
    private function label(string $key, array $arguments = []): string
    {
        $text = (string)$GLOBALS['LANG']->sL(self::LLL . $key);
        if ($text === '') {
            $text = $key;
        }

        return $arguments === [] ? $text : vsprintf($text, $arguments);
    }

    /**
     * Enqueue a translated flash message into the queue the ModuleTemplate renders.
     *
     * @param list<string|int> $arguments
     */
    private function flash(string $key, ContextualFeedbackSeverity $severity, array $arguments = []): void
    {
        $this->flashText($this->label($key, $arguments), $severity);
    }

    private function flashText(string $text, ContextualFeedbackSeverity $severity): void
    {
        $this->getFlashMessageQueue(FlashMessageQueue::FLASHMESSAGE_QUEUE)
            ->enqueue(new FlashMessage($text, '', $severity, true));
    }

    /**
     * Common gate for every mutating action: POST only + custom permission option.
     * Returns a redirect response when the request must be refused, null otherwise.
     *
     * @param array<string, mixed> $redirectArguments
     */
    private function guardMutation(string $permission, string $redirectAction, array $redirectArguments): ?ResponseInterface
    {
        if ($this->request->getMethod() !== 'POST') {
            $this->flash('flash.notPost', ContextualFeedbackSeverity::ERROR);

            return $this->redirect($redirectAction, null, null, $redirectArguments);
        }

        $allowed = ModuleAccess::mayDo($GLOBALS['BE_USER'] ?? null, $permission);
        if (!$allowed) {
            $this->flash('flash.noPermission', ContextualFeedbackSeverity::ERROR);

            return $this->redirect($redirectAction, null, null, $redirectArguments);
        }

        return null;
    }

    /**
     * Options for the create form: add-ons the organization has not unlocked stay visible but disabled.
     *
     * @param array<mixed> $options
     * @return list<array{key: string, label: string, available: bool}>
     */
    private function encodingChoices(array $options): array
    {
        $choices = [];
        foreach (EncodingOptions::normalize($options) as $option) {
            $label = $option['label'];
            if (!$option['available']) {
                $label = $this->label('list.encoding.locked', [$label]);
            } elseif ($option['addon']) {
                $label = $this->label('list.encoding.addon', [$label]);
            }
            $choices[] = ['key' => $option['key'], 'label' => $label, 'available' => $option['available']];
        }

        return $choices;
    }

    /**
     * Server-side media_managed enforcement (the templates only hide the UI).
     */
    private function isMediaManaged(string $libraryId): bool
    {
        try {
            $library = $this->client->getLibrary($libraryId);
        } catch (VideoOptimizerException) {
            return false;
        }

        return ($library['media_managed'] ?? false) === true;
    }

    /**
     * Validate an ingest source URL; returns an error message or null when acceptable.
     */
    private function validateSourceUrl(string $url): ?string
    {
        return PublicHttpsUrl::isAllowed($url) ? null : $this->label('flash.ingest.invalidUrl');
    }

    /**
     * @param list<array<string, mixed>> $videos
     * @return list<array<string, mixed>>
     */
    private function formatVideoRows(array $videos, string $libraryId): array
    {
        $rows = [];
        foreach ($videos as $video) {
            if (!is_array($video)) {
                continue;
            }

            $uuid = (string)($video['uuid'] ?? '');
            $status = (string)($video['status'] ?? '');
            $statusClass = match ($status) {
                'ready' => 'text-bg-success',
                'processing' => 'text-bg-warning',
                default => 'text-bg-danger',
            };
            $statusLabel = match ($status) {
                '' => $this->label('videos.status.unknown'),
                'ready' => $this->label('videos.status.ready'),
                'processing' => $this->label('videos.status.processing'),
                'error', 'failed' => $this->label('videos.status.error'),
                default => ucfirst($status),
            };

            $rows[] = [
                'uuid' => $uuid,
                'title' => trim((string)($video['title'] ?? '')) !== ''
                    ? (string)$video['title']
                    : $this->label('videos.fallbackTitle', [substr($uuid, 0, 8)]),
                'status' => $statusLabel,
                'statusClass' => $statusClass,
                'errorReason' => VideoOptimizerClient::errorReason($video),
                'duration' => $this->formatDuration($video['duration'] ?? 0),
                'created' => (string)($video['created_at'] ?? ''),
                'tags' => implode(', ', array_map('strval', (array)($video['tags'] ?? []))),
                'metadata' => $this->formatMetadata((array)($video['metadata'] ?? [])),
                'metadataLines' => VideoInput::metadataToLines((array)($video['metadata'] ?? [])),
                'setMetadataUrl' => $this->moduleUrl('setMetadata', ['libraryId' => $libraryId, 'videoUuid' => $uuid]),
                'setMetadataToken' => $this->formToken('setMetadata', $uuid),
                'thumbnailUrl' => MediaUrl::safe($video['poster_url'] ?? '') ?: MediaUrl::safe($video['thumbnail_url'] ?? ''),
                'renameUrl' => $this->moduleUrl('rename', ['libraryId' => $libraryId, 'videoUuid' => $uuid]),
                'setTagsUrl' => $this->moduleUrl('setTags', ['libraryId' => $libraryId, 'videoUuid' => $uuid]),
                'deleteVideoUrl' => $this->moduleUrl('deleteVideo', ['libraryId' => $libraryId, 'videoUuid' => $uuid]),
                'thumbnailPickerUrl' => $this->moduleUrl('thumbnail', ['libraryId' => $libraryId, 'videoUuid' => $uuid]),
                'subtitlesUrl' => $this->moduleUrl('subtitles', ['libraryId' => $libraryId, 'videoUuid' => $uuid]),
                'renameToken' => $this->formToken('rename', $uuid),
                'setTagsToken' => $this->formToken('setTags', $uuid),
                'deleteVideoToken' => $this->formToken('deleteVideo', $uuid),
            ];
        }

        return $rows;
    }

    /**
     * Compact "key: value · key: value" display for a video row.
     *
     * @param array<string, mixed> $metadata
     */
    private function formatMetadata(array $metadata): string
    {
        $parts = [];
        foreach ($metadata as $key => $value) {
            $displayKey = trim((string)$key);
            $displayValue = trim((string)$value);
            if ($displayKey === '' || $displayValue === '') {
                continue;
            }
            $parts[] = $displayKey . ': ' . $displayValue;
        }

        return implode(' · ', $parts);
    }

    private function formatDuration(mixed $seconds): string
    {
        $total = (int)$seconds;
        if ($total <= 0) {
            return '';
        }

        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);
        $secs = $total % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $secs)
            : sprintf('%d:%02d', $minutes, $secs);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function moduleUrl(string $action, array $arguments = []): string
    {
        $uriBuilder = GeneralUtility::makeInstance(BackendUriBuilder::class);

        return (string)$uriBuilder->buildUriFromRoute(
            self::MODULE_IDENTIFIER . '.' . self::CONTROLLER_ALIAS . '_' . $action,
            $arguments
        );
    }

    /**
     * Readable local path for an uploaded file, without TYPO3's moveTo() (which only
     * accepts targets inside the project and enforces the BE maxFileSize).
     * Prefers PHP's own upload temp file (no copy; PHP deletes it after the request),
     * else copies the PSR-7 stream in 1 MiB chunks to a temp file we own.
     *
     * @return array{0: string, 1: bool} [path, whether the caller must delete it]
     */
    private function stageUpload(UploadedFileInterface $uploadedFile, string $prefix): array
    {
        $stream = $uploadedFile->getStream();
        $uri = $stream->getMetadata('uri');
        if (is_string($uri) && is_file($uri) && is_readable($uri) && is_uploaded_file($uri)) {
            return [$uri, false];
        }

        $tmpPath = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmpPath === false) {
            throw new \RuntimeException('Could not create a temporary file for the upload.', 1764000001);
        }
        $target = fopen($tmpPath, 'wb');
        if ($target === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Could not open the temporary upload file for writing.', 1764000002);
        }
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            while (!$stream->eof()) {
                $chunk = $stream->read(1048576);
                if ($chunk === '' ) {
                    continue;
                }
                if (fwrite($target, $chunk) === false) {
                    throw new \RuntimeException('Writing the temporary upload file failed.', 1764000003);
                }
            }
        } catch (\RuntimeException $exception) {
            fclose($target);
            @unlink($tmpPath);
            throw $exception;
        }
        fclose($target);

        return [$tmpPath, true];
    }

    private function formToken(string $action, string $instance = ''): string
    {
        try {
            return GeneralUtility::makeInstance(FormProtectionFactory::class)
                ->createForType('backend')
                ->generateToken('videooptimizer', $action, $instance);
        } catch (\Throwable) {
            return '';
        }
    }

    private function validateFormToken(string $token, string $action, string $instance = ''): bool
    {
        if (trim($token) === '') {
            return false;
        }

        try {
            return GeneralUtility::makeInstance(FormProtectionFactory::class)
                ->createForType('backend')
                ->validateToken(trim($token), 'videooptimizer', $action, $instance);
        } catch (\Throwable) {
            return false;
        }
    }
}
