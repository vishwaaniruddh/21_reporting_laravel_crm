<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== ROLES ===\n\n";

$roles = DB::connection('pgsql')->table('roles')->get();
foreach ($roles as $role) {
    echo "• {$role->display_name} ({$role->name})\n";
    echo "  {$role->description}\n\n";
}

echo "\n=== PERMISSIONS BY MODULE ===\n\n";

$permissions = DB::connection('pgsql')->table('permissions')->orderBy('module')->orderBy('name')->get();
$grouped = [];

foreach ($permissions as $perm) {
    if (!isset($grouped[$perm->module])) {
        $grouped[$perm->module] = [];
    }
    $grouped[$perm->module][] = $perm;
}

foreach ($grouped as $module => $perms) {
    echo strtoupper($module) . ":\n";
    foreach ($perms as $perm) {
        echo "  • {$perm->display_name} ({$perm->name})\n";
    }
    echo "\n";
}

echo "\n=== ROLE-PERMISSION ASSIGNMENTS ===\n\n";

foreach ($roles as $role) {
    $assignedPerms = DB::connection('pgsql')
        ->table('role_permissions')
        ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
        ->where('role_permissions.role_id', $role->id)
        ->orderBy('permissions.module')
        ->orderBy('permissions.name')
        ->get(['permissions.name', 'permissions.module']);
    
    echo "{$role->display_name} ({$role->name}):\n";
    if ($assignedPerms->isEmpty()) {
        echo "  No permissions assigned\n";
    } else {
        $currentModule = null;
        foreach ($assignedPerms as $perm) {
            if ($currentModule !== $perm->module) {
                $currentModule = $perm->module;
                echo "  [{$perm->module}]\n";
            }
            echo "    • {$perm->name}\n";
        }
    }
    echo "\n";
}

echo "=== SUMMARY ===\n";
echo "Total Roles: " . count($roles) . "\n";
echo "Total Permissions: " . count($permissions) . "\n";
echo "Total Assignments: " . DB::connection('pgsql')->table('role_permissions')->count() . "\n";
