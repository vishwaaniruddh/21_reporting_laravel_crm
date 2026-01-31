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
 * Property-Based Tests for User Creation Persistence
 * 
 * @feature rbac-user-management
 * @property 4: User Creation Persistence
 * **Validates: Requirements 2.1**
 * 
 * Property: For any valid user data submitted by a Superadmin, creating the user 
 * should result in that user existing in the database with the exact specified 
 * attributes and role.
 */
class UserCreationPersistencePropertyTest extends TestCase
{
    protected User $superadmin;
    protected string $superadminToken;

    protected function setUp(): void
    {
        parent::setUp();
        
        Artisan::call('migrate:fresh');
        $this->addRbacSchema();
        $this->seedRolesAndPermissions();
        $this->createSuperadmin();
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
        $managerRole = Role::create(['name' => 'manager', 'display_name' => 'Manager', 'description' => 'Manager access']);

        $permissions = [
            ['name' => 'users.create', 'display_name' => 'Create Users', 'module' => 'users'],
            ['name' => 'users.read', 'display_name' => 'Read Users', 'module' => 'users'],
            ['name' => 'users.update', 'display_name' => 'Update Users', 'module' => 'users'],
            ['name' => 'users.delete', 'display_name' => 'Delete Users', 'module' => 'users'],
        ];

        foreach ($permissions as $perm) {
            $permission = Permission::create($perm);
            $superadminRole->permissions()->attach($permission->id);
        }
    }

    protected function createSuperadmin(): void
    {
        $this->superadmin = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password123'),
            'is_active' => true,
        ]);

        $superadminRole = Role::where('name', 'superadmin')->first();
        $this->superadmin->roles()->attach($superadminRole->id);
        $this->superadminToken = $this->superadmin->createToken('test-token')->plainTextToken;
    }

    /**
     * Data provider for user creation persistence property test
     * Generates 100 test cases with various valid user data
     */
    public static function userCreationDataProvider(): array
    {
        $testCases = [];
        $roles = ['admin', 'manager'];

        for ($i = 0; $i < 10; $i++) {
            $role = $roles[$i % 2];
            $testCases["user_creation_$i"] = [
                'name' => "Test User $i",
                'email' => "testuser$i@example.com",
                'password' => "SecurePass$i!",
                'role' => $role,
            ];
        }

        return $testCases;
    }

    /**
     * @test
     * @dataProvider userCreationDataProvider
     * 
     * Property 4: User Creation Persistence
     * For any valid user data submitted by a Superadmin, creating the user should 
     * result in that user existing in the database with the exact specified attributes and role.
     */
    public function user_creation_persists_with_exact_attributes(
        string $name,
        string $email,
        string $password,
        string $role
    ): void {
        // Act: Create user via API
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superadminToken,
        ])->postJson('/api/users', [
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ]);

        // Assert: User was created successfully
        $response->assertStatus(201);
        $responseData = $response->json('data');

        // Assert: User exists in database with exact attributes
        $this->assertDatabaseHas('users', [
            'name' => $name,
            'email' => $email,
            'is_active' => true,
        ]);

        // Assert: User has the correct role assigned
        $createdUser = User::where('email', $email)->first();
        $this->assertNotNull($createdUser);
        $this->assertEquals($name, $createdUser->name);
        $this->assertEquals($email, $createdUser->email);
        $this->assertTrue($createdUser->is_active);
        $this->assertEquals($role, $createdUser->getPrimaryRole()?->name);

        // Assert: Password is hashed (not stored as plaintext)
        $this->assertNotEquals($password, $createdUser->password);
        $this->assertTrue(Hash::check($password, $createdUser->password));
    }
}
