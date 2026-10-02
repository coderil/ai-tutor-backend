<?php

use App\Ai\Agents\RecallGrader;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LessonFixtures;

function attemptOn(string $kind): array
{
    $workspace = Workspace::factory()->create();
    $lesson = LessonFixtures::store($workspace, $kind);

    test()->actingAs($workspace->user);

    return [$lesson, $workspace];
}

it('grades quiz answers against the correct option, with the option\'s feedback', function () {
    RecallGrader::fake()->preventStrayPrompts();
    [$lesson] = attemptOn('review');

    $response = $this->postJson("/api/lessons/{$lesson->id}/attempts", ['answers' => [
        ['type' => 'quiz', 'questionId' => 'q1', 'optionId' => 'b'],
        ['type' => 'quiz', 'questionId' => 'q2', 'optionId' => 'c'],
    ]])->assertOk();

    $response->assertExactJson([
        'message' => 'Attempt graded.',
        'data' => [
            'perAnswer' => [
                [
                    'type' => 'quiz',
                    'id' => 'q1',
                    'correct' => true,
                    'feedback' => 'Exactly that. Take the 3 off first and the term x is left standing alone. Undo addition before you undo multiplication. Doing it the other way round changes what the 2 is multiplying.',
                ],
                [
                    'type' => 'quiz',
                    'id' => 'q2',
                    'correct' => false,
                    'feedback' => 'They should match. If they do not, the mistake is earlier than the last line. Check that every step was reversible, then put the answer back on both sides.',
                ],
            ],
            'recordCandidate' => null,
            'glossaryCandidates' => [],
        ],
    ]);
    expectMatchesContract($response, 'attemptResult');
    RecallGrader::assertNeverPrompted();
});

it('grades recall answers with one grader call, after submit', function () {
    RecallGrader::fake([json_encode(['results' => [
        ['recallId' => 'rc1', 'correct' => true, 'feedback' => 'Right: addition comes off first.'],
        ['recallId' => 'rc2', 'correct' => false, 'feedback' => 'The expected answer covered doing the same thing to both sides.'],
    ]])])->preventStrayPrompts();
    [$lesson] = attemptOn('review');

    $response = $this->postJson("/api/lessons/{$lesson->id}/attempts", ['answers' => [
        ['type' => 'recall', 'recallId' => 'rc1', 'text' => 'Addition was done last.'],
        ['type' => 'recall', 'recallId' => 'rc2', 'text' => 'No idea.'],
    ]])->assertOk();

    $response
        ->assertJsonPath('data.perAnswer.0', ['type' => 'recall', 'id' => 'rc1', 'correct' => true, 'feedback' => 'Right: addition comes off first.'])
        ->assertJsonPath('data.perAnswer.1', ['type' => 'recall', 'id' => 'rc2', 'correct' => false, 'feedback' => 'The expected answer covered doing the same thing to both sides.']);
    expectMatchesContract($response, 'attemptResult');
    RecallGrader::assertPromptedTimes(1);
    RecallGrader::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Addition was done last.'));
});

it('grades an empty recall answer wrong without calling the grader', function () {
    RecallGrader::fake()->preventStrayPrompts();
    [$lesson] = attemptOn('review');

    $this->postJson("/api/lessons/{$lesson->id}/attempts", ['answers' => [
        ['type' => 'recall', 'recallId' => 'rc1', 'text' => '   '],
    ]])
        ->assertOk()
        ->assertJsonPath('data.perAnswer.0.id', 'rc1')
        ->assertJsonPath('data.perAnswer.0.correct', false);

    RecallGrader::assertNeverPrompted();
});

it('keeps steps and skipped answers out of perAnswer', function () {
    RecallGrader::fake()->preventStrayPrompts();
    [$lesson] = attemptOn('hands-on');

    $response = $this->postJson("/api/lessons/{$lesson->id}/attempts", ['answers' => [
        ['type' => 'steps', 'stepId' => 's1', 'done' => true],
        ['type' => 'steps', 'stepId' => 's2', 'done' => false],
    ]])->assertOk();

    $response->assertJsonPath('data.perAnswer', []);
    expectMatchesContract($response, 'attemptResult');
});

