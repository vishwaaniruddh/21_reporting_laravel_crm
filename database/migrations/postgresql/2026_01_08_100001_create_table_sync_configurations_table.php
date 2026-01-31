<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    /**
     * Run the migrations.
     * Creates table_sync_configurations table in PostgreSQL for storing sync configuration metadata.
     * ⚠️ NO DELETION FROM MYSQL: This table only stores config metadata in PostgreSQL
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('table_sync_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('source_table', 255);
            $table->string('target_table', 255);
            $table->string('primary_key_column', 255)->default('id');
            $table->string('sync_marker_column', 255)->default('synced_at');
            $table->jsonb('column_mappings')->default('{}');
            $table->jsonb('excluded_columns')->default('[]');
            $table->integer('batch_size')->default(10000);
            $table->string('schedule', 100)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status', 50)->nullable();
            $table->timestamps();

            // Unique constraint on source_table
            $table->unique('source_table');

            // Indexes for common queries
            $table->index('is_enabled');
            $table->index('last_sync_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('table_sync_configurations');
    }
};
