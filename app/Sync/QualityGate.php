<?php

namespace App\Sync;

use App\Enums\SyncStatus;
use App\Models\DataSource;

/**
 * Stops a sync from replacing good marts with a suspiciously small extract, e.g. a source that
 * returned an empty or partial stream on a full run.
 */
class QualityGate
{
    /**
     * @param  array<string, array{active: int}>  $stats  this run's per-stream counts
     * @return list<string> one message per failed check; empty when the run may build marts
     */
    public function check(DataSource $source, array $stats): array
    {
        $previous = $source->runs()->where('status', SyncStatus::Succeeded)->latest('id')->first()?->stats ?? [];
        $maxDrop = config('connectors.max_drop_ratio');
        $failures = [];

        foreach ($stats as $stream => $counts) {
            $before = $previous[$stream]['active'] ?? 0;

            if ($before > 0 && $counts['active'] < $before * (1 - $maxDrop)) {
                $failures[] = "{$stream}: active records fell from {$before} to {$counts['active']}.";
            }
        }

        return $failures;
    }
}
