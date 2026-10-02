<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One learner's study of one subject. Every other learner-owned row hangs off a workspace,
 * so every request resolves its rows through the signed-in user's workspaces.
 */
#[Fillable(['topic'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'communities_opt_out' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class);
    }

    public function activeMission(): HasOne
    {
        // The constraint goes inside ofMany so the newest *active* row wins. A where() before
        // latestOfMany() would pick the newest row of any status, then filter it out.
        return $this->hasOne(Mission::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->where('is_active', true),
        );
    }

    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
}
