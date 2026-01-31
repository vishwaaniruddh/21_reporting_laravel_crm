<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // User module permissions
            [
                'name' => 'users.create',
                'display_name' => 'Create Users',
                'module' => 'users',
            ],
            [
                'name' => 'users.read',
                'display_name' => 'View Users',
                'module' => 'users',
            ],
            [
                'name' => 'users.update',
                'display_name' => 'Update Users',
                'module' => 'users',
            ],
            [
                'name' => 'users.delete',
                'display_name' => 'Delete Users',
                'module' => 'users',
            ],
            // Role module permissions
            [
                'name' => 'roles.read',
                'display_name' => 'View Roles',
                'module' => 'roles',
            ],
            // Permission module permissions
            [
                'name' => 'permissions.read',
                'display_name' => 'View Permissions',
                'module' => 'permissions',
            ],
            [
                'name' => 'permissions.assign',
                'display_name' => 'Assign Permissions',
                'module' => 'permissions',
            ],
            // Dashboard module permissions
            [
                'name' => 'dashboard.view',
                'display_name' => 'View Dashboard',
                'module' => 'dashboard',
            ],
            // Reports module permissions
            [
                'name' => 'reports.view',
                'display_name' => 'View Reports',
                'module' => 'reports',
            ],
            // Table Sync module permissions
            [
                'name' => 'table-sync.view',
                'display_name' => 'View Table Sync',
                'module' => 'table-sync',
            ],
            [
                'name' => 'table-sync.manage',
                'display_name' => 'Manage Table Sync',
                'module' => 'table-sync',
            ],
            // Partitions module permissions
            [
                'name' => 'partitions.view',
                'display_name' => 'View Partitions',
                'module' => 'partitions',
            ],
            [
                'name' => 'partitions.manage',
                'display_name' => 'Manage Partitions',
                'module' => 'partitions',
            ],
            // Sites module permissions
            [
                'name' => 'sites.view',
                'display_name' => 'View Sites',
                'module' => 'sites',
            ],
            [
                'name' => 'sites.rms',
                'display_name' => 'Access RMS',
                'module' => 'sites',
            ],
            [
                'name' => 'sites.dvr',
                'display_name' => 'Access DVR',
                'module' => 'sites',
            ],
            [
                'name' => 'sites.cloud',
                'display_name' => 'Access Cloud',
                'module' => 'sites',
            ],
            [
                'name' => 'sites.gps',
                'display_name' => 'Access GPS',
                'module' => 'sites',
            ],
            // Services module permissions
            [
                'name' => 'services.manage',
                'display_name' => 'Manage Services',
                'module' => 'services',
            ],
        ];

        foreach ($permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name']],
                $permission
            );
        }
    }
}
