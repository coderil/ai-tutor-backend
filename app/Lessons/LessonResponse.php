<?php

namespace App\Lessons;

use App\Models\Lesson;
use App\Models\Source;

/**
 * Builds the lesson response the contract describes: the stored lesson with the recall
 * secrets stripped, plus the terms and resources it references, hydrated so the frontend
 * never makes a second request.
 */
class LessonResponse
{
    /**
     * @return array{lesson: array<string, mixed>, terms: object, resources: object}
     */
    public static function for(Lesson $lesson): array
    {
        $content = $lesson->content;

        // Stored with the lesson for grading, never sent before an attempt.
        $content['blocks'] = array_map(
            fn (array $block) => $block['type'] === 'recall'
                ? array_diff_key($block, ['modelAnswer' => true, 'rubric' => true])
                : $block,
            $content['blocks'],
        );

        $resources = $lesson->workspace->sources()
            ->whereIn('id', LessonReferences::resourceIds($content))
            ->get(['id', 'title', 'url'])
            ->mapWithKeys(fn (Source $source) => [(string) $source->id => [
                'title' => $source->title,
                'url' => $source->url,
            ]])
            ->all();

        // Both maps must serialise as JSON objects, including when empty. An empty PHP
        // array serialises as [] and fails the frontend's parse.
        return [
            'lesson' => $content,
            // No glossary yet, and the validator rejects term segments until there is one.
            'terms' => (object) [],
            'resources' => (object) $resources,
        ];
    }
}
