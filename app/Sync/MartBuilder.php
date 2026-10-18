<?php

namespace App\Sync;

use App\Models\DataSource;
use App\Models\EntityXref;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Brings a source's rows in the mart tables up to date with its current raw records, in one
 * transaction, so dashboards only ever see a complete sync. Full runs (and a stream's first
 * build) rebuild the source's rows; incremental runs only upsert the records the run touched.
 */
class MartBuilder
{
    /**
     * @param  array<string, StreamDefinition>  $streams
     * @param  int|null  $incrementalRunId  the run whose touched records to apply; null rebuilds
     * @return array<string, int> stream => the source's rows in the mart afterwards
     */
    public function build(DataSource $source, array $streams, ?int $incrementalRunId = null): array
    {
        $chunk = config('connectors.chunk_size');
        $syncedAt = now();

        return DB::transaction(function () use ($source, $streams, $chunk, $syncedAt, $incrementalRunId) {
            $rowsInMart = [];

            foreach ($streams as $name => $stream) {
                $mart = DB::table($stream->table())->where('data_source_id', $source->id);
                $incremental = $incrementalRunId !== null && $mart->clone()->exists();

                if ($incremental) {
                    // records a blocked full run marked deleted, and that a later run now applies
                    $mart->clone()->whereIn($stream->keyColumn, DB::table('raw_records')
                        ->where('data_source_id', $source->id)
                        ->where('stream', $name)
                        ->whereNotNull('deleted_at')
                        ->select('record_key'))->delete();
                } else {
                    $mart->clone()->delete();
                }

                $raw = DB::table('raw_records')
                    ->where('data_source_id', $source->id)
                    ->where('stream', $name)
                    ->whereNull('deleted_at')
                    ->when($incremental, fn ($query) => $query->where('seen_run_id', $incrementalRunId))
                    ->select(['id', 'record_key', 'payload']);

                $rows = [];
                foreach ($raw->lazyById($chunk) as $record) {
                    $rows[] = $this->row($source, $stream, $record->record_key, json_decode($record->payload, true), $syncedAt);

                    if (count($rows) === $chunk) {
                        $this->write($source, $stream, $rows, $incremental);
                        $rows = [];
                    }
                }
                $this->write($source, $stream, $rows, $incremental);

                $rowsInMart[$name] = $mart->clone()->count();
            }

            return $rowsInMart;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function row(DataSource $source, StreamDefinition $stream, string $key, array $payload, Carbon $syncedAt): array
    {
        $row = [
            'data_source_id' => $source->id,
            $stream->keyColumn => $this->cast($key, $stream->keyCast),
        ];

        foreach ($stream->columns as $column => $spec) {
            $row[$column] = $this->cast(data_get($payload, $spec['field']), $spec['cast']);
        }

        $row['synced_at'] = $syncedAt;

        return $row;
    }

    /**
     * Insert (or, incrementally, upsert) mart rows and, for identity streams, register their keys
     * in entity_xref.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function write(DataSource $source, StreamDefinition $stream, array $rows, bool $upsert): void
    {
        if ($rows === []) {
            return;
        }

        $upsert
            ? DB::table($stream->table())->upsert($rows, ['data_source_id', $stream->keyColumn], [...array_keys($stream->columns), 'synced_at'])
            : DB::table($stream->table())->insert($rows);

        if ($stream->identity) {
            $now = now();
            EntityXref::upsert(
                array_map(fn (array $row) => [
                    'kind' => $stream->identity,
                    'data_source_id' => $source->id,
                    'source_key' => (string) $row[$stream->keyColumn],
                    'canonical_key' => "{$source->tenant}:{$row[$stream->keyColumn]}",
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $rows),
                ['kind', 'data_source_id', 'source_key'],
                ['updated_at'],
            );
        }
    }

    private function cast(mixed $value, string $cast): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($cast) {
            'string' => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE),
            'int' => (int) $value,
            'bool' => (bool) $value,
            'money' => round((float) $value, 2),
            'decimal' => round((float) $value, 3),
            'date' => substr((string) $value, 0, 10),
            'month' => substr((string) $value, 0, 7),
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
        };
    }
}
