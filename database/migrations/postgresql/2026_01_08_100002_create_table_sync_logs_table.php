<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    /**
     * Run the migrations.
     * Creates table_sync_logs table in PostgreSQL for storing sync operation logs.
     * ⚠️ NO DELETION FROM MYSQL: Logs are stored in PostgreSQL only
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('table_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_id')
                ->constrained('table_sync_configurations')
                ->onDelete('cascade');
            $table->string('source_table', 255);
            $table->integer('records_synced')->default(0);
            $table->integer('records_failed')->default(0);
            $table->unsignedBigInteger('start_id')->nullable();
            $table->unsignedBigInteger('end_id')->nullable();
            $table->string('status', 50);
            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Indexes for common queries
            $table->index('configuration_id');
            $table->index('source_table');
            $table->index('status');
            $table->index('started_at');
            $table->index('completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('table_sync_logs');
    }
};
