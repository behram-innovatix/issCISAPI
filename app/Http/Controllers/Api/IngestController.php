<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncDataSource;
use App\Models\DataSource;
use App\Models\IngestRecord;
use App\Sync\StreamCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IngestController extends Controller
{
    /** How far a request's timestamp may be from now, against replays. */
    private const MAX_SKEW_SECONDS = 300;

    /**
     * Receive records pushed by a push-ingest source.
     *
     * Headers: X-CIS-Timestamp (unix seconds) and X-CIS-Signature, the hex HMAC-SHA256 of
     * "{timestamp}.{raw body}" with the source's signing secret.
     * Body: {"records": [{"id": "…", …canonical fields}], "deleted": ["id", …]}
     */
    public function store(Request $request, DataSource $dataSource, string $stream, StreamCatalog $catalog): JsonResponse
    {
        abort_unless($dataSource->type === 'push-ingest' && $dataSource->is_active, 404);
        abort_unless(isset($catalog->streams($dataSource->platform)[$stream]), 404, "Unknown {$dataSource->platform} stream [{$stream}].");

        $timestamp = $request->header('X-CIS-Timestamp');
        $signature = (string) $request->header('X-CIS-Signature');
        $secret = (string) ($dataSource->config['signing_secret'] ?? '');
        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);

        if ($secret === '' || ! ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > self::MAX_SKEW_SECONDS || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid or expired signature.'], 401);
        }

        $request->validate([
            'records' => ['present', 'array', 'max:5000'],
            'records.*' => ['array'],
            'records.*.id' => ['required'],
            'deleted' => ['sometimes', 'array', 'max:5000'],
            'deleted.*' => ['required'],
        ]);
        // whole records: validated() would keep only the validated `id` of each
        $records = $request->input('records');

        $receivedAt = now()->format('Y-m-d H:i:s.u');
        foreach (array_chunk($records, 1000) as $chunk) {
            IngestRecord::upsert(array_map(fn (array $record) => [
                'data_source_id' => $dataSource->id,
                'stream' => $stream,
                'record_key' => (string) $record['id'],
                'payload' => json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                'received_at' => $receivedAt,
            ], $chunk), ['data_source_id', 'stream', 'record_key'], ['payload', 'received_at']);
        }

        $deleted = IngestRecord::where('data_source_id', $dataSource->id)
            ->where('stream', $stream)
            ->whereIn('record_key', array_map('strval', (array) $request->input('deleted', [])))
            ->delete();

        if ($dataSource->config['sync_on_push'] ?? true) {
            SyncDataSource::dispatch($dataSource);
        }

        return response()->json(['data' => ['accepted' => count($records), 'deleted' => $deleted]], 202);
    }
}
