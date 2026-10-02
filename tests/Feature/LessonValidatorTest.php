<?php

use App\Lessons\LessonValidator;
use App\Models\Lesson;
use App\Models\Source;
use App\Models\Workspace;
use Database\Seeders\LessonFixtures;

/**
 * A fixture lesson for $kind, citing a fresh source in a fresh workspace, run through
 * $break before validation.
 *
 * @return list<string>
 */
function lessonErrors(string $kind, ?Closure $break = null): array
{
    $workspace = Workspace::factory()->create();
    $source = Source::factory()->for($workspace)->create();
    $lesson = LessonFixtures::lesson($kind, $source->id);

    if ($break !== null) {
        $lesson = $break($lesson, $workspace) ?? $lesson;
    }

    return (new LessonValidator)->validate($lesson, $workspace);
}

/**
 * Index of the first block of $type.
 */
function blockIndex(array $lesson, string $type): int
{
    foreach ($lesson['blocks'] as $i => $block) {
        if ($block['type'] === $type) {
            return $i;
        }
    }

    throw new RuntimeException("No {$type} block.");
}

it('accepts every fixture kind', function (string $kind) {
    expect(lessonErrors($kind))->toBe([]);
})->with(array_keys(LessonFixtures::NAMES));

it('rejects a lesson that breaks a rule', function (string $kind, Closure $break, string $error) {
    expect(implode("\n", lessonErrors($kind, $break)))->toContain($error);
})->with([
    'missing a required field' => ['concept', function ($l) {
        unset($l['title']);

        return $l;
    }, 'title'],
    'longer than 15 minutes' => ['concept', fn ($l) => [...$l, 'minutes' => 16], 'minutes'],
    'no practice block' => ['hands-on', function ($l) {
        $l['blocks'] = array_values(array_filter($l['blocks'], fn ($b) => ! in_array($b['type'], ['steps', 'recall'])));

        return $l;
    }, 'at least one quiz, recall or steps block'],
    'concept without recall' => ['concept', function ($l) {
        $l['blocks'] = array_values(array_filter($l['blocks'], fn ($b) => $b['type'] !== 'recall'));

        return $l;
    }, 'concept lessons require a recall block'],
    'concept with steps' => ['concept', function ($l) {
        $l['blocks'][] = ['type' => 'steps', 'title' => 'Do it', 'items' => [['id' => 's1', 'instruction' => 'Do', 'check' => 'Done']]];

        return $l;
    }, 'concept lessons must not contain a steps block'],
    'hands-on without a callout' => ['hands-on', function ($l) {
        $l['blocks'] = array_values(array_filter($l['blocks'], fn ($b) => $b['type'] !== 'callout'));

        return $l;
    }, 'hands-on lessons require a callout block'],
    'review with a paragraph' => ['review', function ($l) {
        $l['blocks'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'New material.']]];

        return $l;
    }, 'review lessons must not contain a paragraph block'],
    'review with a table' => ['review', function ($l) {
        $l['blocks'][] = ['type' => 'table', 'headers' => ['a'], 'rows' => [['1']]];

        return $l;
    }, 'review lessons must not contain a table block'],
    'a figure block' => ['concept', function ($l) {
        $l['blocks'][] = ['type' => 'figure', 'kind' => 'image', 'source' => 'https://example.org/a.png', 'alt' => 'A chart'];

        return $l;
    }, 'figure blocks are not allowed'],
    'a quiz question with two options' => ['review', function ($l) {
        $q = blockIndex($l, 'quiz');
        array_pop($l['blocks'][$q]['questions'][0]['options']);
        array_pop($l['blocks'][$q]['questions'][0]['options']);

        return $l;
    }, 'q1 must have 3 or 4 options'],
    'quiz options of different lengths' => ['review', function ($l) {
        $q = blockIndex($l, 'quiz');
        $l['blocks'][$q]['questions'][0]['options'][1]['text'] = 'Subtract three from both sides of the equation';

        return $l;
    }, 'q1 options must all have the same word count'],
    'a correct option that does not exist' => ['review', function ($l) {
        $l['blocks'][blockIndex($l, 'quiz')]['questions'][0]['correctOptionId'] = 'z';

        return $l;
    }, 'q1 correctOptionId'],
    'duplicate question ids' => ['review', function ($l) {
        $q = blockIndex($l, 'quiz');
        $l['blocks'][$q]['questions'][1]['id'] = 'q1';

        return $l;
    }, 'question id q1 is used more than once'],
    'duplicate recall ids' => ['review', function ($l) {
        $l['blocks'] = array_map(fn ($b) => $b['type'] === 'recall' ? [...$b, 'id' => 'rc1'] : $b, $l['blocks']);

        return $l;
    }, 'recall id rc1 is used more than once'],
    'duplicate step ids' => ['hands-on', function ($l) {
        $s = blockIndex($l, 'steps');
        $l['blocks'][$s]['items'][1]['id'] = 's1';

        return $l;
    }, 'step id s1 is used more than once'],
    'a recall without its model answer' => ['concept', function ($l) {
        unset($l['blocks'][blockIndex($l, 'recall')]['modelAnswer']);

        return $l;
    }, 'rc1 needs a modelAnswer and a rubric'],
    'a ragged table' => ['concept', function ($l) {
        $l['blocks'][] = ['type' => 'table', 'headers' => ['a', 'b'], 'rows' => [['1', '2'], ['3']]];

        return $l;
    }, 'row 2 has 1 cells but the table has 2 headers'],
    'no cite segment' => ['concept', function ($l) {
        foreach ($l['blocks'] as $b => $block) {
            if (isset($block['content'])) {
                $l['blocks'][$b]['content'] = array_values(array_filter($block['content'], fn ($s) => $s['type'] !== 'cite'));
            }
        }

        return $l;
    }, 'at least one cite segment'],
    'a term segment' => ['concept', function ($l) {
        $l['blocks'][blockIndex($l, 'paragraph')]['content'][] = ['type' => 'term', 'termId' => 1, 'text' => 'inverse'];

        return $l;
    }, 'term 1 is not in this workspace\'s glossary'],
    'a link to a lesson outside the workspace' => ['concept', function ($l) {
        $l['blocks'][blockIndex($l, 'paragraph')]['content'][] = ['type' => 'link', 'to' => 'lesson', 'targetId' => 999999, 'text' => 'earlier'];

        return $l;
    }, 'lesson 999999 is not in this workspace'],
]);

