<?php

namespace App\Connectors\Contracts;

use App\Enums\SyncMode;
use App\Models\DataSource;
use App\Models\SyncRun;

/**
 * A connector that copies source tables into the replica database before its streams are read
 * (the streams are then built from those copies).
 */
interface ReplicatesTables
{
    /**
     * Copy the source's selected tables.
     *
     * @return array<string, array<string, mixed>> table => stats (read, deleted, rows, warning?)
     */
    public function replicate(DataSource $source, SyncRun $run, SyncMode $mode): array;
}
