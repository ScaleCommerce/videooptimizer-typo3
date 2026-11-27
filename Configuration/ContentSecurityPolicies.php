<?php

declare(strict_types=1);

use ScaleCommerce\VideoOptimizer\Security\StorageOrigin;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\SourceScheme;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Type\Map;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// The object storage origin of the presigned URLs: extension configuration `storageOrigin`
// (read once; the default is the production storage).
$configured = '';
try {
    $value = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('videooptimizer', 'storageOrigin');
    $configured = is_string($value) ? $value : '';
} catch (\Throwable) {
}
$storageOrigin = StorageOrigin::normalize($configured);

// Video thumbnails/posters (poster.videooptimizer.eu, <library>.cdn.videooptimizer.eu) are shown
// in the whole backend: the video picker in the content element form and the page module preview.
// connect-src: the module uploads video parts and posters straight from the browser to the
// presigned URLs on that storage.
//
// Frontend (only effective when the site enables TYPO3's frontend CSP): the native player loads HLS
// segments, MP4 files and subtitles from <library>.cdn.videooptimizer.eu and posters from
// poster.videooptimizer.eu; hls.js feeds the video through a blob: URL (Media Source Extensions) and
// runs without a worker. The hosted player is an iframe on player.videooptimizer.eu.
return Map::fromEntries([
    Scope::backend(),
    new MutationCollection(
        new Mutation(
            MutationMode::Extend,
            Directive::ImgSrc,
            new UriValue('https://*.videooptimizer.eu')
        ),
        new Mutation(
            MutationMode::Extend,
            Directive::ConnectSrc,
            new UriValue($storageOrigin)
        ),
    ),
], [
    Scope::frontend(),
    new MutationCollection(
        new Mutation(
            MutationMode::Extend,
            Directive::ImgSrc,
            new UriValue('https://poster.videooptimizer.eu'),
            new UriValue('https://*.cdn.videooptimizer.eu'),
            // Scene previews of the chapters element: the API redirects /videos/{uuid}/thumbnails/{i} to the CDN.
            new UriValue('https://api.videooptimizer.eu')
        ),
        new Mutation(
            MutationMode::Extend,
            Directive::MediaSrc,
            new UriValue('https://*.cdn.videooptimizer.eu'),
            SourceScheme::blob
        ),
        new Mutation(
            MutationMode::Extend,
            Directive::ConnectSrc,
            new UriValue('https://*.cdn.videooptimizer.eu')
        ),
        new Mutation(
            MutationMode::Extend,
            Directive::FrameSrc,
            new UriValue('https://*.videooptimizer.eu')
        ),
    ),
]);
