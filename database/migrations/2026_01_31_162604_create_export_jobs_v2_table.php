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
        Schema::create('export_jobs_v2', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('type'); // 'all-alerts' or 'vm-alerts'
            $table->date('date');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('filepath')->nullable();
            $table->integer('total_records')->nullable();
            $table->integer('total_count')->nullable(); // For progress calculation
            $table->decimal('progress_percent', 5, 2)->default(0); // Progress percentage
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
            $table->index('job_id');
            
            $table->comment('Redis-based export jobs (V2) - Testing parallel to V1');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_jobs_v2');
    }
};
