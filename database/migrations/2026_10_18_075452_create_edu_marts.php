<?php

use App\Sync\MartSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Mart tables for these streams; columns come from config/cis_streams.php.
     *
     * @var list<string>
     */
    private array $streams = [
        'edu.schools',
        'edu.mark_codes',
        'edu.behaviour_types',
        'edu.staff',
        'edu.sections',
        'edu.students',
        'edu.attendance_marks',
        'edu.behaviour_events',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->streams as $stream) {
            MartSchema::create($stream);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->streams) as $stream) {
            MartSchema::drop($stream);
        }
    }
};
