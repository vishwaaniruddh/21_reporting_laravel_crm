<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    /**
     * Run the migrations.
     * Creates cleanup_batches table in PostgreSQL for storing individual batch operation details.
     * 
     * Requirements: 5.4, 5.5
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('cleanup_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cleanup_log_id')
                ->constrained('cleanup_logs')
                ->onDelete('cascade');
            $table->integer('batch_number');
            $table->integer('records_identified');
            $table->integer('records_verified');
            $table->integer('records_deleted');
            $table->integer('records_skipped');
            $table->json('skipped_record_ids')->nullable();
            $table->text('skip_reason')->nullable();
            $table->timestamp('processed_at');
            $table->integer('duration_ms');
            $table->timestamps();
            
            // Indexes for common queries
            $table->index('cleanup_log_id');
            $table->index('processed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('cleanup_batches');
    }
};
