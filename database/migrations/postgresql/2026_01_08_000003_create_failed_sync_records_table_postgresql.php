<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration to create the failed_sync_records table in PostgreSQL.
 * 
 * This table serves as an error queue for records that repeatedly fail to sync.
 * It stores the original alert data along with error information for admin review.
 * 
 * Requirements: 7.5
 */
return new class extends Migration
{
    /**
     * The database connection that should be used by the migration.
     */
    protected $connection = 'pgsql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('pgsql')->create('failed_sync_records', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('alert_id')->index();
            $table->bigInteger('batch_id')->nullable()->index();
            
            // Store the original alert data as JSON for preservation
            $table->jsonb('alert_data');
            
            // Error tracking
            $table->string('error_message', 1000);
            $table->integer('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            
            // Status for admin management
            $table->string('status', 20)->default('pending')->index();
            // Status values: pending, retrying, resolved, ignored
            
            // Admin notes
            $table->text('admin_notes')->nullable();
            $table->bigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            
            $table->timestamps();
            
            // Unique constraint to prevent duplicate entries for same alert
            $table->unique(['alert_id', 'batch_id']);
        });

        // Add index for finding records to retry
        Schema::connection('pgsql')->table('failed_sync_records', function (Blueprint $table) {
            $table->index(['status', 'retry_count']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('failed_sync_records');
    }
};
