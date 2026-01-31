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
        Schema::connection('mysql')->create('backalert_pg_update_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('backalert_id');
            $table->tinyInteger('status')->default(1)->comment('1=pending, 2=completed, 3=failed');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            
            // Indexes for performance
            $table->index(['status', 'created_at'], 'idx_status_created');
            $table->index('backalert_id', 'idx_backalert_id');
            $table->index('created_at', 'idx_created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('mysql')->dropIfExists('backalert_pg_update_log');
    }
};