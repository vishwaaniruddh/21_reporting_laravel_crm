<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('alert_pg_update_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('alert_id');
            $table->tinyInteger('status')->default(1)->comment('1=pending, 2=completed, 3=failed');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            
            // Indexes
            $table->index(['status', 'created_at'], 'idx_status_created');
            $table->index('alert_id', 'idx_alert_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('alert_pg_update_log');
    }
};
