<?php

/**
 * Bidirectional User Sync Script
 * 
 * This script keeps users synchronized between two servers by:
 * 1. Comparing both servers
 * 2. Using the most recently updated record as source of truth
 * 3. Syncing in both directions as needed
 * 
 * Usage:
 *   php sync_users_bidirectional.php              # Dry run
 *   php sync_users_bidirectional.php --execute    # Execute sync
 *   php sync_users_bidirectional.php --force-from=21  # Force sync from .21 to .23
 *   php sync_users_bidirectional.php --force-from=23  # Force sync from .23 to .21
 */

require __DIR__.'/vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Parse arguments
$dryRun = !in_array('--execute', $argv);
$forceFrom = null;

foreach ($argv as $arg) {
    if (strpos($arg, '--force-from=') === 0) {
        $forceFrom = substr($arg, 13);
    }
}

// Database connections
$server21 = [
    'host' => '192.168.100.21',
    'port' => '5432',
    'database' => 'reporting_app',
    'username' => 'postgres',
    'password' => 'root',
];

$server23 = [
    'host' => '192.168.100.23',
    'port' => '5432',
    'database' => 'reporting_app',
    'username' => 'postgres',
    'password' => 'root',
];

echo "===========================================\n";
echo "Bidirectional User Sync\n";
echo "===========================================\n";
echo "Server 21: {$server21['host']}\n";
echo "Server 23: {$server23['host']}\n";
echo "Mode: " . ($dryRun ? "DRY RUN" : "EXECUTE") . "\n";
if ($forceFrom) {
    echo "Force Direction: FROM .{$forceFrom} TO ." . ($forceFrom == '21' ? '23' : '21') . "\n";
}
echo "===========================================\n\n";

// Configure connections
config(['database.connections.server21' => array_merge([
    'driver' => 'pgsql',
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => 'prefer',
], $server21)]);

config(['database.connections.server23' => array_merge([
    'driver' => 'pgsql',
    'charset' => 'utf8',
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => 'public',
    'sslmode' => 'prefer',
], $server23)]);

