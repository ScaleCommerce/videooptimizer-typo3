<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Input;

/**
 * Parsing of the free-text fields of the backend module (tags, metadata).
 */
final class VideoInput
{
    private const MAX_TAGS = 20;
    private const MAX_TAG_LENGTH = 50;
    private const MAX_METADATA_KEYS = 10;
    private const MAX_METADATA_VALUE_LENGTH = 64;

    /**
     * Comma-separated tag input -> normalized list (max 20, 50 chars each, case-insensitively unique).
     *
     * @return list<string>
     */
    public static function parseTags(string $input): array
    {
        $unique = [];
        foreach (explode(',', $input) as $tag) {
            $tag = trim($tag);
            if ($tag === '' || mb_strlen($tag) > self::MAX_TAG_LENGTH) {
                continue;
            }
            $key = strtolower($tag);
            if (!isset($unique[$key])) {
                $unique[$key] = $tag;
            }
        }

        return array_slice(array_values($unique), 0, self::MAX_TAGS);
    }

    /**
     * Metadata textarea ("key: value" per line, also accepts "key=value") -> key => value map
     * (max 10 keys, API rules; invalid lines are skipped).
     *
     * @return array<string, string>
     */
    public static function parseMetadata(string $input): array
    {
        $result = [];
        foreach (preg_split('/\r\n|\r|\n/', $input) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                $separator = strpos($line, '=');
            }
            if ($separator === false) {
                continue;
            }
            $key = strtolower(trim(substr($line, 0, $separator)));
            if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,39}$/', $key) !== 1) {
                continue;
            }
            $value = preg_replace('/[\x00-\x1F\x7F]/u', '', substr($line, $separator + 1)) ?? '';
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $result[$key] = mb_substr($value, 0, self::MAX_METADATA_VALUE_LENGTH);
            if (count($result) >= self::MAX_METADATA_KEYS) {
                break;
            }
        }

        return $result;
    }

    /**
     * Metadata map -> editor lines ("key: value").
     *
     * @param array<mixed> $metadata
     */
    public static function metadataToLines(array $metadata): string
    {
        $lines = [];
        foreach ($metadata as $key => $value) {
            $key = trim((string)$key);
            $value = is_scalar($value) ? trim((string)$value) : '';
            if ($key !== '' && $value !== '') {
                $lines[] = $key . ': ' . $value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * PATCH payload that turns $current into $new: the API merges metadata, so keys
     * that disappeared from the editor must be sent as null to be removed.
     *
     * @param array<mixed> $current
     * @param array<string, string> $new
     * @return array<string, string|null>
     */
    public static function metadataPatch(array $current, array $new): array
    {
        $patch = [];
        foreach ($new as $key => $value) {
            if (!isset($current[$key]) || (string)$current[$key] !== $value) {
                $patch[$key] = $value;
            }
        }
        foreach ($current as $key => $_) {
            if (!array_key_exists((string)$key, $new)) {
                $patch[(string)$key] = null;
            }
        }

        return $patch;
    }
}
