<?php

namespace Database\Seeders;

use App\Models\Lesson;
use App\Models\Source;
use App\Models\Workspace;

/**
 * Stored-lesson fixtures in database/fixtures/lessons, derived from the frontend's plain
 * fixtures and made to pass the backend's lesson rules. Each file is a lesson response
 * with the recall secrets put back. Resource ids in a file are local to it, so they are
 * remapped onto real source rows before use.
 */
class LessonFixtures
{
    public const NAMES = [
        'concept' => 'concept-solving-two-step-equations',
        'hands-on' => 'hands-on-setting-up-the-practice-set',
        'review' => 'review-reviewing-two-step-equations',
    ];

    /**
     * The fixture file as decoded JSON: `lesson`, `terms` and `resources`.
     *
     * @return array<string, mixed>
     */
    public static function raw(string $kind): array
    {
        $path = database_path('fixtures/lessons/'.self::NAMES[$kind].'.json');

        return json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The fixture's lesson with every resource reference pointed at $resourceId.
     *
     * @return array<string, mixed>
     */
    public static function lesson(string $kind, int $resourceId): array
    {
        return self::remapResources(self::raw($kind)['lesson'], fn () => $resourceId);
    }

    /**
     * Store the fixture as the workspace's next lesson, creating a source for each resource
     * the fixture cites unless the workspace already has one at that url.
     */
    public static function store(Workspace $workspace, string $kind): Lesson
    {
        $fixture = self::raw($kind);
        $ids = [];

        foreach ($fixture['resources'] as $localId => $resource) {
            $ids[$localId] = $workspace->sources()->firstOrCreate(
                ['url' => $resource['url']],
                [
                    'kind' => 'knowledge',
                    'title' => $resource['title'],
                    'annotation' => 'Seeded from the frontend lesson fixtures.',
                    'status' => Source::STATUS_ACTIVE,
                ],
            )->id;
        }

        $lesson = self::remapResources($fixture['lesson'], fn (int $localId) => $ids[$localId]);
        $lesson['number'] = ($workspace->lessons()->max('number') ?? 0) + 1;

        return $workspace->lessons()->create([
            'number' => $lesson['number'],
            'content' => $lesson,
        ]);
    }

    /**
     * @param  array<string, mixed>  $lesson
     * @param  callable(int): int  $map
     * @return array<string, mixed>
     */
    private static function remapResources(array $lesson, callable $map): array
    {
        $lesson['primarySource']['resourceId'] = $map($lesson['primarySource']['resourceId']);

        foreach ($lesson['blocks'] as $b => $block) {
            foreach ($block['content'] ?? [] as $s => $segment) {
                if ($segment['type'] === 'cite') {
                    $lesson['blocks'][$b]['content'][$s]['resourceId'] = $map($segment['resourceId']);
                }
            }
        }

        return $lesson;
    }
}
