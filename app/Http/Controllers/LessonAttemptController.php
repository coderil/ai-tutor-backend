<?php

namespace App\Http\Controllers;

use App\Enums\ErrorCode;
use App\Lessons\AttemptGrader;
use App\Lessons\GradingFailed;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LessonAttemptController extends Controller
{
    /**
     * Submit attempt
     *
     * Takes the contract's attemptRequest, grades it, and returns its attemptResult.
     * Recall answers are graded synchronously by the model.
     */
    public function store(Request $request, AttemptGrader $grader, string $lesson)
    {
        $lesson = $request->user()->lessons()->findOrFail($lesson);

        $answers = $grader->validate(json_decode($request->getContent()), $lesson);

        try {
            $result = $grader->grade($lesson, $answers);
        } catch (GradingFailed $e) {
            Log::warning('Attempt grading failed.', ['lesson_id' => $lesson->id, 'reason' => $e->getMessage()]);

            return ApiResponse::error(
                'Your answers could not be graded just now. Submit again.',
                ErrorCode::SERVICE_UNAVAILABLE->value,
                503,
            );
        }

        $lesson->attempts()->create(['answers' => $answers, 'result' => $result]);

        return ApiResponse::success('Attempt graded.', $result);
    }
}
