<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Directive;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Mutation;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationCollection;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\MutationMode;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\Scope;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\UriValue;
use TYPO3\CMS\Core\Type\Map;

// Video thumbnails/posters (poster.videooptimizer.eu, <library>.cdn.videooptimizer.eu) are shown
// in the whole backend: the video picker in the content element form and the page module preview.
// connect-src: the module uploads video parts and posters straight from the browser to the
// presigned storage URLs of the platform.
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
            new UriValue('https://*.videooptimizer.eu')
        ),
    ),
]);
