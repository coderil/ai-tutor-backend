<?php

namespace App\Lessons;

use App\Jobs\GenerateLesson;
use App\Models\Lesson;
use App\Models\Mission;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores what a /test-teach lesson turn produced: the mission the interview captured and
 * the lesson the model wrote, in the same rows the production lesson flow uses. A turn
 * with no workspace gets a new one, named after the mission's topic.
 *
 * The teach agent cannot cite workspace sources (it has no ids to cite), so the lesson's
 * primarySource becomes a source row here and the lesson points at it by id.
 */
class TeachTurnRecorder
{
    public function __construct(private LessonContract $contract = new LessonContract) {}

    /**
     * @param  array<mixed>  $missionDraft  The model's `mission_draft`.
     * @param  array<mixed>|null  $lessonDraft  The model's `lesson`, recall secrets included,
     *                                          or null when it failed the route's checks.
     * @return array{workspace: Workspace, mission: ?Mission, lesson: ?Lesson, errors: list<string>}
     */
    public function record(User $user, ?Workspace $workspace, array $missionDraft, ?array $lessonDraft): array
    {
        $workspace ??= $user->workspaces()->create([
            'topic' => Str::limit(self::text($missionDraft['topic'] ?? null) ?: self::text($lessonDraft['skill'] ?? null) ?: 'Untitled topic', 250),
        ]);

        $mission = $this->mission($workspace, $missionDraft);

        if ($lessonDraft === null) {
            return ['workspace' => $workspace, 'mission' => $mission, 'lesson' => null, 'errors' => []];
        }

        // resourceId and number are placeholders here; the shape check does not depend on
        // their values, and storing assigns the real ones.
        $content = $this->content($lessonDraft, 1, 1);
        $errors = array_map(fn (string $error) => "Contract: {$error}", $this->contract->errors(['lesson' => $content]));

        $lesson = $errors === [] ? $this->storeLesson($workspace, $lessonDraft) : null;

        return ['workspace' => $workspace, 'mission' => $mission, 'lesson' => $lesson, 'errors' => $errors];
    }

    /**
     * The active mission after this turn. A draft that differs from the active mission is
     * written as a new revision; an unchanged one writes nothing, so repeated lesson turns
     * do not pile up identical revisions.
     *
     * @param  array<mixed>  $draft
     */
    private function mission(Workspace $workspace, array $draft): ?Mission
    {
        // Queried, not read from the relation: a workspace passed back in from an earlier
        // turn may have cached an active mission that has since been superseded.
        $active = $workspace->activeMission()->first();
        $why = self::text($draft['why'] ?? null);

        if ($why === '') {
            return $active;
        }

        $fields = [
            'why' => $why,
            'success_criteria' => self::texts($draft['success'] ?? null),
            'constraints' => self::texts($draft['constraints'] ?? null),
            'out_of_scope' => self::texts($draft['out_of_scope'] ?? null),
        ];

        if ($active !== null && $active->only(array_keys($fields)) === $fields) {
            return $active;
        }

        $mission = DB::transaction(function () use ($workspace, $fields) {
            $workspace->missions()->where('is_active', true)->update(['is_active' => false]);

            return $workspace->missions()->create([...$fields, 'is_active' => true]);
        });

        $workspace->setRelation('activeMission', $mission);

        return $mission;
    }

    /**
     * @param  array<mixed>  $draft
     */
    private function storeLesson(Workspace $workspace, array $draft): Lesson
    {
        return DB::transaction(function () use ($workspace, $draft) {
            // Locking the workspace row serialises numbering within the workspace.
            Workspace::whereKey($workspace->id)->lockForUpdate()->first();

            $source = $this->source($workspace, $draft['primarySource']);
            $number = ($workspace->lessons()->max('number') ?? 0) + 1;

            return $workspace->lessons()->create([
                'number' => $number,
                'content' => $this->content($draft, $number, $source->id),
            ]);
        });
    }

    /**
     * The workspace's active source with this url, or a new one. Reusing it keeps one row
     * per url when several lessons draw on the same page.
     *
     * @param  array<mixed>  $primary
     */
    private function source(Workspace $workspace, array $primary): Source
    {
        return $workspace->sources()->active()->where('url', $primary['url'])->first()
            ?? $workspace->sources()->create([
                'kind' => 'knowledge',
                'title' => Str::limit((string) $primary['title'], 250),
                'url' => $primary['url'],
                'annotation' => $primary['why'],
                'status' => Source::STATUS_ACTIVE,
            ]);
    }

    /**
     * The lesson as the contract stores it: backend-owned fields filled in, the source
     * reduced to its id.
     *
     * @param  array<mixed>  $draft
     * @return array<string, mixed>
     */
    private function content(array $draft, int $number, int $resourceId): array
    {
        return [
            'schemaVersion' => GenerateLesson::SCHEMA_VERSION,
            'number' => $number,
            'kind' => $draft['kind'],
            'slug' => Str::slug(self::text($draft['title'] ?? null)) ?: "lesson-{$number}",
            'title' => $draft['title'],
            'skill' => $draft['skill'],
            'missionLink' => $draft['missionLink'],
            'minutes' => $draft['minutes'],
            'blocks' => array_values($draft['blocks']),
            'primarySource' => [
                'resourceId' => $resourceId,
                'why' => $draft['primarySource']['why'],
            ],
        ];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return list<string>
     */
    private static function texts(mixed $value): array
    {
        $items = is_array($value) ? array_map(self::text(...), $value) : [];

        return array_values(array_filter($items, fn (string $item) => $item !== ''));
    }
}
