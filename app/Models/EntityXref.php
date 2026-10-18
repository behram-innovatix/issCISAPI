<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a source's own id for a person (student, employee) to the CIS canonical id, so the same
 * person can be joined across platforms and tenants.
 */
#[Table(name: 'entity_xref')]
#[Fillable(['kind', 'data_source_id', 'source_key', 'canonical_key'])]
class EntityXref extends Model
{
    /**
     * @return BelongsTo<DataSource, $this>
     */
    public function dataSource(): BelongsTo
    {
        return $this->belongsTo(DataSource::class);
    }
}
