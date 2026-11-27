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
     * Metadata textarea ("key: value" per line, also "key=value"; the first ":" or "=" separates) ->
     * key => value map (max 10 keys, API rules). Invalid lines are skipped; use
     * parseMetadataDetailed() to learn which.
     *
     * @return array<string, string>
     */
    public static function parseMetadata(string $input): array
    {
        return self::parseMetadataDetailed($input)['metadata'];
    }

    /**
     * @return array{metadata: array<string, string>, invalid: list<string>} invalid = the offending lines (shortened)
     */
    public static function parseMetadataDetailed(string $input): array
    {
        $result = [];
        $invalid = [];
        foreach (preg_split('/\r\n|\r|\n/', $input) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            $equals = strpos($line, '=');
            $separator = $colon === false ? $equals : ($equals === false ? $colon : min($colon, $equals));
            $key = $separator === false ? '' : strtolower(trim(substr($line, 0, $separator)));
            $value = $separator === false ? '' : trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', substr($line, $separator + 1)));
            if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,39}$/', $key) !== 1 || $value === '' || mb_strlen($value) > self::MAX_METADATA_VALUE_LENGTH
                || (!isset($result[$key]) && count($result) >= self::MAX_METADATA_KEYS)
            ) {
                $invalid[] = mb_substr($line, 0, 60);
                continue;
            }
            $result[$key] = $value;
        }

        return ['metadata' => $result, 'invalid' => $invalid];
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
