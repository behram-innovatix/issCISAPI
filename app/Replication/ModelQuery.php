<?php

namespace App\Replication;

use App\Models\DataSource;
use App\Models\SourceTable;
use App\Models\StreamModel;
use App\Sync\StreamDefinition;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use PDO;

/**
 * Turns a dashboard-stream model into SQL over the source's copied tables, and runs it.
 *
 * - "table": one copy, its columns mapped onto the stream's fields, with an optional condition.
 * - "query": a SELECT written against the copies, which it names as {{table}}.
 *
 * Every model can sync incrementally: a table mapping filters on the copy's `_cis_synced_at`; a
 * query does when it returns an `updated_at` column (e.g. GREATEST of its tables' `_cis_synced_at`).
 */
class ModelQuery
{
    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function compile(DataSource $source, StreamModel $model, ?string $cursor = null): array
    {
        $tables = $this->tables($source);
        $grammar = $this->connection()->getQueryGrammar();

        if ($model->kind === 'table') {
            $table = $tables->firstWhere('id', $model->source_table_id)
                ?? throw new InvalidArgumentException("The table for {$model->stream} isn't copied any more.");
            $map = array_filter($model->column_map ?? [], fn ($column) => filled($column));

            if (! isset($map['id'])) {
                throw new InvalidArgumentException('Map a column to id.');
            }
            foreach ($map as $field => $column) {
                if (! array_key_exists($column, $table->column_types)) {
                    throw new InvalidArgumentException("{$column} isn't a copied column of {$table->qualifiedName()}.");
                }
            }

            $select = collect($map)->map(fn ($column, $field) => $grammar->wrap($column).' AS '.$grammar->wrap($field))->values();
            if (! isset($map['updated_at'])) {
                $select->push($grammar->wrap(ReplicaSchema::SYNCED_AT).' AS '.$grammar->wrap('updated_at'));
            }

            $sql = 'SELECT '.$select->implode(', ').' FROM '.$grammar->wrapTable($table->replica_table)
                .(filled($model->filter) ? ' WHERE ('.SqlText::assertCondition($model->filter).')' : '');
        } else {
            $sql = $this->resolve(SqlText::assertSelect((string) $model->sql), $tables);
        }

        [$sql, $bindings] = SqlText::bind($sql, SqlText::parameters($source->config['parameters'] ?? ''));

        if ($cursor !== null) {
            $sql = "SELECT * FROM ({$sql}) cis_q WHERE cis_q.updated_at > ?";
            $bindings[] = $cursor;
        }

        return [$sql, $bindings];
    }

    /**
     * Run a model over the copies, row by row (unbuffered on MySQL).
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function rows(DataSource $source, StreamModel $model, ?string $cursor): LazyCollection
    {
        [$sql, $bindings] = $this->compile($source, $model, $cursor);
        $connection = $this->connection();

        return LazyCollection::make(function () use ($connection, $sql, $bindings) {
            $mysql = $connection->getDriverName() === 'mysql';
            $pdos = $mysql ? [$connection->getPdo(), $connection->getReadPdo()] : [];
            foreach ($pdos as $pdo) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            }

            try {
                foreach ($connection->cursor($sql, $bindings) as $row) {
                    yield (array) $row;
                }
            } finally {
                foreach ($pdos as $pdo) {
                    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
                }
            }
        });
    }

    /**
     * The first rows of a (possibly unsaved) model, and the columns it returns.
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>}
     */
    public function preview(DataSource $source, StreamModel $model, int $limit): array
    {
        [$sql, $bindings] = $this->compile($source, $model);
        $rows = collect($this->connection()->select("SELECT * FROM ({$sql}) cis_q LIMIT ".max(1, $limit), $bindings))
            ->map(fn ($row) => (array) $row);

        $columns = $rows->isNotEmpty()
            ? array_keys($rows->first())
            : $this->columnsOf($sql, $bindings);

        return ['columns' => $columns, 'rows' => $rows->all()];
    }

    /**
     * Check a model against its stream: it must run and return an `id`. Returns its columns.
     *
     * @return list<string>
     */
    public function validate(DataSource $source, StreamModel $model, StreamDefinition $stream): array
    {
        $columns = $this->preview($source, $model, 1)['columns'];

        if (! in_array('id', $columns, true)) {
            throw new InvalidArgumentException("{$stream->name} needs an id column.");
        }

        return $columns;
    }

    /**
     * The copied tables of a source.
     *
     * @return Collection<int, SourceTable>
     */
    public function tables(DataSource $source): Collection
    {
        return $source->sourceTables()->orderBy('name')->get();
    }

    private function connection(): Connection
    {
        return ReplicaSchema::connection();
    }

    /**
     * Replace {{table}} (or {{schema.table}}) with the copies' names, and refuse to read any other table.
     *
     * @param  Collection<int, SourceTable>  $tables
     */
    private function resolve(string $sql, Collection $tables): string
    {
        $grammar = $this->connection()->getQueryGrammar();
        $byName = [];
        foreach ($tables as $table) {
            $byName[strtolower($table->name)] = $table->replica_table;
            $byName[strtolower($table->qualifiedName())] = $table->replica_table;
        }

        $resolved = preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function (array $m) use ($byName, $grammar) {
            $replica = $byName[strtolower($m[1])] ?? throw new InvalidArgumentException("{{{$m[1]}}} isn't a copied table. Add it on the Tables tab first.");

            return $grammar->wrapTable($replica);
        }, $sql);

        // no reaching other databases by qualified name (the CIS's own tables, system schemas)
        $databases = array_filter([config('database.connections.'.config('database.default').'.database'), 'information_schema', 'mysql', 'performance_schema', 'sys']);
        if (preg_match('/[`"\[]?\b('.implode('|', array_map(fn ($d) => preg_quote((string) $d, '/'), $databases)).')\b[`"\]]?\s*\./i', $resolved, $m)) {
            throw new InvalidArgumentException("The query reads from {$m[1]}. Queries can only read copied tables, written as {{table}}.");
        }

        $allowed = array_map('strtolower', array_values($byName));
        foreach (SqlText::tablesRead($resolved) as $read) {
            if (! in_array(strtolower($read), $allowed, true)) {
                throw new InvalidArgumentException("The query reads {$read}. Queries can only read copied tables, written as {{table}}.");
            }
        }

        return $resolved;
    }

    /**
     * Column names of a query that returned no rows.
     *
     * @param  list<mixed>  $bindings
     * @return list<string>
     */
    private function columnsOf(string $sql, array $bindings): array
    {
        $statement = $this->connection()->getPdo()->prepare("SELECT * FROM ({$sql}) cis_q LIMIT 0");
        $statement->execute($bindings);

        return collect(range(0, $statement->columnCount() - 1))
            ->map(fn (int $i) => $statement->getColumnMeta($i)['name'] ?? null)
            ->filter()
            ->values()
            ->all();
    }
}
