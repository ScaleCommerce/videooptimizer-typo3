<?php

declare(strict_types=1);

/**
 * Checks that every English XLF file has a German counterpart (de.<name>.xlf) with exactly the same
 * trans-unit ids, and no duplicated ids. Usage: php Build/xlf-parity.php [directory ...]
 * (default: Resources/Private/Language and the site set label files).
 */
$root = dirname(__DIR__);
$directories = array_slice($argv, 1) ?: [$root . '/Resources/Private/Language', ...glob($root . '/Configuration/Sets/*') ?: []];

/** @return list<string> */
$ids = static function (string $file): array {
    $xml = simplexml_load_file($file);
    if ($xml === false) {
        fwrite(STDERR, "Not well-formed: $file\n");
        exit(1);
    }
    $xml->registerXPathNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
    $found = [];
    foreach ($xml->xpath('//x:trans-unit') ?: [] as $unit) {
        $found[] = (string)$unit['id'];
    }

    return $found;
};

$errors = 0;
foreach ($directories as $directory) {
    foreach (glob($directory . '/*.xlf') ?: [] as $file) {
        $name = basename($file);
        if (str_starts_with($name, 'de.')) {
            continue;
        }
        $german = dirname($file) . '/de.' . $name;
        if (!is_file($german)) {
            echo "Missing German file: $german\n";
            $errors++;
            continue;
        }
        $english = $ids($file);
        $translated = $ids($german);
        foreach ([$file => $english, $german => $translated] as $path => $list) {
            $duplicates = array_keys(array_filter(array_count_values($list), static fn(int $count): bool => $count > 1));
            if ($duplicates !== []) {
                echo 'Duplicate ids in ' . basename($path) . ': ' . implode(', ', $duplicates) . "\n";
                $errors++;
            }
        }
        $difference = array_merge(array_diff($english, $translated), array_diff($translated, $english));
        if ($difference !== []) {
            echo "Id mismatch between $name and de.$name: " . implode(', ', $difference) . "\n";
            $errors++;
        }
    }
}

exit($errors > 0 ? 1 : 0);
