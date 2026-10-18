<?php

namespace App\Replication;

use App\Models\DataSource;
use App\Models\SourceTable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use PDO;
use Throwable;

/**
 * Read access to a database source: its connection, its table catalog (with row estimates), the
 * columns of a table, previews, and streaming the rows of a selected table.
 */
class SourceDatabase
{
    public function connection(DataSource $source): Connection
    {
        $name = "cis_source_{$source->id}";
        $config = $source->config;
        $driver = $config['driver'] ?? 'mysql';

        config(["database.connections.{$name}" => array_filter([
            'driver' => $driver,
            'host' => $config['host'] ?? null,
            'port' => $config['port'] ?? null,
            'database' => $config['database'] ?? null,
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? '',
            'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
            'prefix' => '',
            'trust_server_certificate' => $driver === 'sqlsrv' ? (bool) ($config['trust_server_certificate'] ?? false) : null,
            'options' => $driver === 'mysql' ? [PDO::ATTR_TIMEOUT => 15] : [],
        ], fn ($value) => $value !== null)]);

        DB::purge($name);

        return DB::connection($name);
    }

    /**
     * Every table the source's login can see, largest first.
     *
     * @return list<array{schema: string|null, name: string, qualified: string, rows: int|null, size: int|null}>
     */
    public function tables(DataSource $source, bool $refresh = false): array
    {
        $key = "source-tables:{$source->id}:".md5(json_encode(array_intersect_key($source->config, array_flip(['driver', 'host', 'port', 'database', 'username']))));

        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, config('replication.catalog_ttl'), function () use ($source) {
            $connection = $this->connection($source);
            $estimates = $this->rowEstimates($connection);
            $defaultSchema = $this->defaultSchema($connection);

            return collect($connection->getSchemaBuilder()->getTables())
                ->map(function (array $t) use ($estimates, $defaultSchema) {
                    $schema = $t['schema'] ?? null;
                    $schema = $schema === $defaultSchema ? null : $schema;
                    $qualified = $schema ? "{$schema}.{$t['name']}" : $t['name'];

                    return [
                        'schema' => $schema,
                        'name' => $t['name'],
                        'qualified' => $qualified,
                        'rows' => $estimates[strtolower($t['name'])] ?? null,
                        'size' => $t['size'] ?? null,
                    ];
                })
                ->sortBy([['rows', 'desc'], ['name', 'asc']])
                ->values()
                ->all();
        });
    }

    /**
     * A table's columns and primary key, with suggestions for the copy's key and incremental column.
     *
     * @return array{columns: list<array{name: string, type: string, type_name: string, nullable: bool, replicable: bool}>, primary_key: list<string>, suggested_cursor: string|null}
     */
    public function describe(DataSource $source, ?string $schema, string $name): array
    {
        $connection = $this->connection($source);
        $builder = $connection->getSchemaBuilder();
        $table = $schema ? "{$schema}.{$name}" : $name;
        $driver = $connection->getDriverName();

        $columns = collect($builder->getColumns($table))
            ->map(fn (array $c) => [
                'name' => $c['name'],
                'type' => $c['type'],
                'type_name' => $c['type_name'],
                'nullable' => (bool) $c['nullable'],
                'replicable' => ReplicaSchema::typeFor($c, $driver) !== null,
            ])
            ->values()
            ->all();

        $primary = collect($builder->getIndexes($table))->firstWhere('primary', true)['columns'] ?? [];
        $names = array_column($columns, 'name');
        $cursor = collect(['updated_at', 'modified_at', 'last_modified', 'updatedat', 'modified', 'date_modified'])
            ->first(fn (string $c) => in_array($c, array_map('strtolower', $names), true));
        $cursor = $cursor ? $names[array_search($cursor, array_map('strtolower', $names), true)] : null;

        return ['columns' => $columns, 'primary_key' => array_values($primary), 'suggested_cursor' => $cursor];
    }

    /**
     * The first rows of a table as the selection would copy them.
     *
     * @param  list<string>|null  $columns
     * @return list<array<string, mixed>>
     */
    public function preview(DataSource $source, ?string $schema, string $name, ?array $columns, ?string $filter, int $limit): array
    {
        return $this->query($source, $schema, $name, $columns, $filter)
            ->limit($limit)
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    /**
     * Rows of a selected table, changed after $cursor when it has an incremental column.
     *
     * @param  list<string>  $columns
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function rows(SourceTable $table, array $columns, ?string $cursor): LazyCollection
    {
        $query = $this->query($table->dataSource, $table->schema, $table->name, $columns, $table->filter)
            ->when($cursor !== null && $table->cursor_column, fn (Builder $q) => $q->where($table->cursor_column, '>', $cursor));

        return LazyCollection::make(function () use ($query) {
            foreach ($query->cursor() as $row) {
                yield (array) $row;
            }
        });
    }

    /**
     * @param  list<string>|null  $columns
     */
    private function query(DataSource $source, ?string $schema, string $name, ?array $columns, ?string $filter): Builder
    {
        $query = $this->connection($source)->table($schema ? "{$schema}.{$name}" : $name)->select($columns ?: ['*']);

        if (filled($filter)) {
            [$sql, $bindings] = SqlText::bind(SqlText::assertCondition($filter), SqlText::parameters($source->config['parameters'] ?? ''));
            $query->whereRaw("({$sql})", $bindings);
        }

        return $query;
    }

    /**
     * Approximate row counts by table name, from the database's statistics (cheap on large tables).
     *
     * @return array<string, int>
     */
    private function rowEstimates(Connection $connection): array
    {
        $sql = match ($connection->getDriverName()) {
            'sqlsrv' => 'SELECT t.name AS name, SUM(p.rows) AS row_count FROM sys.tables t JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1) GROUP BY t.name',
            'mysql', 'mariadb' => 'SELECT TABLE_NAME AS name, TABLE_ROWS AS row_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
            'pgsql' => 'SELECT relname AS name, GREATEST(reltuples, 0)::bigint AS row_count FROM pg_class WHERE relkind = \'r\'',
            default => null,
        };

        if (! $sql) {
            return [];
        }

        try {
            return collect($connection->select($sql))->mapWithKeys(fn ($r) => [strtolower($r->name) => (int) $r->row_count])->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function defaultSchema(Connection $connection): ?string
    {
        return match ($connection->getDriverName()) {
            'sqlsrv' => 'dbo',
            'pgsql' => 'public',
            'mysql', 'mariadb' => $connection->getDatabaseName(),
            default => 'main',
        };
    }
}
