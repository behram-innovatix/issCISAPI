<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records pushed to the ingest endpoint by push-ingest sources, kept as the latest state per
     * record until syncs pull them into raw_records.
     */
    public function up(): void
    {
        Schema::create('ingest_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('stream', 64);
            $table->string('record_key', 191);
            $table->json('payload');
            $table->timestamp('received_at', 6);

            $table->unique(['data_source_id', 'stream', 'record_key']);
            $table->index(['data_source_id', 'stream', 'received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingest_records');
    }
};
