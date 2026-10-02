<?php

use App\Models\Mission;
use App\Models\User;
use App\Models\Workspace;

it('returns null when the workspace has no active mission', function () {
    $workspace = Workspace::factory()->create();
    Mission::factory()->for($workspace)->create(['is_active' => false]);

    $this->actingAs($workspace->user)
        ->getJson("/api/workspaces/{$workspace->id}/mission")
        ->assertOk()
        ->assertExactJson(['message' => 'Mission retrieved.', 'data' => null]);
});

it('returns the active mission and never a superseded one', function () {
    $workspace = Workspace::factory()->create();
    Mission::factory()->for($workspace)->create(['why' => 'Old reason', 'is_active' => false]);
    $active = Mission::factory()->for($workspace)->create([
        'why' => 'Pass the practice set by Friday.',
        'success_criteria' => ['Solve every problem on the sheet'],
        'is_active' => true,
    ]);

    $this->actingAs($workspace->user)
        ->getJson("/api/workspaces/{$workspace->id}/mission")
        ->assertOk()
        ->assertJsonPath('data.id', $active->id)
        ->assertJsonPath('data.why', 'Pass the practice set by Friday.')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.success_criteria', ['Solve every problem on the sheet']);
});

it('returns the active mission when a newer revision is inactive', function () {
    $workspace = Workspace::factory()->create();
    $active = Mission::factory()->for($workspace)->create(['is_active' => true]);
    Mission::factory()->for($workspace)->create(['is_active' => false]);

    $this->actingAs($workspace->user)
        ->getJson("/api/workspaces/{$workspace->id}/mission")
        ->assertJsonPath('data.id', $active->id);
});

it('hides another learner\'s mission behind a 404', function () {
    $workspace = Workspace::factory()->create();
    Mission::factory()->for($workspace)->create();

    $this->actingAs(User::factory()->create())
        ->getJson("/api/workspaces/{$workspace->id}/mission")
        ->assertNotFound();
});

it('sets an active mission from the command line and supersedes the previous one', function () {
    $workspace = Workspace::factory()->create();

    $this->artisan('mission:set', ['workspace' => $workspace->id, '--why' => 'First reason.'])
        ->assertSuccessful();
    $this->artisan('mission:set', [
        'workspace' => $workspace->id,
        '--why' => 'Second reason.',
        '--success' => ['Solve the sheet'],
        '--out-of-scope' => ['Quadratics'],
    ])->assertSuccessful();

    $this->actingAs($workspace->user)
        ->getJson("/api/workspaces/{$workspace->id}/mission")
        ->assertJsonPath('data.why', 'Second reason.')
        ->assertJsonPath('data.success_criteria', ['Solve the sheet'])
        ->assertJsonPath('data.out_of_scope', ['Quadratics']);

    expect($workspace->missions()->count())->toBe(2)
        ->and($workspace->missions()->where('is_active', true)->count())->toBe(1);
});

it('refuses to set a mission without a reason', function () {
    $workspace = Workspace::factory()->create();

    $this->artisan('mission:set', ['workspace' => $workspace->id, '--why' => '  '])
        ->assertFailed();

    expect($workspace->missions()->count())->toBe(0);
});
