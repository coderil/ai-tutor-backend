<?php

namespace App\Jobs;

use App\Ai\Agents\LessonWriter;
use App\Ai\Agents\SourceSuggester;
use App\Ai\ModelJson;
use App\Lessons\LessonValidator;
use App\Models\Lesson;
use App\Models\Mission;
use App\Models\Source;
use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Writes the workspace's next lesson. Asks the lesson writer, validates the reply, and asks
 * again with the validator's errors until a lesson passes or the attempts run out. Only a
 * lesson that passes is stored. The frontend learns it arrived by polling the lesson list.
 *
 * Unique per workspace, so a second request while one is queued or running adds nothing.
 */
class GenerateLesson implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * One first try plus two retries. The spec leaves the limit open.
     */
    public const ATTEMPTS = 3;

    public const SCHEMA_VERSION = 1;

    /**
     * The model calls retry inside the job, so the queue does not retry it.
     */
    public int $tries = 1;

    public int $timeout = 600;

    /**
     * Longer than $timeout, so a lock outlives its job only if the worker dies.
     */
    public int $uniqueFor = 900;

    public function __construct(public Workspace $workspace) {}

    public function uniqueId(): string
    {
        return (string) $this->workspace->id;
    }

    public function handle(LessonValidator $validator): void
    {
        $mission = $this->workspace->activeMission;

        if ($mission === null) {
            Log::warning('Lesson generation skipped: the workspace has no active mission.', $this->context());

            return;
        }

        $sources = $this->workspace->sources()->active()->orderBy('id')->get();

        if ($sources->isEmpty()) {
            $sources = $this->suggestSources($mission);
        }

        if ($sources->isEmpty()) {
            Log::warning('Lesson generation gave up: no suggested source resolved.', $this->context());

            return;
        }

        $brief = $this->brief($mission, $sources);
        $prompt = $brief;
        $failures = [];

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $reply = LessonWriter::make()->prompt($prompt)->text;
            $decoded = ModelJson::decode($reply);
            $lesson = $decoded === null ? null : $this->complete($decoded);

            $errors = $lesson === null
                ? ['The reply was not a single JSON object.']
                : $validator->validate($lesson, $this->workspace);

            if ($errors === []) {
                $this->store($lesson);

                return;
            }

            $failures[] = ['attempt' => $attempt, 'errors' => $errors, 'output' => $reply];
            $prompt = $this->retryPrompt($brief, $reply, $errors);
        }

        Log::warning('Lesson generation gave up: no attempt passed validation.', $this->context() + [
            'failures' => $failures,
        ]);
    }

    /**
     * Fill in the fields the backend owns. `number` is provisional here; store() assigns
     * the real one under a lock.
     *
     * @param  array<mixed>  $lesson
     * @return array<mixed>
     */
    private function complete(array $lesson): array
    {
        $number = ($this->workspace->lessons()->max('number') ?? 0) + 1;
        $title = is_string($lesson['title'] ?? null) ? $lesson['title'] : '';

        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'number' => $number,
            'slug' => Str::slug($title) ?: "lesson-{$number}",
            ...array_diff_key($lesson, array_flip(['schemaVersion', 'number', 'slug'])),
        ];
    }

    /**
     * @param  array<mixed>  $lesson
     */
    private function store(array $lesson): Lesson
    {
        return DB::transaction(function () use ($lesson) {
            // Locking the workspace row serialises numbering within the workspace.
            Workspace::whereKey($this->workspace->id)->lockForUpdate()->first();

            $lesson['number'] = ($this->workspace->lessons()->max('number') ?? 0) + 1;

            return $this->workspace->lessons()->create([
                'number' => $lesson['number'],
                'content' => $lesson,
            ]);
        });
    }

    /**
     * Interim sources: ask the model for a few, keep the ones whose url resolves. Weaker
     * than the source finder the spec asks for, and meant to be replaced by it.
     *
     * @return Collection<int, Source>
     */
    private function suggestSources(Mission $mission): Collection
    {
        $reply = SourceSuggester::make()->prompt(implode("\n\n", [
            "Topic: {$this->workspace->topic}",
            $this->describeMission($mission),
        ]))->text;

        $suggestions = ModelJson::decode($reply)['sources'] ?? [];

        return collect(is_array($suggestions) ? $suggestions : [])
            ->filter(fn ($s) => is_array($s)
                && filled($s['title'] ?? null) && is_string($s['title'])
                && filled($s['annotation'] ?? null) && is_string($s['annotation'])
                && is_string($s['url'] ?? null) && preg_match('#^https?://#i', $s['url']))
            ->filter(fn (array $s) => $this->resolves($s['url']))
            ->map(fn (array $s) => $this->workspace->sources()->create([
                'kind' => 'knowledge',
                'title' => Str::limit($s['title'], 250),
                'url' => $s['url'],
                'annotation' => $s['annotation'],
                'status' => Source::STATUS_ACTIVE,
            ]))
            ->values();
    }

    /**
     * A GET that ends in a 2xx after redirects.
     */
    private function resolves(string $url): bool
    {
        try {
            return Http::timeout(10)->get($url)->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    /**
     * @param  Collection<int, Source>  $sources
     */
    private function brief(Mission $mission, Collection $sources): string
    {
        $sourceList = $sources
            ->map(fn (Source $s) => "- id {$s->id}: {$s->title} ({$s->url}). {$s->annotation}")
            ->implode("\n");

        $earlier = $this->workspace->lessons()->orderBy('number')->get()
            ->map(fn (Lesson $l) => "- Lesson {$l->number} ({$l->content['kind']}): {$l->content['title']}. Skill: {$l->content['skill']}")
            ->implode("\n");

        return implode("\n\n", [
            "Topic: {$this->workspace->topic}",
            $this->describeMission($mission),
            "Sources you may cite, by id:\n{$sourceList}",
            'Lessons the learner has already had:'."\n".($earlier ?: '- None yet. This is the first lesson.'),
            'Write the next lesson.',
        ]);
    }

    /**
     * @param  list<string>  $errors
     */
    private function retryPrompt(string $brief, string $reply, array $errors): string
    {
        return implode("\n\n", [
            $brief,
            "Your previous reply was rejected:\n{$reply}",
            "Fix every one of these problems and reply with the whole corrected lesson:\n- ".implode("\n- ", $errors),
        ]);
    }

    private function describeMission(Mission $mission): string
    {
        $lines = ["Mission: {$mission->why}"];

        foreach (['Success looks like' => $mission->success_criteria, 'Constraints' => $mission->constraints, 'Out of scope' => $mission->out_of_scope] as $label => $items) {
            if (! empty($items)) {
                $lines[] = "{$label}: ".implode('; ', $items);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return ['workspace_id' => $this->workspace->id];
    }
}
