<?php

namespace App\Replication;

use App\Models\DataSource;
use App\Models\SourceTable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The copies of selected source tables in the replica database: one table per selection, with the
 * selected columns in portable types, a unique index on the key, indexes on id-like columns (for
 * joins), and two bookkeeping columns: `_cis_synced_at` (when the row last changed in the copy,
 * which incremental dashboard streams read from) and `_cis_run` (the sync that last saw it).
 */
class ReplicaSchema
{
    public const SYNCED_AT = '_cis_synced_at';

    public const RUN = '_cis_run';

    public static function connection(): Connection
    {
        return DB::connection(config('replication.connection') ?: null);
    }

    /**
     * Name of the copy: "s{source}_{table}", kept within MySQL's 64 characters.
     */
    public static function replicaName(DataSource $source, ?string $schema, string $name): string
    {
        $base = Str::of(($schema && ! in_array(strtolower($schema), ['dbo', 'public', 'main'], true) ? "{$schema}_" : '').$name)
            ->lower()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_')->value();
        $name = "s{$source->id}_{$base}";

        return strlen($name) <= 64 ? $name : substr($name, 0, 55).'_'.substr(md5($name), 0, 8);
    }

    /**
     * Portable type for a source column, or null for columns that aren't copied (binary data,
     * spatial types, SQL Server rowversion).
     *
     * @param  array{type_name: string, type: string}  $column
     * @return array{kind: string, length?: int}|null
     */
    public static function typeFor(array $column, string $driver): ?array
    {
        $name = strtolower($column['type_name']);
        $full = strtolower($column['type']);

        if ($driver === 'sqlsrv' && in_array($name, ['timestamp', 'rowversion'], true)) {
            return null;
        }

        return match (true) {
            in_array($name, ['bit', 'bool', 'boolean'], true), $full === 'tinyint(1)' => ['kind' => 'boolean'],
            in_array($name, ['int', 'integer', 'smallint', 'tinyint', 'mediumint', 'bigint', 'serial', 'bigserial', 'int2', 'int4', 'int8'], true) => ['kind' => 'integer'],
            in_array($name, ['decimal', 'numeric', 'money', 'smallmoney'], true) => ['kind' => 'decimal'],
            in_array($name, ['float', 'real', 'double', 'double precision', 'float4', 'float8'], true) => ['kind' => 'float'],
            $name === 'date' => ['kind' => 'date'],
            in_array($name, ['datetime', 'datetime2', 'smalldatetime', 'timestamp', 'timestamptz', 'datetimeoffset', 'timestamp without time zone', 'timestamp with time zone'], true) => ['kind' => 'datetime'],
            in_array($name, ['time', 'timetz'], true) => ['kind' => 'time'],
            in_array($name, ['uniqueidentifier', 'uuid'], true) => ['kind' => 'string', 'length' => 36],
            in_array($name, ['binary', 'varbinary', 'image', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'bytea', 'geography', 'geometry', 'hierarchyid', 'sql_variant', 'point', 'polygon'], true) => null,
            in_array($name, ['char', 'varchar', 'nchar', 'nvarchar', 'character', 'character varying', 'bpchar'], true) => self::stringType($name, $full, $driver),
            default => ['kind' => 'text'],
        };
    }

