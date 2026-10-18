<?php

namespace Database\Seeders;

use App\Models\DataSource;
use App\Replication\ReplicaSchema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * The connections the CIS starts with: issEduApi's database for EDU, and the HRM and Finance
 * export APIs for ERP. Settings come from config/cis_sources.php (the environment); a connection
 * without its settings is created paused and shows as "needs setup" in the admin screen.
 * Existing connections are left as they are.
 */
class DataSourceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $edu = config('cis_sources.issedu');
        $issEdu = $this->seed('issEduApi · SQL Server', 'database', 'edu', filled($edu['host']), [
            'driver' => 'sqlsrv',
            'host' => $edu['host'],
            'port' => $edu['port'] ? (int) $edu['port'] : null,
            'database' => $edu['database'],
            'username' => $edu['username'],
            'password' => $edu['password'],
            'trust_server_certificate' => $edu['trust_server_certificate'],
            'parameters' => "academic_year = {$edu['academic_year']}\nfrom_date = {$edu['from_date']}",
        ], $edu['schedule']);

        if ($issEdu->wasRecentlyCreated) {
            $this->tablesAndStreams($issEdu, database_path('seeders/connections/issedu'));
        }

        foreach (['hrm' => ['HRM API', 'hr'], 'finance' => ['Finance API', 'fin']] as $key => [$name, $platform]) {
            $api = config("cis_sources.{$key}");
            $this->seed($name, 'iss-export', $platform, filled($api['base_url']) && filled($api['token']), [
                'base_url' => $api['base_url'],
                'auth' => 'token',
                'token' => $api['token'],
                'per_page' => 1000,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function seed(string $name, string $type, string $platform, bool $configured, array $config, ?string $schedule = null): DataSource
    {
        $source = DataSource::firstOrCreate(['name' => $name], [
            'type' => $type,
            'platform' => $platform,
            'tenant' => 'iss',
            'config' => array_filter($config, fn ($value) => $value !== null && $value !== ''),
            'schedule' => $schedule ?? config('connectors.default_schedule'),
            'is_active' => $configured,
        ]);

        $this->command?->info($source->wasRecentlyCreated
            ? "{$name}: created".($configured ? '' : ' (paused until its settings are filled in)')
            : "{$name}: already exists, left unchanged");

        return $source;
    }

    /**
     * The tables a database connection copies (tables.php: name => columns, filter) and the queries
     * that build its dashboard streams from the copies (streams/<stream>.sql). The copies are
     * created and filled on the first sync.
     */
    private function tablesAndStreams(DataSource $source, string $directory): void
    {
        foreach (require "{$directory}/tables.php" as $name => $table) {
            $source->sourceTables()->create([
                'schema' => null,
                'name' => $name,
                'replica_table' => ReplicaSchema::replicaName($source, null, $name),
                'columns' => $table['columns'],
                'primary_key' => ['uid'],
                'cursor_column' => 'updated_at',
                'filter' => $table['filter'] ?? null,
                'column_types' => [],
            ]);
        }

        foreach (File::files("{$directory}/streams") as $file) {
            $source->streamModels()->create([
                'stream' => $file->getFilenameWithoutExtension(),
                'kind' => 'query',
                'sql' => trim($file->getContents()),
                // each query returns updated_at, so the stream syncs incrementally
                'output_columns' => ['id', 'updated_at'],
            ]);
        }

        $this->command?->info("{$source->name}: ".count(require "{$directory}/tables.php").' tables to copy, '.count(File::files("{$directory}/streams")).' dashboard streams');
    }
}
