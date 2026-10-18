<?php

namespace Tests\Feature;

use App\Enums\SyncMode;
use App\Jobs\SyncDataSource;
use App\Models\DataSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncSourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_sources_or_a_selection_flag(): void
    {
        $this->artisan('cis:sync')->assertExitCode(2);
    }

    public function test_due_only_queues_active_sources_whose_schedule_is_due(): void
    {
        Queue::fake();
        $this->travelTo(now()->setTime(10, 0));
        $hourly = DataSource::factory()->create(['schedule' => '0 * * * *']);
        DataSource::factory()->create(['schedule' => '30 * * * *']);
        DataSource::factory()->inactive()->create(['schedule' => '0 * * * *']);

        $this->artisan('cis:sync --due --queue')->assertSuccessful();

        Queue::assertPushed(SyncDataSource::class, 1);
        Queue::assertPushed(SyncDataSource::class, fn (SyncDataSource $job) => $job->source->is($hourly) && $job->mode === SyncMode::Incremental);
    }

    public function test_named_sources_are_queued_even_when_inactive(): void
    {
        Queue::fake();
        $source = DataSource::factory()->inactive()->create(['name' => 'hrm-api']);

        $this->artisan('cis:sync hrm-api --full --queue')->assertSuccessful();

        Queue::assertPushed(SyncDataSource::class, fn (SyncDataSource $job) => $job->source->is($source) && $job->mode === SyncMode::Full);
    }

    public function test_it_exits_with_failure_when_a_source_fails(): void
    {
        DataSource::factory()->create(['name' => 'missing-files', 'config' => ['path' => storage_path('framework/testing/does-not-exist')]]);

        $this->artisan('cis:sync missing-files')->assertFailed();
    }
}
