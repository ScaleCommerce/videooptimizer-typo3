<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Security\StorageOrigin;

final class StorageOriginTest extends TestCase
{
    #[Test]
    public function defaultsToTheProductionStorage(): void
    {
        self::assertSame('https://object-storage.scale.sc', StorageOrigin::DEFAULT);
        self::assertSame(StorageOrigin::DEFAULT, StorageOrigin::normalize(''));
    }

    #[Test]
    public function normalizesConfiguredOrigins(): void
    {
        self::assertSame('https://s3.example.com', StorageOrigin::normalize(' https://S3.Example.com/ '));
        self::assertSame('https://s3.example.com:9000', StorageOrigin::normalize('https://s3.example.com:9000'));
    }

    #[Test]
    public function invalidOriginsFallBackToTheDefault(): void
    {
        foreach (['http://s3.example.com', 'https://user@s3.example.com', 'https://s3.example.com/bucket', 'https://s3.example.com?x=1', 'test-token', 'https://localhost', 'https://10.0.0.1', '*'] as $bad) {
            self::assertSame(StorageOrigin::DEFAULT, StorageOrigin::normalize($bad), $bad);
        }
    }

    #[Test]
    public function allowsOnlyUrlsOfTheOrigin(): void
    {
        $origin = 'https://object-storage.scale.sc';

        self::assertTrue(StorageOrigin::allows($origin, 'https://object-storage.scale.sc/bucket/key?X-Amz-Signature=1'));
        self::assertFalse(StorageOrigin::allows($origin, 'https://object-storage.scale.sc:8443/bucket/key'));
        self::assertFalse(StorageOrigin::allows($origin, 'https://evil.example.com/bucket/key'));
        self::assertFalse(StorageOrigin::allows($origin, 'https://object-storage.scale.sc.evil.com/k'));
        self::assertFalse(StorageOrigin::allows($origin, 'http://object-storage.scale.sc/k'));
        self::assertFalse(StorageOrigin::allows($origin, 'https://user@object-storage.scale.sc/k'));
        self::assertFalse(StorageOrigin::allows($origin, 'https://object-storage.scale.sc\\@evil.com/k'));
    }
}
