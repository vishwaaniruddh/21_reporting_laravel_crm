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
        Schema::connection('mysql')->table('backalerts', function (Blueprint $table) {
            $table->timestamp('synced_at')->nullable()->after('critical_alerts');
            $table->integer('sync_batch_id')->nullable()->after('synced_at');
            
            // Add indexes for sync queries
            $table->index('synced_at', 'idx_backalerts_synced_at');
            $table->index('sync_batch_id', 'idx_backalerts_sync_batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mysql')->table('backalerts', function (Blueprint $table) {
            $table->dropIndex('idx_backalerts_synced_at');
            $table->dropIndex('idx_backalerts_sync_batch_id');
            $table->dropColumn(['synced_at', 'sync_batch_id']);
        });
    }
};