<?php

namespace App\Ai;

/**
 * Reads a JSON object out of a model's text reply. The agents ask for plain JSON-in-text
 * rather than native structured output, because the lesson shape (a list of nine block
 * types, each with its own fields) does not fit the providers' structured output subsets.
 * Models still wrap JSON in code fences or add a sentence around it, so this strips both.
 */
class ModelJson
{
    /**
     * @return array<mixed>|null The decoded object, or null when there is none.
     */
    public static function decode(string $text): ?array
    {
        $candidate = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', trim($text));

        $decoded = json_decode($candidate, true);

        if (! is_array($decoded)) {
            $start = strpos($candidate, '{');
            $end = strrpos($candidate, '}');

            $decoded = $start !== false && $end > $start
                ? json_decode(substr($candidate, $start, $end - $start + 1), true)
                : null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
