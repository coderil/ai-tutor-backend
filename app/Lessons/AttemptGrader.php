<?php

namespace App\Lessons;

use App\Ai\Agents\RecallGrader;
use App\Ai\ModelJson;
use App\Models\Lesson;
use Illuminate\Validation\ValidationException;

/**
 * Checks an attempt request against the contract and the lesson, then grades it. Quiz
 * answers are graded here; recall answers go to one RecallGrader call per attempt; steps
 * answers are ungraded telemetry and never reach perAnswer. An omitted answer is skipped.
 */
class AttemptGrader
{
    public function __construct(private LessonContract $contract = new LessonContract) {}

    /**
     * The request's answers, or a 422 when the request breaks the contract or names an id
     * the lesson does not have.
     *
     * @param  mixed  $body  The request body decoded with JSON objects as stdClass, so an
     *                       object and an array stay distinguishable.
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public function validate(mixed $body, Lesson $lesson): array
    {
        $schemaErrors = $this->contract->errors($body, 'attemptRequest');

        if ($schemaErrors !== []) {
            throw ValidationException::withMessages(['answers' => $schemaErrors]);
        }

        $answers = json_decode(json_encode($body->answers), true);
        [$questions, $recalls, $steps] = $this->index($lesson);
        $seen = [];
        $errors = [];

        foreach ($answers as $i => $answer) {
            $id = $answer['questionId'] ?? $answer['recallId'] ?? $answer['stepId'];

            $error = match ($answer['type']) {
                'quiz' => match (true) {
                    ! isset($questions[$id]) => "The lesson has no question {$id}.",
                    ! in_array($answer['optionId'], array_column($questions[$id]['options'], 'id'), true) => "Question {$id} has no option {$answer['optionId']}.",
                    default => null,
                },
                'recall' => isset($recalls[$id]) ? null : "The lesson has no recall {$id}.",
                'steps' => isset($steps[$id]) ? null : "The lesson has no step {$id}.",
            };

            if ($error === null && isset($seen[$answer['type'].':'.$id])) {
                $error = "{$id} is answered more than once.";
            }

            $seen[$answer['type'].':'.$id] = true;

            if ($error !== null) {
                $errors["answers.{$i}"] = $error;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $answers;
    }

    /**
     * The contract's attemptResult for answers that validate() accepted.
     *
     * @param  list<array<string, mixed>>  $answers
     * @return array{perAnswer: list<array<string, mixed>>, recordCandidate: null, glossaryCandidates: list<never>}
     *
     * @throws GradingFailed
     */
    public function grade(Lesson $lesson, array $answers): array
    {
        [$questions, $recalls] = $this->index($lesson);

        $recallGrades = $this->gradeRecalls(
            array_values(array_filter($answers, fn (array $a) => $a['type'] === 'recall')),
            $recalls,
        );

        $perAnswer = [];

        foreach ($answers as $answer) {
            if ($answer['type'] === 'quiz') {
                $question = $questions[$answer['questionId']];
                $option = collect($question['options'])->firstWhere('id', $answer['optionId']);

                $perAnswer[] = [
                    'type' => 'quiz',
                    'id' => $answer['questionId'],
                    'correct' => $answer['optionId'] === $question['correctOptionId'],
                    'feedback' => trim($option['feedback'].' '.$question['explanation']),
                ];
            }

            if ($answer['type'] === 'recall') {
                $perAnswer[] = ['type' => 'recall', 'id' => $answer['recallId'], ...$recallGrades[$answer['recallId']]];
            }
        }

        // Null and empty until learning records and the glossary exist.
        return [
            'perAnswer' => $perAnswer,
            'recordCandidate' => null,
            'glossaryCandidates' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $answers
     * @param  array<string, array<string, mixed>>  $recalls
     * @return array<string, array{correct: bool, feedback: string}>
     *
     * @throws GradingFailed
     */
    private function gradeRecalls(array $answers, array $recalls): array
    {
        $grades = [];
        $toGrade = [];

        foreach ($answers as $answer) {
            $recall = $recalls[$answer['recallId']];

            if (trim($answer['text']) === '') {
                $grades[$answer['recallId']] = [
                    'correct' => false,
                    'feedback' => "No answer was given. A complete answer says: {$recall['modelAnswer']}",
                ];

                continue;
            }

            $toGrade[] = [
                'recallId' => $answer['recallId'],
                'question' => $recall['prompt'],
                'modelAnswer' => $recall['modelAnswer'],
                'rubric' => $recall['rubric'],
                'learnerAnswer' => $answer['text'],
            ];
        }

        if ($toGrade === []) {
            return $grades;
        }

        $reply = RecallGrader::make()->prompt(
            "Grade these recall answers:\n".json_encode($toGrade, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        )->text;

        $results = collect(ModelJson::decode($reply)['results'] ?? [])
            ->filter(fn ($r) => is_array($r) && is_string($r['recallId'] ?? null))
            ->keyBy('recallId');

        foreach ($toGrade as $item) {
            $result = $results->get($item['recallId']);

            if (! is_bool($result['correct'] ?? null) || ! is_string($result['feedback'] ?? null) || trim($result['feedback']) === '') {
                throw new GradingFailed("The grader returned no usable grade for {$item['recallId']}.");
            }

            $grades[$item['recallId']] = ['correct' => $result['correct'], 'feedback' => $result['feedback']];
        }

        return $grades;
    }

    /**
     * The lesson's answerable blocks by id: quiz questions, recall blocks and step ids.
     *
     * @return array{array<string, array<string, mixed>>, array<string, array<string, mixed>>, array<string, true>}
     */
    private function index(Lesson $lesson): array
    {
        $questions = [];
        $recalls = [];
        $steps = [];

        foreach ($lesson->content['blocks'] as $block) {
            if ($block['type'] === 'quiz') {
                foreach ($block['questions'] as $question) {
                    $questions[$question['id']] = $question;
                }
            }

            if ($block['type'] === 'recall') {
                $recalls[$block['id']] = $block;
            }

            if ($block['type'] === 'steps') {
                foreach ($block['items'] as $item) {
                    $steps[$item['id']] = true;
                }
            }
        }

        return [$questions, $recalls, $steps];
    }
}
