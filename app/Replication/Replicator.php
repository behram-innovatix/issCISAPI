<?php

namespace App\Replication;

use App\Enums\SyncMode;
use App\Models\SourceTable;
use App\Models\SyncRun;
use RuntimeException;

/**
 * Copies a selected source table into its replica: the rows changed since the table's cursor
 * (everything on a full run, or when the table has no incremental column), upserted on its key.
 * A full run also removes rows the source no longer returns, unless that would remove more than
 * the quality gate allows, in which case the copy is kept and the run is warned.
 */
class Replicator
{
    public function __construct(
        private SourceDatabase $sources,
        private ReplicaSchema $schema,
    ) {}

    /**
     * Columns to copy and their portable types, from the source's current columns.
     *
     * @return array<string, array{kind: string, length?: int}>
     */
    public function types(SourceTable $table): array
    {
        $source = $table->dataSource;
        $described = $this->sources->describe($source, $table->schema, $table->name);
        $driver = $source->config['driver'] ?? 'mysql';
        $wanted = $table->columns ? array_flip($table->columns) : null;
        $types = [];

        foreach ($described['columns'] as $column) {
            if ($wanted !== null && ! isset($wanted[$column['name']]) && ! in_array($column['name'], $table->primary_key, true)) {
                continue;
            }
            $type = ReplicaSchema::typeFor($column, $driver);
            if ($type !== null) {
                $types[$column['name']] = $type;
            }
        }

        foreach ($table->primary_key as $key) {
            if (! isset($types[$key])) {
                throw new RuntimeException("{$table->qualifiedName()}: key column {$key} can't be copied.");
            }
        }

        return $types;
    }

    /**
     * @return array{read: int, deleted: int, rows: int, warning?: string}
     */
    public function replicate(SourceTable $table, SyncRun $run, SyncMode $mode): array
    {
        $types = $this->types($table);
        $this->schema->apply($table, $types);
        $table->update(['column_types' => $types]);

        $replica = ReplicaSchema::connection()->table($table->replica_table);
        $incremental = $mode === SyncMode::Incremental && $table->cursor_column && $table->cursor !== null;
        $cursor = $incremental ? $table->cursor : null;
        $latest = $table->cursor;
        $read = 0;
        $now = now()->format('Y-m-d H:i:s.u');
        $columns = array_keys($types);

        $this->sources->rows($table, $columns, $cursor)
            ->chunk(config('connectors.chunk_size'))
            ->each(function ($rows) use ($table, $types, $replica, $run, $now, $columns, &$latest, &$read) {
                $batch = [];
                foreach ($rows as $row) {
                    $record = [];
                    foreach ($types as $column => $type) {
                        $record[$column] = $this->value($row[$column] ?? null, $type['kind']);
                    }
                    $record[ReplicaSchema::SYNCED_AT] = $now;
                    $record[ReplicaSchema::RUN] = $run->id;
                    $batch[implode("\x1f", array_map(fn ($k) => (string) $record[$k], $table->primary_key))] = $record;

                    $value = $table->cursor_column ? ($row[$table->cursor_column] ?? null) : null;
                    if ($value !== null && ($latest === null || strcmp((string) $value, $latest) > 0)) {
                        $latest = (string) $value;
                    }
                }

                $replica->clone()->upsert(array_values($batch), $table->primary_key, [...array_diff($columns, $table->primary_key), ReplicaSchema::SYNCED_AT, ReplicaSchema::RUN]);
                $read += count($rows);
            });

        $stats = ['read' => $read, 'deleted' => 0];

        // A whole read (full run, or no incremental column) is the source's current state: drop the rest.
        if (! $incremental) {
            $before = $replica->clone()->count();
            $stale = $replica->clone()->where(ReplicaSchema::RUN, '!=', $run->id)->count();
            $kept = $before - $stale;

            if ($before > 0 && $kept < $before * (1 - config('connectors.max_drop_ratio'))) {
                $stats['warning'] = "Kept {$stale} rows the source didn't return: removing them would drop more than the quality gate allows.";
            } else {
                $stats['deleted'] = $replica->clone()->where(ReplicaSchema::RUN, '!=', $run->id)->delete();
            }
        }

        $stats['rows'] = $replica->clone()->count();
        $table->update(['cursor' => $latest, 'rows' => $stats['rows'], 'last_synced_at' => now(), 'last_error' => null]);

        return $stats;
    }

    private function value(mixed $value, string $kind): mixed
    {
        if ($value === null || ($value === '' && $kind !== 'string' && $kind !== 'text')) {
            return null;
        }

        return match ($kind) {
            'boolean' => in_array(strtolower((string) $value), ['1', 'true', 't', 'y', 'yes'], true) ? 1 : 0,
            'integer' => (int) $value,
            'decimal', 'float' => is_numeric($value) ? $value : null,
            // drop time-zone offsets (SQL Server datetimeoffset) that MySQL DATETIME won't take
            'datetime' => preg_replace('/(\.\d{1,6})\d*|\s*[+-]\d{2}:\d{2}$|Z$/', '$1', (string) $value),
            default => is_scalar($value) ? (string) $value : json_encode($value),
        };
    }
}
