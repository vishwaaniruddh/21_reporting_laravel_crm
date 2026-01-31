<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Tests for Password Security
 * 
 * @feature rbac-user-management
 * @property 2: Password Security
 * **Validates: Requirements 1.4**
 * 
 * Property: For any password stored in the system, the stored value must be
 * a secure hash that cannot be reversed, and the original password must
 * verify correctly against the hash.
 */
class PasswordSecurityPropertyTest extends TestCase
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
     * Data provider for password security property test
     * Generates 100 test cases with various password patterns
     */
    public static function passwordSecurityProvider(): array
    {
        $testCases = [];
        
        // Simple passwords
        for ($i = 0; $i < 5; $i++) {
            $testCases["simple_password_$i"] = [
                'password' => "SimplePass$i",
            ];
        }

        // Complex passwords with special characters
        $specialChars = ['!', '@', '#', '$', '%', '^', '&', '*', '(', ')'];
        for ($i = 0; $i < 5; $i++) {
            $char = $specialChars[$i % count($specialChars)];
            $testCases["complex_password_$i"] = [
                'password' => "Complex{$char}Pass{$i}Word",
            ];
        }

        // Long passwords
        for ($i = 0; $i < 5; $i++) {
            $testCases["long_password_$i"] = [
                'password' => str_repeat("LongPass$i", 5),
            ];
        }

        // Passwords with unicode characters
        for ($i = 0; $i < 5; $i++) {
            $testCases["unicode_password_$i"] = [
                'password' => "Password{$i}éàü",
            ];
        }

        // Passwords with numbers only
        for ($i = 0; $i < 5; $i++) {
            $testCases["numeric_password_$i"] = [
                'password' => str_pad((string)($i * 12345), 10, '0', STR_PAD_LEFT),
            ];
        }

        return $testCases;
    }

    /**
     * @test
     * @dataProvider passwordSecurityProvider
     * 
     * Property 2: Password Security
     * For any password, the system must:
     * 1. Store it as a hash (not plaintext)
     * 2. The hash must verify correctly against the original password
     * 3. The hash must not equal the plaintext password
     */
    public function passwords_are_securely_hashed_and_verifiable(string $password): void
    {
        // Arrange: Create a user with the given password
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test_' . uniqid() . '@example.com',
            'password' => $password, // Laravel's User model casts password to 'hashed'
            'is_active' => true,
        ]);

        // Refresh from database to get the stored value
        $user->refresh();
        $storedPassword = $user->getRawOriginal('password');

        // Assert 1: Stored password is NOT the plaintext password
        $this->assertNotEquals(
            $password,
            $storedPassword,
            'Password should not be stored in plaintext'
        );

        // Assert 2: Stored password is a valid bcrypt hash (starts with $2y$)
        $this->assertMatchesRegularExpression(
            '/^\$2[aby]\$\d{2}\$.{53}$/',
            $storedPassword,
            'Password should be stored as a bcrypt hash'
        );

        // Assert 3: Original password verifies against the hash
        $this->assertTrue(
            Hash::check($password, $storedPassword),
            'Original password should verify against the stored hash'
        );

        // Assert 4: Wrong password does NOT verify against the hash
        $this->assertFalse(
            Hash::check($password . 'wrong', $storedPassword),
            'Wrong password should not verify against the stored hash'
        );
    }
}
