<?php

namespace Tests\Feature;

use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Models\DataSource;
use App\Models\User;
use App\Replication\ReplicaSchema;
use App\Sync\SyncPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PDO;
use Tests\TestCase;

class DatabaseTablesTest extends TestCase
{
    use RefreshDatabase;

    private string $database;

    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->create(), ['connections:manage']);

        // the source system: its own SQLite database with its own table layout
        File::ensureDirectoryExists(storage_path('framework/testing'));
        $this->database = storage_path('framework/testing/source-'.uniqid().'.sqlite');
        $this->pdo = new PDO("sqlite:{$this->database}");
        $this->pdo->exec('CREATE TABLE tbl_students (uid VARCHAR(36) PRIMARY KEY, profile_uid VARCHAR(36), school VARCHAR(10), qid VARCHAR(20), updated_at DATETIME)');
        $this->pdo->exec('CREATE TABLE tbl_profiles (uid VARCHAR(36) PRIMARY KEY, full_name TEXT, sex INTEGER, updated_at DATETIME)');
        $this->pdo->exec("INSERT INTO tbl_students VALUES ('s1', 'p1', 'AJA', '28400000001', '2026-01-10 08:00:00'), ('s2', 'p2', 'AMAG', '28400000002', '2026-01-11 08:00:00')");
        $this->pdo->exec("INSERT INTO tbl_profiles VALUES ('p1', 'Omar Said', 0, '2026-01-10 08:00:00'), ('p2', 'Mariam Ali', 1, '2026-01-11 08:00:00')");
    }

    protected function tearDown(): void
    {
        unset($this->pdo);
        DB::purge('cis_source_'.(DataSource::max('id') ?? 0));
        File::delete($this->database);

        parent::tearDown();
    }

    public function test_the_tables_tab_lists_the_source_tables_and_describes_one(): void
    {
        $source = $this->source();

        $this->getJson("/api/v1/admin/data-sources/{$source->id}/tables")->assertOk()
            ->assertJsonFragment(['qualified' => 'tbl_students', 'selection' => null])
            ->assertJsonFragment(['qualified' => 'tbl_profiles']);

        $this->getJson("/api/v1/admin/data-sources/{$source->id}/tables/tbl_students")->assertOk()
            ->assertJsonPath('data.primary_key', ['uid'])
            ->assertJsonPath('data.suggested_cursor', 'updated_at')
            ->assertJsonPath('data.columns.3.name', 'qid');

        $this->getJson("/api/v1/admin/data-sources/{$source->id}/tables/tbl_nope")->assertNotFound();
        $this->assertTrue($this->getJson("/api/v1/admin/data-sources/{$source->id}")->json('data.needs_setup'));
    }

    public function test_an_added_table_is_copied_with_only_its_selected_columns_and_kept_in_sync(): void
    {
        $source = $this->source();

        $this->putJson("/api/v1/admin/data-sources/{$source->id}/tables/tbl_students", [
            'columns' => ['uid', 'profile_uid', 'school'],
            'primary_key' => ['uid'],
            'cursor_column' => 'updated_at',
        ])->assertCreated()->assertJsonPath('data.copied_columns', ['uid', 'profile_uid', 'school', 'updated_at']);

        $replica = ReplicaSchema::replicaName($source, null, 'tbl_students');
        $this->assertFalse(Schema::hasColumn($replica, 'qid'), 'deselected columns are not copied');

        $run = app(SyncPipeline::class)->run($source);
        $this->assertSame(2, $run->replication['tbl_students']['read']);
        $this->assertSame(['s1', 's2'], DB::table($replica)->orderBy('uid')->pluck('uid')->all());

        // incremental: only the changed row is read again
        $this->pdo->exec("UPDATE tbl_students SET school = 'AMAB', updated_at = '2026-02-01 07:00:00' WHERE uid = 's1'");
        $run = app(SyncPipeline::class)->run($source);
        $this->assertSame(1, $run->replication['tbl_students']['read']);
        $this->assertSame('AMAB', DB::table($replica)->where('uid', 's1')->value('school'));

        // a full run removes rows the source no longer has
        $this->pdo->exec("INSERT INTO tbl_students VALUES ('s3', 'p3', 'AJA', 'x', '2026-02-02 07:00:00')");
        app(SyncPipeline::class)->run($source);
        $this->pdo->exec("DELETE FROM tbl_students WHERE uid = 's2'");
        $full = app(SyncPipeline::class)->run($source, SyncMode::Full);
        $this->assertSame(1, $full->replication['tbl_students']['deleted']);
        $this->assertSame(['s1', 's3'], DB::table($replica)->orderBy('uid')->pluck('uid')->all());
    }

    public function test_a_stream_mapped_from_one_table_reaches_the_marts(): void
    {
        $source = $this->source();
        $profiles = $this->select($source, 'tbl_profiles');

        $this->postJson("/api/v1/admin/data-sources/{$source->id}/streams/staff/preview", [
            'kind' => 'table', 'source_table_id' => $profiles, 'column_map' => ['id' => 'uid', 'name' => 'full_name'],
        ])->assertOk()->assertJsonPath('data.columns', ['id', 'name', 'updated_at']);

        app(SyncPipeline::class)->run($source);
        $this->putJson("/api/v1/admin/data-sources/{$source->id}/streams/staff", [
            'kind' => 'table', 'source_table_id' => $profiles, 'column_map' => ['id' => 'uid', 'name' => 'full_name'], 'filter' => 'sex = 1',
        ])->assertCreated();
        $this->getJson("/api/v1/admin/data-sources/{$source->id}")->assertJsonPath('data.tables', ['copied' => 1, 'streams' => 1]);

        $run = app(SyncPipeline::class)->run($source);

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(['p2'], DB::table('mart_edu_staff')->pluck('staff_id')->all());
        $this->assertDatabaseHas('mart_edu_staff', ['staff_id' => 'p2', 'name' => 'Mariam Ali']);

        // only rows changed in the copy are read again
        $this->pdo->exec("UPDATE tbl_profiles SET full_name = 'Mariam A. Ali', updated_at = '2026-03-01 00:00:00' WHERE uid = 'p2'");
        $again = app(SyncPipeline::class)->run($source);
        $this->assertSame(1, $again->stats['staff']['read']);
        $this->assertDatabaseHas('mart_edu_staff', ['staff_id' => 'p2', 'name' => 'Mariam A. Ali']);
    }

    public function test_a_query_stream_joins_copied_tables_and_may_not_read_anything_else(): void
    {
        $source = $this->source();
        $this->select($source, 'tbl_students');
        $this->select($source, 'tbl_profiles');
        app(SyncPipeline::class)->run($source);

        $sql = "SELECT s.uid AS id, p.full_name AS name, CASE p.sex WHEN 1 THEN 'F' ELSE 'M' END AS gender, s.school AS school,
                    MAX(s._cis_synced_at, p._cis_synced_at) AS updated_at
                FROM {{tbl_students}} s JOIN {{tbl_profiles}} p ON p.uid = s.profile_uid
                WHERE s.school = :school";
        $source->update(['config' => [...$source->config, 'parameters' => 'school = AMAG']]);

        $this->putJson("/api/v1/admin/data-sources/{$source->id}/streams/students", ['kind' => 'query', 'sql' => $sql])
            ->assertCreated()->assertJsonPath('data.output_columns', ['id', 'name', 'gender', 'school', 'updated_at']);
        app(SyncPipeline::class)->run($source);
        $this->assertDatabaseHas('mart_edu_students', ['student_id' => 's2', 'name' => 'Mariam Ali', 'gender' => 'F', 'school' => 'AMAG']);
        $this->assertSame(1, DB::table('mart_edu_students')->count());

        foreach ([
            'SELECT id, name FROM users' => 'can only read copied tables',
            'SELECT uid AS id FROM {{tbl_staff}}' => "isn't a copied table",
            'DELETE FROM {{tbl_students}}' => 'Only SELECT',
            'SELECT uid AS name FROM {{tbl_students}}' => 'needs an id column',
        ] as $bad => $message) {
            $this->putJson("/api/v1/admin/data-sources/{$source->id}/streams/students", ['kind' => 'query', 'sql' => $bad])
                ->assertUnprocessable()
                ->assertJsonPath('errors.sql.0', fn (string $m) => str_contains($m, $message));
        }
    }

    public function test_a_table_used_by_a_stream_cant_be_removed_until_the_stream_is(): void
    {
        $source = $this->source();
        $profiles = $this->select($source, 'tbl_profiles');
        $this->putJson("/api/v1/admin/data-sources/{$source->id}/streams/staff", [
            'kind' => 'table', 'source_table_id' => $profiles, 'column_map' => ['id' => 'uid', 'name' => 'full_name'],
        ])->assertCreated();

        $this->deleteJson("/api/v1/admin/data-sources/{$source->id}/tables/tbl_profiles")->assertStatus(409)->assertJsonPath('message', fn (string $m) => str_contains($m, 'staff'));

        $this->deleteJson("/api/v1/admin/data-sources/{$source->id}/streams/staff")->assertNoContent();
        $this->deleteJson("/api/v1/admin/data-sources/{$source->id}/tables/tbl_profiles")->assertNoContent();
        $this->assertFalse(Schema::hasTable(ReplicaSchema::replicaName($source, null, 'tbl_profiles')));
    }

    public function test_an_invalid_filter_is_refused(): void
    {
        $source = $this->source();

        $this->putJson("/api/v1/admin/data-sources/{$source->id}/tables/tbl_students", ['primary_key' => ['uid'], 'filter' => 'no_such_column = 1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['filter']);
        $this->putJson("/api/v1/admin/data-sources/{$source->id}/tables/tbl_students", ['primary_key' => ['uid'], 'filter' => '1 = 1; DROP TABLE tbl_students'])
            ->assertUnprocessable()->assertJsonValidationErrors(['filter']);
        $this->putJson("/api/v1/admin/data-sources/{$source->id}/tables/tbl_students", ['primary_key' => []])
            ->assertJsonValidationErrors(['primary_key']);
    }

    private function select(DataSource $source, string $table): int
    {
        return $this->putJson("/api/v1/admin/data-sources/{$source->id}/tables/{$table}", ['primary_key' => ['uid'], 'cursor_column' => 'updated_at'])
            ->assertCreated()->json('data.id');
    }

    private function source(): DataSource
    {
        return DataSource::factory()->create([
            'type' => 'database',
            'config' => ['driver' => 'sqlite', 'host' => 'localhost', 'database' => $this->database, 'username' => 'reader'],
        ]);
    }
}
