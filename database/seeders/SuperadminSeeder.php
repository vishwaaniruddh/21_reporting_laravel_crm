<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperadminSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create the initial superadmin user
        $superadmin = User::updateOrCreate(
            ['email' => 'superadmin@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
                'created_by' => null,
            ]
        );

        // Get the superadmin role
        $superadminRole = Role::where('name', 'superadmin')->first();

        if ($superadminRole) {
            // Assign the superadmin role if not already assigned
            if (!$superadmin->roles()->where('role_id', $superadminRole->id)->exists()) {
                $superadmin->roles()->attach($superadminRole->id, [
                    'assigned_by' => null,
                    'created_at' => now(),
                ]);
            }
        }
    }
}
