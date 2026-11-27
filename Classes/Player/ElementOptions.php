<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Player;

/**
 * Validated reading of the content element options stored on the tt_content record (TCA vo_* fields).
 * Pure functions: every value is checked against its allow-list or clamped, unknown or missing values
 * fall back to the default, so an old or hand-edited record can never break the output.
 */
final class ElementOptions
{
    /**
     * Plain text field (strip tags, trim, cut).
     *
     * @param array<string, mixed> $row
     */
    public static function text(array $row, string $field, int $max): string
    {
        $value = $row[$field] ?? '';

        return is_scalar($value) ? mb_substr(trim(strip_tags((string)$value)), 0, $max) : '';
    }

    /**
     * Side of the video in the media split element.
     *
     * @param array<string, mixed> $row
     */
    public static function side(array $row): string
    {
        return ($row['vo_split_side'] ?? '') === 'right' ? 'right' : 'left';
    }

    /**
     * Call-to-action button of the media split; null unless label and link are both set (the link is a
     * TYPO3 link, resolved by f:link.typolink).
     *
     * @param array<string, mixed> $row
     * @return array{label: string, link: string}|null
     */
    public static function cta(array $row): ?array
    {
        $label = self::text($row, 'vo_cta_label', 50);
        $link = is_scalar($row['vo_cta_link'] ?? null) ? trim((string)$row['vo_cta_link']) : '';

        return $label !== '' && $link !== '' ? ['label' => $label, 'link' => $link] : null;
    }

    /** Aspect ratios of the "format" option (database value => CSS aspect-ratio). */
    private const FORMATS = [
        '16-9' => '16/9',
        '9-16' => '9/16',
        '1-1' => '1/1',
        '4-5' => '4/5',
        '21-9' => '21/9',
    ];

    /**
     * Format of the video surface: `original` (the video's own ratio) or one of the fixed ratios.
     *
     * @param array<string, mixed> $row
     */
    public static function format(array $row): string
    {
        $value = $row['vo_format'] ?? '';

        return is_string($value) && isset(self::FORMATS[$value]) ? $value : 'original';
    }

    /** CSS aspect-ratio of a format; '' for `original`. */
    public static function aspect(string $format): string
    {
        return self::FORMATS[$format] ?? '';
    }

    /** Portrait-like formats get a maximum width so they do not fill a desktop screen. */
    public static function isPortrait(string $format): bool
    {
        return in_array($format, ['9-16', '4-5'], true);
    }

    /**
     * Focal point in percent (object-position of video and poster), default the center.
     *
     * @param array<string, mixed> $row
     * @return array{x: int, y: int}
     */
    public static function focal(array $row): array
    {
        $axis = static fn (mixed $value): int => is_numeric($value) ? max(0, min(100, (int)$value)) : 50;

        return ['x' => $axis($row['vo_focus_x'] ?? 50), 'y' => $axis($row['vo_focus_y'] ?? 50)];
    }

    /**
     * Floating mini player (explicit opt-in).
     *
     * @param array<string, mixed> $row
     */
    public static function miniPlayer(array $row): bool
    {
        return (int)(is_scalar($row['vo_mini_player'] ?? null) ? $row['vo_mini_player'] : 0) === 1;
    }

    /** Header type used when the record has none (the fluid_styled_content default). */
    private const DEFAULT_HEADER_LEVEL = 2;

    /**
     * Header, sub header, header type, link and position of the record, with the same rules as the core
     * `Header/All` partial: type 100 hides everything, 0 takes the default type, 1-6 are h1-h6, a sub
     * header is one level below (an h6 and unset types use the default). Null when nothing is shown.
     *
     * @param array<string, mixed> $row
     * @return array{header: array{text: string, level: int, link: string, position: string}, sub: array{text: string, level: int}|null}|null
     */
    public static function headline(array $row): ?array
    {
        $layout = is_numeric($row['header_layout'] ?? null) ? (int)$row['header_layout'] : 0;
        if ($layout === 100) {
            return null;
        }
        $text = is_scalar($row['header'] ?? null) ? trim((string)$row['header']) : '';
        $sub = is_scalar($row['subheader'] ?? null) ? trim((string)$row['subheader']) : '';
        if ($text === '' && $sub === '') {
            return null;
        }
        $position = $row['header_position'] ?? '';
        $link = is_scalar($row['header_link'] ?? null) ? trim((string)$row['header_link']) : '';

        return [
            'header' => [
                'text' => $text,
                'level' => $layout >= 1 && $layout <= 6 ? $layout : self::DEFAULT_HEADER_LEVEL,
                'link' => $link,
                'position' => in_array($position, ['center', 'right'], true) ? (string)$position : '',
            ],
            'sub' => $sub === '' ? null : [
                'text' => $sub,
                'level' => $layout >= 1 && $layout <= 5 ? $layout + 1 : self::DEFAULT_HEADER_LEVEL + 1,
            ],
        ];
    }
}
