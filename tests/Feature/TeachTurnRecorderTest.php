<?php

use App\Lessons\LessonResponse;
use App\Lessons\TeachTurnRecorder;
use App\Models\Mission;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;

/**
 * A mission draft as the teach agent writes it on a lesson turn.
 *
 * @return array<string, mixed>
 */
function teachMissionDraft(array $overrides = []): array
{
    return [
        'topic' => 'mental math for two-digit addition',
        'why' => 'Add two-digit numbers in my head quickly enough to use in everyday situations.',
        'success' => ['Add two two-digit numbers mentally in under five seconds.'],
        'constraints' => ['Practice for 10 minutes a day.'],
        'out_of_scope' => ['Numbers with more than three digits.'],
        ...$overrides,
    ];
}

/**
 * A hands-on lesson as the teach agent writes it, recall secrets included.
 *
 * @return array<string, mixed>
 */
function teachLessonDraft(array $overrides = []): array
{
    return [
        'kind' => 'hands-on',
        'title' => 'Round to the nearest ten, then adjust',
        'skill' => 'Add two-digit numbers mentally by rounding one addend to ten and compensating.',
        'missionLink' => 'Gives you a fast method toward your five-second goal.',
        'minutes' => 10,
        'blocks' => [
            ['type' => 'heading', 'level' => 2, 'text' => 'Round, add, adjust'],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'For 47 + 36, round 36 to 40, add, then subtract 4.']]],
            ['type' => 'callout', 'tone' => 'note', 'content' => [['type' => 'text', 'text' => 'Round the number whose ones digit is 5 or more.']]],
            ['type' => 'steps', 'title' => 'Sprint', 'items' => [
                ['id' => 's1', 'instruction' => 'Calculate 28 + 35.', 'check' => '63.'],
            ]],
            [
                'type' => 'recall',
                'id' => 'rc1',
                'prompt' => 'Explain how you would calculate 58 + 26.',
                'modelAnswer' => 'Round 26 to 30, add 58 + 30 = 88, subtract 4 to get 84.',
                'rubric' => ['Rounds 26 to 30.', 'Gives 84.'],
            ],
        ],
        'primarySource' => [
            'title' => 'OpenStax Prealgebra 2e',
            'url' => 'https://openstax.org/books/prealgebra-2e/pages/2-2-addition-and-subtraction-of-integers',
            'why' => 'It covers the place-value reasoning behind the rounding strategy.',
        ],
        ...$overrides,
    ];
}

it('creates a workspace, an active mission and a lesson from a lesson turn', function () {
    $user = User::factory()->create();

    $recorded = app(TeachTurnRecorder::class)->record($user, null, teachMissionDraft(), teachLessonDraft());

    expect($recorded['errors'])->toBe([]);

    $workspace = $recorded['workspace'];
    expect($workspace->user_id)->toBe($user->id)
        ->and($workspace->topic)->toBe('mental math for two-digit addition');

    $mission = $workspace->activeMission;
    expect($mission->id)->toBe($recorded['mission']->id)
        ->and($mission->why)->toBe('Add two-digit numbers in my head quickly enough to use in everyday situations.')
        ->and($mission->success_criteria)->toBe(['Add two two-digit numbers mentally in under five seconds.'])
        ->and($mission->constraints)->toBe(['Practice for 10 minutes a day.'])
        ->and($mission->out_of_scope)->toBe(['Numbers with more than three digits.']);

    $source = $workspace->sources()->sole();
    expect($source->url)->toBe('https://openstax.org/books/prealgebra-2e/pages/2-2-addition-and-subtraction-of-integers')
        ->and($source->status)->toBe(Source::STATUS_ACTIVE);

    $lesson = $recorded['lesson'];
    expect($lesson->number)->toBe(1)
        ->and($lesson->content['slug'])->toBe('round-to-the-nearest-ten-then-adjust')
        ->and($lesson->content['primarySource'])->toBe(['resourceId' => $source->id, 'why' => 'It covers the place-value reasoning behind the rounding strategy.'])
        ->and($lesson->content['blocks'][4]['modelAnswer'])->toBe('Round 26 to 30, add 58 + 30 = 88, subtract 4 to get 84.');
});

it('stores a lesson the production read routes serve within the contract', function () {
    $user = User::factory()->create();
    $lesson = app(TeachTurnRecorder::class)->record($user, null, teachMissionDraft(), teachLessonDraft())['lesson'];

    $response = $this->actingAs($user)->getJson("/api/lessons/{$lesson->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.lesson.blocks.4.modelAnswer');

    expectMatchesContract($response);
    expect(LessonResponse::for($lesson)['resources'])->toHaveKey((string) $lesson->content['primarySource']['resourceId']);
});

it('adds to a given workspace, numbering lessons and reusing an unchanged mission and source', function () {
    $user = User::factory()->create();
    $recorder = app(TeachTurnRecorder::class);

    $first = $recorder->record($user, null, teachMissionDraft(), teachLessonDraft());
    $second = $recorder->record($user, $first['workspace'], teachMissionDraft(), teachLessonDraft(['title' => 'Split and combine']));

    expect($second['workspace']->id)->toBe($first['workspace']->id)
        ->and($second['mission']->id)->toBe($first['mission']->id)
        ->and($second['lesson']->number)->toBe(2)
        ->and($user->workspaces()->count())->toBe(1)
        ->and(Mission::count())->toBe(1)
        ->and(Source::count())->toBe(1);
});

it('writes a changed mission as a new active revision', function () {
    $user = User::factory()->create();
    $recorder = app(TeachTurnRecorder::class);

    $first = $recorder->record($user, null, teachMissionDraft(), null);
    $second = $recorder->record($user, $first['workspace'], teachMissionDraft(['constraints' => ['Practice for 20 minutes a day.']]), null);

    expect($second['mission']->id)->not->toBe($first['mission']->id)
        ->and($first['mission']->fresh()->is_active)->toBeFalse()
        ->and($second['workspace']->activeMission->constraints)->toBe(['Practice for 20 minutes a day.']);
});

it('keeps the mission but stores no lesson when the lesson breaks the contract', function () {
    $user = User::factory()->create();
    $draft = teachLessonDraft();
    $draft['blocks'][0]['level'] = 5;

    $recorded = app(TeachTurnRecorder::class)->record($user, null, teachMissionDraft(), $draft);

    expect($recorded['lesson'])->toBeNull()
        ->and($recorded['errors'])->not->toBe([])
        ->and($recorded['mission'])->not->toBeNull()
        ->and(Source::count())->toBe(0)
        ->and($recorded['workspace']->lessons()->count())->toBe(0);
});

it('writes no mission when the draft has no why', function () {
    $workspace = Workspace::factory()->create();

    $recorded = app(TeachTurnRecorder::class)->record($workspace->user, $workspace, teachMissionDraft(['why' => null]), null);

    expect($recorded['mission'])->toBeNull()
        ->and(Mission::count())->toBe(0);
});
