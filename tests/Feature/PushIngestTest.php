<?php

namespace Tests\Feature;

use App\Enums\SyncMode;
use App\Jobs\SyncDataSource;
use App\Models\DataSource;
use App\Models\User;
use App\Sync\SyncPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushIngestTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shared-signing-secret';

    public function test_signed_pushes_are_staged_queue_a_sync_and_reach_the_marts(): void
    {
        Queue::fake();
        $source = $this->source();

        $this->push($source, 'staff', ['records' => [['id' => 't1', 'name' => 'Ms Grant'], ['id' => 't2', 'name' => 'Mr Ellis']]])
            ->assertAccepted()
            ->assertJsonPath('data.accepted', 2);

        Queue::assertPushed(SyncDataSource::class, fn (SyncDataSource $job) => $job->source->is($source));

        app(SyncPipeline::class)->run($source);
        $this->assertSame(2, DB::table('mart_edu_staff')->count());

        $this->push($source, 'staff', ['records' => [['id' => 't2', 'name' => 'Mr Ellis (Head)']], 'deleted' => ['t1']])->assertJsonPath('data.deleted', 1);
        $incremental = app(SyncPipeline::class)->run($source);
        $this->assertSame(1, $incremental->stats['staff']['read']);
        $this->assertDatabaseHas('mart_edu_staff', ['staff_id' => 't2', 'name' => 'Mr Ellis (Head)']);

        app(SyncPipeline::class)->run($source, SyncMode::Full);
        $this->assertSame(['t2'], DB::table('mart_edu_staff')->pluck('staff_id')->all());
    }

    public function test_bad_or_stale_signatures_and_unknown_streams_are_rejected(): void
    {
        $source = $this->source();
        $body = json_encode(['records' => [['id' => 'x']]]);

        $this->call('POST', "/api/v1/ingest/{$source->id}/staff", [], [], [], $this->headers(time(), 'not-the-signature'), $body)->assertUnauthorized();
        $old = time() - 3600;
        $this->call('POST', "/api/v1/ingest/{$source->id}/staff", [], [], [], $this->headers($old, hash_hmac('sha256', "{$old}.{$body}", self::SECRET)), $body)->assertUnauthorized();
        $this->push($source, 'payroll', ['records' => []])->assertNotFound();
        $this->push(DataSource::factory()->create(), 'staff', ['records' => []])->assertNotFound();
        $this->assertDatabaseCount('ingest_records', 0);
    }

    public function test_creating_a_push_source_generates_its_signing_secret_and_shows_the_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['connections:manage']);

        $response = $this->postJson('/api/v1/admin/data-sources', [
            'name' => 'Biometric gates',
            'type' => 'push-ingest',
            'platform' => 'edu',
            'tenant' => 'aja',
            'schedule' => '0 * * * *',
            'config' => ['signing_secret' => ''],
        ])->assertCreated();

        $response->assertJsonPath('data.secrets', ['signing_secret'])
            ->assertJsonPath('data.needs_setup', false)
            ->assertJsonPath('data.ingest_url', url('/api/v1/ingest/'.$response->json('data.id').'/{stream}'));
        $this->assertSame(48, strlen(DataSource::first()->config['signing_secret']));
    }

    private function source(): DataSource
    {
        return DataSource::factory()->create(['type' => 'push-ingest', 'config' => ['signing_secret' => self::SECRET, 'sync_on_push' => true]]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(DataSource $source, string $stream, array $payload): TestResponse
    {
        $body = json_encode($payload);
        $timestamp = time();

        return $this->call('POST', "/api/v1/ingest/{$source->id}/{$stream}", [], [], [], $this->headers($timestamp, hash_hmac('sha256', "{$timestamp}.{$body}", self::SECRET)), $body);
    }

    /**
     * @return array<string, string>
     */
    private function headers(int $timestamp, string $signature): array
    {
        return ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_CIS_TIMESTAMP' => (string) $timestamp, 'HTTP_X_CIS_SIGNATURE' => $signature];
    }
}
