<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MissionController extends Controller
{
    /**
     * Show active mission
     *
     * The workspace's active mission, or null when it has none. A missing mission is a 200
     * with null data, so it is never confused with a missing workspace.
     */
    public function show(Request $request, string $workspace)
    {
        $workspace = $request->user()->workspaces()->findOrFail($workspace);

        $mission = $workspace->activeMission;

        return ApiResponse::success('Mission retrieved.', $mission?->only([
            'id', 'why', 'is_active', 'success_criteria', 'constraints', 'out_of_scope',
        ]));
    }
}
