<?php

namespace App\Lessons;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * The frontend's lesson contract, docs/lesson-schema.json, copied into
 * resources/contracts. Validates data against the whole schema or one of its $defs.
 */
class LessonContract
{
    public const SCHEMA_ID = 'https://github.com/Maykiyel/ai-tutor-frontend/docs/lesson-schema.json';

    private Validator $validator;

    public function __construct()
    {
        $this->validator = new Validator;
        $this->validator->setMaxErrors(20);
        $this->validator->resolver()->registerFile(
            self::SCHEMA_ID,
            resource_path('contracts/lesson-schema.json'),
        );
    }

    /**
     * Validate against the root schema (the lesson response) or a named definition such as
     * `attemptRequest`. Returns "path: message" strings, empty when the data is valid.
     *
     * @return list<string>
     */
    public function errors(mixed $data, ?string $definition = null): array
    {
        $uri = $definition === null ? self::SCHEMA_ID : self::SCHEMA_ID.'#/$defs/'.$definition;

        $result = $this->validator->validate(self::toJsonValue($data), $uri);

        if ($result->isValid()) {
            return [];
        }

        $errors = [];

        foreach ((new ErrorFormatter)->formatKeyed($result->error()) as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = "{$path}: {$message}";
            }
        }

        return $errors;
    }

    /**
     * Opis reads JSON objects as stdClass. Round-trip decoded arrays through JSON so a
     * PHP associative array becomes an object and a list stays an array.
     */
    private static function toJsonValue(mixed $data): mixed
    {
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
    }
}
