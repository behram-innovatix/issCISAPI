<?php

namespace Tests\Feature;

use App\Connectors\SqlDatabaseConnector;
use App\Enums\SyncStatus;
use App\Models\DataSource;
use App\Models\SyncCursor;
use App\Sync\SyncPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PDO;
use Tests\TestCase;

class SqlDatabaseConnectorTest extends TestCase
{
    use RefreshDatabase;

    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        // a stand-in for the source system: its own SQLite database with its own table layout
        File::ensureDirectoryExists(storage_path('framework/testing'));
        $this->database = storage_path('framework/testing/source-'.uniqid().'.sqlite');
        $pdo = new PDO("sqlite:{$this->database}");
        $pdo->exec('CREATE TABLE tbl_students (uid TEXT, full_name TEXT, sex INTEGER, school TEXT, year TEXT, academic_year TEXT, updated_at TEXT)');
        $pdo->exec("INSERT INTO tbl_students VALUES
            ('a1', 'Mariam Ali', 1, 'AMAG', '07', '2025/2026', '2026-01-10 08:00:00'),
            ('b2', 'Omar Said', 0, 'AMAB', '08', '2025/2026', '2026-01-12 09:30:00'),
            ('c3', 'Old Pupil', 0, 'AMAB', '13', '2024/2025', '2025-06-01 10:00:00')");
    }

    protected function tearDown(): void
    {
        DB::purge('cis_source_'.(DataSource::max('id') ?? 0));
        File::delete($this->database);

        parent::tearDown();
    }

    public function test_it_maps_query_columns_onto_the_stream_and_binds_parameters(): void
    {
        $source = $this->source();

        $run = app(SyncPipeline::class)->run($source);

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(2, DB::table('mart_edu_students')->count());
        $this->assertDatabaseHas('mart_edu_students', ['student_id' => 'a1', 'name' => 'Mariam Ali', 'gender' => 'F', 'school' => 'AMAG']);
        $this->assertSame('2026-01-12 09:30:00', SyncCursor::where('stream', 'students')->value('cursor'));
    }

    public function test_incremental_syncs_only_read_rows_changed_since_the_cursor(): void
    {
        $source = $this->source();
        app(SyncPipeline::class)->run($source);
        (new PDO("sqlite:{$this->database}"))->exec("UPDATE tbl_students SET full_name = 'Mariam A. Ali', updated_at = '2026-02-01 07:00:00' WHERE uid = 'a1'");

        $run = app(SyncPipeline::class)->run($source);

        $this->assertSame(1, $run->stats['students']['read']);
        $this->assertSame(2, $run->stats['students']['mart']);
        $this->assertDatabaseHas('mart_edu_students', ['student_id' => 'a1', 'name' => 'Mariam A. Ali']);
    }

    public function test_compile_wraps_incremental_reads_and_turns_named_parameters_into_bindings(): void
    {
        $source = $this->source();

        [$sql, $bindings] = app(SqlDatabaseConnector::class)->compile(
            $source,
            "SELECT uid AS id, ':not_a_param' AS note FROM t WHERE year = :academic_year OR prev = :academic_year",
            '2026-01-01',
        );

        $this->assertSame("SELECT * FROM (SELECT uid AS id, ':not_a_param' AS note FROM t WHERE year = ? OR prev = ?) cis_q WHERE cis_q.updated_at > ?", $sql);
        $this->assertSame(['2025/2026', '2025/2026', '2026-01-01'], $bindings);
    }

    public function test_only_single_read_only_selects_are_accepted(): void
    {
        foreach (['DELETE FROM t', 'SELECT 1; DROP TABLE t', 'SELECT * INTO copy FROM t', 'UPDATE t SET a = 1'] as $query) {
            try {
                SqlDatabaseConnector::assertSelect($query);
                $this->fail("Accepted: {$query}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame("WITH x AS (SELECT 'delete me' AS a) SELECT * FROM x", SqlDatabaseConnector::assertSelect("WITH x AS (SELECT 'delete me' AS a) SELECT * FROM x;"));
    }

    public function test_an_undefined_parameter_fails_the_run(): void
    {
        $source = $this->source(['parameters' => '']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(':academic_year');

        app(SyncPipeline::class)->run($source);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function source(array $config = []): DataSource
    {
        return DataSource::factory()->create([
            'type' => 'database',
            'config' => $config + [
                'driver' => 'sqlite',
                'host' => 'localhost',
                'database' => $this->database,
                'username' => 'reader',
                'incremental' => true,
                'parameters' => "academic_year = 2025/2026\n# a comment",
                'queries' => [
                    'students' => "SELECT uid AS id, full_name AS name, CASE sex WHEN 1 THEN 'F' ELSE 'M' END AS gender, school, year AS year_group, updated_at
                        FROM tbl_students WHERE academic_year = :academic_year",
                ],
            ],
        ]);
    }
}
