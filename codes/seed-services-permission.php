<?php

/**
 * Add services.manage permission to database
 * Run: php codes/seed-services-permission.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Permission;
use App\Models\Role;

echo "Adding services.manage permission...\n";

// Create the permission
$permission = Permission::updateOrCreate(
    ['name' => 'services.manage'],
    [
        'display_name' => 'Manage Services',
        'module' => 'services',
    ]
);

echo "✓ Permission created: {$permission->name}\n";

// Assign to superadmin role
$superadmin = Role::where('name', 'superadmin')->first();
if ($superadmin) {
    if (!$superadmin->permissions()->where('permission_id', $permission->id)->exists()) {
        $superadmin->permissions()->attach($permission->id);
        echo "✓ Permission assigned to superadmin role\n";
    } else {
        echo "✓ Permission already assigned to superadmin\n";
    }
} else {
    echo "✗ Superadmin role not found\n";
}

echo "\nDone!\n";
