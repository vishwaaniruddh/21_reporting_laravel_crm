<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates a dedicated sync tracking table in PostgreSQL.
 * 
 * This table tracks which records from MySQL have been synced to PostgreSQL
 * WITHOUT modifying the source MySQL tables or adding columns to target tables.
 * 
 * Benefits:
 * - No modification to MySQL source tables
 * - No extra columns in PostgreSQL target tables
 * - Clean separation of sync metadata from actual data
 * - Easy to query sync status and history
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('pgsql')->create('sync_tracking', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('configuration_id');
            $table->string('source_table', 255);
            $table->string('record_id', 255); // Primary key value from source table (as string for flexibility)
            $table->timestamp('synced_at');
            $table->unsignedBigInteger('sync_log_id')->nullable(); // Reference to which sync batch
            
            // Composite unique index to prevent duplicate tracking
            $table->unique(['configuration_id', 'source_table', 'record_id'], 'sync_tracking_unique');
            
            // Index for fast lookups
            $table->index(['configuration_id', 'source_table'], 'sync_tracking_config_table');
            $table->index('synced_at', 'sync_tracking_synced_at');
            
            // Foreign key to configuration (only if table exists)
            // Note: table_sync_configurations is created in a later migration
            // This foreign key will be added after that table exists
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('sync_tracking');
    }
};
