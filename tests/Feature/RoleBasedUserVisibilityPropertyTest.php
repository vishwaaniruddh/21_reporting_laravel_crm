<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Tests for Role-Based User Visibility
 * 
 * @feature rbac-user-management
 * @property 9: Role-Based User Visibility
 * **Validates: Requirements 2.4, 4.4**
 * 
 * Property: For any Admin viewing the user list, the response should contain only Manager users
 * (not Superadmins or other Admins). For Superadmins, all users should be visible.
 */
class RoleBasedUserVisibilityPropertyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addRbacSchema();
        $this->seedRolesAndPermissions();
    }

    protected function addRbacSchema(): void
    {
        if (!Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function ($table) {
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
            });
        }

        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name');
                $table->string('module');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function ($table) {
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->primary(['role_id', 'permission_id']);
            });
        }

        if (!Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function ($table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->primary(['user_id', 'role_id']);
            });
        }

        if (!Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function ($table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function seedRolesAndPermissions(): void
    {
        $superadminRole = Role::create(['name' => 'superadmin', 'display_name' => 'Super Admin', 'description' => 'Full access']);
        $adminRole = Role::create(['name' => 'admin', 'display_name' => 'Admin', 'description' => 'Admin access']);
        Role::create(['name' => 'manager', 'display_name' => 'Manager', 'description' => 'Manager access']);

        $permissions = [
            ['name' => 'users.create', 'display_name' => 'Create Users', 'module' => 'users'],
            ['name' => 'users.read', 'display_name' => 'Read Users', 'module' => 'users'],
            ['name' => 'users.update', 'display_name' => 'Update Users', 'module' => 'users'],
            ['name' => 'users.delete', 'display_name' => 'Delete Users', 'module' => 'users'],
        ];

        foreach ($permissions as $perm) {
            $permission = Permission::create($perm);
            $superadminRole->permissions()->attach($permission->id);
            $adminRole->permissions()->attach($permission->id);
        }
    }

    protected function createUserWithRole(string $name, string $email, string $roleName): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $role = Role::where('name', $roleName)->first();
        $user->roles()->attach($role->id);
        return $user;
    }

    /**
     * Data provider for user visibility tests
     */
    public static function userVisibilityDataProvider(): array
    {
        return [
            'one_manager_no_extra_admins' => ['num_managers' => 1, 'num_additional_admins' => 0],
            'two_managers_one_admin' => ['num_managers' => 2, 'num_additional_admins' => 1],
            'three_managers_two_admins' => ['num_managers' => 3, 'num_additional_admins' => 2],
            'five_managers_no_extra_admins' => ['num_managers' => 5, 'num_additional_admins' => 0],
            'four_managers_one_admin' => ['num_managers' => 4, 'num_additional_admins' => 1],
        ];
    }

    /**
     * @test
     * @dataProvider userVisibilityDataProvider
     * 
     * Property 9: Role-Based User Visibility
     * For any Admin viewing the user list, the response should contain only Manager users.
     * For Superadmins, all users should be visible.
     */
    public function role_based_user_visibility_is_enforced(
        int $num_managers,
        int $num_additional_admins
    ): void {
        // Create base users
        $superadmin = $this->createUserWithRole('Super Admin', 'superadmin@example.com', 'superadmin');
        $superadminToken = $superadmin->createToken('test-token')->plainTextToken;
        
        $admin = $this->createUserWithRole('Admin User', 'admin@example.com', 'admin');
        $adminToken = $admin->createToken('test-token')->plainTextToken;

        // Create managers
        for ($i = 0; $i < $num_managers; $i++) {
            $this->createUserWithRole("Manager $i", "manager{$i}_" . uniqid() . "@example.com", 'manager');
        }

        // Create additional admins
        for ($i = 0; $i < $num_additional_admins; $i++) {
            $this->createUserWithRole("Additional Admin $i", "addadmin{$i}_" . uniqid() . "@example.com", 'admin');
        }

        // Test Admin visibility - should only see Managers
        $adminResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $adminToken,
        ])->getJson('/api/users');

        $adminResponse->assertStatus(200);
        $adminVisibleUsers = collect($adminResponse->json('data'));

        // Property: Admin sees ONLY managers (no superadmins, no admins)
        foreach ($adminVisibleUsers as $user) {
            $this->assertEquals('manager', $user['role'], 
                "Admin should only see managers, but saw user with role: {$user['role']}");
        }

        // Property: Admin sees ALL managers
        $this->assertEquals($num_managers, $adminVisibleUsers->count(),
            "Admin should see exactly $num_managers managers");

        // Test Superadmin visibility - should see all users
        $superadminResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $superadminToken,
        ])->getJson('/api/users');

        $superadminResponse->assertStatus(200);
        $superadminVisibleUsers = collect($superadminResponse->json('data'));

        // Property: Superadmin sees at least as many users as Admin (or more)
        $this->assertGreaterThanOrEqual($adminVisibleUsers->count(), $superadminVisibleUsers->count(),
            "Superadmin should see at least as many users as Admin");

        // Property: Superadmin sees at least the managers
        $superadminVisibleManagers = $superadminVisibleUsers->where('role', 'manager')->count();
        $this->assertEquals($num_managers, $superadminVisibleManagers,
            "Superadmin should see all $num_managers managers");
    }
}
