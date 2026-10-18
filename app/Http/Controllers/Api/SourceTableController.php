<?php

namespace App\Http\Controllers\Api;

use App\Connectors\ConnectorRegistry;
use App\Http\Controllers\Controller;
use App\Models\DataSource;
use App\Models\SourceTable;
use App\Replication\ReplicaSchema;
use App\Replication\Replicator;
use App\Replication\SourceDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The Tables tab of a database source: browse the source's tables, and choose which to copy (with
 * which columns, key, incremental column and row filter).
 */
class SourceTableController extends Controller
{
    public function __construct(
        private SourceDatabase $database,
        private ReplicaSchema $schema,
        private Replicator $replicator,
    ) {}

    /**
     * Every table of the source, with the copy settings of the selected ones.
     */
    public function index(Request $request, DataSource $dataSource, ConnectorRegistry $registry): JsonResponse
    {
        $this->ensureTables($dataSource, $registry);

        try {
            $catalog = $this->database->tables($dataSource, $request->boolean('refresh'));
        } catch (Throwable $e) {
            return response()->json(['message' => "Couldn’t read the source’s tables: {$e->getMessage()}"], 422);
        }

        $selected = $dataSource->sourceTables()->with('models:id,source_table_id,stream')->get()->keyBy(fn (SourceTable $t) => strtolower($t->qualifiedName()));
        $tables = collect($catalog)->map(fn (array $t) => [...$t, 'selection' => $this->selection($selected->pull(strtolower($t['qualified'])))]);

        // selected tables the source no longer has
        foreach ($selected as $missing) {
            $tables->prepend(['schema' => $missing->schema, 'name' => $missing->name, 'qualified' => $missing->qualifiedName(), 'rows' => null, 'size' => null, 'missing' => true, 'selection' => $this->selection($missing)]);
        }

        return response()->json(['data' => ['tables' => $tables->values()]]);
    }

    /**
     * One table's columns, key and copy settings.
     */
    public function show(DataSource $dataSource, string $table, ConnectorRegistry $registry): JsonResponse
    {
        $this->ensureTables($dataSource, $registry);
        [$schema, $name] = $this->locate($dataSource, $table);
        $selection = $this->selected($dataSource, $schema, $name);

        return response()->json(['data' => [
            'schema' => $schema,
            'name' => $name,
            'qualified' => $schema ? "{$schema}.{$name}" : $name,
            ...$this->database->describe($dataSource, $schema, $name),
            'selection' => $this->selection($selection),
        ]]);
    }

    /**
     * The first rows of a table as the given settings would copy them.
     */
    public function preview(Request $request, DataSource $dataSource, string $table, ConnectorRegistry $registry): JsonResponse
    {
        $this->ensureTables($dataSource, $registry);
        [$schema, $name] = $this->locate($dataSource, $table);
        $validated = $request->validate(['columns' => ['sometimes', 'array'], 'columns.*' => ['string'], 'filter' => ['nullable', 'string', 'max:4000']]);

        try {
            $rows = $this->database->preview($dataSource, $schema, $name, $validated['columns'] ?? null, $validated['filter'] ?? null, config('replication.preview_rows'));
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['filter' => $e->getMessage()]);
        }

