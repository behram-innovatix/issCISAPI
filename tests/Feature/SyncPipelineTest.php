<?php

namespace Tests\Feature;

use App\Enums\SourceStatus;
use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Jobs\BuildInsights;
use App\Models\DataSource;
use App\Models\RawRecord;
use App\Sync\SyncPipeline;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class SyncPipelineTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = storage_path('framework/testing/fixtures-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtures);

        parent::tearDown();
    }

    public function test_it_loads_fixture_streams_into_raw_records_and_marts(): void
    {
        $this->writeStudents(3);
        $this->writeFixture('edu', 'attendance_marks', [
            ['id' => '1:2026-08-23:AM', 'student_id' => 1, 'date' => '2026-08-23', 'session' => 'AM', 'code' => '/'],
            ['id' => '1:2026-08-23:PM', 'student_id' => 1, 'date' => '2026-08-23', 'session' => 'PM', 'code' => 'L'],
        ]);
        $source = $this->fixtureSource();

        $run = $this->pipeline()->run($source);

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(['read' => 3, 'changed' => 3, 'deleted' => 0, 'active' => 3, 'mart' => 3], $run->stats['students']);
        $this->assertSame(SourceStatus::Healthy, $source->fresh()->status);
        $this->assertNotNull($source->fresh()->last_synced_at);
        $this->assertSame(5, RawRecord::count());
        $this->assertDatabaseHas('mart_edu_students', ['data_source_id' => $source->id, 'student_id' => 2, 'name' => 'Student 2', 'school' => 'S1']);
        $this->assertDatabaseHas('mart_edu_attendance_marks', ['mark_key' => '1:2026-08-23:PM', 'student_id' => 1, 'date' => '2026-08-23', 'code' => 'L']);
        $this->assertDatabaseHas('entity_xref', ['kind' => 'student', 'source_key' => '3', 'canonical_key' => 'iss:3']);
    }

    public function test_running_twice_gives_the_same_marts(): void
    {
        $this->writeStudents(4);
        $source = $this->fixtureSource();

        $this->pipeline()->run($source);
        $this->pipeline()->run($source);

        $this->assertSame(4, RawRecord::count());
        $this->assertSame(4, DB::table('mart_edu_students')->count());
        $this->assertSame(4, DB::table('entity_xref')->count());
    }

    public function test_only_syncs_that_change_data_rebuild_the_dashboard_dataset(): void
    {
        Queue::fake();
        $this->writeStudents(2);
        $source = $this->fixtureSource();

        $this->pipeline()->run($source);
        $changedAt = $source->fresh()->data_changed_at;
        Queue::assertPushed(BuildInsights::class, fn (BuildInsights $job) => $job->platform === 'edu');

        $this->travel(5)->minutes();
        $unchanged = $this->pipeline()->run($source);
        $this->assertSame(0, $unchanged->stats['students']['changed']);
        $this->assertEquals($changedAt, $source->fresh()->data_changed_at);
        Queue::assertPushed(BuildInsights::class, 1);

        // a processed build releases its unique lock; the fake queue never processes it
        (new UniqueLock(Cache::driver()))->release(new BuildInsights('edu'));
        $this->writeStudents(3);
        $this->pipeline()->run($source);
        Queue::assertPushed(BuildInsights::class, 2);
        $this->assertTrue($source->fresh()->data_changed_at->greaterThan($changedAt));
    }

    public function test_a_stream_read_whole_drops_records_the_source_no_longer_returns(): void
    {
        $this->writeStudents(4);
        $source = $this->fixtureSource();
        $this->pipeline()->run($source);

        $this->writeStudents(3);
        $incremental = $this->pipeline()->run($source);

        $this->assertSame(['read' => 3, 'changed' => 0, 'deleted' => 1, 'active' => 3, 'mart' => 3], $incremental->stats['students']);
        $this->assertDatabaseMissing('mart_edu_students', ['student_id' => 4]);
        $this->assertNotNull(RawRecord::where('record_key', '4')->value('deleted_at'));
    }

    public function test_a_record_that_comes_back_is_restored(): void
    {
        $this->writeStudents(2);
        $source = $this->fixtureSource();
        $this->pipeline()->run($source);
        $this->writeStudents(1);
        $this->pipeline()->run($source, SyncMode::Full);

        $this->writeStudents(2);
        $this->pipeline()->run($source);

        $this->assertNull(RawRecord::where('record_key', '2')->value('deleted_at'));
        $this->assertDatabaseHas('mart_edu_students', ['student_id' => 2]);
    }

    public function test_a_large_drop_fails_the_quality_gate_and_keeps_the_last_good_marts(): void
    {
        $this->writeStudents(10);
        $source = $this->fixtureSource();
        $this->pipeline()->run($source);

        $this->writeStudents(2);
        $run = $this->pipeline()->run($source, SyncMode::Full);

        $this->assertSame(SyncStatus::Degraded, $run->status);
        $this->assertStringContainsString('students: active records fell from 10 to 2', $run->error);
        $this->assertSame(SourceStatus::Degraded, $source->fresh()->status);
        $this->assertSame(10, DB::table('mart_edu_students')->count());
    }

    public function test_invalid_records_fail_the_run_without_touching_the_marts(): void
    {
        $this->writeStudents(2);
        $source = $this->fixtureSource();
        $this->pipeline()->run($source);

        $this->writeFixture('edu', 'students', [['name' => 'No id']]);

        try {
            $this->pipeline()->run($source);
            $this->fail('Expected the sync to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('A [students] record has no id.', $e->getMessage());
        }

        $source->refresh();
        $this->assertSame(SourceStatus::Failing, $source->status);
        $this->assertSame('A [students] record has no id.', $source->last_error);
        $this->assertSame(SyncStatus::Failed, $source->runs()->latest('id')->first()->status);
        $this->assertSame(2, DB::table('mart_edu_students')->count());
    }

    public function test_a_missing_fixture_directory_fails_the_run(): void
    {
        $source = $this->fixtureSource();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $this->pipeline()->run($source);
    }

    public function test_sources_are_isolated_from_each_other(): void
    {
        $this->writeStudents(3);
        $first = $this->fixtureSource();
        $second = $this->fixtureSource();

        $this->pipeline()->run($first);
        $this->pipeline()->run($second);
        $this->writeStudents(2);
        $this->pipeline()->run($second, SyncMode::Full);

        $this->assertSame(3, DB::table('mart_edu_students')->where('data_source_id', $first->id)->count());
        $this->assertSame(2, DB::table('mart_edu_students')->where('data_source_id', $second->id)->count());
    }

    private function pipeline(): SyncPipeline
    {
        return app(SyncPipeline::class);
    }

    private function fixtureSource(): DataSource
    {
        return DataSource::factory()->create(['tenant' => 'iss', 'config' => ['path' => $this->fixtures]]);
    }

    private function writeStudents(int $count): void
    {
        $this->writeFixture('edu', 'students', array_map(fn (int $id) => [
            'id' => $id,
            'name' => "Student {$id}",
            'gender' => 'F',
            'school' => 'S1',
            'year_group' => '07',
            'stage' => 'Secondary',
            'section' => 'A',
            'section_key' => 'S1-07-A',
            'house' => 'Pearl',
            'nationality' => 'Qatari',
        ], range(1, $count)));
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function writeFixture(string $platform, string $stream, array $records): void
    {
        File::ensureDirectoryExists("{$this->fixtures}/{$platform}");
        File::put("{$this->fixtures}/{$platform}/{$stream}.jsonl", implode("\n", array_map('json_encode', $records))."\n");
    }
}
