<?php

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A resource the workspace's lessons draw their claims from. The domain says Source; the
 * table and the wire format (`resourceId`, `resources`) say resource, deliberately.
 */
#[Table('resources')]
#[Fillable(['kind', 'title', 'url', 'annotation', 'status'])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PRUNED = 'pruned';

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param  Builder<Source>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