it('rejects a cite of a source from another workspace', function () {
    $theirs = Source::factory()->create();

    $errors = lessonErrors('concept', function ($l) use ($theirs) {
        $l['blocks'][blockIndex($l, 'paragraph')]['content'][] = ['type' => 'cite', 'resourceId' => $theirs->id, 'text' => 'elsewhere'];

        return $l;
    });

    expect(implode("\n", $errors))->toContain("cite of resource {$theirs->id}");
});

it('rejects a cite of a pruned source', function () {
    $errors = lessonErrors('concept', function ($l, Workspace $workspace) {
        $pruned = Source::factory()->for($workspace)->pruned()->create();
        $l['blocks'][blockIndex($l, 'paragraph')]['content'][] = ['type' => 'cite', 'resourceId' => $pruned->id, 'text' => 'old'];

        return $l;
    });

    expect(implode("\n", $errors))->toContain('is not an active resource in this workspace');
});

it('rejects a primary source from another workspace', function () {
    $theirs = Source::factory()->create();

    $errors = lessonErrors('concept', function ($l) use ($theirs) {
        $l['primarySource']['resourceId'] = $theirs->id;

        return $l;
    });

    expect(implode("\n", $errors))->toContain("primarySource resource {$theirs->id} is not in this workspace");
});

it('accepts a link to a lesson in the same workspace', function () {
    $errors = lessonErrors('concept', function ($l, Workspace $workspace) {
        $earlier = LessonFixtures::store($workspace, 'review');
        $l['blocks'][blockIndex($l, 'paragraph')]['content'][] = ['type' => 'link', 'to' => 'lesson', 'targetId' => $earlier->id, 'text' => 'earlier'];

        return $l;
    });

    expect($errors)->toBe([]);
});

it('reports a non-object lesson without crashing', function () {
    $workspace = Workspace::factory()->create();

    expect((new LessonValidator)->validate(['blocks' => 'nope'], $workspace))->not->toBe([]);
});
