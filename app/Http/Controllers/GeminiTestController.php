<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Gemini\Laravel\Facades\Gemini;
use Illuminate\Http\Request;

class GeminiTestController extends Controller
{
    /**
     * Test Gemini connection
     *
     * Send a prompt to Gemini and return the generated text.
     */
    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'prompt' => ['sometimes', 'string', 'max:5000'],
            'model' => ['sometimes', 'string', 'max:100'],
        ]);

        $prompt = $validated['prompt'] ?? 'Say hello in one short sentence.';
        $model = $validated['model'] ?? 'gemini-3.8-flash';

        try {
            $result = Gemini::generativeModel($model)->generateContent($prompt);

            return ApiResponse::success(
                'Gemini responded successfully.',
                [
                    'model' => $model,
                    'prompt' => $prompt,
                    'text' => $result->text(),
                ]
            );
        } catch (\Throwable $e) {
            return ApiResponse::error(
                message: 'Gemini request failed: '.$e->getMessage(),
                statusCode: 500,
            );
        }
    }
}
