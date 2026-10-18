<?php

namespace Database\Seeders;

use App\Models\DataSource;
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
        $this->seed('issEduApi · SQL Server', 'database', 'edu', filled($edu['host']), [
            'driver' => 'sqlsrv',
            'host' => $edu['host'],
            'port' => $edu['port'] ? (int) $edu['port'] : null,
            'database' => $edu['database'],
            'username' => $edu['username'],
            'password' => $edu['password'],
            'trust_server_certificate' => $edu['trust_server_certificate'],
            'incremental' => true,
            'parameters' => "academic_year = {$edu['academic_year']}",
            'queries' => collect(File::files(database_path('seeders/connections/issedu')))
                ->mapWithKeys(fn ($file) => [$file->getFilenameWithoutExtension() => trim($file->getContents())])
                ->all(),
        ], $edu['schedule']);

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
    private function seed(string $name, string $type, string $platform, bool $configured, array $config, ?string $schedule = null): void
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
    }
}
