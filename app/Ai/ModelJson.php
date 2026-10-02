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

        if (is_array($decoded) && ! array_is_list($decoded)) {
            return $decoded;
        }

        // The model sometimes appends one extra `}` after valid JSON, so the
        // old first-`{`-to-last-`}` fallback decodes nothing: the extra brace is
        // the last `}`. Scan string-aware for the first complete object instead.
        foreach (self::balancedObjects($candidate) as $object) {
            $decoded = json_decode($object, true);

            if (is_array($decoded) && ! array_is_list($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Every balanced `{...}` span starting from each opening brace, in order,
     * with braces inside double-quoted strings (and `\"` escapes) ignored.
     *
     * @return list<string>
     */
    private static function balancedObjects(string $text): array
    {
        $objects = [];
        $length = strlen($text);

        for ($start = 0; $start < $length; $start++) {
            if ($text[$start] !== '{') {
                continue;
            }

            $depth = 0;
            $inString = false;
            $escaped = false;

            for ($i = $start; $i < $length; $i++) {
                $char = $text[$i];

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($char === '"') {
                    $inString = true;
                } elseif ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $objects[] = substr($text, $start, $i - $start + 1);
                        break;
                    }
                }
            }
        }

        return $objects;
    }
}
