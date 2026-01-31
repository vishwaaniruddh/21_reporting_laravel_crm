<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'superadmin',
                'display_name' => 'Super Administrator',
                'description' => 'Full system access with ability to manage all users and roles',
            ],
            [
                'name' => 'admin',
                'display_name' => 'Administrator',
                'description' => 'Elevated privileges to manage Managers and assign permissions',
            ],
            [
                'name' => 'manager',
                'display_name' => 'Manager',
                'description' => 'Limited privileges assigned by Admins',
            ],
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

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                $role
            );
        }
    }
}
