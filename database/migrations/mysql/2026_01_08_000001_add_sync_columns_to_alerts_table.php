<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    /**
     * Add sync tracking columns to existing alerts table
     */
    public function up(): void
    {
        Schema::connection($this->connection)->table('alerts', function (Blueprint $table) {
            // Sync tracking columns - add at end of table (no 'after' clause)
            $table->timestamp('synced_at')->nullable();
            $table->unsignedBigInteger('sync_batch_id')->nullable();
            
            // Indexes for efficient sync queries
            $table->index('synced_at');
            $table->index('sync_batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->table('alerts', function (Blueprint $table) {
            $table->dropIndex(['synced_at']);
            $table->dropIndex(['sync_batch_id']);
            $table->dropColumn(['synced_at', 'sync_batch_id']);
        });
    }
};
