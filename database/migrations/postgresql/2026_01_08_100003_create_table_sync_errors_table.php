<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    /**
     * Run the migrations.
     * Creates table_sync_errors table in PostgreSQL for storing failed sync records.
     * ⚠️ NO DELETION FROM MYSQL: Error queue is stored in PostgreSQL only
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('table_sync_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_id')
                ->constrained('table_sync_configurations')
                ->onDelete('cascade');
            $table->string('source_table', 255);
            $table->unsignedBigInteger('record_id');
            $table->jsonb('record_data');
            $table->text('error_message');
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Indexes for common queries
            $table->index('configuration_id');
            $table->index('source_table');
            $table->index('record_id');
            $table->index('retry_count');
            $table->index('resolved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('table_sync_errors');
    }
};
