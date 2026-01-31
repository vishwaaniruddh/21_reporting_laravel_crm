<?php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== SUPERADMIN USER VERIFICATION ===\n\n";

$user = DB::connection('pgsql')->table('users')->where('email', 'superadmin@gmail.com')->first();

if ($user) {
    echo "✅ User Created Successfully!\n\n";
    echo "ID: {$user->id}\n";
    echo "Name: {$user->name}\n";
    echo "Email: {$user->email}\n";
    echo "Active: " . ($user->is_active ? 'Yes' : 'No') . "\n";
    echo "Created: {$user->created_at}\n\n";
    
    // Check assigned roles
    $roles = DB::connection('pgsql')
        ->table('user_roles')
        ->join('roles', 'user_roles.role_id', '=', 'roles.id')
        ->where('user_roles.user_id', $user->id)
        ->get(['roles.name', 'roles.display_name']);
    
    echo "=== ASSIGNED ROLES ===\n";
    if ($roles->isEmpty()) {
        echo "⚠️ No roles assigned\n";
    } else {
        foreach ($roles as $role) {
            echo "✅ {$role->display_name} ({$role->name})\n";
        }
    }
    
    echo "\n=== LOGIN CREDENTIALS ===\n";
    echo "Email: superadmin@gmail.com\n";
    echo "Password: password\n";
    
} else {
    echo "❌ User not found!\n";
}
