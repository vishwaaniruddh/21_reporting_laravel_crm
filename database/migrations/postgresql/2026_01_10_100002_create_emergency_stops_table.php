<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    /**
     * Run the migrations.
     * Creates emergency_stops table in PostgreSQL for storing emergency stop flags.
     * 
     * Requirements: 11.1, 11.2, 11.3, 11.5
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('emergency_stops', function (Blueprint $table) {
            $table->id();
            $table->string('service_name')->unique(); // 'age_based_cleanup'
            $table->boolean('is_stopped')->default(false);
            $table->text('reason')->nullable();
            $table->string('stopped_by')->nullable(); // Admin user or system
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
            
            // Index for quick flag checks
            $table->index(['service_name', 'is_stopped']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('emergency_stops');
    }
};
