<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    /**
     * Run the migrations.
     * Creates alerts table in PostgreSQL mirroring the MySQL alerts structure
     */
    public function up(): void
    {
        Schema::connection($this->connection)->create('alerts', function (Blueprint $table) {
            // ID from MySQL - not auto-generated
            $table->unsignedBigInteger('id')->primary();
            
            // Mirror MySQL alerts table structure - all as strings to handle mixed data
            $table->string('panelid', 50)->nullable();
            $table->string('seqno', 50)->nullable();  // String because MySQL has values like "738R"
            $table->string('zone', 50)->nullable();
            $table->string('alarm', 255)->nullable();
            $table->timestamp('createtime')->nullable();
            $table->timestamp('receivedtime')->nullable();
            $table->text('comment')->nullable();
            $table->string('status', 50)->nullable();
            $table->string('sendtoclient', 50)->nullable();
            $table->string('closedBy', 100)->nullable();
            $table->timestamp('closedtime')->nullable();
            $table->string('sendip', 50)->nullable();
            $table->string('alerttype', 100)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('priority', 50)->nullable();
            $table->string('AlertUserStatus', 50)->nullable();
            $table->string('level', 50)->nullable();
            $table->string('sip2', 100)->nullable();
            $table->string('c_status', 50)->nullable();
            $table->string('auto_alert', 50)->nullable();
            $table->string('critical_alerts', 50)->nullable();
            $table->string('Readstatus', 50)->nullable();
            
            // Sync metadata
            $table->timestamp('synced_at')->useCurrent();
            $table->unsignedBigInteger('sync_batch_id');
            
            // Indexes for reporting queries
            $table->index('panelid');
            $table->index('alerttype');
            $table->index('priority');
            $table->index('createtime');
            $table->index('synced_at');
            $table->index('sync_batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('alerts');
    }
};
