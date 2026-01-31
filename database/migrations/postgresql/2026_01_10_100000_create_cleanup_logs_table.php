<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    /**
     * Run the migrations.
     * Creates cleanup_logs table in PostgreSQL for storing cleanup operation audit trail.
     * 
     * Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('cleanup_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('operation_type', ['age_based_cleanup', 'manual_cleanup', 'dry_run']);
            $table->enum('status', ['started', 'completed', 'failed', 'stopped']);
            $table->integer('age_threshold_hours');
            $table->integer('batch_size');
            $table->integer('batches_processed')->default(0);
            $table->integer('records_deleted')->default(0);
            $table->integer('records_skipped')->default(0);
            $table->text('error_message')->nullable();
            $table->json('configuration')->nullable(); // Snapshot of config at runtime
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('triggered_by')->nullable(); // 'scheduler', 'admin', 'api'
            $table->timestamps();
            
            // Indexes for common queries
            $table->index('started_at');
            $table->index('status');
            $table->index('operation_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('cleanup_logs');
    }
};
