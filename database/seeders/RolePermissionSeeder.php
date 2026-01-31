<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Define role-permission mappings
        $rolePermissions = [
            'superadmin' => [
                // Superadmin gets all permissions
                'users.create',
                'users.read',
                'users.update',
                'users.delete',
                'roles.read',
                'permissions.read',
                'permissions.assign',
                'dashboard.view',
                'reports.view',
                'table-sync.view',
                'table-sync.manage',
                'partitions.view',
                'partitions.manage',
                'sites.view',
                'sites.rms',
                'sites.dvr',
                'sites.cloud',
                'sites.gps',
                'services.manage',
            ],
            'admin' => [
                // Admin gets limited permissions (can manage managers only)
                'users.create',
                'users.read',
                'users.update',
                'users.delete',
                'roles.read',
                'permissions.read',
                'permissions.assign',
                'dashboard.view',
                'reports.view',
                'table-sync.view',
                'table-sync.manage',
                'partitions.view',
                'partitions.manage',
            ],
            'manager' => [
                // Manager can only view dashboard and reports
                'dashboard.view',
                'reports.view',
            ],
            'team_leader' => [
                // Team Leader can view dashboard and reports
                'dashboard.view',
                'reports.view',
            ],
            'surveillance_team' => [
                // Surveillance Team can view dashboard and reports
                'dashboard.view',
                'reports.view',
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->first();

            if (!$role) {
                continue;
            }

            $permissionIds = Permission::whereIn('name', $permissionNames)
                ->pluck('id')
                ->toArray();

            // Sync permissions (removes old ones and adds new ones)
            $role->permissions()->sync($permissionIds);
        }
    }
}
