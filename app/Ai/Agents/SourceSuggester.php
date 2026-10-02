<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Interim stand-in for the source finder. Suggests sources from the model's memory, so the
 * url check in GenerateLesson is the only guard against invented ones. Replace it with the
 * web-search source finder rather than building on it.
 */
#[Provider(Lab::OpenRouter)]
#[Model('stealth/space-bunny-alpha')]
class SourceSuggester implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
You suggest learning sources for a learner's topic and mission. Suggest two or three well-known, stable web pages (official documentation, established references, reputable tutorials) that teach what the mission needs. Only suggest pages you are confident exist at that exact url.

Reply with ONLY a single JSON object, no markdown fences, no commentary:
{"sources": [{"title": string, "url": string (http or https), "annotation": string (one line: why this source is worth reading for this mission)}]}
PROMPT;
    }
}
