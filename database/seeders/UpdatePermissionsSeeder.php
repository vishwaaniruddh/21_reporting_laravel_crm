<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UpdatePermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $now = Carbon::now();

        // New permissions to add
        $newPermissions = [
            ['name' => 'system.view', 'display_name' => 'View System Health', 'module' => 'System', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'admin.access', 'display_name' => 'Access Administration', 'module' => 'Administration', 'created_at' => $now, 'updated_at' => $now],
        ];

        // Insert permissions if they don't exist
        foreach ($newPermissions as $permission) {
            $exists = DB::table('permissions')->where('name', $permission['name'])->exists();
            if (!$exists) {
                DB::table('permissions')->insert($permission);
                $this->command->info("Added permission: {$permission['name']}");
            } else {
                $this->command->info("Permission already exists: {$permission['name']}");
            }
        }

        // Get superadmin role
        $superadminRole = DB::table('roles')->where('name', 'superadmin')->first();
        
        if ($superadminRole) {
            // Get all permissions
            $allPermissions = DB::table('permissions')->get();
            
            foreach ($allPermissions as $permission) {
                // Check if role already has this permission
                $exists = DB::table('role_permissions')
                    ->where('role_id', $superadminRole->id)
                    ->where('permission_id', $permission->id)
                    ->exists();
                
                if (!$exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $superadminRole->id,
                        'permission_id' => $permission->id,
                    ]);
                    $this->command->info("Assigned permission '{$permission->name}' to superadmin");
                }
            }
            
            $this->command->info("Superadmin role now has all permissions");
        } else {
            $this->command->error("Superadmin role not found!");
        }
    }
}
