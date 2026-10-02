<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A learner's answers to a lesson, with the grading that came back. A learner may submit
 * the same lesson more than once; every attempt is kept.
 */
#[Fillable(['answers', 'result'])]
class LessonAttempt extends Model
{
    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'result' => 'array',
        ];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
