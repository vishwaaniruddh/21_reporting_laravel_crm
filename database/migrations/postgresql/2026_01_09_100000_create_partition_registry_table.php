<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('partition_registry', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 100)->unique();
            $table->date('partition_date');
            $table->bigInteger('record_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('last_synced_at')->nullable();
            
            // Indexes
            $table->index('partition_date');
            $table->index('table_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('partition_registry');
    }
};
