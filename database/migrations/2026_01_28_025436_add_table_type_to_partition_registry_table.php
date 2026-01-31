<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('pgsql')->table('partition_registry', function (Blueprint $table) {
            $table->string('table_type', 20)->default('alerts')->after('table_name');
            $table->index(['table_type', 'partition_date']);
        });
        
        // Update existing records to have table_type = 'alerts'
        DB::connection('pgsql')->table('partition_registry')
            ->whereNull('table_type')
            ->orWhere('table_type', '')
            ->update(['table_type' => 'alerts']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pgsql')->table('partition_registry', function (Blueprint $table) {
            $table->dropIndex(['table_type', 'partition_date']);
            $table->dropColumn('table_type');
        });
    }
};
