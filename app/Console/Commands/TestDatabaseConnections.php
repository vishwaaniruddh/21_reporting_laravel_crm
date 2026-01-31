<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Exception;

class TestDatabaseConnections extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:test-connections';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test database connections for MySQL and PostgreSQL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Testing database connections...');
        
        // Test MySQL connection
        $this->testConnection('mysql', 'MySQL');
        
        // Test PostgreSQL connection
        $this->testConnection('pgsql', 'PostgreSQL');
    }
    
    private function testConnection($connection, $name)
    {
        try {
            DB::connection($connection)->getPdo();
            $this->info("✓ {$name} connection successful");
        } catch (Exception $e) {
            $this->error("✗ {$name} connection failed: " . $e->getMessage());
        }
    }
}
