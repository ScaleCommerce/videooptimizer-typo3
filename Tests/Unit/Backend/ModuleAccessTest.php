<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Backend\Security\ModuleAccess;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class ModuleAccessTest extends TestCase
{
    /**
     * @param array<string, bool> $allowed "<type>:<value>" => granted
     */
    private function user(bool $admin, array $allowed = []): BackendUserAuthentication
    {
        $user = $this->createStub(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('check')->willReturnCallback(static fn(string $type, string $value): bool => $allowed[$type . ':' . $value] ?? false);

        return $user;
    }

    #[Test]
    public function adminsMayDoEverything(): void
    {
        self::assertTrue(ModuleAccess::mayDo($this->user(true), 'upload'));
        self::assertTrue(ModuleAccess::mayView($this->user(true)));
    }

    #[Test]
    public function editorsNeedTheCustomOption(): void
    {
        $user = $this->user(false, ['custom_options:videooptimizer:upload' => true]);

        self::assertTrue(ModuleAccess::mayDo($user, 'upload'));
        self::assertFalse(ModuleAccess::mayDo($user, 'manage'));
        self::assertFalse(ModuleAccess::mayDo($user, 'delete'));
    }

    #[Test]
    public function viewingNeedsTheModule(): void
    {
        self::assertTrue(ModuleAccess::mayView($this->user(false, ['modules:system_videooptimizer' => true])));
        self::assertFalse(ModuleAccess::mayView($this->user(false)));
    }

    #[Test]
    public function withoutABackendUserNothingIsAllowed(): void
    {
        self::assertFalse(ModuleAccess::mayDo(null, 'upload'));
        self::assertFalse(ModuleAccess::mayView(null));
        self::assertFalse(ModuleAccess::mayDo('admin', 'upload'));
    }
}
