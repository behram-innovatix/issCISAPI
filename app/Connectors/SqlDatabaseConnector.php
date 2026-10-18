<?php

namespace App\Connectors;

use App\Connectors\Concerns\NormalizesRecords;
use App\Connectors\Contracts\Connector;
use App\Connectors\Contracts\ReplicatesTables;
use App\Enums\SyncMode;
use App\Models\DataSource;
use App\Models\StreamModel;
use App\Models\SyncRun;
use App\Replication\ModelQuery;
use App\Replication\Replicator;
use App\Replication\SourceDatabase;
use App\Sync\StreamCatalog;
use Illuminate\Support\LazyCollection;
use RuntimeException;
use Throwable;

/**
 * A source database (MySQL, SQL Server or PostgreSQL). Administrators pick its tables on the
 * source's Tables tab; every sync first copies those tables into the replica database
 * (incrementally on each table's change column), then builds each dashboard stream from the
 * copies, by a column mapping or a query (Dashboard streams tab).
 *
 * Connect with a read-only database login: the CIS only ever reads the source.
 */
class SqlDatabaseConnector implements Connector, ReplicatesTables
{
    use NormalizesRecords;

    public function __construct(
        private StreamCatalog $catalog,
        private SourceDatabase $database,
        private Replicator $replicator,
        private ModelQuery $models,
    ) {}

    public static function definition(): ConnectorDefinition
    {
        return new ConnectorDefinition(
            label: 'SQL database',
            description: 'Connects to a source database (MySQL, SQL Server, PostgreSQL): pick its tables to copy, then map them onto the dashboard streams.',
            category: 'Databases',
            icon: 'database',
            fields: [
                new ConfigField('driver', 'Database', 'select', required: true, default: 'mysql', options: [
                    'mysql' => 'MySQL / MariaDB',
                    'sqlsrv' => 'SQL Server',
                    'pgsql' => 'PostgreSQL',
                ]),
                new ConfigField('host', 'Host', required: true, placeholder: 'db.internal or SERVER\INSTANCE',
                    help: 'For a named SQL Server instance use SERVER\INSTANCE and leave the port empty.'),
                new ConfigField('port', 'Port', 'number', placeholder: '3306 / 1433 / 5432'),
                new ConfigField('database', 'Database name', required: true),
                new ConfigField('username', 'Username', required: true, help: 'Use a login with read-only (SELECT) permission.'),
                new ConfigField('password', 'Password', 'secret'),
                new ConfigField('trust_server_certificate', 'Trust the server certificate', 'boolean', default: false, when: ['driver' => 'sqlsrv'],
                    help: 'Needed for SQL Server instances with a self-signed certificate.'),
                new ConfigField('parameters', 'Parameters', 'textarea', language: 'text', placeholder: "academic_year = 2025/2026\nfrom_date = 2025-08-01",
                    help: 'One name = value per line, usable as :name in table filters and dashboard-stream queries.'),
            ],
            features: ['tables'],
        );
    }

    public function test(DataSource $source): HealthResult
    {
        try {
            $this->database->connection($source)->select('SELECT 1 AS ok');
        } catch (Throwable $e) {
            return HealthResult::failed($e->getMessage());
        }

        $tables = $source->sourceTables()->count();
        $streams = $source->streamModels()->count();

        return HealthResult::ok("Connected; {$tables} ".str('table')->plural($tables).' copied, '.$streams.' dashboard '.str('stream')->plural($streams).' mapped');
    }

    /**
     * Copy every selected table into the replica database.
     *
     * @return array<string, array<string, mixed>> table => stats
     */
    public function replicate(DataSource $source, SyncRun $run, SyncMode $mode): array
    {
        $stats = [];

        foreach ($source->sourceTables()->orderBy('name')->get() as $table) {
            try {
                $stats[$table->qualifiedName()] = $this->replicator->replicate($table, $run, $mode);
            } catch (Throwable $e) {
                $table->update(['last_error' => $e->getMessage()]);

                throw new RuntimeException("Copying {$table->qualifiedName()} failed: {$e->getMessage()}", previous: $e);
            }
        }

        return $stats;
    }

    public function streams(DataSource $source): array
    {
        $known = $this->catalog->streams($source->platform);

        return $source->streamModels()->orderBy('stream')->get()
            ->filter(fn (StreamModel $m) => isset($known[$m->stream]))
            ->map(fn (StreamModel $m) => new StreamDef(
                $m->stream,
                $m->kind === 'table' || in_array('updated_at', $m->output_columns ?? [], true) ? 'updated_at' : null,
            ))
            ->values()
            ->all();
    }

    public function read(DataSource $source, StreamDef $stream, ?string $cursor): LazyCollection
    {
        $definition = $this->catalog->streams($source->platform)[$stream->name];
        $model = $source->streamModels()->where('stream', $stream->name)->firstOrFail();

        return $this->models->rows($source, $model, $stream->cursorField ? $cursor : null)
            ->map(fn (array $row) => $this->normalize($definition, $row));
    }
}
