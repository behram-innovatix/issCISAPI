<?php

namespace App\Models;

use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sync of one source: the audit trail of what was read, kept and built, and why it failed.
 */
#[Fillable(['data_source_id', 'mode', 'status', 'stats', 'replication', 'error', 'started_at', 'finished_at'])]
class SyncRun extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => SyncMode::class,
            'status' => SyncStatus::class,
            'stats' => 'array',
            'replication' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
