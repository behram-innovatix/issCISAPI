<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A table of a database source that is copied into the replica database on every sync.
 */
#[Fillable(['data_source_id', 'schema', 'name', 'replica_table', 'columns', 'primary_key', 'cursor_column', 'filter', 'column_types', 'cursor', 'rows', 'last_synced_at', 'last_error'])]
class SourceTable extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'primary_key' => 'array',
            'column_types' => 'array',
            'rows' => 'integer',
            'last_synced_at' => 'datetime',
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
     * @return HasMany<StreamModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(StreamModel::class);
    }

    /**
     * Name as the source knows it: "schema.table", or the bare name when there is no schema.
     */
    public function qualifiedName(): string
    {
        return $this->schema ? "{$this->schema}.{$this->name}" : $this->name;
    }
}
