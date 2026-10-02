<?php

use App\Models\Lesson;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\LessonFixtures;

it('lists the workspace\'s lessons with number, kind, title and minutes', function () {
    $workspace = Workspace::factory()->create();
    $concept = LessonFixtures::store($workspace, 'concept');
    $review = LessonFixtures::store($workspace, 'review');

    $this->actingAs($workspace->user)
        ->getJson("/api/workspaces/{$workspace->id}/lessons")
        ->assertOk()
        ->assertExactJson([
            'message' => 'Lessons retrieved.',
            'data' => [
                ['id' => $concept->id, 'number' => 1, 'kind' => 'concept', 'title' => 'Solving two-step equations', 'minutes' => 8],
                ['id' => $review->id, 'number' => 2, 'kind' => 'review', 'title' => 'Reviewing two-step equations', 'minutes' => 6],
            ],
        ]);
});

it('returns an empty list for a workspace with no lessons', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->user)
        ->getJson("/api/workspaces/{$workspace->id}/lessons")
        ->assertOk()
        ->assertExactJson(['message' => 'Lessons retrieved.', 'data' => []]);
});

it('returns a lesson that matches the contract, for every kind', function (string $kind) {
    $workspace = Workspace::factory()->create();
    $lesson = LessonFixtures::store($workspace, $kind);

    $response = $this->actingAs($workspace->user)
        ->getJson("/api/lessons/{$lesson->id}")
        ->assertOk()
        ->assertJsonPath('data.lesson.kind', $kind)
        ->assertJsonPath('data.lesson.number', 1);

    expectMatchesContract($response);
})->with(array_keys(LessonFixtures::NAMES));

it('never sends recall answers or rubrics, and keeps them in storage', function () {
    $workspace = Workspace::factory()->create();
    $lesson = LessonFixtures::store($workspace, 'review');

    $response = $this->actingAs($workspace->user)
        ->getJson("/api/lessons/{$lesson->id}")
        ->assertOk();

    expect($response->getContent())
        ->not->toContain('modelAnswer')
        ->not->toContain('rubric');

    $recalls = collect($lesson->fresh()->content['blocks'])->where('type', 'recall');
    expect($recalls)->toHaveCount(3)
        ->each(fn ($recall) => $recall->toHaveKeys(['modelAnswer', 'rubric']));
});

it('hydrates the resources the lesson cites, keyed by id', function () {
    $workspace = Workspace::factory()->create();
    $lesson = LessonFixtures::store($workspace, 'concept');
    $source = $workspace->sources()->sole();

    $this->actingAs($workspace->user)
        ->getJson("/api/lessons/{$lesson->id}")
        ->assertJsonPath('data.lesson.primarySource.resourceId', $source->id)
        ->assertJsonPath('data.resources', [
            (string) $source->id => ['title' => $source->title, 'url' => $source->url],
        ]);
});

it('still hydrates a cited source after it is pruned', function () {
    $workspace = Workspace::factory()->create();
    $lesson = LessonFixtures::store($workspace, 'concept');
    $source = $workspace->sources()->sole();
    $source->update(['status' => 'pruned']);

    $this->actingAs($workspace->user)
        ->getJson("/api/lessons/{$lesson->id}")
        ->assertJsonPath("data.resources.{$source->id}.title", $source->title);
});

it('serialises terms and resources as JSON objects, including when empty', function () {
    $workspace = Workspace::factory()->create();
    $lesson = LessonFixtures::store($workspace, 'concept');
    // A lesson whose sources are gone leaves an empty resources map.
    $workspace->sources()->delete();

    $body = $this->actingAs($workspace->user)
        ->getJson("/api/lessons/{$lesson->id}")
        ->assertOk()
        ->getContent();

    expect($body)->toContain('"terms":{}')->toContain('"resources":{}');
});

it('hides another learner\'s lesson list and lesson behind a 404', function () {
    $workspace = Workspace::factory()->create();
    $lesson = LessonFixtures::store($workspace, 'concept');

    $this->actingAs(User::factory()->create());

    $this->getJson("/api/workspaces/{$workspace->id}/lessons")->assertNotFound();
    $this->getJson("/api/lessons/{$lesson->id}")->assertNotFound();
});

it('returns 404 for a lesson that does not exist', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/lessons/999999')
        ->assertNotFound();
});
