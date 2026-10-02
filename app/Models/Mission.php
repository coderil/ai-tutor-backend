<?php

namespace App\Models;

use Database\Factories\MissionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Why the learner studies the workspace's topic. Stored as revisions: a change writes a
 * new active row and deactivates the old one, never an update in place.
 */
#[Fillable(['why', 'success_criteria', 'constraints', 'out_of_scope', 'is_active'])]
class Mission extends Model
{
    /** @use HasFactory<MissionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'success_criteria' => 'array',
            'constraints' => 'array',
            'out_of_scope' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
