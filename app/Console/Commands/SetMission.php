<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Development-only stand-in for the mission interview, which is not built yet. Without an
 * active mission the frontend never offers the next lesson, so the app cannot be walked
 * end to end without this.
 */
class SetMission extends Command
{
    protected $signature = 'mission:set
        {workspace : The workspace id}
        {--why= : Why the learner is studying the topic}
        {--success=* : What success looks like (repeatable)}
        {--constraint=* : A constraint on the learner (repeatable)}
        {--out-of-scope=* : A topic to leave out for now (repeatable)}';

    protected $description = 'Give a workspace a new active mission (development only)';

    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('mission:set is for development only.');

            return self::FAILURE;
        }

        $workspace = Workspace::find($this->argument('workspace'));

        if ($workspace === null) {
            $this->error('No workspace with that id.');

            return self::FAILURE;
        }

        $why = trim((string) $this->option('why'));

        if ($why === '') {
            $this->error('--why is required.');

            return self::FAILURE;
        }

        $mission = DB::transaction(function () use ($workspace, $why) {
            $workspace->missions()->where('is_active', true)->update(['is_active' => false]);

            return $workspace->missions()->create([
                'why' => $why,
                'success_criteria' => $this->option('success'),
                'constraints' => $this->option('constraint'),
                'out_of_scope' => $this->option('out-of-scope'),
                'is_active' => true,
            ]);
        });

        $this->info("Mission {$mission->id} is now active for workspace {$workspace->id}.");

        return self::SUCCESS;
    }
}
