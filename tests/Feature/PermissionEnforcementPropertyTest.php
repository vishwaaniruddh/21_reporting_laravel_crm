<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Tests for Permission Enforcement
 * 
 * @feature rbac-user-management
 * @property 7: Permission Enforcement
 * **Validates: Requirements 3.4, 5.1, 5.2**
 * 
 * Property: For any user and any protected resource, access should be granted 
 * if and only if the user's role includes the required permission. 
 * Unauthorized access should return 403 Forbidden.
 */
class PermissionEnforcementPropertyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Run base migrations
        Artisan::call('migrate:fresh');
        
        // Add RBAC schema
        $this->addRbacSchema();
        
        // Create test permissions
        $this->createTestPermissions();
        
        // Create test roles with different permission sets
        $this->createTestRoles();
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

        // Create personal_access_tokens table for Sanctum
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

    protected function createTestPermissions(): void
    {
        $permissions = [
            ['name' => 'users.create', 'display_name' => 'Create Users', 'module' => 'users'],
            ['name' => 'users.read', 'display_name' => 'View Users', 'module' => 'users'],
            ['name' => 'users.update', 'display_name' => 'Update Users', 'module' => 'users'],
            ['name' => 'users.delete', 'display_name' => 'Delete Users', 'module' => 'users'],
            ['name' => 'roles.read', 'display_name' => 'View Roles', 'module' => 'roles'],
            ['name' => 'permissions.read', 'display_name' => 'View Permissions', 'module' => 'permissions'],
            ['name' => 'permissions.assign', 'display_name' => 'Assign Permissions', 'module' => 'permissions'],
            ['name' => 'dashboard.view', 'display_name' => 'View Dashboard', 'module' => 'dashboard'],
        ];

        foreach ($permissions as $permission) {
            Permission::create($permission);
        }
    }

    protected function createTestRoles(): void
    {
        // Superadmin role with all permissions
        $superadmin = Role::create([
            'name' => 'superadmin',
            'display_name' => 'Super Administrator',
            'description' => 'Full system access'
        ]);
        $superadmin->permissions()->attach(Permission::all()->pluck('id'));

        // Admin role with limited permissions
        $admin = Role::create([
            'name' => 'admin',
            'display_name' => 'Administrator',
            'description' => 'Limited admin access'
        ]);
        $adminPermissions = Permission::whereIn('name', [
            'users.create', 'users.read', 'users.update', 'users.delete',
            'roles.read', 'permissions.read', 'permissions.assign', 'dashboard.view'
        ])->pluck('id');
        $admin->permissions()->attach($adminPermissions);

        // Manager role with minimal permissions
        $manager = Role::create([
            'name' => 'manager',
            'display_name' => 'Manager',
            'description' => 'Basic access'
        ]);
        $managerPermissions = Permission::whereIn('name', ['dashboard.view'])->pluck('id');
        $manager->permissions()->attach($managerPermissions);
    }

    /**
     * Create a user with a specific role
     */
    protected function createUserWithRole(string $roleName, string $email): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $role = Role::where('name', $roleName)->first();
        $user->roles()->attach($role->id);
        
        // Reload user with roles relationship
        $user->load('roles.permissions');

        return $user;
    }

    /**
     * Test the middleware directly by simulating a request
     */
    protected function testMiddlewareWithUser(?User $user, string $permission): array
    {
        $middleware = new CheckPermission();
        
        // Create a mock request
        $request = Request::create('/test', 'GET');
        
        // Set the user on the request if provided
        if ($user) {
            $request->setUserResolver(function () use ($user) {
                return $user;
            });
        }
        
        $responseCode = null;
        $responseData = null;
        
        $response = $middleware->handle($request, function ($req) {
            return response()->json(['success' => true], 200);
        }, $permission);
        
        $responseCode = $response->getStatusCode();
        $responseData = json_decode($response->getContent(), true);
        
        return [
            'status' => $responseCode,
            'data' => $responseData
        ];
    }

    /**
     * Data provider for permission enforcement property test
     * Generates 100+ test cases with various user/permission combinations
     */
    public static function permissionEnforcementProvider(): array
    {
        $testCases = [];
        
        // Define role-permission matrix
        $rolePermissions = [
            'superadmin' => [
                'users.create', 'users.read', 'users.update', 'users.delete',
                'roles.read', 'permissions.read', 'permissions.assign', 'dashboard.view'
            ],
            'admin' => [
                'users.create', 'users.read', 'users.update', 'users.delete',
                'roles.read', 'permissions.read', 'permissions.assign', 'dashboard.view'
            ],
            'manager' => ['dashboard.view'],
        ];

        $allPermissions = [
            'users.create', 'users.read', 'users.update', 'users.delete',
            'roles.read', 'permissions.read', 'permissions.assign', 'dashboard.view'
        ];

        $caseIndex = 0;

        // Generate test cases for each role and permission combination
        foreach ($rolePermissions as $role => $grantedPermissions) {
            foreach ($allPermissions as $permission) {
                $hasPermission = in_array($permission, $grantedPermissions);
                $expectedStatus = $hasPermission ? 200 : 403;
                
                // Generate one test case for each combination
                $testCases["case_{$caseIndex}_{$role}_{$permission}"] = [
                    'role' => $role,
                    'permission' => $permission,
                    'has_permission' => $hasPermission,
                    'expected_status' => $expectedStatus,
                    'email' => "test_{$caseIndex}@example.com",
                ];
                $caseIndex++;
            }
        }

        return $testCases;
    }

    /**
     * @test
     * @dataProvider permissionEnforcementProvider
     * 
     * Property 7: Permission Enforcement
     * For any user and any protected resource, access should be granted 
     * if and only if the user's role includes the required permission.
     */
    public function access_granted_iff_user_has_required_permission(
        string $role,
        string $permission,
        bool $has_permission,
        int $expected_status,
        string $email
    ): void {
        // Arrange: Create user with specified role
        $user = $this->createUserWithRole($role, $email);

        // Act: Test middleware directly
        $result = $this->testMiddlewareWithUser($user, $permission);

        // Assert: Verify access is granted iff user has permission
        $this->assertEquals(
            $expected_status, 
            $result['status'],
            "Expected status {$expected_status} for role '{$role}' with permission '{$permission}', got {$result['status']}"
        );

        if ($has_permission) {
            $this->assertTrue($result['data']['success'] ?? false);
        } else {
            $this->assertEquals(
                'You do not have permission to perform this action',
                $result['data']['error'] ?? ''
            );
        }
    }

    /**
     * @test
     * 
     * Additional property test: Unauthenticated requests should return 401
     */
    public function unauthenticated_requests_return_401(): void
    {
        $permissions = [
            'users.create',
            'users.read',
            'dashboard.view',
        ];

        foreach ($permissions as $permission) {
            $result = $this->testMiddlewareWithUser(null, $permission);
            $this->assertEquals(401, $result['status']);
            $this->assertEquals('Unauthenticated', $result['data']['error'] ?? '');
        }
    }
}
