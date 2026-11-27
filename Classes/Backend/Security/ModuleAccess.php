<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Security;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Permission checks of the module and its AJAX endpoints: the custom module options
 * (videooptimizer:upload|manage|delete) for changes, module access for reading.
 */
final class ModuleAccess
{
    public const MODULE = 'system_videooptimizer';

    public static function mayDo(mixed $user, string $permission): bool
    {
        return $user instanceof BackendUserAuthentication
            && ($user->isAdmin() || $user->check('custom_options', 'videooptimizer:' . $permission));
    }

    public static function mayView(mixed $user): bool
    {
        return $user instanceof BackendUserAuthentication
            && ($user->isAdmin() || $user->check('modules', self::MODULE));
    }
}
