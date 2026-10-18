<?php

namespace Tests\Feature;

use App\Connectors\IssExportConnector;
use App\Enums\SyncStatus;
use App\Models\DataSource;
use App\Models\SyncCursor;
use App\Sync\SyncPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IssExportConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_pages_through_a_stream_and_stores_the_latest_updated_at_as_the_cursor(): void
    {
        Http::fake([
            'edu.iss.test/api/cis-export/students?per_page=1000' => Http::response([
                'data' => [$this->student(1, '2026-10-17T08:00:00Z'), $this->student(2, '2026-10-18T06:30:00Z')],
                'next_cursor' => 'page-2',
            ]),
            'edu.iss.test/api/cis-export/students?cursor=page-2&per_page=1000' => Http::response([
                'data' => [$this->student(3, '2026-10-18T05:00:00Z')],
                'next_cursor' => null,
            ]),
        ]);
        $source = $this->source(['streams' => ['students'], 'entity' => 'AJA']);

        $run = app(SyncPipeline::class)->run($source);

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(3, DB::table('mart_edu_students')->count());
        $this->assertSame('2026-10-18T06:30:00Z', SyncCursor::where('stream', 'students')->value('cursor'));
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('X-ISS-Entity', 'AJA'));
    }

    public function test_incremental_syncs_ask_only_for_records_updated_since_the_cursor(): void
    {
        Http::fake(['edu.iss.test/*' => Http::response(['data' => [], 'next_cursor' => null])]);
        $source = $this->source(['streams' => ['students']]);
        SyncCursor::create(['data_source_id' => $source->id, 'stream' => 'students', 'cursor' => '2026-10-18T06:30:00Z']);

        app(SyncPipeline::class)->run($source);

        Http::assertSent(fn (Request $request) => $request['updated_since'] === '2026-10-18T06:30:00Z');
        $this->assertSame('2026-10-18T06:30:00Z', SyncCursor::where('stream', 'students')->value('cursor'));
    }

    public function test_it_fetches_a_client_credentials_token_once_per_job(): void
    {
        Http::fake([
            'edu.iss.test/oauth/token' => Http::response(['access_token' => 'passport-token']),
            'edu.iss.test/api/cis-export/*' => Http::response(['data' => [], 'next_cursor' => null]),
        ]);
        $source = $this->source([
            'token' => null,
            'client_id' => 'cis',
            'client_secret' => 'secret',
            'token_url' => 'https://edu.iss.test/oauth/token',
            'streams' => ['sections', 'students'],
        ]);

        app(SyncPipeline::class)->run($source);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://edu.iss.test/oauth/token'
            && $request['grant_type'] === 'client_credentials' && $request['client_id'] === 'cis');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'cis-export/students')
            && $request->hasHeader('Authorization', 'Bearer passport-token'));
    }

    public function test_a_rejected_token_fails_the_run_without_retrying(): void
    {
        Http::fake(['edu.iss.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);
        $source = $this->source(['streams' => ['students']]);

        try {
            app(SyncPipeline::class)->run($source);
            $this->fail('Expected the sync to fail.');
        } catch (RequestException $e) {
            $this->assertSame(401, $e->response->status());
        }

        $this->assertSame(SyncStatus::Failed, $source->runs()->latest('id')->first()->status);
        Http::assertSentCount(1);
    }

    public function test_the_connection_test_reports_failures(): void
    {
        Http::fake(['edu.iss.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $result = app(IssExportConnector::class)->test($this->source(['streams' => ['students']]));

        $this->assertFalse($result->ok);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function source(array $config = []): DataSource
    {
        return DataSource::factory()->issExport('edu', $config)->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function student(int $id, string $updatedAt): array
    {
        return [
            'id' => $id,
            'name' => "Student {$id}",
            'gender' => 'M',
            'school' => 'S2',
            'year_group' => '10',
            'stage' => 'Secondary',
            'section' => 'B',
            'section_key' => 'S2-10-B',
            'house' => 'Oryx',
            'nationality' => 'Egyptian',
            'updated_at' => $updatedAt,
        ];
    }
}
