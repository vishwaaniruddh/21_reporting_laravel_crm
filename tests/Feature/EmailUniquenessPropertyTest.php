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
 * Property-Based Tests for Email Uniqueness Constraint
 * 
 * @feature rbac-user-management
 * @property 5: Email Uniqueness Constraint
 * **Validates: Requirements 2.5**
 * 
 * Property: For any two users in the system, their email addresses must be different.
 * Attempting to create a user with an existing email should fail with a validation error.
 */
class EmailUniquenessPropertyTest extends TestCase
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
        Role::create(['name' => 'admin', 'display_name' => 'Admin', 'description' => 'Admin access']);
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
     * Data provider for email uniqueness property test
     * Generates 100 test cases with duplicate email attempts
     */
    public static function emailUniquenessDataProvider(): array
    {
        $testCases = [];

        for ($i = 0; $i < 10; $i++) {
            $testCases["duplicate_email_$i"] = [
                'existing_email' => "existing$i@example.com",
                'existing_name' => "Existing User $i",
                'new_name' => "New User $i",
            ];
        }

        return $testCases;
    }

    /**
     * @test
     * @dataProvider emailUniquenessDataProvider
     * 
     * Property 5: Email Uniqueness Constraint
     * For any two users in the system, their email addresses must be different.
     * Attempting to create a user with an existing email should fail with a validation error.
     */
    public function duplicate_email_creation_fails_with_validation_error(
        string $existing_email,
        string $existing_name,
        string $new_name
    ): void {
        // Arrange: Create first user with the email
        $firstResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superadminToken,
        ])->postJson('/api/users', [
            'name' => $existing_name,
            'email' => $existing_email,
            'password' => 'SecurePass123!',
            'role' => 'manager',
        ]);

        $firstResponse->assertStatus(201);

        // Act: Attempt to create second user with same email
        $secondResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->superadminToken,
        ])->postJson('/api/users', [
            'name' => $new_name,
            'email' => $existing_email,
            'password' => 'DifferentPass456!',
            'role' => 'manager',
        ]);

        // Assert: Second creation fails with validation error
        $secondResponse->assertStatus(422);
        $secondResponse->assertJsonValidationErrors(['email']);

        // Assert: Only one user exists with this email
        $usersWithEmail = User::where('email', $existing_email)->count();
        $this->assertEquals(1, $usersWithEmail);
    }
}
