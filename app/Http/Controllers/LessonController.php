<?php

namespace App\Http\Controllers;

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
