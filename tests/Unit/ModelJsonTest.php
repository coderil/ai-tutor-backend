<?php

use App\Ai\ModelJson;

test('decodes a plain JSON object', function () {
    expect(ModelJson::decode('{"message": "hi", "phase": "interviewing"}'))->toBe([
        'message' => 'hi',
        'phase' => 'interviewing',
    ]);
});

test('strips code fences', function () {
    expect(ModelJson::decode("```json\n{\"message\": \"hi\"}\n```"))->toBe(['message' => 'hi']);
});

test('extracts the object from surrounding prose', function () {
    expect(ModelJson::decode('Here you go: {"message": "hi"} hope that helps'))->toBe([
        'message' => 'hi',
    ]);
});

test('recovers when the model appends one extra closing brace', function () {
    $reply = '{"message": "This first lesson covers two-step equations.", "phase": "lesson"}'.'}';

    expect(ModelJson::decode($reply))->toBe([
        'message' => 'This first lesson covers two-step equations.',
        'phase' => 'lesson',
    ]);
});

test('ignores braces inside strings when scanning for the object end', function () {
    $reply = '{"message": "close with } inside"}'.'}';

    expect(ModelJson::decode($reply))->toBe(['message' => 'close with } inside']);
});

test('returns null when there is no JSON object', function () {
    expect(ModelJson::decode('just prose, no braces'))->toBeNull();
    expect(ModelJson::decode('[1, 2, 3]'))->toBeNull();
});
