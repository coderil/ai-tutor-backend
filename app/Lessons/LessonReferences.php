<?php

namespace App\Lessons;

/**
 * Reads the ids a lesson refers to out of its JSON. Tolerates malformed input, because the
 * validator runs it over model output before the schema has been checked.
 */
class LessonReferences
{
    /**
     * Every resource id the lesson uses: the primary source and every cite segment.
     *
     * @param  array<string, mixed>  $lesson
     * @return list<int>
     */
    public static function resourceIds(array $lesson): array
    {
        $ids = [];

        if (is_int($lesson['primarySource']['resourceId'] ?? null)) {
            $ids[] = $lesson['primarySource']['resourceId'];
        }

        foreach (self::segments($lesson) as $segment) {
            if (($segment['type'] ?? null) === 'cite' && is_int($segment['resourceId'] ?? null)) {
                $ids[] = $segment['resourceId'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every inline segment in every paragraph and callout.
     *
     * @param  array<string, mixed>  $lesson
     * @return list<array<string, mixed>>
     */
    public static function segments(array $lesson): array
    {
        $segments = [];

        foreach (self::blocks($lesson) as $block) {
            foreach (is_array($block['content'] ?? null) ? $block['content'] : [] as $segment) {
                if (is_array($segment)) {
                    $segments[] = $segment;
                }
            }
        }

        return $segments;
    }

    /**
     * Every block that is at least an array, keyed by its position in the lesson.
     *
     * @param  array<string, mixed>  $lesson
     * @return array<int, array<string, mixed>>
     */
    public static function blocks(array $lesson): array
    {
        $blocks = is_array($lesson['blocks'] ?? null) ? $lesson['blocks'] : [];

        return array_filter($blocks, 'is_array');
    }
}
