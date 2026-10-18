<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('data_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type', 64);
            $table->string('platform', 32);
            $table->string('tenant', 64)->default('default');
            $table->text('config');
            $table->string('schedule', 64)->default('0 * * * *');
            $table->boolean('is_active')->default(true);
            $table->string('status', 16)->default('new');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('data_changed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['platform', 'is_active']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 16);
            $table->string('status', 16);
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['data_source_id', 'status']);
        });

        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('stream', 64);
            $table->string('cursor')->nullable();
            $table->timestamps();

            $table->unique(['data_source_id', 'stream']);
        });

        Schema::create('raw_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('stream', 64);
            $table->string('record_key', 191);
            $table->json('payload');
            $table->char('payload_hash', 40);
            $table->unsignedBigInteger('seen_run_id');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['data_source_id', 'stream', 'record_key']);
            $table->index(['data_source_id', 'stream', 'deleted_at']);
        });

        Schema::create('entity_xref', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('source_key', 191);
            $table->string('canonical_key', 191);
            $table->timestamps();

            $table->unique(['kind', 'data_source_id', 'source_key']);
            $table->index(['kind', 'canonical_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entity_xref');
        Schema::dropIfExists('raw_records');
        Schema::dropIfExists('sync_cursors');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('data_sources');
    }
};
