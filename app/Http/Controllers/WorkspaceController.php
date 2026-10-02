<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    /**
     * List workspaces
     *
     * The signed-in learner's own workspaces.
     */
    public function index(Request $request)
    {
        $workspaces = $request->user()->workspaces()
            ->orderBy('id')
            ->get(['id', 'topic']);

        return ApiResponse::success('Workspaces retrieved.', $workspaces);
    }

    /**
     * Create workspace
     *
     * Start studying a new topic.
     */
    public function store(Request $request)
    {
        // The TrimStrings middleware has already trimmed the topic, so a
        // whitespace-only topic arrives as null and fails `required`.
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:255'],
        ]);

        $workspace = $request->user()->workspaces()->create($validated);

        return ApiResponse::success(
            'Workspace created.',
            $workspace->only(['id', 'topic']),
            201
        );
    }

    /**
     * Show workspace
     */
    public function show(Request $request, string $workspace)
    {
        $workspace = $request->user()->workspaces()->findOrFail($workspace);

        return ApiResponse::success('Workspace retrieved.', $workspace->only(['id', 'topic']));
    }
}
