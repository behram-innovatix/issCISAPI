<?php

namespace App\Sync;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Creates mart tables from their stream definitions, so a stream's columns are declared once
 * (config/cis_streams.php) and migrations only name the streams they add.
 */
class MartSchema
{
    public static function create(string $qualifiedName): void
    {
        $stream = app(StreamCatalog::class)->find($qualifiedName);

        Schema::create($stream->table(), function (Blueprint $table) use ($stream) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            self::column($table, $stream->keyColumn, $stream->keyCast)->nullable(false);

            foreach ($stream->columns as $column => $spec) {
                self::column($table, $column, $spec['cast']);
            }

            $table->timestamp('synced_at');

            $table->unique(['data_source_id', $stream->keyColumn]);
            // every query filters by source first, so each index leads with it
            // (named by position: generated names of wide indexes pass MySQL's 64-character limit)
            foreach ($stream->indexes as $i => $columns) {
                $table->index(['data_source_id', ...$columns], "{$stream->table()}_idx_{$i}");
            }
        });
    }

    public static function drop(string $qualifiedName): void
    {
        Schema::dropIfExists(app(StreamCatalog::class)->find($qualifiedName)->table());
    }

    private static function column(Blueprint $table, string $name, string $cast): ColumnDefinition
    {
        $column = match ($cast) {
            'string' => $table->string($name, 191),
            'int' => $table->bigInteger($name),
            'bool' => $table->boolean($name),
            'money' => $table->decimal($name, 14, 2),
            'decimal' => $table->decimal($name, 12, 3),
            'date' => $table->date($name),
            'month' => $table->char($name, 7),
            'json' => $table->json($name),
            default => throw new InvalidArgumentException("Unknown mart column cast [{$cast}] for [{$name}]."),
        };

        return $column->nullable();
    }
}
