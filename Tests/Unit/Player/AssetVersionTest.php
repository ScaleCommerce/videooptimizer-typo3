<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Tests\Unit\Player;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ScaleCommerce\VideoOptimizer\Player\AssetVersion;

final class AssetVersionTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/vo-assets-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->directory . 'vendor', 0777, true);
        foreach (['videooptimizer.js', 'video-source.js', 'native-config.js', 'chapters.js'] as $file) {
            file_put_contents($this->directory . $file, $file . ' v1');
        }
        file_put_contents($this->directory . 'vendor/hls.light-1.7.4.min.js', 'hls');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . 'vendor/*') ?: []);
        array_map('unlink', glob($this->directory . '*.js') ?: []);
        rmdir($this->directory . 'vendor');
        rmdir($this->directory);
    }

    #[Test]
    public function theVersionIsStableAndSafeForAQueryString(): void
    {
        $version = AssetVersion::forDirectory($this->directory);

        self::assertSame($version, AssetVersion::forDirectory($this->directory));
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $version);
    }

    #[Test]
    public function itChangesWhenAnyModuleChanges(): void
    {
        $before = AssetVersion::forDirectory($this->directory);

        foreach (['videooptimizer.js', 'video-source.js', 'native-config.js', 'chapters.js'] as $file) {
            file_put_contents($this->directory . $file, $file . ' v2');
            $after = AssetVersion::forDirectory($this->directory);
            self::assertNotSame($before, $after, $file);
            file_put_contents($this->directory . $file, $file . ' v1');
            self::assertSame($before, AssetVersion::forDirectory($this->directory), 'reverting restores the version');
        }
    }

    #[Test]
    public function itChangesWithTheVendoredHlsJs(): void
    {
        $before = AssetVersion::forDirectory($this->directory);
        rename($this->directory . 'vendor/hls.light-1.7.4.min.js', $this->directory . 'vendor/hls.light-1.8.0.min.js');

        self::assertNotSame($before, AssetVersion::forDirectory($this->directory));
    }
}
