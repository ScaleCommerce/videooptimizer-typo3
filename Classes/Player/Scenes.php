<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Player;

use ScaleCommerce\VideoOptimizer\Api\VideoOptimizerClient;
use ScaleCommerce\VideoOptimizer\Security\MediaUrl;

/**
 * Scene previews of the chapters element. The encoder samples ten thumbnails per video at
 * duration / 11 * (i + 1) seconds; the public endpoint /videos/{uuid}/thumbnails/{i} (0..9) serves them
 * without a token (302 to the CDN).
 */
final class Scenes
{
    public const THUMBNAILS = 10;
    public const DEFAULT_COUNT = 5;

    /**
     * Thumbnail indices for $count scenes, spread evenly over 0..9: round(k * 9 / (n - 1)).
     *
     * @return list<int>
     */
    public static function indices(int $count): array
    {
        $count = max(2, min(self::THUMBNAILS, $count));
        $indices = [];
        for ($k = 0; $k < $count; $k++) {
            $indices[] = (int)round($k * (self::THUMBNAILS - 1) / ($count - 1));
        }

        return $indices;
    }

    /** Start time in seconds of thumbnail $index. */
    public static function start(int $duration, int $index): int
    {
        return (int)floor($duration / (self::THUMBNAILS + 1) * ($index + 1));
    }

    /** m:ss, or h:mm:ss from one hour on. */
    public static function formatTime(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $rest = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $rest)
            : sprintf('%d:%02d', $minutes, $rest);
    }

    /**
     * @param array<string, mixed> $row tt_content record
     */
    public static function count(array $row): int
    {
        $value = $row['vo_scene_count'] ?? self::DEFAULT_COUNT;

        return is_numeric($value) ? max(3, min(self::THUMBNAILS, (int)$value)) : self::DEFAULT_COUNT;
    }

    /**
     * Scene titles of the record, one per line (tags stripped, at most one per thumbnail).
     *
     * @param array<string, mixed> $row tt_content record
     * @return list<string>
     */
    public static function titles(array $row): array
    {
        $raw = is_scalar($row['vo_scene_titles'] ?? null) ? (string)$row['vo_scene_titles'] : '';
        $titles = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $titles[] = mb_substr(trim(strip_tags($line)), 0, 80);
        }
        while ($titles !== [] && end($titles) === '') {
            array_pop($titles);
        }

        return array_slice($titles, 0, self::THUMBNAILS);
    }

    /**
     * @param list<string> $titles titles by scene position; an empty one keeps the default label
     * @param string $labelFormat default label with the scene number, e.g. "Scene %d"
     * @return list<array{index: int, start: int, time: string, thumb: string, label: string, aria: string}>
     */
    public static function build(string $uuid, int $duration, int $count, array $titles, string $labelFormat): array
    {
        if ($duration <= 0 || preg_match('/^[A-Za-z0-9-]{1,64}$/', $uuid) !== 1) {
            return [];
        }
        $scenes = [];
        foreach (self::indices($count) as $position => $index) {
            $thumb = MediaUrl::safe(VideoOptimizerClient::BASE_URL . '/videos/' . $uuid . '/thumbnails/' . $index);
            if ($thumb === '') {
                continue;
            }
            $start = self::start($duration, $index);
            $time = self::formatTime($start);
            $title = $titles[$position] ?? '';
            $label = $title !== '' ? $title : sprintf($labelFormat, $position + 1);
            $scenes[] = [
                'index' => $index,
                'start' => $start,
                'time' => $time,
                'thumb' => $thumb,
                'label' => $label,
                'aria' => $label . ' · ' . $time,
            ];
        }

        return $scenes;
    }
}
