<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Input;

use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;

/**
 * Validation of the JSON bodies of the browser upload endpoints (never trusts the browser).
 */
final class UploadRequest
{
    public const MAX_VIDEO_BYTES = 5 * 1024 * 1024 * 1024;
    public const MAX_POSTER_BYTES = 20 * 1024 * 1024;
    public const MAX_PARTS = 10000;
    public const VIDEO_EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm', 'mkv', 'avi', 'mpeg', 'mpg'];

    private const VIDEO_TYPES = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/x-m4v',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
        'avi' => 'video/x-msvideo',
        'mpeg' => 'video/mpeg',
        'mpg' => 'video/mpeg',
    ];
    private const POSTER_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param array<string, mixed> $input
     * @return array{libraryId: string, filename: string, contentType: string, fileSize: int}
     */
    public static function initiate(array $input): array
    {
        $libraryId = self::uuid($input['libraryId'] ?? null, 'flash.upload.noLibrary');
        $filename = basename(str_replace('\\', '/', is_string($input['filename'] ?? null) ? $input['filename'] : ''));
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($filename === '' || !in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            throw new InputException('flash.upload.badType', [implode(', ', self::VIDEO_EXTENSIONS)]);
        }
        $size = $input['fileSize'] ?? null;
        if (!is_int($size) && !(is_string($size) && ctype_digit($size))) {
            throw new InputException('flash.upload.badSize');
        }
        $size = (int)$size;
        if ($size <= 0 || $size > self::MAX_VIDEO_BYTES) {
            throw new InputException('flash.upload.badSize');
        }
        $contentType = strtolower(trim(is_string($input['contentType'] ?? null) ? $input['contentType'] : ''));
        if ($contentType === '' || $contentType === 'application/octet-stream') {
            $contentType = self::VIDEO_TYPES[$extension];
        }
        if (!str_starts_with($contentType, 'video/') || preg_match('#^video/[a-z0-9.+-]{1,60}$#', $contentType) !== 1) {
            throw new InputException('flash.upload.notVideo', [$contentType]);
        }

        return ['libraryId' => $libraryId, 'filename' => $filename, 'contentType' => $contentType, 'fileSize' => $size];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{libraryId: string, session: array{uuid: string, key: string, uploadId: string}, title: ?string, tags: list<string>, metadata: array<string, string>, parts: list<array{partNumber: int, etag: string}>}
     */
    public static function complete(array $input): array
    {
        $libraryId = self::uuid($input['libraryId'] ?? null, 'flash.upload.noLibrary');
        $session = is_array($input['session'] ?? null) ? $input['session'] : [];
        $uuid = self::uuid($session['uuid'] ?? null, 'flash.upload.badSession');
        $key = $session['key'] ?? null;
        $uploadId = $session['uploadId'] ?? null;
        foreach ([$key, $uploadId] as $value) {
            if (!is_string($value) || $value === '' || strlen($value) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InputException('flash.upload.badSession');
            }
        }

        $parts = [];
        $rawParts = is_array($input['parts'] ?? null) ? $input['parts'] : [];
        if ($rawParts === [] || count($rawParts) > self::MAX_PARTS) {
            throw new InputException('flash.upload.badParts');
        }
        foreach ($rawParts as $part) {
            $number = is_array($part) ? ($part['partNumber'] ?? null) : null;
            $etag = is_array($part) ? ($part['etag'] ?? null) : null;
            if (!(is_int($number) || (is_string($number) && ctype_digit($number))) || (int)$number < 1 || (int)$number > self::MAX_PARTS
                || !is_string($etag) || trim($etag) === '' || strlen($etag) > 256 || isset($parts[(int)$number])
            ) {
                throw new InputException('flash.upload.badParts');
            }
            $parts[(int)$number] = ['partNumber' => (int)$number, 'etag' => trim($etag)];
        }
        ksort($parts);

        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $tags = $input['tags'] ?? '';

        return [
            'libraryId' => $libraryId,
            'session' => ['uuid' => $uuid, 'key' => (string)$key, 'uploadId' => (string)$uploadId],
            'title' => $title !== '' ? mb_substr($title, 0, 255) : null,
            'tags' => VideoInput::parseTags(is_array($tags) ? implode(',', array_map('strval', $tags)) : (string)$tags),
            'metadata' => VideoInput::parseMetadata(is_string($input['metadata'] ?? null) ? $input['metadata'] : ''),
            'parts' => array_values($parts),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{uuid: string, contentType: string, fileSize: int}
     */
    public static function posterInitiate(array $input): array
    {
        $uuid = self::uuid($input['uuid'] ?? null, 'flash.poster.badVideo');
        $contentType = is_string($input['contentType'] ?? null) ? strtolower(trim($input['contentType'])) : '';
        if (!in_array($contentType, VideoOptimizerClient::POSTER_CONTENT_TYPES, true)) {
            throw new InputException('flash.poster.badType');
        }
        $size = $input['fileSize'] ?? null;
        if (!is_int($size) && !(is_string($size) && ctype_digit($size))) {
            throw new InputException('flash.poster.badSize');
        }
        $size = (int)$size;
        if ($size <= 0 || $size > self::MAX_POSTER_BYTES) {
            throw new InputException('flash.poster.badSize');
        }

        return ['uuid' => $uuid, 'contentType' => $contentType, 'fileSize' => $size];
    }

    /** image/jpeg|png|webp for a file name, null for everything else. */
    public static function posterContentTypeForFilename(string $filename): ?string
    {
        return self::POSTER_TYPES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))] ?? null;
    }

    public static function uuid(mixed $value, string $labelKey): string
    {
        if (!is_string($value) || preg_match(self::UUID_PATTERN, trim($value)) !== 1) {
            throw new InputException($labelKey);
        }

        return trim($value);
    }
}
