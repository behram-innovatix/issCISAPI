<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one dashboard stream of a database source is built from its copied tables: a column mapping
 * from one table ("table"), or a SELECT over the copies ("query").
 */
#[Fillable(['data_source_id', 'stream', 'kind', 'source_table_id', 'column_map', 'filter', 'sql', 'output_columns'])]
class StreamModel extends Model
{
    public const KINDS = ['table', 'query'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'column_map' => 'array',
            'output_columns' => 'array',
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
     * @return BelongsTo<SourceTable, $this>
     */
    public function sourceTable(): BelongsTo
    {
        return $this->belongsTo(SourceTable::class);
    }
}
