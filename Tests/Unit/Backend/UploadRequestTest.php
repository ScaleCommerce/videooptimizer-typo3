<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Backend\Input\InputException;
use ScaleCommerce\VideoOptimizer\Backend\Input\UploadRequest;

final class UploadRequestTest extends TestCase
{
    private const UUID = 'e7ab89b1-b74f-42fd-b2a9-53bfe1d492a8';

    #[Test]
    public function acceptsAVideoInitiateRequest(): void
    {
        $result = UploadRequest::initiate([
            'libraryId' => self::UUID,
            'filename' => '../../My Clip.MP4',
            'contentType' => 'video/mp4',
            'fileSize' => 1048576,
        ]);

        self::assertSame(['libraryId' => self::UUID, 'filename' => 'My Clip.MP4', 'contentType' => 'video/mp4', 'fileSize' => 1048576], $result);
    }

    #[Test]
    public function derivesTheContentTypeFromTheExtensionWhenTheBrowserSendsNone(): void
    {
        $result = UploadRequest::initiate(['libraryId' => self::UUID, 'filename' => 'a.mkv', 'contentType' => '', 'fileSize' => 10]);

        self::assertSame('video/x-matroska', $result['contentType']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badInitiateProvider(): iterable
    {
        $ok = ['libraryId' => self::UUID, 'filename' => 'a.mp4', 'contentType' => 'video/mp4', 'fileSize' => 10];
        yield 'bad library' => [['libraryId' => 'x'] + $ok, 'flash.upload.noLibrary'];
        yield 'bad extension' => [['filename' => 'a.exe'] + $ok, 'flash.upload.badType'];
        yield 'no filename' => [['filename' => ''] + $ok, 'flash.upload.badType'];
        yield 'zero size' => [['fileSize' => 0] + $ok, 'flash.upload.badSize'];
        yield 'too large' => [['fileSize' => 6 * 1024 * 1024 * 1024] + $ok, 'flash.upload.badSize'];
        yield 'non numeric size' => [['fileSize' => 'big'] + $ok, 'flash.upload.badSize'];
        yield 'not a video type' => [['contentType' => 'text/html'] + $ok, 'flash.upload.notVideo'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('badInitiateProvider')]
    public function rejectsBadInitiateRequests(array $input, string $labelKey): void
    {
        try {
            UploadRequest::initiate($input);
            self::fail('Expected an InputException.');
        } catch (InputException $exception) {
            self::assertSame($labelKey, $exception->labelKey);
        }
    }

    #[Test]
    public function completeNormalizesPartsAndOptionalFields(): void
    {
        $result = UploadRequest::complete([
            'libraryId' => self::UUID,
            'session' => ['uuid' => self::UUID, 'key' => 'k/1', 'uploadId' => 'up1'],
            'title' => ' My title ',
            'tags' => 'a, b',
            'metadata' => "k: v",
            'parts' => [['partNumber' => '2', 'etag' => '"e2"'], ['partNumber' => 1, 'etag' => '"e1"']],
        ]);

        self::assertSame(self::UUID, $result['libraryId']);
        self::assertSame(['uuid' => self::UUID, 'key' => 'k/1', 'uploadId' => 'up1'], $result['session']);
        self::assertSame('My title', $result['title']);
        self::assertSame(['a', 'b'], $result['tags']);
        self::assertSame(['k' => 'v'], $result['metadata']);
        self::assertSame([['partNumber' => 1, 'etag' => '"e1"'], ['partNumber' => 2, 'etag' => '"e2"']], $result['parts']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badCompleteProvider(): iterable
    {
        $ok = [
            'libraryId' => self::UUID,
            'session' => ['uuid' => self::UUID, 'key' => 'k', 'uploadId' => 'u'],
            'parts' => [['partNumber' => 1, 'etag' => 'e']],
        ];
        yield 'no parts' => [['parts' => []] + $ok];
        yield 'part without etag' => [['parts' => [['partNumber' => 1, 'etag' => '']]] + $ok];
        yield 'part number zero' => [['parts' => [['partNumber' => 0, 'etag' => 'e']]] + $ok];
        yield 'duplicate part' => [['parts' => [['partNumber' => 1, 'etag' => 'e'], ['partNumber' => 1, 'etag' => 'f']]] + $ok];
        yield 'session without key' => [['session' => ['uuid' => self::UUID, 'uploadId' => 'u']] + $ok];
        yield 'bad video uuid' => [['session' => ['uuid' => 'nope', 'key' => 'k', 'uploadId' => 'u']] + $ok];
        yield 'bad library' => [['libraryId' => '../x'] + $ok];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('badCompleteProvider')]
    public function rejectsBadCompleteRequests(array $input): void
    {
        $this->expectException(InputException::class);
        UploadRequest::complete($input);
    }

    #[Test]
    public function posterInitiateChecksTypeAndSize(): void
    {
        self::assertSame(
            ['uuid' => self::UUID, 'contentType' => 'image/webp', 'fileSize' => 5000],
            UploadRequest::posterInitiate(['uuid' => self::UUID, 'contentType' => 'image/webp', 'fileSize' => 5000])
        );

        foreach ([
            ['uuid' => self::UUID, 'contentType' => 'image/gif', 'fileSize' => 5000],
            ['uuid' => self::UUID, 'contentType' => 'image/png', 'fileSize' => 0],
            ['uuid' => self::UUID, 'contentType' => 'image/png', 'fileSize' => UploadRequest::MAX_POSTER_BYTES + 1],
            ['uuid' => 'x', 'contentType' => 'image/png', 'fileSize' => 10],
        ] as $bad) {
            try {
                UploadRequest::posterInitiate($bad);
                self::fail('Expected an InputException.');
            } catch (InputException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function posterContentTypeComesFromTheExtension(): void
    {
        self::assertSame('image/jpeg', UploadRequest::posterContentTypeForFilename('a.JPG'));
        self::assertSame('image/jpeg', UploadRequest::posterContentTypeForFilename('a.jpeg'));
        self::assertSame('image/png', UploadRequest::posterContentTypeForFilename('a.png'));
        self::assertSame('image/webp', UploadRequest::posterContentTypeForFilename('a.webp'));
        self::assertNull(UploadRequest::posterContentTypeForFilename('a.gif'));
        self::assertNull(UploadRequest::posterContentTypeForFilename('a'));
    }
}