    /**
     * Create the copy, or bring it in line with the selection: add newly selected columns and
     * drop deselected ones.
     *
     * @param  array<string, array{kind: string, length?: int}>  $types  column => portable type
     */
    public function apply(SourceTable $table, array $types): void
    {
        $schema = self::connection()->getSchemaBuilder();
        $replica = $table->replica_table;
        $indexed = $this->indexedColumns($table, array_keys($types));
        $types = $this->indexable($types, $indexed);

        if (! $schema->hasTable($replica)) {
            // index names: short (MySQL allows 64 characters) and unique per database (SQLite)
            $prefix = 'r'.substr(md5($replica), 0, 10);
            $schema->create($replica, function (Blueprint $t) use ($types, $table, $indexed, $prefix) {
                $t->id('_cis_id');
                foreach ($types as $column => $type) {
                    $this->column($t, $column, $type);
                }
                $t->dateTime(self::SYNCED_AT, 6)->index("{$prefix}_synced");
                $t->unsignedBigInteger(self::RUN)->default(0);
                $t->unique($table->primary_key, "{$prefix}_key");
                foreach ($indexed as $i => $column) {
                    if ($table->primary_key !== [$column]) {
                        $t->index([$column], "{$prefix}_ix{$i}");
                    }
                }
            });

            return;
        }

        $existing = collect($schema->getColumns($replica))->pluck('name')->all();
        $added = array_diff_key($types, array_flip($existing));
        $removed = array_values(array_diff($existing, ['_cis_id', self::SYNCED_AT, self::RUN], array_keys($types)));

        if ($added) {
            $schema->table($replica, function (Blueprint $t) use ($added) {
                foreach ($added as $column => $type) {
                    $this->column($t, $column, $type);
                }
            });
        }
        if ($removed) {
            $indexes = collect($schema->getIndexes($replica));
            $schema->table($replica, function (Blueprint $t) use ($removed, $indexes) {
                foreach ($indexes as $index) {
                    if (! $index['primary'] && array_intersect($index['columns'], $removed)) {
                        $t->dropIndex($index['name']);
                    }
                }
                $t->dropColumn($removed);
            });
        }
    }

    public function drop(SourceTable $table): void
    {
        self::connection()->getSchemaBuilder()->dropIfExists($table->replica_table);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string> key, incremental and id-like columns (joins)
     */
    private function indexedColumns(SourceTable $table, array $columns): array
    {
        $idLike = array_filter($columns, fn (string $c) => preg_match('/(^id$|_id$|_uid$|^uid$|_key$|_code$)/i', $c));

        return array_values(array_unique([...$table->primary_key, ...array_filter([$table->cursor_column]), ...$idLike]));
    }

    /**
     * Indexed text columns become strings (MySQL can't index TEXT without a prefix).
     *
     * @param  array<string, array{kind: string, length?: int}>  $types
     * @param  list<string>  $indexed
     * @return array<string, array{kind: string, length?: int}>
     */
    private function indexable(array $types, array $indexed): array
    {
        foreach ($indexed as $column) {
            if (($types[$column]['kind'] ?? null) === 'text') {
                $types[$column] = ['kind' => 'string', 'length' => 191];
            }
        }

        return $types;
    }

    /**
     * @param  array{kind: string, length?: int}  $type
     */
    private function column(Blueprint $t, string $name, array $type): void
    {
        match ($type['kind']) {
            'boolean' => $t->boolean($name)->nullable(),
            'integer' => $t->bigInteger($name)->nullable(),
            'decimal' => $t->decimal($name, 24, 6)->nullable(),
            'float' => $t->double($name)->nullable(),
            'date' => $t->date($name)->nullable(),
            'datetime' => $t->dateTime($name, 6)->nullable(),
            'time' => $t->time($name)->nullable(),
            'string' => $t->string($name, $type['length'] ?? 191)->nullable(),
            default => $t->longText($name)->nullable(),
        };
    }

    /**
     * @return array{kind: string, length?: int}
     */
    private static function stringType(string $name, string $full, string $driver): array
    {
        if (! preg_match('/\((\d+)\)/', $full, $m)) {
            return ['kind' => 'text'];
        }

        // SQL Server reports n(var)char lengths in bytes
        $length = (int) $m[1];
        if ($driver === 'sqlsrv' && str_starts_with($name, 'n')) {
            $length = intdiv($length, 2);
        }

        return $length > 0 && $length <= 191 ? ['kind' => 'string', 'length' => $length] : ['kind' => 'text'];
    }
}
