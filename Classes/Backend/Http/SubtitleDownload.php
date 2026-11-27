<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Http;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * File download of a stored subtitle (WebVTT) in the backend module.
 */
final class SubtitleDownload
{
    public static function response(string $vtt, string $videoTitle, string $language): ResponseInterface
    {
        $body = new Stream('php://temp', 'rw');
        $body->write($vtt);
        $body->rewind();

        return (new Response($body, 200))
            ->withHeader('Content-Type', 'text/vtt; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . self::filename($videoTitle, $language) . '"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /** ASCII-only `<title-slug>.<language>.vtt`; never contains quotes, slashes or line breaks. */
    public static function filename(string $videoTitle, string $language): string
    {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($videoTitle)), '-');
        $slug = rtrim(substr($slug, 0, 60), '-');
        $language = (string)preg_replace('/[^A-Za-z0-9-]/', '', $language);

        return ($slug !== '' ? $slug : 'subtitle') . '.' . ($language !== '' ? $language : 'und') . '.vtt';
    }
}
