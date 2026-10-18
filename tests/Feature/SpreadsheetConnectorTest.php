<?php

namespace Tests\Feature;

use App\Enums\SyncStatus;
use App\Models\DataSource;
use App\Models\User;
use App\Sync\SyncPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SpreadsheetConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create(), ['connections:manage']);
    }

    public function test_an_uploaded_csv_loads_with_headers_matched_to_fields_and_mapped_columns(): void
    {
        $source = $this->source(['column_map' => ['budgets' => '{"id": "Budget code", "annual": "FY total"}']], 'fin');
        $csv = "Budget code;Location;Category;FY total\nS1-SAL;S1;Salaries;1250000.50\nS1-RES;S1;Resources;98000\n";

        $this->post("/api/v1/admin/data-sources/{$source->id}/files", [
            'stream' => 'budgets',
            'file' => UploadedFile::fake()->createWithContent('Budget 2026.csv', $csv),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.0.name', 'Budget 2026.csv');

        $run = app(SyncPipeline::class)->run($source);

        $this->assertSame(SyncStatus::Succeeded, $run->status);
        $this->assertDatabaseHas('mart_fin_budgets', ['budget_key' => 'S1-SAL', 'location' => 'S1', 'category' => 'Salaries', 'annual' => 1250000.50]);
        $this->assertSame(2, DB::table('mart_fin_budgets')->count());
    }

    public function test_an_excel_upload_reads_dates_and_replacing_the_file_replaces_the_stream(): void
    {
        $source = $this->source();
        $this->upload($source, 'staff', [['ID', 'Name', 'Role'], ['t1', 'Ms Grant', 'Teacher'], ['t2', 'Mr Ellis', 'Head of Year']]);
        app(SyncPipeline::class)->run($source);

        $this->upload($source, 'staff', [['ID', 'Name', 'Role'], ['t2', 'Mr Ellis', 'Deputy Head']]);
        $run = app(SyncPipeline::class)->run($source);

        $this->assertSame(1, $run->stats['staff']['deleted']);
        $this->assertSame(['t2'], DB::table('mart_edu_staff')->pluck('staff_id')->all());
        $this->assertDatabaseHas('mart_edu_staff', ['staff_id' => 't2', 'role' => 'Deputy Head']);

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([['id', 'student_id', 'date', 'session', 'code'], ['m1', 'a1', null, 'AM', '/']]);
        $sheet->setCellValue('C2', Date::PHPToExcel(new \DateTime('2026-03-15')));
        $sheet->getStyle('C2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
        $path = tempnam(sys_get_temp_dir(), 'cis').'.xlsx';
        (new Xlsx($book))->save($path);
        $this->post("/api/v1/admin/data-sources/{$source->id}/files", [
            'stream' => 'attendance_marks',
            'file' => new UploadedFile($path, 'marks.xlsx', test: true),
        ], ['Accept' => 'application/json'])->assertCreated();

        app(SyncPipeline::class)->run($source);

        $this->assertDatabaseHas('mart_edu_attendance_marks', ['mark_key' => 'm1', 'date' => '2026-03-15', 'code' => '/']);
    }

    public function test_files_are_listed_and_removed_and_other_types_are_rejected(): void
    {
        $source = $this->source();
        $this->upload($source, 'staff', [['id', 'name'], ['t1', 'Ms Grant']]);

        $this->getJson("/api/v1/admin/data-sources/{$source->id}/files")->assertOk()->assertJsonPath('data.0.stream', 'staff');
        $this->post("/api/v1/admin/data-sources/{$source->id}/files", [
            'stream' => 'staff',
            'file' => UploadedFile::fake()->create('notes.pdf', 10),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors(['file']);

        $this->deleteJson("/api/v1/admin/data-sources/{$source->id}/files/staff")->assertNoContent();
        $this->getJson("/api/v1/admin/data-sources/{$source->id}/files")->assertJsonPath('data', []);

        $sql = DataSource::factory()->create(['type' => 'database']);
        $this->getJson("/api/v1/admin/data-sources/{$sql->id}/files")->assertNotFound();
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function upload(DataSource $source, string $stream, array $rows): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'cis').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->post("/api/v1/admin/data-sources/{$source->id}/files", [
            'stream' => $stream,
            'file' => new UploadedFile($path, "{$stream}.xlsx", test: true),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function source(array $config = [], string $platform = 'edu'): DataSource
    {
        return DataSource::factory()->create(['type' => 'spreadsheet', 'platform' => $platform, 'config' => $config]);
    }
}
