<?php

namespace App\Connectors\Concerns;

use App\Sync\StreamDefinition;

/**
 * For connectors whose sources only return flat strings (SQL rows, spreadsheet cells): JSON-cast
 * columns arrive as JSON text and empty cells as "", so turn them into the values the marts expect.
 */
trait NormalizesRecords
{
    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    protected function normalize(StreamDefinition $stream, array $record): array
    {
        foreach ($record as $field => $value) {
            if ($value === '') {
                $record[$field] = null;
            }
        }

        foreach ($stream->columns as $spec) {
            $value = $record[$spec['field']] ?? null;

            if ($spec['cast'] === 'json' && is_string($value)) {
                $decoded = json_decode($value, true);
                $record[$spec['field']] = json_last_error() === JSON_ERROR_NONE ? $decoded : array_values(array_filter(array_map('trim', explode(',', $value))));
            }
        }

        return $record;
    }
}
