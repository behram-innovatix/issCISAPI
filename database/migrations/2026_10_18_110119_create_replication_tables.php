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
        // the source tables an administrator chose to copy, and how
        Schema::create('source_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('schema', 128)->nullable();
            $table->string('name', 128);
            $table->string('replica_table', 64);
            /** columns to copy; null for all */
            $table->json('columns')->nullable();
            $table->json('primary_key');
            $table->string('cursor_column', 128)->nullable();
            $table->text('filter')->nullable();
            /** source column => type, as last applied to the replica */
            $table->json('column_types');
            $table->string('cursor')->nullable();
            $table->unsignedBigInteger('rows')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['data_source_id', 'schema', 'name']);
            $table->unique('replica_table');
        });

        // how each dashboard stream of the source is built from its copied tables
        Schema::create('stream_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('stream', 64);
            $table->string('kind', 16);
            $table->foreignId('source_table_id')->nullable()->constrained()->restrictOnDelete();
            /** table mapping: stream field => copied column */
            $table->json('column_map')->nullable();
            $table->text('filter')->nullable();
            $table->text('sql')->nullable();
            /** columns the model returned when it was last checked */
            $table->json('output_columns')->nullable();
            $table->timestamps();

            $table->unique(['data_source_id', 'stream']);
        });

        Schema::table('sync_runs', function (Blueprint $table) {
            $table->json('replication')->nullable()->after('stats');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_runs', fn (Blueprint $table) => $table->dropColumn('replication'));
        Schema::dropIfExists('stream_models');
        Schema::dropIfExists('source_tables');
    }
};
