<?php

/**
 * User Sync Script - Sync users between two servers
 * 
 * This script synchronizes the users table between two PostgreSQL servers:
 * - Source: 192.168.100.21:9000 (16 users)
 * - Target: 192.168.100.23:9000 (12 users)
 * 
 * Features:
 * - Syncs users table
 * - Syncs user_roles pivot table
 * - Handles inserts and updates
 * - Preserves passwords and relationships
 * - Dry-run mode for testing
 * 
 * Usage:
 *   php sync_users_between_servers.php          # Dry run (preview only)
 *   php sync_users_between_servers.php --execute # Actually sync
 */

require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\DB;

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Configuration
$dryRun = !in_array('--execute', $argv);

// Database connections
$sourceDb = [
    'host' => '192.168.100.21',
    'port' => '5432',
    'database' => 'reporting_app',
    'username' => 'postgres',
    'password' => 'root',
];

$targetDb = [
    'host' => '192.168.100.23',
    'port' => '5432',
    'database' => 'reporting_app',
    'username' => 'postgres',
    'password' => 'root',
];

echo "===========================================\n";
echo "User Sync Script\n";
echo "===========================================\n";
echo "Source: {$sourceDb['host']} ({$sourceDb['database']})\n";
echo "Target: {$targetDb['host']} ({$targetDb['database']})\n";
echo "Mode: " . ($dryRun ? "DRY RUN (preview only)" : "EXECUTE (will make changes)") . "\n";
echo "===========================================\n\n";

// Configure database connections
config(['database.connections.source_server' => array_merge([
    'driver' => 'pgsql',
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => 'prefer',
], $sourceDb)]);

config(['database.connections.target_server' => array_merge([
    'driver' => 'pgsql',
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => 'prefer',
], $targetDb)]);

