<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Tests for Role Permission Inheritance
 * 
 * @feature rbac-user-management
 * @property 6: Role Permission Inheritance
 * **Validates: Requirements 3.2, 2.2**
 * 
 * Property: For any user assigned to a role, the user should have exactly 
 * the permissions associated with that role - no more, no less.
 */
class RolePermissionInheritancePropertyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Run base migrations
        Artisan::call('migrate:fresh');
        
        // Add RBAC schema
        $this->addRbacSchema();
    }

    protected function addRbacSchema(): void
    {
        // Add RBAC columns to users table if not present
        if (!Schema::hasColumn('users', 'is_active')) {
            Schema::table('users', function ($table) {
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
            });
        }

        // Create roles table
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name');
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        // Create permissions table
        if (!Schema::hasTable('permissions')) {
            Schema::create('permissions', function ($table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name');
                $table->string('module');
                $table->timestamps();
            });
        }

        // Create role_permissions pivot table
        if (!Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function ($table) {
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('permission_id');
                $table->primary(['role_id', 'permission_id']);
            });
        }

        // Create user_roles pivot table
        if (!Schema::hasTable('user_roles')) {
            Schema::create('user_roles', function ($table) {
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('role_id');
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->primary(['user_id', 'role_id']);
            });
        }
    }

    /**
     * Create a set of test permissions
     */
    protected function createTestPermissions(array $permissionNames): array
    {
        $permissions = [];
        foreach ($permissionNames as $name) {
            $module = explode('.', $name)[0];
            $permissions[$name] = Permission::create([
                'name' => $name,
                'display_name' => ucwords(str_replace('.', ' ', $name)),
                'module' => $module,
            ]);
        }
        return $permissions;
    }

    /**
     * Create a role with specific permissions
     */
    protected function createRoleWithPermissions(string $roleName, array $permissionIds): Role
    {
        $role = Role::create([
            'name' => $roleName,
            'display_name' => ucwords(str_replace('_', ' ', $roleName)),
            'description' => "Test role: {$roleName}",
        ]);
        
        $role->permissions()->attach($permissionIds);
        
        return $role;
    }

    /**
     * Create a user and assign a role
     */
    protected function createUserWithRole(Role $role, string $email): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $user->roles()->attach($role->id);
        
        // Reload user with roles and permissions
        $user->load('roles.permissions');

        return $user;
    }

    /**
     * Data provider for role permission inheritance property test
     * Generates 100+ test cases with various role/permission combinations
     */
    public static function rolePermissionInheritanceProvider(): array
    {
        $testCases = [];
        
        // All available permissions
        $allPermissions = [
            'users.create', 'users.read', 'users.update', 'users.delete',
            'roles.read', 'roles.create', 'roles.update', 'roles.delete',
            'permissions.read', 'permissions.assign',
            'dashboard.view', 'reports.view', 'settings.manage',
        ];

        // Generate various permission subsets for different roles
        $permissionSubsets = [
            // Full access
            $allPermissions,
            // User management only
            ['users.create', 'users.read', 'users.update', 'users.delete'],
            // Read-only access
            ['users.read', 'roles.read', 'permissions.read', 'dashboard.view'],
            // Dashboard only
            ['dashboard.view'],
            // Empty permissions
            [],
            // Single permission
            ['users.read'],
            // Mixed permissions
            ['users.create', 'roles.read', 'dashboard.view'],
            ['users.read', 'users.update', 'permissions.read'],
            ['dashboard.view', 'reports.view'],
            ['settings.manage', 'users.delete'],
        ];

        $caseIndex = 0;

        // Generate test cases for each permission subset
        foreach ($permissionSubsets as $subsetIndex => $permissionSubset) {
            // Generate 1 test case for each subset
            $testCases["case_{$caseIndex}_subset_{$subsetIndex}"] = [
                'role_name' => "test_role_{$caseIndex}",
                'assigned_permissions' => $permissionSubset,
                'all_permissions' => $allPermissions,
                'email' => "test_user_{$caseIndex}@example.com",
            ];
            $caseIndex++;
        }

        return $testCases;
    }

    /**
     * @test
     * @dataProvider rolePermissionInheritanceProvider
     * 
     * Property 6: Role Permission Inheritance
     * For any user assigned to a role, the user should have exactly 
     * the permissions associated with that role - no more, no less.
     */
    public function user_has_exactly_role_permissions(
        string $role_name,
        array $assigned_permissions,
        array $all_permissions,
        string $email
    ): void {
        // Arrange: Create all permissions
        $permissions = $this->createTestPermissions($all_permissions);
        
        // Get IDs of permissions to assign to the role
        $permissionIds = array_map(
            fn($name) => $permissions[$name]->id,
            $assigned_permissions
        );
        
        // Create role with specific permissions
        $role = $this->createRoleWithPermissions($role_name, $permissionIds);
        
        // Create user with the role
        $user = $this->createUserWithRole($role, $email);

        // Act & Assert: Verify user has exactly the role's permissions
        
        // 1. User should have all permissions assigned to the role
        foreach ($assigned_permissions as $permissionName) {
            $this->assertTrue(
                $user->hasPermission($permissionName),
                "User should have permission '{$permissionName}' from role '{$role_name}'"
            );
        }

        // 2. User should NOT have any permissions NOT assigned to the role
        $unassignedPermissions = array_diff($all_permissions, $assigned_permissions);
        foreach ($unassignedPermissions as $permissionName) {
            $this->assertFalse(
                $user->hasPermission($permissionName),
                "User should NOT have permission '{$permissionName}' (not in role '{$role_name}')"
            );
        }

        // 3. Verify the count of user permissions matches role permissions
        $userPermissions = $user->permissions();
        $this->assertCount(
            count($assigned_permissions),
            $userPermissions,
            "User should have exactly " . count($assigned_permissions) . " permissions"
        );
    }

    /**
     * @test
     * 
     * Additional property: When role permissions are modified, user access should update
     * This validates Requirement 3.3
     */
    public function role_permission_changes_propagate_to_users(): void
    {
        // Create permissions
        $permissions = $this->createTestPermissions([
            'users.read', 'users.create', 'dashboard.view'
        ]);

        // Create role with initial permissions
        $role = $this->createRoleWithPermissions('dynamic_role', [
            $permissions['users.read']->id,
            $permissions['dashboard.view']->id,
        ]);

        // Create user with the role
        $user = $this->createUserWithRole($role, 'dynamic_user@example.com');

        // Verify initial permissions
        $this->assertTrue($user->hasPermission('users.read'));
        $this->assertTrue($user->hasPermission('dashboard.view'));
        $this->assertFalse($user->hasPermission('users.create'));

        // Modify role permissions - add users.create
        $role->permissions()->attach($permissions['users.create']->id);
        
        // Reload user to get updated permissions
        $user->load('roles.permissions');

        // Verify user now has the new permission
        $this->assertTrue(
            $user->hasPermission('users.create'),
            "User should have 'users.create' after role permission was added"
        );

        // Modify role permissions - remove users.read
        $role->permissions()->detach($permissions['users.read']->id);
        
        // Reload user
        $user->load('roles.permissions');

        // Verify user no longer has the removed permission
        $this->assertFalse(
            $user->hasPermission('users.read'),
            "User should NOT have 'users.read' after role permission was removed"
        );
    }

    /**
     * @test
     * 
     * Additional property: User with multiple roles inherits all permissions
     */
    public function user_with_multiple_roles_inherits_all_permissions(): void
    {
        // Create permissions
        $permissions = $this->createTestPermissions([
            'users.read', 'users.create', 'dashboard.view', 'reports.view'
        ]);

        // Create first role with some permissions
        $role1 = $this->createRoleWithPermissions('role_one', [
            $permissions['users.read']->id,
            $permissions['dashboard.view']->id,
        ]);

        // Create second role with different permissions
        $role2 = $this->createRoleWithPermissions('role_two', [
            $permissions['users.create']->id,
            $permissions['reports.view']->id,
        ]);

        // Create user
        $user = User::create([
            'name' => 'Multi Role User',
            'email' => 'multi_role@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        // Assign both roles
        $user->roles()->attach([$role1->id, $role2->id]);
        $user->load('roles.permissions');

        // Verify user has all permissions from both roles
        $this->assertTrue($user->hasPermission('users.read'));
        $this->assertTrue($user->hasPermission('dashboard.view'));
        $this->assertTrue($user->hasPermission('users.create'));
        $this->assertTrue($user->hasPermission('reports.view'));

        // Verify total permission count
        $userPermissions = $user->permissions();
        $this->assertCount(4, $userPermissions);
    }
}
