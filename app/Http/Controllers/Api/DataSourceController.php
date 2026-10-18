<?php

namespace App\Http\Controllers\Api;

use App\Connectors\ConnectorRegistry;
use App\Connectors\StreamDef;
use App\Enums\SourceStatus;
use App\Enums\SyncMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DataSourceRequest;
use App\Http\Resources\DataSourceResource;
use App\Http\Resources\SyncRunResource;
use App\Jobs\SyncDataSource;
use App\Models\DataSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Throwable;

class DataSourceController extends Controller
{
    /**
     * Every configured data source with its latest run.
     */
    public function index(): AnonymousResourceCollection
    {
        return DataSourceResource::collection(DataSource::with('latestRun')->orderBy('platform')->orderBy('name')->get());
    }

    /**
     * Add a data source.
     */
    public function store(DataSourceRequest $request): JsonResponse
    {
        $source = DataSource::create([
            ...$request->safe()->only(['name', 'type', 'platform', 'tenant', 'schedule']),
            'is_active' => $request->boolean('is_active', true),
            'config' => $request->connectorConfig(),
            'status' => SourceStatus::New,
        ]);

        return DataSourceResource::make($source)->response()->setStatusCode(201);
    }

    /**
     * One data source with its latest run.
     */
    public function show(DataSource $dataSource): DataSourceResource
    {
        return DataSourceResource::make($dataSource->load('latestRun'));
    }

    /**
     * Change a data source's settings (its connector type is fixed).
     */
    public function update(DataSourceRequest $request, DataSource $dataSource): DataSourceResource
    {
        $dataSource->update([
            ...$request->safe()->only(['name', 'platform', 'tenant', 'schedule']),
            'is_active' => $request->boolean('is_active', $dataSource->is_active),
            'config' => $request->connectorConfig(),
        ]);

        return DataSourceResource::make($dataSource->load('latestRun'));
    }

    /**
     * Remove a data source together with its raw records, mart rows and run history.
     */
    public function destroy(DataSource $dataSource): Response
    {
        $dataSource->delete();

        return response()->noContent();
    }

    /**
     * Check that the source is reachable with its saved settings, and list the streams it offers.
     */
    public function test(DataSource $dataSource, ConnectorRegistry $registry): JsonResponse
    {
        try {
            $connector = $registry->for($dataSource);
            $health = $connector->test($dataSource);
            $streams = $health->ok ? array_map(fn (StreamDef $stream) => $stream->name, $connector->streams($dataSource)) : [];
        } catch (Throwable $e) {
            return response()->json(['data' => ['ok' => false, 'message' => $e->getMessage(), 'streams' => []]]);
        }

        return response()->json(['data' => ['ok' => $health->ok, 'message' => $health->message, 'streams' => $streams]]);
    }

    /**
     * Queue a sync now, incremental by default.
     */
    public function sync(Request $request, DataSource $dataSource): JsonResponse
    {
        $validated = $request->validate(['mode' => ['sometimes', Rule::enum(SyncMode::class)]]);
        $mode = SyncMode::from($validated['mode'] ?? SyncMode::Incremental->value);

        SyncDataSource::dispatch($dataSource, $mode);

        return response()->json(['data' => ['queued' => true, 'mode' => $mode->value]], 202);
    }

    /**
     * Recent sync runs, newest first.
     */
    public function runs(Request $request, DataSource $dataSource): AnonymousResourceCollection
    {
        $limit = min(max((int) $request->query('limit', 20), 1), 100);

        return SyncRunResource::collection($dataSource->runs()->latest('id')->limit($limit)->get());
    }
}
