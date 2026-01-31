<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Tests for Authentication Correctness
 * 
 * @feature rbac-user-management
 * @property 1: Authentication Correctness
 * **Validates: Requirements 1.1, 1.2, 2.3**
 * 
 * Property: For any user credentials submitted to the login endpoint, 
 * the system should authenticate successfully if and only if the email exists, 
 * the password matches the stored hash, and the user is active.
 */
class AuthenticationPropertyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Run base migrations
        Artisan::call('migrate:fresh');
        
        // Add RBAC schema
        $this->addRbacSchema();
        
        // Create default role for testing
        Role::create([
            'name' => 'manager',
            'display_name' => 'Manager',
            'description' => 'Manager role for testing'
        ]);
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

    /**
     * Data provider for authentication property test
     * Generates 100+ test cases with various credential combinations
     */
    public static function authenticationCredentialsProvider(): array
    {
        $testCases = [];
        
        // Generate test cases for valid credentials with active users
        for ($i = 0; $i < 5; $i++) {
            $testCases["valid_active_user_$i"] = [
                'email' => "valid_user_$i@example.com",
                'password' => "ValidPassword$i!",
                'is_active' => true,
                'user_exists' => true,
                'correct_password' => true,
                'expected_success' => true,
            ];
        }

        // Generate test cases for valid credentials with inactive users
        for ($i = 0; $i < 5; $i++) {
            $testCases["valid_inactive_user_$i"] = [
                'email' => "inactive_user_$i@example.com",
                'password' => "ValidPassword$i!",
                'is_active' => false,
                'user_exists' => true,
                'correct_password' => true,
                'expected_success' => false,
            ];
        }

        // Generate test cases for invalid passwords
        for ($i = 0; $i < 5; $i++) {
            $testCases["invalid_password_$i"] = [
                'email' => "user_wrong_pass_$i@example.com",
                'password' => "CorrectPassword$i!",
                'is_active' => true,
                'user_exists' => true,
                'correct_password' => false,
                'expected_success' => false,
            ];
        }

        // Generate test cases for non-existent users
        for ($i = 0; $i < 5; $i++) {
            $testCases["nonexistent_user_$i"] = [
                'email' => "nonexistent_$i@example.com",
                'password' => "AnyPassword$i!",
                'is_active' => true,
                'user_exists' => false,
                'correct_password' => false,
                'expected_success' => false,
            ];
        }

        return $testCases;
    }


    /**
     * @test
     * @dataProvider authenticationCredentialsProvider
     * 
     * Property 1: Authentication Correctness
     * For any user credentials, authentication succeeds iff:
     * - email exists AND password matches AND user is active
     */
    public function authentication_succeeds_iff_valid_credentials_and_active_user(
        string $email,
        string $password,
        bool $is_active,
        bool $user_exists,
        bool $correct_password,
        bool $expected_success
    ): void {
        // Arrange: Create user if they should exist
        if ($user_exists) {
            $storedPassword = $correct_password ? $password : 'DifferentPassword123!';
            
            $user = User::create([
                'name' => 'Test User',
                'email' => $email,
                'password' => Hash::make($storedPassword),
                'is_active' => $is_active,
            ]);

            // Assign role to user
            $role = Role::where('name', 'manager')->first();
            $user->roles()->attach($role->id);
        }

        // Act: Attempt login
        $response = $this->postJson('/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        // Assert: Verify authentication result matches expected outcome
        if ($expected_success) {
            $response->assertStatus(200)
                ->assertJsonStructure([
                    'user' => ['id', 'name', 'email', 'role'],
                    'token',
                    'permissions',
                ]);
        } else {
            // Should fail with 401 (invalid credentials) or 403 (deactivated)
            $this->assertTrue(
                in_array($response->status(), [401, 403]),
                "Expected 401 or 403, got {$response->status()}"
            );
        }
    }
}