        return response()->json(['data' => ['rows' => $rows]]);
    }

    /**
     * Copy a table (or change how it is copied). The copy is created straight away and filled on the next sync.
     */
    public function update(Request $request, DataSource $dataSource, string $table, ConnectorRegistry $registry): JsonResponse
    {
        $this->ensureTables($dataSource, $registry);
        [$schema, $name] = $this->locate($dataSource, $table);
        $described = $this->database->describe($dataSource, $schema, $name);
        $names = collect($described['columns'])->where('replicable', true)->pluck('name')->all();

        $validated = $request->validate([
            'columns' => ['nullable', 'array'],
            'columns.*' => ['string', Rule::in($names)],
            'primary_key' => ['required', 'array', 'min:1', 'max:4'],
            'primary_key.*' => ['string', Rule::in($names)],
            'cursor_column' => ['nullable', 'string', Rule::in($names)],
            'filter' => ['nullable', 'string', 'max:4000'],
        ], ['primary_key.required' => 'Choose the column(s) that identify a row.']);

        $columns = $validated['columns'] ?? null;
        if ($columns !== null) {
            $columns = array_values(array_unique([...$validated['primary_key'], ...array_filter([$validated['cursor_column'] ?? null]), ...$columns]));
            $columns = count($columns) >= count($names) ? null : $columns;
        }

        try {
            $this->database->preview($dataSource, $schema, $name, $columns, $validated['filter'] ?? null, 1);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['filter' => "The filter doesn’t run on the source: {$e->getMessage()}"]);
        }

        $existing = $this->selected($dataSource, $schema, $name);
        $settings = [
            'columns' => $columns,
            'primary_key' => array_values($validated['primary_key']),
            'cursor_column' => $validated['cursor_column'] ?? null,
            'filter' => filled($validated['filter'] ?? null) ? trim($validated['filter']) : null,
        ];

        if ($existing) {
            $rowsChange = $existing->primary_key !== $settings['primary_key'] || $existing->filter !== $settings['filter'] || $existing->cursor_column !== $settings['cursor_column'];
            if ($existing->primary_key !== $settings['primary_key']) {
                // a new key needs a new unique index: start the copy afresh
                $this->schema->drop($existing);
            }
            $existing->update([...$settings, ...($rowsChange ? ['cursor' => null] : [])]);
            $sourceTable = $existing;
        } else {
            $sourceTable = $dataSource->sourceTables()->create([
                ...$settings,
                'schema' => $schema,
                'name' => $name,
                'replica_table' => ReplicaSchema::replicaName($dataSource, $schema, $name),
                'column_types' => [],
            ]);
        }

        $types = $this->replicator->types($sourceTable);
        $this->schema->apply($sourceTable, $types);
        $sourceTable->update(['column_types' => $types]);

        return response()->json(['data' => $this->selection($sourceTable->fresh('models'))], $existing ? 200 : 201);
    }

    /**
     * Stop copying a table and drop its copy. Not allowed while a dashboard stream maps it.
     */
    public function destroy(DataSource $dataSource, string $table, ConnectorRegistry $registry): Response|JsonResponse
    {
        $this->ensureTables($dataSource, $registry);
        $selection = $dataSource->sourceTables()->get()->first(fn (SourceTable $t) => strcasecmp($t->qualifiedName(), $table) === 0);
        abort_unless($selection, 404, 'This table isn’t copied.');

        $usedBy = $selection->models()->pluck('stream');
        if ($usedBy->isNotEmpty()) {
            return response()->json(['message' => "Remove it from the dashboard streams that use it first: {$usedBy->implode(', ')}."], 409);
        }

        $this->schema->drop($selection);
        $selection->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function selection(?SourceTable $table): ?array
    {
        if (! $table) {
            return null;
        }

        return [
            'id' => $table->id,
            'qualified' => $table->qualifiedName(),
            'columns' => $table->columns,
            'copied_columns' => array_keys($table->column_types ?? []),
            'primary_key' => $table->primary_key,
            'cursor_column' => $table->cursor_column,
            'filter' => $table->filter,
            'replica_table' => $table->replica_table,
            'rows' => $table->rows,
            'last_synced_at' => $table->last_synced_at?->toIso8601String(),
            'last_error' => $table->last_error,
            'used_by' => $table->relationLoaded('models') ? $table->models->pluck('stream')->values() : $table->models()->pluck('stream'),
        ];
    }

    /**
     * @return array{0: string|null, 1: string} schema and name of a table the source has
     */
    private function locate(DataSource $source, string $table): array
    {
        try {
            $match = collect($this->database->tables($source))->first(fn (array $t) => strcasecmp($t['qualified'], $table) === 0);
        } catch (Throwable $e) {
            abort(422, "Couldn’t read the source’s tables: {$e->getMessage()}");
        }
        abort_unless($match, 404, "The source has no table called {$table}.");

        return [$match['schema'], $match['name']];
    }

    private function selected(DataSource $source, ?string $schema, string $name): ?SourceTable
    {
        return $source->sourceTables()->where('name', $name)->where('schema', $schema)->first();
    }

    private function ensureTables(DataSource $source, ConnectorRegistry $registry): void
    {
        abort_unless($registry->has($source->type) && in_array('tables', $registry->definition($source->type)->features, true), 404);
    }
}
