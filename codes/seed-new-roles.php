<?php

/**
 * Seed New Roles: Team Leader and Surveillance Team
 * 
 * This script adds two new roles with dashboard and reports access
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Role;
use App\Models\Permission;

echo "===========================================\n";
echo "Seeding New Roles\n";
echo "===========================================\n\n";

// Define new roles
$newRoles = [
    [
        'name' => 'team_leader',
        'display_name' => 'Team Leader',
        'description' => 'Access to Dashboards and Reports',
    ],
    [
        'name' => 'surveillance_team',
        'display_name' => 'Surveillance Team',
        'description' => 'Access to Dashboards and Reports',
    ],
];

// Create or update roles
foreach ($newRoles as $roleData) {
    $role = Role::updateOrCreate(
        ['name' => $roleData['name']],
        $roleData
    );
    
    echo "✓ Role created/updated: {$role->display_name} ({$role->name})\n";
}

echo "\n";

// Assign permissions to new roles
$permissions = ['dashboard.view', 'reports.view'];

foreach ($newRoles as $roleData) {
    $role = Role::where('name', $roleData['name'])->first();
    
    if (!$role) {
        echo "✗ Role not found: {$roleData['name']}\n";
        continue;
    }
    
    $permissionIds = Permission::whereIn('name', $permissions)
        ->pluck('id')
        ->toArray();
    
    if (empty($permissionIds)) {
        echo "✗ Permissions not found for role: {$role->display_name}\n";
        continue;
    }
    
    // Sync permissions
    $role->permissions()->sync($permissionIds);
    
    echo "✓ Permissions assigned to {$role->display_name}:\n";
    foreach ($permissions as $permission) {
        echo "  - {$permission}\n";
    }
}

echo "\n===========================================\n";
echo "Summary\n";
echo "===========================================\n";

// Display all roles with their permissions
$allRoles = Role::with('permissions')->get();

foreach ($allRoles as $role) {
    echo "\n{$role->display_name} ({$role->name}):\n";
    echo "  Description: {$role->description}\n";
    echo "  Permissions: " . $role->permissions->pluck('name')->implode(', ') . "\n";
}

echo "\n===========================================\n";
echo "Done!\n";
echo "===========================================\n";
