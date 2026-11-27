<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Api;

/**
 * Shapes the entries of `GET /encodings` for forms: add-ons the organization has not unlocked
 * (`available: false`) stay visible but must not be selectable.
 */
final class EncodingOptions
{
    /**
     * Choices for the edit form of a library: the offered keys plus every active key
     * (so a resolution that is no longer offered is neither dropped nor lost on save).
     *
     * @param array<mixed> $available
     * @param array<mixed> $active
     * @return list<array{value: string, selected: bool}>
     */
    public static function editChoices(array $available, array $active): array
    {
        $active = array_values(array_unique(array_map('strval', $active)));
        $keys = array_values(array_unique(array_merge(array_map('strval', $available), $active)));

        return array_map(static fn(string $key): array => ['value' => $key, 'selected' => in_array($key, $active, true)], $keys);
    }

    /**
     * @param array<mixed> $options entries of `codecs` / `resolutions`
     * @return list<array{key: string, label: string, available: bool, addon: bool}>
     */
    public static function normalize(array $options): array
    {
        $result = [];
        foreach ($options as $option) {
            if (!is_array($option) || !is_string($option['key'] ?? null) || trim($option['key']) === '') {
                continue;
            }
            $key = trim($option['key']);
            $label = is_string($option['label'] ?? null) ? trim($option['label']) : '';
            $result[] = [
                'key' => $key,
                'label' => $label !== '' ? $label : $key,
                'available' => ($option['available'] ?? true) !== false,
                'addon' => ($option['access'] ?? '') === 'addon',
            ];
        }

        return $result;
    }
}
