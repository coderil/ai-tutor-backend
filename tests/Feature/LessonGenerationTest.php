<?php

use App\Ai\Agents\LessonWriter;
use App\Ai\Agents\SourceSuggester;
use App\Models\Mission;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LessonFixtures;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * A workspace with an active mission and one active source.
 *
 * @return array{Workspace, Source}
 */
function readyWorkspace(): array
{
    $workspace = Workspace::factory()->create();
    Mission::factory()->for($workspace)->create();
    $source = Source::factory()->for($workspace)->create();

    return [$workspace, $source];
}

/**
 * The lesson writer's reply: a fixture lesson citing $resourceId, as the model would
 * write it (the backend fills in schemaVersion, number and slug).
 */
function writerReply(string $kind, int $resourceId, ?Closure $change = null): string
{
    $lesson = LessonFixtures::lesson($kind, $resourceId);
    unset($lesson['schemaVersion'], $lesson['number'], $lesson['slug']);

    return json_encode($change ? $change($lesson) : $lesson);
}

it('refuses to generate without an active mission', function () {
    LessonWriter::fake()->preventStrayPrompts();
    $workspace = Workspace::factory()->create();
    Mission::factory()->for($workspace)->create(['is_active' => false]);
    Source::factory()->for($workspace)->create();

    $this->actingAs($workspace->user)
        ->postJson("/api/workspaces/{$workspace->id}/lessons/next")
        ->assertConflict();

    $this->getJson("/api/workspaces/{$workspace->id}/lessons")->assertJsonPath('data', []);
    LessonWriter::assertNeverPrompted();
});

it('hides another learner\'s workspace behind a 404', function () {
    LessonWriter::fake()->preventStrayPrompts();
    [$workspace] = readyWorkspace();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/workspaces/{$workspace->id}/lessons/next")
        ->assertNotFound();

    LessonWriter::assertNeverPrompted();
});

it('stores a valid generated lesson as the next lesson', function () {
    [$workspace, $source] = readyWorkspace();
    LessonFixtures::store($workspace, 'concept');
    LessonFixtures::store($workspace, 'review');
    LessonWriter::fake([writerReply('hands-on', $source->id)])->preventStrayPrompts();

    $this->actingAs($workspace->user)
        ->postJson("/api/workspaces/{$workspace->id}/lessons/next")
        ->assertAccepted();

    $this->getJson("/api/workspaces/{$workspace->id}/lessons")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.2.number', 3)
        ->assertJsonPath('data.2.kind', 'hands-on')
        ->assertJsonPath('data.2.title', 'Setting up the practice set')
        ->assertJsonPath('data.2.minutes', 12);

    $id = $this->getJson("/api/workspaces/{$workspace->id}/lessons")->json('data.2.id');
    $response = $this->getJson("/api/lessons/{$id}")
        ->assertJsonPath('data.lesson.number', 3)
        ->assertJsonPath('data.lesson.schemaVersion', 1)
        ->assertJsonPath('data.lesson.slug', 'setting-up-the-practice-set');

    expectMatchesContract($response);
});

it('accepts a reply wrapped in a code fence', function () {
    [$workspace, $source] = readyWorkspace();
    LessonWriter::fake(["```json\n".writerReply('concept', $source->id)."\n```"])->preventStrayPrompts();

    $this->actingAs($workspace->user)
        ->postJson("/api/workspaces/{$workspace->id}/lessons/next")
        ->assertAccepted();

    $this->getJson("/api/workspaces/{$workspace->id}/lessons")->assertJsonCount(1, 'data');
});

it('regenerates a lesson that breaks a rule', function () {
    [$workspace, $source] = readyWorkspace();
    LessonWriter::fake([
        writerReply('concept', $source->id, fn ($l) => [...$l, 'minutes' => 25]),
        'Sorry, here is your lesson!',
        writerReply('concept', $source->id),
    ])->preventStrayPrompts();

    $this->actingAs($workspace->user)
        ->postJson("/api/workspaces/{$workspace->id}/lessons/next")
        ->assertAccepted();

    $this->getJson("/api/workspaces/{$workspace->id}/lessons")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.minutes', 8);
    LessonWriter::assertPromptedTimes(3);
});

