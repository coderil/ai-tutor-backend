<?php

namespace Database\Factories;

use App\Models\Mission;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mission>
 */
class MissionFactory extends Factory
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
            'why' => fake()->sentence(),
            'success_criteria' => [fake()->sentence()],
            'constraints' => [],
            'out_of_scope' => [],
            'is_active' => true,
        ];
    }
}
