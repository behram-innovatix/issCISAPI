<?php

namespace App\Sync;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Contracts\Connector;
use App\Connectors\Contracts\ReplicatesTables;
use App\Connectors\StreamDef;
use App\Enums\SourceStatus;
use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Jobs\BuildInsights;
use App\Models\DataSource;
use App\Models\RawRecord;
use App\Models\SyncCursor;
use App\Models\SyncRun;
use RuntimeException;
use Throwable;

/**
 * Syncs one data source end to end: pull each stream into raw_records (from its cursor, or
 * everything on a full run), check the result against the last good run, then rebuild the
 * source's mart rows. Marts only change when the whole run passes.
 */
class SyncPipeline
{
    public function __construct(
        private ConnectorRegistry $connectors,
        private StreamCatalog $catalog,
        private QualityGate $gate,
        private MartBuilder $marts,
    ) {}

    public function run(DataSource $source, SyncMode $mode = SyncMode::Incremental): SyncRun
    {
        $run = $source->runs()->create([
            'mode' => $mode,
            'status' => SyncStatus::Running,
            'started_at' => now(),
        ]);

        /** @var array<string, array{read: int, deleted: int, active: int, mart?: int}> $stats */
        $stats = [];

        try {
            $connector = $this->connectors->for($source);
            $canonical = $this->catalog->streams($source->platform);

            // database sources first copy their selected tables; their streams are built from the copies
            if ($connector instanceof ReplicatesTables) {
                $run->update(['replication' => $connector->replicate($source, $run, $mode)]);
            }

            foreach ($connector->streams($source) as $stream) {
                if (! isset($canonical[$stream->name])) {
                    throw new RuntimeException("Stream [{$stream->name}] is not a canonical [{$source->platform}] stream.");
                }

                $stats[$stream->name] = $this->extract($source, $connector, $stream, $run, $mode);
            }

            $failures = $this->gate->check($source, $stats);

            if ($failures !== []) {
                $this->finish($run, SyncStatus::Degraded, $stats, implode("\n", $failures));
                $source->update(['status' => SourceStatus::Degraded, 'last_error' => implode("\n", $failures)]);

                return $run;
            }

            $built = $this->marts->build($source, array_intersect_key($canonical, $stats), $mode === SyncMode::Incremental ? $run->id : null);
            foreach ($built as $stream => $rows) {
                $stats[$stream]['mart'] = $rows;
            }

            $this->finish($run, SyncStatus::Succeeded, $stats);
            $dataChanged = collect($stats)->contains(fn (array $s) => $s['changed'] > 0 || $s['deleted'] > 0);
            $source->update([
                'status' => SourceStatus::Healthy,
                'last_synced_at' => $run->finished_at,
                'last_error' => null,
                ...($dataChanged ? ['data_changed_at' => $run->finished_at] : []),
            ]);

            // rebuild the platform's dashboard dataset in the background, so dashboards stay instant
            if ($dataChanged) {
                BuildInsights::dispatch($source->platform);
            }

            return $run;
        } catch (Throwable $e) {
            $this->finish($run, SyncStatus::Failed, $stats, $e->getMessage());
            $source->update(['status' => SourceStatus::Failing, 'last_error' => $e->getMessage()]);

            throw $e;
        }
    }

    /**
     * Upsert one stream into raw_records and advance its cursor.
     *
     * @return array{read: int, changed: int, deleted: int, active: int}
     */
    private function extract(DataSource $source, Connector $connector, StreamDef $stream, SyncRun $run, SyncMode $mode): array
    {
        $cursor = SyncCursor::firstOrNew(['data_source_id' => $source->id, 'stream' => $stream->name]);
        $latest = $cursor->cursor;
        // a read from scratch (full run, no cursor yet, or a stream read whole) is the source's complete state
        $whole = $mode === SyncMode::Full || $stream->complete || $cursor->cursor === null;
        $read = 0;
        $changed = 0;

        $connector
            ->read($source, $stream, $mode === SyncMode::Full ? null : $cursor->cursor)
            ->chunk(config('connectors.chunk_size'))
            ->each(function ($records) use ($source, $stream, $run, &$latest, &$read, &$changed) {
                $rows = [];

                foreach ($records as $record) {
                    $key = $record['id'] ?? throw new RuntimeException("A [{$stream->name}] record has no id.");
                    $payload = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

                    $rows[(string) $key] = [
                        'data_source_id' => $source->id,
                        'stream' => $stream->name,
                        'record_key' => (string) $key,
                        'payload' => $payload,
                        'payload_hash' => sha1($payload),
                        'seen_run_id' => $run->id,
                        'deleted_at' => null,
                    ];

                    $value = $stream->cursorField ? ($record[$stream->cursorField] ?? null) : null;
                    if ($value !== null && ($latest === null || strcmp((string) $value, $latest) > 0)) {
                        $latest = (string) $value;
                    }
                }

                // new or different payloads (and records the source had dropped) count as changes
                $known = RawRecord::where('data_source_id', $source->id)
                    ->where('stream', $stream->name)
                    ->whereIn('record_key', array_keys($rows))
                    ->current()
                    ->pluck('payload_hash', 'record_key');
                foreach ($rows as $key => $row) {
                    if (($known[(string) $key] ?? null) !== $row['payload_hash']) {
                        $changed++;
                    }
                }

                RawRecord::upsert(
                    array_values($rows),
                    ['data_source_id', 'stream', 'record_key'],
                    ['payload', 'payload_hash', 'seen_run_id', 'deleted_at', 'updated_at'],
                );

                $read += count($records);
            });

        $deleted = $whole
            ? RawRecord::where('data_source_id', $source->id)
                ->where('stream', $stream->name)
                ->current()
                ->where('seen_run_id', '!=', $run->id)
                ->update(['deleted_at' => now()])
            : 0;

        $cursor->cursor = $latest;
        $cursor->save();

        $active = RawRecord::where('data_source_id', $source->id)->where('stream', $stream->name)->current()->count();

        return ['read' => $read, 'changed' => $changed, 'deleted' => $deleted, 'active' => $active];
    }

    /**
     * @param  array<string, array<string, int>>  $stats
     */
    private function finish(SyncRun $run, SyncStatus $status, array $stats, ?string $error = null): void
    {
        $run->update([
            'status' => $status,
            'stats' => $stats,
            'error' => $error,
            'finished_at' => now(),
        ]);
    }
}
