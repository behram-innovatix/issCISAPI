<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\DataSource;
use App\Sync\SyncPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RestApiConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_pages_by_number_and_maps_fields_with_dot_paths(): void
    {
        Http::fake([
            'lms.test/v2/learners?status=active&page=1' => Http::response(['result' => ['items' => [
                ['uuid' => 'u1', 'profile' => ['full_name' => 'Sara Khan'], 'sex' => 'F', 'campus' => 'AJA'],
                ['uuid' => 'u2', 'profile' => ['full_name' => 'Ali Noor'], 'sex' => 'M', 'campus' => 'AJA'],
            ]]]),
            'lms.test/v2/learners?status=active&page=2' => Http::response(['result' => ['items' => [
                ['uuid' => 'u3', 'profile' => ['full_name' => 'Huda Amin'], 'sex' => 'F', 'campus' => 'AMAG'],
            ]]]),
            'lms.test/v2/learners?status=active&page=3' => Http::response(['result' => ['items' => []]]),
        ]);
        $source = $this->source([
            'auth' => 'bearer',
            'token' => 'lms-token',
            'endpoints' => ['students' => '/learners?status=active'],
            'records_path' => 'result.items',
            'pagination' => 'page',
            'page_param' => 'page',
            'field_map' => ['students' => '{"id": "uuid", "name": "profile.full_name", "gender": "sex", "school": "campus"}'],
        ]);

        $run = app(SyncPipeline::class)->run($source);

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertSame(3, DB::table('mart_edu_students')->count());
        $this->assertDatabaseHas('mart_edu_students', ['student_id' => 'u3', 'name' => 'Huda Amin', 'school' => 'AMAG']);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer lms-token'));
    }

    public function test_it_follows_cursors_and_sends_the_updated_since_parameter(): void
    {
        Http::fake([
            'crm.test/api/staff?since=*' => Http::sequence()
                ->push(['data' => [['id' => 's1', 'name' => 'Ms Grant', 'updated_at' => '2026-03-01T10:00:00Z']], 'meta' => ['next' => 'abc']])
                ->push(['data' => [['id' => 's2', 'name' => 'Mr Haddad', 'updated_at' => '2026-03-02T10:00:00Z']], 'meta' => ['next' => null]]),
            'crm.test/api/staff*' => Http::sequence()
                ->push(['data' => [['id' => 's1', 'name' => 'Ms Grant', 'updated_at' => '2026-03-01T10:00:00Z']], 'meta' => ['next' => 'abc']])
                ->push(['data' => [['id' => 's2', 'name' => 'Mr Haddad', 'updated_at' => '2026-03-02T10:00:00Z']], 'meta' => ['next' => null]]),
        ]);
        $source = $this->source([
            'base_url' => 'https://crm.test/api',
            'auth' => 'header',
            'header_name' => 'X-Api-Key',
            'header_value' => 'k3y',
            'endpoints' => ['staff' => 'staff'],
            'records_path' => 'data',
            'pagination' => 'cursor',
            'cursor_param' => 'after',
            'next_path' => 'meta.next',
            'updated_since_param' => 'since',
        ]);
        DB::table('sync_cursors')->insert(['data_source_id' => $source->id, 'stream' => 'staff', 'cursor' => '2026-02-28T00:00:00Z']);

        app(SyncPipeline::class)->run($source);

        $this->assertSame(2, DB::table('mart_edu_staff')->count());
        Http::assertSent(fn (Request $request) => $request['since'] === '2026-02-28T00:00:00Z' && $request->hasHeader('X-Api-Key', 'k3y'));
        Http::assertSent(fn (Request $request) => ($request->data()['after'] ?? null) === 'abc');
        $this->assertSame('2026-03-02T10:00:00Z', DB::table('sync_cursors')->where('stream', 'staff')->value('cursor'));
    }

    public function test_a_response_without_records_at_the_path_fails_the_run(): void
    {
        Http::fake(['*' => Http::response(['data' => 'nope'])]);
        $source = $this->source(['endpoints' => ['students' => '/learners'], 'records_path' => 'data', 'pagination' => 'none']);

        $this->expectExceptionMessage('No array of records at "data"');

        app(SyncPipeline::class)->run($source);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function source(array $config): DataSource
    {
        return DataSource::factory()->create([
            'type' => 'rest-api',
            'config' => $config + ['base_url' => 'https://lms.test/v2', 'auth' => 'none', 'pagination' => 'none'],
        ]);
    }
}
