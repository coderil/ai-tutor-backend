<?php

namespace Database\Factories;

use App\Models\Source;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'kind' => 'knowledge',
            'title' => fake()->sentence(4),
            'url' => fake()->url(),
            'annotation' => fake()->sentence(),
            'status' => Source::STATUS_ACTIVE,
        ];
    }

    public function pruned(): static
    {
        return $this->state(['status' => Source::STATUS_PRUNED]);
    }
}
