<?php

namespace App\Connectors\Contracts;

use App\Connectors\ConnectorDefinition;
use App\Connectors\HealthResult;
use App\Connectors\StreamDef;
use App\Models\DataSource;
use Illuminate\Support\LazyCollection;

/**
 * A kind of source the CIS can pull from. Implementations return canonical records (the shapes in
 * config/cis_streams.php, each with a unique `id`); the pipeline does the loading, cursors and marts.
 */
interface Connector
{
    /**
     * Catalog entry and settings form for this connector type; its fields also validate `config`.
     */
    public static function definition(): ConnectorDefinition;

    /**
     * Check the source is reachable and the credentials work.
     */
    public function test(DataSource $source): HealthResult;

    /**
     * The canonical streams this source provides.
     *
     * @return list<StreamDef>
     */
    public function streams(DataSource $source): array;

    /**
     * Records of one stream, oldest first, changed after $cursor when the stream has a cursor field.
     *
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function read(DataSource $source, StreamDef $stream, ?string $cursor): LazyCollection;
}
