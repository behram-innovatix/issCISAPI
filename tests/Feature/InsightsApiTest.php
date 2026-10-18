<?php

namespace Tests\Feature;

use App\Insights\EduInsights;
use App\Models\DataSource;
use App\Models\User;
use App\Sync\SyncPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InsightsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = storage_path('framework/testing/insights-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtures);

        parent::tearDown();
    }

    public function test_it_requires_the_insights_ability(): void
    {
        $this->getJson('/api/v1/insights/edu')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['connections:manage']);
        $this->getJson('/api/v1/insights/edu')->assertForbidden();
    }

    public function test_a_platform_without_synced_data_reports_not_connected(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['insights:read']);
        DataSource::factory()->create(['name' => 'HRM API', 'platform' => 'hr', 'type' => 'iss-export', 'is_active' => false]);

        $this->getJson('/api/v1/insights/hr')->assertOk()
            ->assertJsonPath('data.connected', false)
            ->assertJsonPath('data.sources.0.name', 'HRM API');
        $this->getJson('/api/v1/insights/edu')->assertJsonPath('data.connected', false);
        $this->getJson('/api/v1/insights/payroll')->assertNotFound();
    }

    public function test_the_edu_dataset_packs_attendance_by_session_inside_the_reporting_window(): void
    {
        config(['insights.edu.max_weeks' => 2]);
        Sanctum::actingAs(User::factory()->create(), ['insights:read']);
        $this->write('schools', [['id' => 'AJA', 'name' => 'Al Jazeera Academy', 'short_name' => 'Al Jazeera', 'intake' => 'Co-educational']]);
        $this->write('mark_codes', [
            ['id' => '/', 'label' => 'Present', 'category' => 'present'],
            ['id' => 'N', 'label' => 'No reason yet', 'category' => 'unauth'],
            ['id' => '#', 'label' => 'School closed', 'category' => 'none'],
        ]);
        $this->write('behaviour_types', [['id' => 'bt1', 'name' => 'Leadership', 'polarity' => 'positive']]);
        $this->write('staff', [['id' => 'st1', 'name' => 'Ms Grant']]);
        $this->write('sections', [['id' => 'sec1', 'school' => 'AJA', 'year_group' => '07', 'stage' => 'Secondary', 'section' => '7A', 'form_tutor_id' => 'st1']]);
        $this->write('students', [['id' => 'a1', 'name' => 'Sara Khan', 'gender' => 'F', 'school' => 'AJA', 'year_group' => '07', 'stage' => 'Secondary', 'section' => '7A', 'section_key' => 'sec1']]);
        // Sunday 1 Mar 2026 is in the week before the window; the window is the weeks of 8 and 15 Mar
        $this->write('attendance_marks', [
            ['id' => '1', 'student_id' => 'a1', 'date' => '2026-03-01', 'session' => 'AM', 'code' => '/'],
            ['id' => '2', 'student_id' => 'a1', 'date' => '2026-03-08', 'session' => 'AM', 'code' => '/'],
            ['id' => '3', 'student_id' => 'a1', 'date' => '2026-03-08', 'session' => 'PM', 'code' => 'N'],
            ['id' => '4', 'student_id' => 'a1', 'date' => '2026-03-19', 'session' => 'PM', 'code' => '#'],
        ]);
        $this->write('behaviour_events', [['id' => 'e1', 'student_id' => 'a1', 'date' => '2026-03-16', 'type' => 'bt1', 'points' => 3, 'recorded_by' => 'st1']]);
        app(SyncPipeline::class)->run(DataSource::factory()->create(['config' => ['path' => $this->fixtures]]));

        $data = $this->getJson('/api/v1/insights/edu')->assertOk()->json('data');

        $this->assertTrue($data['connected']);
        $this->assertSame(['start' => '2026-03-08', 'as_of' => '2026-03-19', 'weeks' => 2, 'label' => '8 Mar – 19 Mar 2026'], $data['calendar']);
        $this->assertSame(['', '/', 'N', '#'], array_column($data['mark_codes'], 'code'));
        // Sun 8 Mar AM present (1), PM unauthorised (2); Thu 19 Mar PM not required (3); the rest unmarked
        $this->assertSame('12'.str_repeat('0', 17).'3', $data['students'][0]['s']);
        $this->assertSame([['w' => 1, 'd' => 1, 't' => 0, 'pts' => 3, 'loc' => null, 'by' => 'st1']], $data['students'][0]['ev']);
        $this->assertSame([['n' => 'Leadership', 's' => 1]], $data['behaviour_types']);
        $this->assertSame('st1', $data['sections'][0]['formTutorId']);
        $this->assertNull($data['sections'][0]['cap']);
        $this->assertSame([['id' => 'AJA', 'name' => 'Al Jazeera Academy', 'short' => 'Al Jazeera', 'intake' => 'Co-educational']], $data['schools']);

        // cached as plain arrays: the database cache store won't unserialize objects such as Collections
        $built = app(EduInsights::class)->build(DataSource::pluck('id')->all());
        array_walk_recursive($built, fn ($value) => $this->assertIsNotObject($value));
        foreach (['schools', 'staff', 'sections', 'students', 'mark_codes'] as $key) {
            $this->assertIsArray($built[$key]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function write(string $stream, array $records): void
    {
        File::ensureDirectoryExists("{$this->fixtures}/edu");
        File::put("{$this->fixtures}/edu/{$stream}.jsonl", implode("\n", array_map('json_encode', $records))."\n");
    }
}