try {
    // Test connections
    echo "Testing connections...\n";
    DB::connection('source_server')->getPdo();
    echo "✓ Source server connected\n";
    
    DB::connection('target_server')->getPdo();
    echo "✓ Target server connected\n\n";
    
    // Fetch users from source
    echo "Fetching users from source server...\n";
    $sourceUsers = DB::connection('source_server')
        ->table('users')
        ->orderBy('id')
        ->get();
    
    echo "Found {$sourceUsers->count()} users on source server\n\n";
    
    // Fetch users from target
    echo "Fetching users from target server...\n";
    $targetUsers = DB::connection('target_server')
        ->table('users')
        ->orderBy('id')
        ->get()
        ->keyBy('id');
    
    echo "Found {$targetUsers->count()} users on target server\n\n";
    
    // Analyze differences
    $toInsert = [];
    $toUpdate = [];
    $unchanged = 0;
    
    foreach ($sourceUsers as $sourceUser) {
        if (!isset($targetUsers[$sourceUser->id])) {
            // User doesn't exist on target - needs insert
            $toInsert[] = $sourceUser;
        } else {
            // User exists - check if update needed
            $targetUser = $targetUsers[$sourceUser->id];
            
            // Compare key fields
            $needsUpdate = false;
            $changes = [];
            
            if ($sourceUser->name !== $targetUser->name) {
                $needsUpdate = true;
                $changes[] = "name: '{$targetUser->name}' → '{$sourceUser->name}'";
            }
            if ($sourceUser->email !== $targetUser->email) {
                $needsUpdate = true;
                $changes[] = "email: '{$targetUser->email}' → '{$sourceUser->email}'";
            }
            if ($sourceUser->password !== $targetUser->password) {
                $needsUpdate = true;
                $changes[] = "password: [changed]";
            }
            if (($sourceUser->is_active ?? true) !== ($targetUser->is_active ?? true)) {
                $needsUpdate = true;
                $changes[] = "is_active: " . ($targetUser->is_active ? 'true' : 'false') . " → " . ($sourceUser->is_active ? 'true' : 'false');
            }
            if (($sourceUser->contact ?? null) !== ($targetUser->contact ?? null)) {
                $needsUpdate = true;
                $changes[] = "contact: '{$targetUser->contact}' → '{$sourceUser->contact}'";
            }
            if (($sourceUser->profile_image ?? null) !== ($targetUser->profile_image ?? null)) {
                $needsUpdate = true;
                $changes[] = "profile_image: [changed]";
            }
            
            if ($needsUpdate) {
                $toUpdate[] = [
                    'user' => $sourceUser,
                    'changes' => $changes
                ];
            } else {
                $unchanged++;
            }
        }
    }
    
    // Display summary
    echo "===========================================\n";
    echo "SYNC SUMMARY\n";
    echo "===========================================\n";
    echo "Users to INSERT: " . count($toInsert) . "\n";
    echo "Users to UPDATE: " . count($toUpdate) . "\n";
    echo "Users UNCHANGED: {$unchanged}\n";
    echo "===========================================\n\n";
    
    // Show details of changes
    if (count($toInsert) > 0) {
        echo "USERS TO INSERT:\n";
        echo "-------------------------------------------\n";
        foreach ($toInsert as $user) {
            echo "ID {$user->id}: {$user->name} ({$user->email})\n";
        }
        echo "\n";
    }
    
    if (count($toUpdate) > 0) {
        echo "USERS TO UPDATE:\n";
        echo "-------------------------------------------\n";
        foreach ($toUpdate as $update) {
            $user = $update['user'];
            echo "ID {$user->id}: {$user->name} ({$user->email})\n";
            foreach ($update['changes'] as $change) {
                echo "  - {$change}\n";
            }
        }
        echo "\n";
    }
    
    // Execute sync if not dry run
    if (!$dryRun) {
        echo "===========================================\n";
        echo "EXECUTING SYNC...\n";
        echo "===========================================\n\n";
        
        DB::connection('target_server')->beginTransaction();
        
        try {
            // Insert new users
            if (count($toInsert) > 0) {
                echo "Inserting " . count($toInsert) . " new users...\n";
                foreach ($toInsert as $user) {
                    $userData = (array) $user;
                    DB::connection('target_server')
                        ->table('users')
                        ->insert($userData);
                    echo "  ✓ Inserted user ID {$user->id}: {$user->name}\n";
                }
                echo "\n";
            }
            
            // Update existing users
            if (count($toUpdate) > 0) {
                echo "Updating " . count($toUpdate) . " users...\n";
                foreach ($toUpdate as $update) {
                    $user = $update['user'];
                    $userData = (array) $user;
                    unset($userData['id']); // Don't update ID
                    
                    DB::connection('target_server')
                        ->table('users')
                        ->where('id', $user->id)
                        ->update($userData);
                    echo "  ✓ Updated user ID {$user->id}: {$user->name}\n";
                }
                echo "\n";
            }
            
            // Sync user_roles pivot table
            echo "Syncing user roles...\n";
            
            // Get all user_roles from source
            $sourceRoles = DB::connection('source_server')
                ->table('user_roles')
                ->get();
            
            // Get all user_roles from target
            $targetRoles = DB::connection('target_server')
                ->table('user_roles')
                ->get()
                ->map(function($role) {
                    return "{$role->user_id}_{$role->role_id}";
                })
                ->toArray();
            
            $rolesInserted = 0;
            foreach ($sourceRoles as $role) {
                $key = "{$role->user_id}_{$role->role_id}";
                if (!in_array($key, $targetRoles)) {
                    DB::connection('target_server')
                        ->table('user_roles')
                        ->insert((array) $role);
                    $rolesInserted++;
                }
            }
            
            echo "  ✓ Inserted {$rolesInserted} role assignments\n\n";
            
            DB::connection('target_server')->commit();
            
            echo "===========================================\n";
            echo "✓ SYNC COMPLETED SUCCESSFULLY\n";
            echo "===========================================\n";
            
        } catch (\Exception $e) {
            DB::connection('target_server')->rollBack();
            throw $e;
        }
        
    } else {
        echo "===========================================\n";
        echo "DRY RUN COMPLETE\n";
        echo "===========================================\n";
        echo "No changes were made to the target server.\n";
        echo "Run with --execute flag to apply changes:\n";
        echo "  php sync_users_between_servers.php --execute\n";
        echo "===========================================\n";
    }
    
} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

