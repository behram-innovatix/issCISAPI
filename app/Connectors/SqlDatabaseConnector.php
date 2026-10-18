<?php

namespace App\Connectors;

use App\Connectors\Concerns\NormalizesRecords;
use App\Connectors\Contracts\Connector;
use App\Models\DataSource;
use App\Sync\StreamCatalog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use Throwable;

/**
 * Reads canonical records with one SELECT per stream against a source's database (MySQL, SQL Server
 * or PostgreSQL). Each query returns the stream's fields as columns, including `id`; with
 * incremental syncs on, it also returns `updated_at` and the CIS only asks for newer rows.
 *
 * Queries may use :named parameters from the "Query parameters" setting. Only single SELECT (or
 * WITH … SELECT) statements are accepted; connect with a read-only database login as well.
 */
class SqlDatabaseConnector implements Connector
{
    use NormalizesRecords;

    public function __construct(private StreamCatalog $catalog) {}

    public static function definition(): ConnectorDefinition
    {
        return new ConnectorDefinition(
            label: 'SQL database',
            description: 'Runs read-only SELECT queries against a source database (MySQL, SQL Server, PostgreSQL), one per stream. For systems without an export API.',
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
                new ConfigField('incremental', 'Incremental syncs', 'boolean', default: true,
                    help: 'Queries return updated_at and each sync only reads rows changed since the last one. The nightly full sync still catches deletions.'),
                new ConfigField('parameters', 'Query parameters', 'textarea', language: 'text', placeholder: 'academic_year = 2025/2026',
                    help: 'One name = value per line, used as :name in the queries.'),
                new ConfigField('queries', 'Queries', 'map', required: true, language: 'sql',
                    help: 'One SELECT per stream; leave a stream empty to skip it. Columns must be named like the stream fields, with id first. No ORDER BY or trailing semicolon.'),
            ],
        );
    }

    public function test(DataSource $source): HealthResult
    {
        try {
            $this->connection($source)->select('SELECT 1 AS ok');
        } catch (Throwable $e) {
            return HealthResult::failed($e->getMessage());
        }

        $streams = count($this->streams($source));

        return $streams > 0
            ? HealthResult::ok("Connected; {$streams} ".str('query')->plural($streams).' configured')
            : HealthResult::failed('Connected, but no queries are configured.');
    }

    public function streams(DataSource $source): array
    {
        $known = $this->catalog->streams($source->platform);
        $incremental = (bool) ($source->config['incremental'] ?? true);

        return collect($source->config['queries'] ?? [])
            ->filter(fn ($sql, $stream) => isset($known[$stream]) && is_string($sql) && trim($sql) !== '')
            ->map(fn ($sql, string $stream) => new StreamDef($stream, $incremental ? 'updated_at' : null))
            ->values()
            ->all();
    }

    public function read(DataSource $source, StreamDef $stream, ?string $cursor): LazyCollection
    {
        $definition = $this->catalog->streams($source->platform)[$stream->name];
        [$sql, $bindings] = $this->compile($source, $source->config['queries'][$stream->name], $stream->cursorField ? $cursor : null);

        return LazyCollection::make(function () use ($source, $sql, $bindings, $definition) {
            foreach ($this->connection($source)->cursor($sql, $bindings) as $row) {
                yield $this->normalize($definition, (array) $row);
            }
        });
    }

    /**
     * Turn a stream query into SQL with positional bindings: named parameters become `?` (drivers
     * differ in reusing a name), and incremental reads wrap the query to filter on updated_at.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public function compile(DataSource $source, string $query, ?string $cursor): array
    {
        $query = self::assertSelect($query);
        $parameters = self::parameters($source->config['parameters'] ?? '');
        $bindings = [];

        $sql = preg_replace_callback('/(?<![:\w]):([a-zA-Z_]\w*)/', function (array $m) use ($parameters, &$bindings) {
            if (! array_key_exists($m[1], $parameters)) {
                throw new InvalidArgumentException("The query uses :{$m[1]}, which isn't defined in the query parameters.");
            }
            $bindings[] = $parameters[$m[1]];

            return '?';
        }, self::maskLiterals($query, $literals));

        $sql = self::unmaskLiterals($sql, $literals);

        if ($cursor !== null) {
            $sql = "SELECT * FROM ({$sql}) cis_q WHERE cis_q.updated_at > ?";
            $bindings[] = $cursor;
        }

        return [$sql, $bindings];
    }

    /**
     * @return array<string, string>
     */
    public static function parameters(string $text): array
    {
        return collect(preg_split('/\R/', $text))
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => $line !== '' && ! str_starts_with($line, '#') && str_contains($line, '='))
            ->mapWithKeys(function (string $line) {
                [$name, $value] = array_map('trim', explode('=', $line, 2));

                return [$name => trim($value, "\"'")];
            })
            ->all();
    }

    /**
     * Accept exactly one SELECT (or WITH … SELECT) statement.
     */
    public static function assertSelect(string $query): string
    {
        $query = rtrim(trim($query), "; \t\n\r");
        $bare = self::maskLiterals(preg_replace(['~--[^\n]*~', '~/\*.*?\*/~s'], '', $query), $ignored);

        if (! preg_match('/^\s*(select|with)\b/i', $bare)) {
            throw new InvalidArgumentException('Only SELECT queries are allowed.');
        }
        if (str_contains($bare, ';')) {
            throw new InvalidArgumentException('A query must be a single statement.');
        }
        if (preg_match('/\b(insert|update|delete|merge|drop|alter|create|truncate|exec|execute|grant|revoke|into)\b/i', $bare)) {
            throw new InvalidArgumentException('Queries may only read data.');
        }

        return $query;
    }

    /**
     * Replace quoted string literals with placeholders, so parameter and keyword checks ignore them.
     *
     * @param-out list<string> $literals
     */
    private static function maskLiterals(string $sql, ?array &$literals): string
    {
        $literals = [];

        return preg_replace_callback("/'(?:[^']|'')*'/", function (array $m) use (&$literals) {
            $literals[] = $m[0];

            return '__cis_lit_'.(count($literals) - 1).'__';
        }, $sql);
    }

    /**
     * @param  list<string>  $literals
     */
    private static function unmaskLiterals(string $sql, array $literals): string
    {
        return preg_replace_callback('/__cis_lit_(\d+)__/', fn (array $m) => $literals[(int) $m[1]], $sql);
    }

    private function connection(DataSource $source): ConnectionInterface
    {
        $name = "cis_source_{$source->id}";
        $config = $source->config;

        config(["database.connections.{$name}" => array_filter([
            'driver' => $config['driver'],
            'host' => $config['host'],
            'port' => $config['port'] ?? null,
            'database' => $config['database'],
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? '',
            'charset' => $config['driver'] === 'mysql' ? 'utf8mb4' : 'utf8',
            'prefix' => '',
            'trust_server_certificate' => $config['driver'] === 'sqlsrv' ? (bool) ($config['trust_server_certificate'] ?? false) : null,
            'options' => $config['driver'] === 'mysql' ? [\PDO::ATTR_TIMEOUT => 15] : [],
        ], fn ($value) => $value !== null)]);

        DB::purge($name);

        return DB::connection($name);
    }
}
