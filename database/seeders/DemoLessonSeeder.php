<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local only. Gives the test account a workspace with an active mission and the three
 * stored-lesson fixtures, so the frontend can walk the real backend before lesson
 * generation is configured.
 */
class DemoLessonSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            return;
        }

        $user = User::where('username', UserSeeder::USERNAME)->firstOrFail();

        $workspace = $user->workspaces()->create(['topic' => 'Two-step equations']);

        $workspace->missions()->create([
            'why' => 'Get through the algebra practice set before the Friday deadline.',
            'success_criteria' => ['Solve every two-step equation on the sheet without looking up the method'],
            'constraints' => ['Fifteen minutes a day'],
            'out_of_scope' => ['Quadratic equations'],
            'is_active' => true,
        ]);

        foreach (array_keys(LessonFixtures::NAMES) as $kind) {
            LessonFixtures::store($workspace, $kind);
        }
    }
}
