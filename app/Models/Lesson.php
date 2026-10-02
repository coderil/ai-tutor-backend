<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One generated lesson. `content` is the lesson JSON exactly as it was validated, recall
 * secrets included; the response layer strips them on the way out.
 */
#[Fillable(['number', 'content'])]
class Lesson extends Model
{
    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(LessonAttempt::class);
    }
}
