<?php

namespace Tests\Feature;

use App\Enums\SyncMode;
use App\Enums\SyncStatus;
use App\Jobs\SyncDataSource;
use App\Models\DataSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConnectionsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_a_token_with_the_connections_ability(): void
    {
        $this->getJson('/api/v1/admin/data-sources')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['dashboards:view']);
        $this->getJson('/api/v1/admin/data-sources')->assertForbidden();
    }

    public function test_the_catalog_lists_connectors_with_their_fields_planned_types_and_platforms(): void
    {
        $this->actingAsConsole();

        $response = $this->getJson('/api/v1/admin/connectors')->assertOk();

        $response->assertJsonPath('data.connectors.0.type', 'iss-export')
            ->assertJsonPath('data.connectors.0.fields.2.key', 'token')
            ->assertJsonPath('data.connectors.0.fields.2.type', 'secret')
            ->assertJsonPath('data.connectors.0.fields.2.when', ['auth' => 'token'])
            ->assertJsonPath('data.connectors.1.type', 'database')
            ->assertJsonPath('data.connectors.1.fields.9.type', 'map')
            ->assertJsonPath('data.connectors.3.features', ['uploads'])
            ->assertJsonPath('data.connectors.4.features', ['ingest'])
            ->assertJsonPath('data.planned', [])
            ->assertJsonPath('data.platforms.0.code', 'edu')
            ->assertJsonPath('data.platforms.0.streams.0', 'schools');
    }

    public function test_an_admin_can_add_a_source_and_its_secret_is_never_returned(): void
    {
        $this->actingAsConsole();

        $response = $this->postJson('/api/v1/admin/data-sources', [
            'name' => 'issEduApi · Al Jazeera',
            'type' => 'iss-export',
            'platform' => 'edu',
            'tenant' => 'aja',
            'schedule' => '*/15 * * * *',
            'config' => ['base_url' => 'https://isseduapi.test/api', 'auth' => 'token', 'token' => 's3cret', 'client_id' => 'ignored'],
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.config.base_url', 'https://isseduapi.test/api')
            ->assertJsonPath('data.config.per_page', 1000)
            ->assertJsonMissingPath('data.config.token')
            ->assertJsonMissingPath('data.config.client_id')
            ->assertJsonPath('data.secrets', ['token']);
        $this->assertStringNotContainsString('s3cret', $response->getContent());
        $this->assertSame('s3cret', DataSource::first()->config['token']);
    }

    public function test_config_is_validated_against_the_connector_fields(): void
    {
        $this->actingAsConsole();

        $this->postJson('/api/v1/admin/data-sources', [
            'name' => 'Broken',
            'type' => 'iss-export',
            'platform' => 'edu',
            'tenant' => 'aja',
            'schedule' => 'every hour',
            'config' => ['base_url' => 'not a url', 'auth' => 'client_credentials'],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'schedule', 'config.base_url', 'config.client_id', 'config.client_secret', 'config.token_url',
        ])->assertJsonMissingValidationErrors(['config.token']);

        $this->postJson('/api/v1/admin/data-sources', ['name' => 'Unknown', 'type' => 'ftp', 'platform' => 'edu', 'tenant' => 'x', 'schedule' => '0 * * * *', 'config' => []])
            ->assertJsonValidationErrors(['type']);
    }

    public function test_updating_keeps_a_secret_that_was_left_empty(): void
    {
        $this->actingAsConsole();
        $source = DataSource::factory()->issExport('edu', ['auth' => 'token', 'token' => 'keep-me'])->create();

        $this->putJson("/api/v1/admin/data-sources/{$source->id}", [
            'name' => 'Renamed',
            'platform' => 'edu',
            'tenant' => 'aja',
            'schedule' => '0 * * * *',
            'is_active' => false,
            'config' => ['base_url' => 'https://other.test/api', 'auth' => 'token', 'token' => ''],
        ])->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.is_active', false);

        $source->refresh();
        $this->assertSame('keep-me', $source->config['token']);
        $this->assertSame('https://other.test/api', $source->config['base_url']);
    }

    public function test_the_connector_type_cannot_be_changed(): void
    {
        $this->actingAsConsole();
        $source = DataSource::factory()->create();

        $this->putJson("/api/v1/admin/data-sources/{$source->id}", [
            'name' => $source->name, 'type' => 'iss-export', 'platform' => 'edu', 'tenant' => 'x', 'schedule' => '0 * * * *', 'config' => ['path' => 'x'],
        ])->assertJsonValidationErrors(['type']);
    }

    public function test_testing_a_source_reports_the_result(): void
    {
        $this->actingAsConsole();
        $source = DataSource::factory()->create(['config' => ['path' => storage_path('framework/testing/nowhere')]]);

        $this->postJson("/api/v1/admin/data-sources/{$source->id}/test")
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.streams', []);
    }

    public function test_sync_now_queues_a_job_and_runs_are_listed_newest_first(): void
    {
        Queue::fake();
        $this->actingAsConsole();
        $source = DataSource::factory()->create();
        $source->runs()->create(['mode' => SyncMode::Incremental, 'status' => SyncStatus::Succeeded, 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()->addSeconds(42), 'stats' => ['students' => ['read' => 3, 'changed' => 3, 'deleted' => 0, 'active' => 3, 'mart' => 3]]]);
        $source->runs()->create(['mode' => SyncMode::Full, 'status' => SyncStatus::Failed, 'started_at' => now(), 'error' => 'Unreachable']);

        $this->postJson("/api/v1/admin/data-sources/{$source->id}/sync", ['mode' => 'full'])->assertAccepted()->assertJsonPath('data.mode', 'full');
        Queue::assertPushed(SyncDataSource::class, fn (SyncDataSource $job) => $job->source->is($source) && $job->mode === SyncMode::Full);

        $this->getJson("/api/v1/admin/data-sources/{$source->id}/runs")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.1.duration_seconds', 42)
            ->assertJsonPath('data.1.stats.students.mart', 3);

        $this->getJson('/api/v1/admin/data-sources')->assertJsonPath('data.0.latest_run.status', 'failed');
    }

    public function test_deleting_a_source_removes_it(): void
    {
        $this->actingAsConsole();
        $source = DataSource::factory()->create();

        $this->deleteJson("/api/v1/admin/data-sources/{$source->id}")->assertNoContent();

        $this->assertModelMissing($source);
    }

    private function actingAsConsole(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['connections:manage']);
    }
}
