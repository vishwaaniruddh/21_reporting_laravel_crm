<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Tests for Admin Scope Restriction
 * 
 * @feature rbac-user-management
 * @property 8: Admin Scope Restriction
 * **Validates: Requirements 4.1, 4.2, 4.3**
 * 
 * Property: For any Admin user, they should only be able to create/modify/delete Manager users,
 * and should only be able to assign permissions that are within their delegatable set.
 * Attempts to modify Superadmin or Admin accounts should fail.
 */
class AdminScopeRestrictionPropertyTest extends TestCase
{
    protected User $superadmin;
    protected User $admin;
    protected string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        
        Artisan::call('migrate:fresh');
        $this->addRbacSchema();
        $this->seedRolesAndPermissions();
        $this->createUsers();
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

    protected function createUsers(): void
    {
        // Create Superadmin
        $this->superadmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $superadminRole = Role::where('name', 'superadmin')->first();
        $this->superadmin->roles()->attach($superadminRole->id);

        // Create Admin
        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $adminRole = Role::where('name', 'admin')->first();
        $this->admin->roles()->attach($adminRole->id);
        $this->adminToken = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * Data provider for Admin cannot create non-Manager users
     */
    public static function adminCannotCreateNonManagerProvider(): array
    {
        $testCases = [];
        $forbiddenRoles = ['superadmin', 'admin'];

        for ($i = 0; $i < 10; $i++) {
            $role = $forbiddenRoles[$i % 2];
            $testCases["admin_cannot_create_{$role}_$i"] = [
                'name' => "Test User $i",
                'email' => "testuser$i@example.com",
                'role' => $role,
            ];
        }

        return $testCases;
    }

    /**
     * Data provider for Admin can create Manager users
     */
    public static function adminCanCreateManagerProvider(): array
    {
        $testCases = [];

        for ($i = 0; $i < 10; $i++) {
            $testCases["admin_can_create_manager_$i"] = [
                'name' => "Manager User $i",
                'email' => "manager$i@example.com",
            ];
        }

        return $testCases;
    }

    /**
     * @test
     * @dataProvider adminCannotCreateNonManagerProvider
     * 
     * Property 8: Admin Scope Restriction - Cannot create Superadmin/Admin
     * For any Admin user, attempts to create Superadmin or Admin accounts should fail.
     */
    public function admin_cannot_create_superadmin_or_admin_users(
        string $name,
        string $email,
        string $role
    ): void {
        // Act: Admin attempts to create non-Manager user
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/users', [
            'name' => $name,
            'email' => $email,
            'password' => 'SecurePass123!',
            'role' => $role,
        ]);

        // Assert: Creation fails with 403 Forbidden
        $response->assertStatus(403);
        
        // Assert: User was not created
        $this->assertDatabaseMissing('users', ['email' => $email]);
    }

    /**
     * @test
     * @dataProvider adminCanCreateManagerProvider
     * 
     * Property 8: Admin Scope Restriction - Can create Manager
     * For any Admin user, they should be able to create Manager users.
     */
    public function admin_can_create_manager_users(
        string $name,
        string $email
    ): void {
        // Act: Admin creates Manager user
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->postJson('/api/users', [
            'name' => $name,
            'email' => $email,
            'password' => 'SecurePass123!',
            'role' => 'manager',
        ]);

        // Assert: Creation succeeds
        $response->assertStatus(201);
        
        // Assert: User was created with Manager role
        $this->assertDatabaseHas('users', ['email' => $email]);
        $createdUser = User::where('email', $email)->first();
        $this->assertEquals('manager', $createdUser->getPrimaryRole()?->name);
    }

    /**
     * @test
     * 
     * Property 8: Admin Scope Restriction - Cannot modify Superadmin
     */
    public function admin_cannot_modify_superadmin(): void
    {
        // Act: Admin attempts to modify Superadmin
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/users/' . $this->superadmin->id, [
            'name' => 'Modified Name',
        ]);

        // Assert: Modification fails with 403 Forbidden
        $response->assertStatus(403);
        
        // Assert: Superadmin was not modified
        $this->superadmin->refresh();
        $this->assertEquals('Super Admin', $this->superadmin->name);
    }

    /**
     * @test
     * 
     * Property 8: Admin Scope Restriction - Cannot modify other Admin
     */
    public function admin_cannot_modify_other_admin(): void
    {
        // Create another Admin
        $otherAdmin = User::create([
            'name' => 'Other Admin',
            'email' => 'otheradmin@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $adminRole = Role::where('name', 'admin')->first();
        $otherAdmin->roles()->attach($adminRole->id);

        // Act: Admin attempts to modify other Admin
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/users/' . $otherAdmin->id, [
            'name' => 'Modified Name',
        ]);

        // Assert: Modification fails with 403 Forbidden
        $response->assertStatus(403);
        
        // Assert: Other Admin was not modified
        $otherAdmin->refresh();
        $this->assertEquals('Other Admin', $otherAdmin->name);
    }

    /**
     * @test
     * 
     * Property 8: Admin Scope Restriction - Cannot delete Superadmin
     */
    public function admin_cannot_delete_superadmin(): void
    {
        // Act: Admin attempts to delete Superadmin
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson('/api/users/' . $this->superadmin->id);

        // Assert: Deletion fails with 403 Forbidden
        $response->assertStatus(403);
        
        // Assert: Superadmin still exists and is active
        $this->superadmin->refresh();
        $this->assertTrue($this->superadmin->is_active);
    }

    /**
     * @test
     * 
     * Property 8: Admin Scope Restriction - Can modify Manager
     */
    public function admin_can_modify_manager(): void
    {
        // Create a Manager
        $manager = User::create([
            'name' => 'Manager User',
            'email' => 'manager@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $managerRole = Role::where('name', 'manager')->first();
        $manager->roles()->attach($managerRole->id);

        // Act: Admin modifies Manager
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/users/' . $manager->id, [
            'name' => 'Modified Manager',
        ]);

        // Assert: Modification succeeds
        $response->assertStatus(200);
        
        // Assert: Manager was modified
        $manager->refresh();
        $this->assertEquals('Modified Manager', $manager->name);
    }

    /**
     * @test
     * 
     * Property 8: Admin Scope Restriction - Can delete (deactivate) Manager
     */
    public function admin_can_delete_manager(): void
    {
        // Create a Manager
        $manager = User::create([
            'name' => 'Manager User',
            'email' => 'manager_delete@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);
        $managerRole = Role::where('name', 'manager')->first();
        $manager->roles()->attach($managerRole->id);

        // Act: Admin deletes (deactivates) Manager
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->deleteJson('/api/users/' . $manager->id);

        // Assert: Deletion succeeds
        $response->assertStatus(200);
        
        // Assert: Manager was deactivated
        $manager->refresh();
        $this->assertFalse($manager->is_active);
    }
}
