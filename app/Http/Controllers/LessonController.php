<?php

namespace App\Http\Controllers;

use App\Enums\ErrorCode;
use App\Jobs\GenerateLesson;
use App\Lessons\LessonResponse;
use App\Models\Lesson;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class LessonController extends Controller
{
    /**
     * List lessons
     *
     * The workspace's lessons, projected out of the stored lesson JSON. Only validated
     * lessons are stored, so every row carries these fields.
     */
    public function index(Request $request, string $workspace)
    {
        $workspace = $request->user()->workspaces()->findOrFail($workspace);

        $lessons = $workspace->lessons()->orderBy('number')->get()->map(fn (Lesson $lesson) => [
            'id' => $lesson->id,
            'number' => $lesson->number,
            'kind' => $lesson->content['kind'],
            'title' => $lesson->content['title'],
            'minutes' => $lesson->content['minutes'],
        ]);

        return ApiResponse::success('Lessons retrieved.', $lessons);
    }

    /**
     * Request next lesson
     *
     * Queues the workspace's next lesson and returns 202. The lesson list is the only signal
     * that it arrived. A second request while one is queued or running queues nothing.
     */
    public function next(Request $request, string $workspace)
    {
        $workspace = $request->user()->workspaces()->findOrFail($workspace);

        if ($workspace->activeMission === null) {
            return ApiResponse::error(
                'The workspace needs an active mission before it can have lessons.',
                ErrorCode::CONFLICT->value,
                409,
            );
        }

        GenerateLesson::dispatch($workspace);

        return ApiResponse::success('Lesson requested.', null, 202);
    }

    /**
     * Show lesson
     *
     * The lesson response from the contract: `lesson`, `terms` and `resources`. Recall
     * answers and rubrics never appear here.
     */
    public function show(Request $request, string $lesson)
    {
        $lesson = $request->user()->lessons()->findOrFail($lesson);

        return ApiResponse::success('Lesson retrieved.', LessonResponse::for($lesson));
    }
}
