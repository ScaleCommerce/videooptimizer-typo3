<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Backend\Http\SubtitleDownload;

final class SubtitleDownloadTest extends TestCase
{
    #[Test]
    public function buildsAnAttachmentResponse(): void
    {
        $response = SubtitleDownload::response("WEBVTT\n\n00:00.000 --> 00:01.000\nHi", 'Summer Campaign 2026', 'de-CH');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/vtt; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="summer-campaign-2026.de-CH.vtt"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertStringStartsWith('WEBVTT', (string)$response->getBody());
    }

    #[Test]
    public function sanitizesTheFilename(): void
    {
        self::assertSame('subtitle.de.vtt', SubtitleDownload::filename('', 'de'));
        self::assertSame('subtitle.de.vtt', SubtitleDownload::filename('../ äöü', 'de'));
        self::assertSame('x.en.vtt', SubtitleDownload::filename("../\"x\"\r\n", 'en'));
        self::assertSame('a.deSet-Cookiex1.vtt', SubtitleDownload::filename('a', "de\r\nSet-Cookie: x=1"));
        self::assertLessThanOrEqual(60 + 12, strlen(SubtitleDownload::filename(str_repeat('a', 500), 'en')));
    }
}