it('keeps every attempt at the same lesson', function () {
    RecallGrader::fake()->preventStrayPrompts();
    [$lesson] = attemptOn('review');
    $answers = ['answers' => [['type' => 'quiz', 'questionId' => 'q1', 'optionId' => 'a']]];

    $this->postJson("/api/lessons/{$lesson->id}/attempts", $answers)->assertOk();
    $this->postJson("/api/lessons/{$lesson->id}/attempts", $answers)->assertOk();

    $this->assertDatabaseCount('lesson_attempts', 2);
});

it('rejects an answer the lesson has no block for', function (array $answer) {
    RecallGrader::fake()->preventStrayPrompts();
    [$lesson] = attemptOn('review');

    $this->postJson("/api/lessons/{$lesson->id}/attempts", ['answers' => [$answer]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('answers.0');

    $this->assertDatabaseCount('lesson_attempts', 0);
})->with([
    'unknown question' => [['type' => 'quiz', 'questionId' => 'q9', 'optionId' => 'a']],
    'unknown option' => [['type' => 'quiz', 'questionId' => 'q1', 'optionId' => 'z']],
    'unknown recall' => [['type' => 'recall', 'recallId' => 'rc9', 'text' => 'Something.']],
    'unknown step' => [['type' => 'steps', 'stepId' => 's1', 'done' => true]],
]);

it('rejects two answers to the same question', function () {
    RecallGrader::fake()->preventStrayPrompts();
    [$lesson] = attemptOn('review');

    $this->postJson("/api/lessons/{$lesson->id}/attempts", ['answers' => [
        ['type' => 'quiz', 'questionId' => 'q1', 'optionId' => 'a'],
        ['type' => 'quiz', 'questionId' => 'q1', 'optionId' => 'b'],
    ]])->assertUnprocessable()->assertJsonValidationErrors('answers.1');
});

it('rejects a request that does not match the contract', function (array $body) {
    RecallGrader::fake()->preventStrayPrompts();
    [$lesson] = attemptOn('review');

    $this->postJson("/api/lessons/{$lesson->id}/attempts", $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('answers');
})->with([
    'no answers key' => [[]],
    'quiz answer without an option' => [['answers' => [['type' => 'quiz', 'questionId' => 'q1']]]],
    'unknown answer type' => [['answers' => [['type' => 'essay', 'text' => 'Hi']]]],
]);

it('returns 503 and stores nothing when the grader reply is unusable', function () {
    RecallGrader::fake(['I think they did fine.'])->preventStrayPrompts();
    [$lesson] = attemptOn('review');

    $this->postJson("/api/lessons/{$lesson->id}/attempts", ['answers' => [
        ['type' => 'recall', 'recallId' => 'rc1', 'text' => 'Addition was done last.'],
    ]])->assertServiceUnavailable();

    $this->assertDatabaseCount('lesson_attempts', 0);
});

it('returns 503 and stores nothing when the grader call fails', function () {
    RecallGrader::fake(fn () => throw new RuntimeException('Provider is down.'));
    [$lesson] = attemptOn('review');

    $this->postJson("/api/lessons/{$lesson->id}/attempts", ['answers' => [
        ['type' => 'recall', 'recallId' => 'rc1', 'text' => 'Addition was done last.'],
    ]])->assertServiceUnavailable();

    $this->assertDatabaseCount('lesson_attempts', 0);
});

it('hides another learner\'s lesson behind a 404', function () {
    RecallGrader::fake()->preventStrayPrompts();
    $lesson = LessonFixtures::store(Workspace::factory()->create(), 'review');

    $this->actingAs(User::factory()->create())
        ->postJson("/api/lessons/{$lesson->id}/attempts", ['answers' => []])
        ->assertNotFound();
});
