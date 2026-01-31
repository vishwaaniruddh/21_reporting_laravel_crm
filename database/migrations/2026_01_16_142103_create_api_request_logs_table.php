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
        Schema::connection('pgsql')->create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 255);
            $table->string('method', 10);
            $table->integer('status_code');
            $table->decimal('response_time', 10, 2)->nullable(); // in milliseconds
            $table->string('ip_address', 45)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes for faster queries
            $table->index('created_at');
            $table->index('endpoint');
            $table->index('status_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('api_request_logs');
    }
};
