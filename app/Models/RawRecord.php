<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record exactly as a source returned it. Marts are rebuilt from these, so they can be
 * re-derived without pulling from the source again.
 */
#[Fillable(['data_source_id', 'stream', 'record_key', 'payload', 'payload_hash', 'seen_run_id', 'deleted_at'])]
class RawRecord extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }

    /**
     * Records the source still returns.
     *
     * @param  Builder<RawRecord>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('deleted_at');
    }
}
