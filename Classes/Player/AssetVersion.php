<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Player;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 * Version stamp of the frontend modules: changes whenever the entry, one of its sub-modules or hls.js
 * changes. The entry reads it from its own <script> tag (data-vo-version) and appends it to the
 * sub-module imports, so a browser cache that keeps JavaScript for a year, or a TYPO3 that rewrites
 * the entry's file name (FE.versionNumberInFilename), cannot pair a new entry with an old sub-module.
 */
final class AssetVersion
{
    private const FILES = ['videooptimizer.js', 'video-source.js', 'native-config.js'];

    private static ?string $cached = null;

    public static function get(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        try {
            $directory = ExtensionManagementUtility::extPath('videooptimizer') . 'Resources/Public/Js/';
        } catch (\Throwable) {
            return '';
        }

        return self::$cached = self::forDirectory($directory);
    }

    /**
     * Hash of the module files in $directory (and the name and size of the vendored hls.js, whose name
     * carries its version).
     */
    public static function forDirectory(string $directory): string
    {
        $parts = [];
        foreach (self::FILES as $file) {
            $parts[] = $file . ':' . (is_file($directory . $file) ? md5_file($directory . $file) : '-');
        }
        foreach (glob($directory . 'vendor/hls.light-*.js') ?: [] as $vendor) {
            $parts[] = basename($vendor) . ':' . filesize($vendor);
        }

        return substr(sha1(implode('|', $parts)), 0, 12);
    }
}
