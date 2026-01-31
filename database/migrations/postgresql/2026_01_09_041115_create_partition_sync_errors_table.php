<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Creates the partition_sync_errors table for tracking failed partition operations.
     * This table stores alerts that fail to sync to partition tables, allowing for
     * retry mechanisms and error analysis.
     * 
     * Requirements: 8.3
     */
    public function up(): void
    {
        Schema::connection('pgsql')->create('partition_sync_errors', function (Blueprint $table) {
            $table->id();
            
            // Alert identification
            $table->bigInteger('alert_id')->index();
            $table->date('partition_date')->index();
            $table->string('partition_table', 100)->index();
            
            // Error details
            $table->string('error_type', 100)->index(); // e.g., 'partition_creation_failed', 'insert_failed'
            $table->text('error_message');
            $table->text('error_trace')->nullable();
            $table->integer('error_code')->nullable();
            
            // Retry tracking
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->timestamp('last_retry_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            
            // Status tracking
            $table->string('status', 50)->default('pending'); // pending, retrying, failed, resolved
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            
            // Alert data snapshot (for retry without MySQL lookup)
            $table->jsonb('alert_data');
            
            // Metadata
            $table->bigInteger('sync_batch_id')->nullable()->index();
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['status', 'next_retry_at']);
            $table->index(['partition_date', 'status']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('partition_sync_errors');
    }
};
