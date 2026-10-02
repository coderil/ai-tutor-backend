<?php

namespace App\Lessons;

use App\Models\Workspace;

/**
 * Decides whether a lesson may be stored. Two layers: the contract schema, then the rules
 * from docs/lesson-format.md that a schema cannot express. Returns every problem found as
 * a plain sentence, so the list can go back to the lesson writer as-is. An empty list
 * means the lesson is valid.
 *
 * Runs on the full lesson (after the backend fills in schemaVersion, number and slug) and
 * before the recall secrets are stripped.
 */
class LessonValidator
{
    public const MAX_MINUTES = 15;

    /**
     * The recipe matrix from docs/lesson-format.md. `figure` is left out of both lists:
     * it is rejected for every kind until inline SVG is sanitised before storage.
     */
    public const RECIPES = [
        'concept' => [
            'required' => ['callout', 'paragraph', 'quiz', 'recall'],
            'forbidden' => ['steps'],
        ],
        'hands-on' => [
            'required' => ['callout', 'paragraph', 'steps', 'recall'],
            'forbidden' => [],
        ],
        'review' => [
            'required' => ['quiz', 'recall'],
            'forbidden' => ['paragraph', 'code', 'table', 'steps'],
        ],
    ];

    private const BLOCK_DEFINITIONS = [
        'callout' => 'calloutBlock',
        'heading' => 'headingBlock',
        'paragraph' => 'paragraphBlock',
        'code' => 'codeBlock',
        'figure' => 'figureBlock',
        'table' => 'tableBlock',
        'steps' => 'stepsBlock',
        'quiz' => 'quizBlock',
        'recall' => 'recallBlock',
    ];

    public function __construct(private LessonContract $contract = new LessonContract) {}

    /**
     * @param  array<mixed>  $lesson
     * @return list<string>
     */
    public function validate(array $lesson, Workspace $workspace): array
    {
        $errors = [
            ...$this->schemaErrors($lesson),
            ...$this->minutesErrors($lesson),
            ...$this->recipeErrors($lesson),
            ...$this->quizErrors($lesson),
            ...$this->recallErrors($lesson),
            ...$this->uniqueIdErrors($lesson),
            ...$this->tableErrors($lesson),
            ...$this->referenceErrors($lesson, $workspace),
        ];

        return array_values(array_unique($errors));
    }

