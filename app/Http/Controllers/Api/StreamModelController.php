<?php

namespace App\Http\Controllers\Api;

use App\Connectors\ConnectorRegistry;
use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\SourceTable;
use App\Models\StreamModel;
use App\Models\SyncCursor;
use App\Replication\ModelQuery;
use App\Sync\StreamCatalog;
use App\Sync\StreamDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Dashboard streams tab of a database source: how each of the platform's streams is built
 * from the copied tables (a column mapping or a query), with previews.
 */
class StreamModelController extends Controller
{
    public function __construct(
        private StreamCatalog $catalog,
        private ModelQuery $models,
    ) {}

    /**
     * The platform's streams (with the fields each needs), their models, and the copied tables to build them from.
     */
    public function index(DataSource $dataSource, ConnectorRegistry $registry): JsonResponse
    {
        $this->ensureTables($dataSource, $registry);
        $models = $dataSource->streamModels()->get()->keyBy('stream');

        return response()->json(['data' => [
            'streams' => collect($this->catalog->streams($dataSource->platform))->map(fn (StreamDefinition $s) => [
                'stream' => $s->name,
                'fields' => [
                    ['name' => 'id', 'cast' => $s->keyCast, 'required' => true],
                    ...collect($s->columns)->map(fn (array $c, string $column) => ['name' => $c['field'], 'cast' => $c['cast'], 'required' => false])->values(),
                ],
                'model' => $this->model($models->get($s->name)),
            ])->values(),
            'tables' => $this->models->tables($dataSource)->map(fn (SourceTable $t) => [
                'id' => $t->id,
                'qualified' => $t->qualifiedName(),
                'placeholder' => "{{{$t->name}}}",
                'columns' => array_keys($t->column_types ?? []),
                'rows' => $t->rows,
            ])->values(),
        ]]);
    }

    /**
     * Save how a stream is built. The model is run once first; it must work and return an id.
     */
    public function update(Request $request, DataSource $dataSource, string $stream, ConnectorRegistry $registry): JsonResponse
    {
        $this->ensureTables($dataSource, $registry);
        $definition = $this->stream($dataSource, $stream);
        $model = $this->fill($request, $dataSource, $stream);

        try {
            $model->output_columns = $this->models->validate($dataSource, $model, $definition);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([$model->kind === 'query' ? 'sql' : 'column_map' => $e->getMessage()]);
        }

        $existing = $dataSource->streamModels()->where('stream', $stream)->first();
        $changed = ! $existing || $existing->only(['kind', 'source_table_id', 'column_map', 'filter', 'sql']) !== $model->only(['kind', 'source_table_id', 'column_map', 'filter', 'sql']);

        $saved = $dataSource->streamModels()->updateOrCreate(['stream' => $stream], $model->only(['kind', 'source_table_id', 'column_map', 'filter', 'sql', 'output_columns']));

        // a new definition is read from scratch on the next sync (which also drops what it no longer returns)
        if ($changed) {
            SyncCursor::where('data_source_id', $dataSource->id)->where('stream', $stream)->delete();
        }

        return response()->json(['data' => $this->model($saved)], $existing ? 200 : 201);
    }

    /**
     * Preview a (possibly unsaved) model.
     */
    public function preview(Request $request, DataSource $dataSource, string $stream, ConnectorRegistry $registry): JsonResponse
    {
        $this->ensureTables($dataSource, $registry);
        $this->stream($dataSource, $stream);
        $model = $this->fill($request, $dataSource, $stream);

        try {
            return response()->json(['data' => $this->models->preview($dataSource, $model, config('replication.preview_rows'))]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([$model->kind === 'query' ? 'sql' : 'column_map' => $e->getMessage()]);
        }
    }

    /**
     * Stop building a stream from this source. Its records leave the dashboards on the next full sync.
     */
    public function destroy(DataSource $dataSource, string $stream, ConnectorRegistry $registry): Response
    {
        $this->ensureTables($dataSource, $registry);
        $dataSource->streamModels()->where('stream', $stream)->delete();
        SyncCursor::where('data_source_id', $dataSource->id)->where('stream', $stream)->delete();

        return response()->noContent();
    }

    private function fill(Request $request, DataSource $source, string $stream): StreamModel
    {
        $validated = $request->validate([
            'kind' => ['required', Rule::in(StreamModel::KINDS)],
            'source_table_id' => ['exclude_unless:kind,table', 'required', Rule::exists('source_tables', 'id')->where('data_source_id', $source->id)],
            'column_map' => ['exclude_unless:kind,table', 'required', 'array'],
            'column_map.*' => ['nullable', 'string'],
            'filter' => ['exclude_unless:kind,table', 'nullable', 'string', 'max:4000'],
            'sql' => ['exclude_unless:kind,query', 'required', 'string', 'max:20000'],
        ], ['source_table_id.required' => 'Choose a copied table.', 'sql.required' => 'Write the query.']);

        return new StreamModel([
            'data_source_id' => $source->id,
            'stream' => $stream,
            'kind' => $validated['kind'],
            'source_table_id' => $validated['source_table_id'] ?? null,
            'column_map' => isset($validated['column_map']) ? array_filter($validated['column_map'], fn ($c) => filled($c)) : null,
            'filter' => filled($validated['filter'] ?? null) ? trim($validated['filter']) : null,
            'sql' => $validated['sql'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function model(?StreamModel $model): ?array
    {
        return $model ? $model->only(['kind', 'source_table_id', 'column_map', 'filter', 'sql', 'output_columns']) + ['updated_at' => $model->updated_at?->toIso8601String()] : null;
    }

    private function stream(DataSource $source, string $stream): StreamDefinition
    {
        return $this->catalog->streams($source->platform)[$stream] ?? abort(404, "{$source->platform} has no stream called {$stream}.");
    }

    private function ensureTables(DataSource $source, ConnectorRegistry $registry): void
    {
        abort_unless($registry->has($source->type) && in_array('tables', $registry->definition($source->type)->features, true), 404);
    }
}
