<?php

use App\Models\User;

it('does not register the AI practice routes outside local', function (string $path) {
    $this->actingAs(User::factory()->create())
        ->postJson($path, ['prompt' => 'Hello'])
        ->assertNotFound();
})->with(['/api/test-gemini-raw', '/api/test-agent-oneshot', '/api/test-agent-chat', '/api/test-teach']);