it('stores nothing when no attempt passes', function () {
    [$workspace, $source] = readyWorkspace();
    $tooLong = writerReply('concept', $source->id, fn ($l) => [...$l, 'minutes' => 25]);
    LessonWriter::fake([$tooLong, $tooLong, $tooLong])->preventStrayPrompts();

    $this->actingAs($workspace->user)
        ->postJson("/api/workspaces/{$workspace->id}/lessons/next")
        ->assertAccepted();

    $this->getJson("/api/workspaces/{$workspace->id}/lessons")->assertJsonPath('data', []);
    LessonWriter::assertPromptedTimes(3);
});

it('rejects a lesson citing a source from another workspace', function () {
    [$workspace] = readyWorkspace();
    $theirs = Source::factory()->create();
    $reply = writerReply('concept', $theirs->id);
    LessonWriter::fake([$reply, $reply, $reply])->preventStrayPrompts();

    $this->actingAs($workspace->user)
        ->postJson("/api/workspaces/{$workspace->id}/lessons/next")
        ->assertAccepted();

    $this->getJson("/api/workspaces/{$workspace->id}/lessons")->assertJsonPath('data', []);
});

it('produces one lesson when asked twice in a row', function () {
    config(['queue.default' => 'database']);
    [$workspace, $source] = readyWorkspace();
    LessonWriter::fake([writerReply('concept', $source->id), writerReply('review', $source->id)]);

    $this->actingAs($workspace->user);
    $this->postJson("/api/workspaces/{$workspace->id}/lessons/next")->assertAccepted();
    $this->postJson("/api/workspaces/{$workspace->id}/lessons/next")->assertAccepted();

    $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

    $this->getJson("/api/workspaces/{$workspace->id}/lessons")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.kind', 'concept');
});

it('finds sources for a workspace that has none, keeping only urls that resolve', function () {
    $workspace = Workspace::factory()->create();
    Mission::factory()->for($workspace)->create();
    Http::fake([
        'https://good.example.org/*' => Http::response('ok'),
        'https://dead.example.org/*' => Http::response('gone', 404),
    ]);
    SourceSuggester::fake([json_encode(['sources' => [
        ['title' => 'Good guide', 'url' => 'https://good.example.org/guide', 'annotation' => 'Worked examples.'],
        ['title' => 'Dead guide', 'url' => 'https://dead.example.org/guide', 'annotation' => 'Gone.'],
        ['title' => 'Not a web page', 'url' => 'ftp://example.org/guide', 'annotation' => 'Wrong scheme.'],
    ]])])->preventStrayPrompts();
    LessonWriter::fake(fn () => writerReply('concept', $workspace->sources()->sole()->id))->preventStrayPrompts();

    $this->actingAs($workspace->user)
        ->postJson("/api/workspaces/{$workspace->id}/lessons/next")
        ->assertAccepted();

    $id = $this->getJson("/api/workspaces/{$workspace->id}/lessons")
        ->assertJsonCount(1, 'data')
        ->json('data.0.id');

    $this->getJson("/api/lessons/{$id}")->assertJsonPath('data.resources', [
        (string) $workspace->sources()->sole()->id => ['title' => 'Good guide', 'url' => 'https://good.example.org/guide'],
    ]);
});

it('writes no lesson when no suggested source resolves', function () {
    $workspace = Workspace::factory()->create();
    Mission::factory()->for($workspace)->create();
    Http::fake(['*' => Http::response('gone', 404)]);
    SourceSuggester::fake([json_encode(['sources' => [
        ['title' => 'Dead guide', 'url' => 'https://dead.example.org/guide', 'annotation' => 'Gone.'],
    ]])])->preventStrayPrompts();
    LessonWriter::fake()->preventStrayPrompts();

    $this->actingAs($workspace->user)
        ->postJson("/api/workspaces/{$workspace->id}/lessons/next")
        ->assertAccepted();

    $this->getJson("/api/workspaces/{$workspace->id}/lessons")->assertJsonPath('data', []);
    LessonWriter::assertNeverPrompted();
});