    /**
     * @return list<string>
     */
    private function schemaErrors(array $lesson): array
    {
        $errors = array_map(
            fn (string $error) => "Schema: {$error}",
            $this->contract->errors(['lesson' => $lesson]),
        );

        if ($errors === []) {
            return [];
        }

        // The schema's oneOf over block types reports a bad block as "matches no block".
        // Checking each block against its own definition says what is actually wrong.
        foreach (LessonReferences::blocks($lesson) as $i => $block) {
            $type = is_string($block['type'] ?? null) ? $block['type'] : '';
            $definition = self::BLOCK_DEFINITIONS[$type] ?? null;

            if ($definition === null) {
                $errors[] = "Block {$i} has an unknown type.";

                continue;
            }

            foreach ($this->contract->errors($block, $definition) as $error) {
                $errors[] = "Schema: block {$i} ({$type}) {$error}";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function minutesErrors(array $lesson): array
    {
        $minutes = $lesson['minutes'] ?? null;

        return is_int($minutes) && $minutes > self::MAX_MINUTES
            ? ['A lesson must take at most '.self::MAX_MINUTES." minutes; this one says {$minutes}."]
            : [];
    }

    /**
     * @return list<string>
     */
    private function recipeErrors(array $lesson): array
    {
        $types = array_map(fn (array $block) => $block['type'] ?? null, LessonReferences::blocks($lesson));
        $errors = [];

        if (array_intersect($types, ['quiz', 'recall', 'steps']) === []) {
            $errors[] = 'A lesson needs at least one quiz, recall or steps block.';
        }

        if (in_array('figure', $types, true)) {
            $errors[] = 'figure blocks are not allowed yet.';
        }

        $kind = $lesson['kind'] ?? null;
        $recipe = is_string($kind) ? (self::RECIPES[$kind] ?? null) : null;

        if ($recipe === null) {
            return $errors;
        }

        foreach ($recipe['required'] as $type) {
            if (! in_array($type, $types, true)) {
                $errors[] = "{$kind} lessons require a {$type} block.";
            }
        }

        foreach ($recipe['forbidden'] as $type) {
            if (in_array($type, $types, true)) {
                $errors[] = "{$kind} lessons must not contain a {$type} block.";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function quizErrors(array $lesson): array
    {
        $errors = [];

        foreach ($this->quizQuestions($lesson) as $question) {
            $id = is_string($question['id'] ?? null) ? $question['id'] : '(no id)';
            $options = array_values(array_filter(
                is_array($question['options'] ?? null) ? $question['options'] : [],
                'is_array',
            ));
            $count = count($options);

            if ($count < 3 || $count > 4) {
                $errors[] = "Quiz question {$id} must have 3 or 4 options; it has {$count}.";
            }

            $wordCounts = array_map(fn (array $option) => self::wordCount($option['text'] ?? ''), $options);

            if (count(array_unique($wordCounts)) > 1) {
                $errors[] = "Quiz question {$id} options must all have the same word count, so none stands out; "
                    .'they have '.implode(', ', $wordCounts).' words.';
            }

            $optionIds = array_column($options, 'id');

            if (! in_array($question['correctOptionId'] ?? null, $optionIds, true)) {
                $errors[] = "Quiz question {$id} correctOptionId does not match any of its option ids.";
            }

            if (count($optionIds) !== count(array_unique($optionIds, SORT_REGULAR))) {
                $errors[] = "Quiz question {$id} uses an option id more than once.";
            }
        }

        return $errors;
    }

    /**
     * The recall secrets are optional in the schema because the lesson response strips
     * them, but a stored lesson needs both to grade an attempt.
     *
     * @return list<string>
     */
    private function recallErrors(array $lesson): array
    {
        $errors = [];

        foreach (LessonReferences::blocks($lesson) as $block) {
            if (($block['type'] ?? null) !== 'recall') {
                continue;
            }

            $answer = $block['modelAnswer'] ?? null;
            $rubric = $block['rubric'] ?? null;

            if (! is_string($answer) || trim($answer) === '' || ! is_array($rubric) || $rubric === []) {
                $id = is_string($block['id'] ?? null) ? $block['id'] : '(no id)';
                $errors[] = "Recall {$id} needs a modelAnswer and a rubric, so the attempt can be graded.";
            }
        }

        return $errors;
    }

    /**
     * Attempts are matched back to blocks by these ids, so a duplicate makes grading
     * ambiguous.
     *
     * @return list<string>
     */
    private function uniqueIdErrors(array $lesson): array
    {
        $ids = ['question' => [], 'recall' => [], 'step' => []];

        foreach ($this->quizQuestions($lesson) as $question) {
            $ids['question'][] = $question['id'] ?? null;
        }

        foreach (LessonReferences::blocks($lesson) as $block) {
            if (($block['type'] ?? null) === 'recall') {
                $ids['recall'][] = $block['id'] ?? null;
            }

            if (($block['type'] ?? null) === 'steps') {
                foreach (is_array($block['items'] ?? null) ? $block['items'] : [] as $item) {
                    $ids['step'][] = is_array($item) ? ($item['id'] ?? null) : null;
                }
            }
        }

        $errors = [];

        foreach ($ids as $kind => $list) {
            $strings = array_filter($list, 'is_string');

            foreach (array_unique(array_diff_assoc($strings, array_unique($strings))) as $duplicate) {
                $errors[] = "The {$kind} id {$duplicate} is used more than once in this lesson.";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function tableErrors(array $lesson): array
    {
        $errors = [];

        foreach (LessonReferences::blocks($lesson) as $i => $block) {
            if (($block['type'] ?? null) !== 'table' || ! is_array($block['headers'] ?? null) || ! is_array($block['rows'] ?? null)) {
                continue;
            }

            $width = count($block['headers']);

            foreach ($block['rows'] as $r => $row) {
                $cells = is_array($row) ? count($row) : 0;

                if ($cells !== $width) {
                    $errors[] = "Table block {$i} row ".($r + 1)." has {$cells} cells but the table has {$width} headers.";
                }
            }
        }

        return $errors;
    }

    /**
     * Every id a lesson points at must exist in the same workspace.
     *
     * @return list<string>
     */
    private function referenceErrors(array $lesson, Workspace $workspace): array
    {
        $errors = [];
        $segments = LessonReferences::segments($lesson);
        $cites = array_filter($segments, fn (array $segment) => ($segment['type'] ?? null) === 'cite');

        if ($cites === []) {
            $errors[] = 'A lesson needs at least one cite segment pointing at a resource it draws on.';
        }

        $activeIds = $workspace->sources()->active()->pluck('id')->all();

        foreach ($cites as $cite) {
            $id = $cite['resourceId'] ?? null;

            if (! in_array($id, $activeIds, true)) {
                $errors[] = 'The cite of resource '.json_encode($id).' is not an active resource in this workspace.';
            }
        }

        $primary = $lesson['primarySource']['resourceId'] ?? null;

        if (is_int($primary) && ! $workspace->sources()->whereKey($primary)->exists()) {
            $errors[] = "The primarySource resource {$primary} is not in this workspace.";
        }

        $lessonIds = null;

        foreach ($segments as $segment) {
            $type = $segment['type'] ?? null;
            $id = json_encode($segment['termId'] ?? $segment['targetId'] ?? null);

            if ($type === 'term') {
                // There is no glossary yet, so no term can exist.
                $errors[] = "The term {$id} is not in this workspace's glossary.";
            }

            if ($type === 'link' && ($segment['to'] ?? null) === 'reference') {
                // There are no reference documents yet, so no link to one can resolve.
                $errors[] = "The link to reference {$id} is not in this workspace.";
            }

            if ($type === 'link' && ($segment['to'] ?? null) === 'lesson') {
                $lessonIds ??= $workspace->lessons()->pluck('id')->all();

                if (! in_array($segment['targetId'] ?? null, $lessonIds, true)) {
                    $errors[] = "The link to lesson {$id} is not in this workspace.";
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quizQuestions(array $lesson): array
    {
        $questions = [];

        foreach (LessonReferences::blocks($lesson) as $block) {
            if (($block['type'] ?? null) === 'quiz' && is_array($block['questions'] ?? null)) {
                array_push($questions, ...array_values(array_filter($block['questions'], 'is_array')));
            }
        }

        return $questions;
    }

    private static function wordCount(mixed $text): int
    {
        return is_string($text) ? count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY)) : 0;
    }
}
