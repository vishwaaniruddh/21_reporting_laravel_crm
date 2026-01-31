<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Exception;

class TestMySQLConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:test-mysql';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test MySQL database connection';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        try {
            // Test MySQL connection
            DB::connection('mysql')->getPdo();
            $this->info('✅ MySQL connection successful!');
            
            // Test basic query
            $result = DB::connection('mysql')->select('SELECT 1 as test');
            if ($result && $result[0]->test == 1) {
                $this->info('✅ MySQL query test successful!');
            }
            
            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error('❌ MySQL connection failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
