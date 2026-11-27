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
