<?php

use App\Models\User;
use App\Models\Workspace;

it('requires sign-in', function () {
    $this->getJson('/api/workspaces')->assertUnauthorized();
});

it('lists only the learner\'s own workspaces', function () {
    $learner = User::factory()->create();
    $mine = Workspace::factory()->for($learner)->create(['topic' => 'Algebra']);
    Workspace::factory()->create(['topic' => 'Someone else\'s']);

    $this->actingAs($learner)
        ->getJson('/api/workspaces')
        ->assertOk()
        ->assertExactJson([
            'message' => 'Workspaces retrieved.',
            'data' => [['id' => $mine->id, 'topic' => 'Algebra']],
        ]);
});

it('creates a workspace from a topic', function () {
    $learner = User::factory()->create();

    $response = $this->actingAs($learner)
        ->postJson('/api/workspaces', ['topic' => '  Two-step equations  '])
        ->assertCreated()
        ->assertJsonPath('data.topic', 'Two-step equations');

    $id = $response->json('data.id');

    $this->getJson("/api/workspaces/{$id}")
        ->assertOk()
        ->assertJsonPath('data', ['id' => $id, 'topic' => 'Two-step equations']);
});

it('rejects an empty or whitespace topic', function (mixed $topic) {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/workspaces', ['topic' => $topic])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('topic');
})->with([
    'missing' => null,
    'empty' => '',
    'whitespace' => '   ',
]);

it('hides another learner\'s workspace behind a 404', function () {
    $theirs = Workspace::factory()->create();

    $this->actingAs(User::factory()->create())
        ->getJson("/api/workspaces/{$theirs->id}")
        ->assertNotFound();
});

it('returns 404 for a workspace that does not exist', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/workspaces/999999')
        ->assertNotFound();
});
