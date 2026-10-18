<?php

namespace App\Connectors;

use App\Connectors\Contracts\Connector;
use App\Models\DataSource;
use App\Models\IngestRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * For systems that can only push. They POST canonical records to
 * /api/v1/ingest/{data source}/{stream}, signed with the source's signing secret (see
 * IngestController); records are staged in ingest_records and every sync pulls what arrived
 * since the last one. Pushing `deleted` ids removes records.
 */
class PushIngestConnector implements Connector
{
    /** Cursor field added to each staged record when it is read. */
    public const RECEIVED_AT = '_cis_received_at';

    public static function definition(): ConnectorDefinition
    {
        return new ConnectorDefinition(
            label: 'Push ingest',
            description: 'For systems that can only push: they post signed records to a CIS ingest endpoint, and each one is synced straight away.',
            category: 'Third-party',
            icon: 'webhook',
            fields: [
                new ConfigField('signing_secret', 'Signing secret', 'secret', required: true, generated: true,
                    help: 'Shared with the sending system to sign each request (HMAC-SHA256). Leave empty to generate one.'),
                new ConfigField('sync_on_push', 'Sync on every push', 'boolean', default: true,
                    help: 'Queue a sync as soon as records arrive. Off: they wait for the schedule.'),
            ],
            features: ['ingest'],
        );
    }

    public function test(DataSource $source): HealthResult
    {
        $count = IngestRecord::where('data_source_id', $source->id)->count();
        $last = IngestRecord::where('data_source_id', $source->id)->max('received_at');

        return HealthResult::ok($count
            ? "Ready; {$count} records received, the latest on {$last}"
            : 'Ready to receive. Nothing has been pushed yet');
    }

    public function streams(DataSource $source): array
    {
        return DB::table('ingest_records')->where('data_source_id', $source->id)->distinct()->orderBy('stream')->pluck('stream')
            ->merge(DB::table('raw_records')->where('data_source_id', $source->id)->distinct()->pluck('stream'))
            ->unique()
            ->values()
            ->map(fn (string $stream) => new StreamDef($stream, self::RECEIVED_AT, complete: false))
            ->all();
    }

    public function read(DataSource $source, StreamDef $stream, ?string $cursor): LazyCollection
    {
        return IngestRecord::where('data_source_id', $source->id)
            ->where('stream', $stream->name)
            ->when($cursor, fn ($query) => $query->where('received_at', '>', $cursor))
            ->lazyById(1000)
            ->map(fn (IngestRecord $record) => [...$record->payload, self::RECEIVED_AT => $record->received_at->format('Y-m-d H:i:s.u')]);
    }
}
