<?php

use App\Ai\Agents\TestAgent;
use App\Ai\Agents\TestTeachAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GeminiTestController;
use Illuminate\Support\Facades\Auth;
use App\Support\ApiResponse;

Route::middleware(['throttle:login'])->group(function() {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware(['auth:sanctum']);

Route::middleware(['auth:sanctum'])->group(function() {
    // Practice test routes for AI calls
    Route::match(['get', 'post'], '/test-gemini-raw', GeminiTestController::class);
    Route::match(['get', 'post'], '/test-agent-oneshot', function (Request $request) {

        $prompt = $request->input('prompt', 'Hi, my name is chiki how about you?');
        $response = (new TestAgent)->prompt($prompt);

        return APIResponse::success(
            'Agent test successful',
            [
                'reply' => $response->text,
                'response' => $response
            ]
        );
    });
    Route::post('/test-agent-chat', function (Request $request) {
        $request->validate([
            'prompt' => 'required|string',
            'conversation_id' => 'nullable|string',
        ]);

        $user = $request->user();

        $agent = $request->conversation_id
            ? TestAgent::make()->continue($request->conversation_id, as: $user)
            : TestAgent::make()->forUser($user);

        $response = $agent->prompt($request->prompt);

        return [
            'conversation_id' => $response->conversationId,
            'reply' => (string) $response,
        ];
    });
    Route::post('/test-teach', function (Request $request) {
        $request->validate([
            'prompt' => 'required|string',
            'conversation_id' => 'nullable|string',
        ]);

        $user = $request->user();

        $agent = $request->conversation_id
            ? TestTeachAgent::make()->continue($request->conversation_id, as: $user)
            : TestTeachAgent::make()->forUser($user);

        $response = $agent->prompt($request->prompt);

        $rawText = $response->text;
        $candidate = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', trim($rawText));

        $structured = null;
        $parseError = null;

        try {
            $decoded = json_decode($candidate, associative: true, flags: JSON_THROW_ON_ERROR);
            $structured = is_array($decoded) && isset($decoded['message']) ? $decoded : null;
            $parseError = $structured === null ? 'Response was valid JSON but lacked a message field.' : null;
        } catch (\JsonException $e) {
            $parseError = 'Response was not valid JSON: '.$e->getMessage();
        }

        // Synthesize a frontend-shaped lesson contract from the AI draft.
        // IDs don't exist anywhere yet (no workspaces table), so resourceId 1
        // is minted in-route and recall secrets are stripped like production.
        $lesson = null;
        $terms = (object) [];
        $resources = (object) [];
        $validationWarnings = [];

        if ($parseError === null && ($structured['phase'] ?? null) === 'lesson') {
            $draft = $structured['lesson'] ?? null;

            if (! is_array($draft)) {
                $validationWarnings[] = 'Phase is lesson but no lesson object was provided.';
            } else {
                foreach (['kind', 'title', 'skill', 'missionLink', 'minutes', 'blocks', 'primarySource'] as $key) {
                    if (! array_key_exists($key, $draft)) {
                        $validationWarnings[] = "Lesson is missing required key: {$key}.";
                    }
                }

                if (isset($draft['kind']) && ! in_array($draft['kind'], ['concept', 'hands-on', 'review'], true)) {
                    $validationWarnings[] = 'Lesson kind must be concept, hands-on, or review.';
                }

                if (isset($draft['minutes']) && (! is_int($draft['minutes']) || $draft['minutes'] < 1 || $draft['minutes'] > 15)) {
                    $validationWarnings[] = 'Lesson minutes must be an integer between 1 and 15.';
                }

                $blocks = $draft['blocks'] ?? null;
                if (! is_array($blocks) || $blocks === []) {
                    $validationWarnings[] = 'Lesson must contain at least one block.';
                    $blocks = [];
                }

                $allowedBlocks = ['heading', 'paragraph', 'callout', 'code', 'table', 'steps', 'quiz', 'recall'];
                $hasQuiz = false;
                $hasSteps = false;
                $hasRecall = false;

                foreach ($blocks as $i => $block) {
                    if (! is_array($block) || ! isset($block['type'])) {
                        $validationWarnings[] = "Block {$i} has no type.";
                        continue;
                    }

                    if (! in_array($block['type'], $allowedBlocks, true)) {
                        $validationWarnings[] = "Block {$i} has forbidden type: {$block['type']}.";
                        continue;
                    }

                    foreach ($block['content'] ?? [] as $segment) {
                        $segmentType = is_array($segment) ? ($segment['type'] ?? null) : null;
                        if (! in_array($segmentType, ['text', 'code'], true)) {
                            $validationWarnings[] = "Block {$i} uses a forbidden segment type: {$segmentType}. Only text and code segments are allowed.";
                            break;
                        }
                    }

                    if ($block['type'] === 'table' && isset($block['headers'], $block['rows'])) {
                        $width = count($block['headers']);
                        foreach ($block['rows'] as $r => $row) {
                            if (count($row) !== $width) {
                                $validationWarnings[] = "Table block {$i} row {$r} length does not match headers.";
                            }
                        }
                    }

                    if ($block['type'] === 'steps') {
                        $hasSteps = true;
                        foreach ($block['items'] ?? [] as $item) {
                            if (! isset($item['id']) || ! preg_match('/^s[0-9]+$/', (string) $item['id'])) {
                                $validationWarnings[] = "Steps block {$i} has an item with a bad id (expected s1, s2, ...).";
                            }
                        }
                    }

                    if ($block['type'] === 'quiz') {
                        $hasQuiz = true;
                        foreach ($block['questions'] ?? [] as $question) {
                            $qid = $question['id'] ?? '?';
                            $options = $question['options'] ?? [];
                            $count = count($options);

                            if ($count < 3 || $count > 4) {
                                $validationWarnings[] = "Quiz question {$qid} must have 3-4 options, got {$count}.";
                            }

                            $counts = array_map(
                                fn ($o) => str_word_count((string) ($o['text'] ?? '')),
                                $options
                            );
                            if (count(array_unique($counts)) > 1) {
                                $validationWarnings[] = "Quiz question {$qid} options do not share the same word count.";
                            }

                            $ids = array_column($options, 'id');
                            if (! in_array($question['correctOptionId'] ?? null, $ids, true)) {
                                $validationWarnings[] = "Quiz question {$qid} correctOptionId matches no option id.";
                            }

                            if (empty($question['explanation'] ?? null)) {
                                $validationWarnings[] = "Quiz question {$qid} is missing an explanation.";
                            }
                        }
                    }

                    if ($block['type'] === 'recall') {
                        $hasRecall = true;
                        if (! preg_match('/^rc[0-9]+$/', (string) ($block['id'] ?? ''))) {
                            $validationWarnings[] = "Recall block {$i} has a bad id (expected rc1, rc2, ...).";
                        }
                        if (! isset($block['modelAnswer']) || ! isset($block['rubric'])) {
                            $validationWarnings[] = "Recall block {$i} is missing modelAnswer or rubric (needed for the attempt flow).";
                        }
                        // Strip secrets like the production lesson response does.
                        unset($blocks[$i]['modelAnswer'], $blocks[$i]['rubric']);
                    }
                }

                $kind = $draft['kind'] ?? null;
                if ($kind === 'concept' && ! $hasQuiz) {
                    $validationWarnings[] = 'Concept lessons require at least one quiz block.';
                }
                if ($kind === 'hands-on' && (! $hasSteps || ! $hasRecall)) {
                    $validationWarnings[] = 'Hands-on lessons require steps and recall blocks.';
                }
                if ($kind === 'review' && (! $hasQuiz || ! $hasRecall)) {
                    $validationWarnings[] = 'Review lessons require quiz and recall blocks.';
                }

                $source = $draft['primarySource'] ?? null;
                if (! is_array($source) || empty($source['title'] ?? null) || empty($source['url'] ?? null) || empty($source['why'] ?? null)) {
                    $validationWarnings[] = 'Lesson primarySource must provide title, url, and why.';
                    $source = ['title' => 'Untitled source', 'url' => 'https://example.com', 'why' => 'No reason given.'];
                } elseif (! preg_match('#^https?://#i', (string) $source['url'])) {
                    $validationWarnings[] = 'Lesson primarySource url must be http or https.';
                }

                $slug = Str::slug((string) ($draft['title'] ?? '')) ?: 'lesson-1';

                $lesson = [
                    'schemaVersion' => 1,
                    'number' => 1,
                    'kind' => $kind,
                    'slug' => $slug,
                    'title' => $draft['title'] ?? '',
                    'skill' => $draft['skill'] ?? '',
                    'missionLink' => $draft['missionLink'] ?? '',
                    'minutes' => $draft['minutes'] ?? 0,
                    'blocks' => array_values($blocks),
                    'primarySource' => [
                        'resourceId' => 1,
                        'why' => $source['why'],
                    ],
                ];

                $resources = [
                    '1' => [
                        'title' => $source['title'],
                        'url' => $source['url'],
                    ],
                ];
            }
        }

        return ApiResponse::success(
            'Teach turn completed.',
            [
                'conversation_id' => $response->conversationId,
                'structured' => $structured,
                'reply' => $structured['message'] ?? $rawText,
                'phase' => $structured['phase'] ?? null,
                'questions' => $structured['questions'] ?? null,
                'lesson' => $lesson,
                'terms' => $terms,
                'resources' => $resources,
                'text' => $rawText,
                'parse_error' => $parseError,
                'validation_warnings' => $validationWarnings,
            ]
        );
    });
});


