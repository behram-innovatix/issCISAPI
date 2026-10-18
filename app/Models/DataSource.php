<?php

namespace App\Models;

use App\Enums\SourceStatus;
use Cron\CronExpression;
use Database\Factories\DataSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One configured source the CIS pulls from: a connector type, the platform its records belong to,
 * and that connector's (encrypted) config.
 */
#[Fillable(['name', 'type', 'platform', 'tenant', 'config', 'schedule', 'is_active', 'status', 'last_synced_at', 'data_changed_at', 'last_error'])]
#[Hidden(['config'])]
class DataSource extends Model
{
    /** @use HasFactory<DataSourceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_active' => 'boolean',
            'status' => SourceStatus::class,
            'last_synced_at' => 'datetime',
            'data_changed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<SyncRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    /**
     * @return HasOne<SyncRun, $this>
     */
    public function latestRun(): HasOne
    {
        return $this->hasOne(SyncRun::class)->latestOfMany();
    }

    /**
     * Tables copied from this (database) source.
     *
     * @return HasMany<SourceTable, $this>
     */
    public function sourceTables(): HasMany
    {
        return $this->hasMany(SourceTable::class);
    }

    /**
     * How this (database) source builds its dashboard streams.
     *
     * @return HasMany<StreamModel, $this>
     */
    public function streamModels(): HasMany
    {
        return $this->hasMany(StreamModel::class);
    }

    /**
     * @return HasMany<SyncCursor, $this>
     */
    public function cursors(): HasMany
    {
        return $this->hasMany(SyncCursor::class);
    }

    /**
     * @return HasMany<RawRecord, $this>
     */
    public function rawRecords(): HasMany
    {
        return $this->hasMany(RawRecord::class);
    }

    /**
     * @param  Builder<DataSource>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isDue(?Carbon $at = null): bool
    {
        return (new CronExpression($this->schedule))->isDue($at ?? now());
    }
}