try {
    // Test connections
    echo "Testing connections...\n";
    DB::connection('server21')->getPdo();
    echo "✓ Server .21 connected\n";
    
    DB::connection('server23')->getPdo();
    echo "✓ Server .23 connected\n\n";
    
    // Fetch users from both servers
    echo "Fetching users...\n";
    $users21 = DB::connection('server21')
        ->table('users')
        ->get()
        ->keyBy('id');
    echo "Server .21: {$users21->count()} users\n";
    
    $users23 = DB::connection('server23')
        ->table('users')
        ->get()
        ->keyBy('id');
    echo "Server .23: {$users23->count()} users\n\n";
    
    // Analyze sync needs
    $syncTo23 = []; // Users to sync from 21 to 23
    $syncTo21 = []; // Users to sync from 23 to 21
    $identical = 0;
    
    // Get all unique user IDs
    $allIds = $users21->keys()->merge($users23->keys())->unique();
    
    foreach ($allIds as $id) {
        $user21 = $users21->get($id);
        $user23 = $users23->get($id);
        
        if (!$user21 && $user23) {
            // User only exists on 23
            if (!$forceFrom || $forceFrom == '23') {
                $syncTo21[] = ['action' => 'insert', 'user' => $user23];
            }
        } elseif ($user21 && !$user23) {
            // User only exists on 21
            if (!$forceFrom || $forceFrom == '21') {
                $syncTo23[] = ['action' => 'insert', 'user' => $user21];
            }
        } else {
            // User exists on both - compare
            if ($forceFrom == '21') {
                // Force from 21 to 23
                if (!usersIdentical($user21, $user23)) {
                    $syncTo23[] = ['action' => 'update', 'user' => $user21];
                } else {
                    $identical++;
                }
            } elseif ($forceFrom == '23') {
                // Force from 23 to 21
                if (!usersIdentical($user21, $user23)) {
                    $syncTo21[] = ['action' => 'update', 'user' => $user23];
                } else {
                    $identical++;
                }
            } else {
                // Smart sync - use most recent
                if (!usersIdentical($user21, $user23)) {
                    $updated21 = Carbon::parse($user21->updated_at ?? $user21->created_at);
                    $updated23 = Carbon::parse($user23->updated_at ?? $user23->created_at);
                    
                    if ($updated21->gt($updated23)) {
                        $syncTo23[] = ['action' => 'update', 'user' => $user21, 'reason' => 'newer'];
                    } else {
                        $syncTo21[] = ['action' => 'update', 'user' => $user23, 'reason' => 'newer'];
                    }
                } else {
                    $identical++;
                }
            }
        }
    }
    
    // Display summary
    echo "===========================================\n";
    echo "SYNC ANALYSIS\n";
    echo "===========================================\n";
    echo "Sync .21 → .23: " . count($syncTo23) . " users\n";
    echo "Sync .23 → .21: " . count($syncTo21) . " users\n";
    echo "Identical: {$identical} users\n";
    echo "===========================================\n\n";
    
    // Show details
    if (count($syncTo23) > 0) {
        echo "SYNC FROM .21 TO .23:\n";
        echo "-------------------------------------------\n";
        foreach ($syncTo23 as $sync) {
            $user = $sync['user'];
            $action = strtoupper($sync['action']);
            $reason = isset($sync['reason']) ? " ({$sync['reason']})" : "";
            echo "{$action}: ID {$user->id} - {$user->name} ({$user->email}){$reason}\n";
        }
        echo "\n";
    }
    
    if (count($syncTo21) > 0) {
        echo "SYNC FROM .23 TO .21:\n";
        echo "-------------------------------------------\n";
        foreach ($syncTo21 as $sync) {
            $user = $sync['user'];
            $action = strtoupper($sync['action']);
            $reason = isset($sync['reason']) ? " ({$sync['reason']})" : "";
            echo "{$action}: ID {$user->id} - {$user->name} ({$user->email}){$reason}\n";
        }
        echo "\n";
    }
    
    // Execute if not dry run
    if (!$dryRun && (count($syncTo23) > 0 || count($syncTo21) > 0)) {
        echo "===========================================\n";
        echo "EXECUTING SYNC...\n";
        echo "===========================================\n\n";
        
        // Sync to 23
        if (count($syncTo23) > 0) {
            echo "Syncing to Server .23...\n";
            DB::connection('server23')->beginTransaction();
            try {
                foreach ($syncTo23 as $sync) {
                    $user = $sync['user'];
                    $userData = (array) $user;
                    
                    if ($sync['action'] == 'insert') {
                        DB::connection('server23')->table('users')->insert($userData);
                        echo "  ✓ Inserted ID {$user->id}: {$user->name}\n";
                    } else {
                        $id = $userData['id'];
                        unset($userData['id']);
                        DB::connection('server23')->table('users')->where('id', $id)->update($userData);
                        echo "  ✓ Updated ID {$user->id}: {$user->name}\n";
                    }
                }
                DB::connection('server23')->commit();
                echo "✓ Server .23 sync complete\n\n";
            } catch (\Exception $e) {
                DB::connection('server23')->rollBack();
                throw $e;
            }
        }
        
        // Sync to 21
        if (count($syncTo21) > 0) {
            echo "Syncing to Server .21...\n";
            DB::connection('server21')->beginTransaction();
            try {
                foreach ($syncTo21 as $sync) {
                    $user = $sync['user'];
                    $userData = (array) $user;
                    
                    if ($sync['action'] == 'insert') {
                        DB::connection('server21')->table('users')->insert($userData);
                        echo "  ✓ Inserted ID {$user->id}: {$user->name}\n";
                    } else {
                        $id = $userData['id'];
                        unset($userData['id']);
                        DB::connection('server21')->table('users')->where('id', $id)->update($userData);
                        echo "  ✓ Updated ID {$user->id}: {$user->name}\n";
                    }
                }
                DB::connection('server21')->commit();
                echo "✓ Server .21 sync complete\n\n";
            } catch (\Exception $e) {
                DB::connection('server21')->rollBack();
                throw $e;
            }
        }
        
        // Sync user_roles
        echo "Syncing user roles...\n";
        syncUserRoles('server21', 'server23', $syncTo23);
        syncUserRoles('server23', 'server21', $syncTo21);
        echo "✓ Roles synced\n\n";
        
        echo "===========================================\n";
        echo "✓ SYNC COMPLETED\n";
        echo "===========================================\n";
    } else {
        echo "===========================================\n";
        echo "DRY RUN COMPLETE\n";
        echo "===========================================\n";
        echo "Run with --execute to apply changes\n";
    }
    
} catch (\Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

function usersIdentical($user1, $user2) {
    $fields = ['name', 'email', 'password', 'is_active', 'contact', 'profile_image', 'bio', 'dob', 'gender'];
    foreach ($fields as $field) {
        if (($user1->$field ?? null) !== ($user2->$field ?? null)) {
            return false;
        }
    }
    return true;
}

function syncUserRoles($sourceConn, $targetConn, $syncedUsers) {
    if (empty($syncedUsers)) return;
    
    $userIds = array_map(function($sync) { return $sync['user']->id; }, $syncedUsers);
    
    $sourceRoles = DB::connection($sourceConn)
        ->table('user_roles')
        ->whereIn('user_id', $userIds)
        ->get();
    
    $targetRoles = DB::connection($targetConn)
        ->table('user_roles')
        ->whereIn('user_id', $userIds)
        ->get()
        ->map(function($role) {
            return "{$role->user_id}_{$role->role_id}";
        })
        ->toArray();
    
    foreach ($sourceRoles as $role) {
        $key = "{$role->user_id}_{$role->role_id}";
        if (!in_array($key, $targetRoles)) {
            DB::connection($targetConn)->table('user_roles')->insert((array) $role);
        }
    }
}

