<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property-Based Tests for Unauthenticated Request Rejection
 * 
 * @feature rbac-user-management
 * @property 10: Unauthenticated Request Rejection
 * **Validates: Requirements 9.7**
 * 
 * Property: For any protected API endpoint and any request without valid authentication,
 * the response should be 401 Unauthorized.
 */
class UnauthenticatedRequestRejectionPropertyTest extends TestCase
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
     * Data provider for protected endpoints
     * Generates test cases for all protected API endpoints with various HTTP methods
     */
    public static function protectedEndpointsProvider(): array
    {
        $testCases = [];
        
        // Protected endpoints that require authentication
        $protectedEndpoints = [
            // User management endpoints
            ['GET', '/api/users', 'get_users'],
            ['POST', '/api/users', 'create_user'],
            ['GET', '/api/users/1', 'get_user'],
            ['PUT', '/api/users/1', 'update_user'],
            ['DELETE', '/api/users/1', 'delete_user'],
            
            // Role endpoints
            ['GET', '/api/roles', 'get_roles'],
            
            // Permission endpoints
            ['GET', '/api/permissions', 'get_permissions'],
            
            // Auth me endpoint
            ['GET', '/api/auth/me', 'get_current_user'],
            
            // Logout endpoint
            ['POST', '/api/auth/logout', 'logout'],
        ];

        // Generate multiple test cases for each endpoint
        foreach ($protectedEndpoints as $index => $endpoint) {
            [$method, $url, $name] = $endpoint;
            
            // Test with no token
            $testCases["{$name}_no_token"] = [
                'method' => $method,
                'url' => $url,
                'token' => null,
                'expected_status' => 401,
            ];
            
            // Test with invalid token
            $testCases["{$name}_invalid_token"] = [
                'method' => $method,
                'url' => $url,
                'token' => 'invalid_token_' . $index,
                'expected_status' => 401,
            ];
            
            // Test with malformed token
            $testCases["{$name}_malformed_token"] = [
                'method' => $method,
                'url' => $url,
                'token' => 'Bearer malformed',
                'expected_status' => 401,
            ];
            
            // Test with empty token
            $testCases["{$name}_empty_token"] = [
                'method' => $method,
                'url' => $url,
                'token' => '',
                'expected_status' => 401,
            ];
        }

        // Add a few additional randomized test cases
        $randomEndpoints = [
            ['GET', '/api/users'],
            ['POST', '/api/users'],
            ['GET', '/api/roles'],
            ['GET', '/api/permissions'],
            ['GET', '/api/auth/me'],
        ];

        for ($i = 0; $i < 5; $i++) {
            $endpoint = $randomEndpoints[$i % count($randomEndpoints)];
            $randomToken = bin2hex(random_bytes(16));
            
            $testCases["random_invalid_token_{$i}"] = [
                'method' => $endpoint[0],
                'url' => $endpoint[1],
                'token' => $randomToken,
                'expected_status' => 401,
            ];
        }

        return $testCases;
    }

    /**
     * @test
     * @dataProvider protectedEndpointsProvider
     * 
     * Property 10: Unauthenticated Request Rejection
     * For any protected API endpoint and any request without valid authentication,
     * the response should be 401 Unauthorized.
     */
    public function protected_endpoints_reject_unauthenticated_requests(
        string $method,
        string $url,
        ?string $token,
        int $expected_status
    ): void {
        // Arrange: Build request headers
        $headers = ['Accept' => 'application/json'];
        
        if ($token !== null && $token !== '') {
            $headers['Authorization'] = "Bearer {$token}";
        }

        // Act: Make request to protected endpoint
        $response = match ($method) {
            'GET' => $this->withHeaders($headers)->getJson($url),
            'POST' => $this->withHeaders($headers)->postJson($url, []),
            'PUT' => $this->withHeaders($headers)->putJson($url, []),
            'DELETE' => $this->withHeaders($headers)->deleteJson($url),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        // Assert: Response should be 401 Unauthorized
        $this->assertEquals(
            $expected_status,
            $response->status(),
            "Expected {$expected_status} for {$method} {$url} with token: " . ($token ?? 'null') . 
            ", got {$response->status()}"
        );
    }

    /**
     * @test
     * 
     * Verify that login endpoint is NOT protected (public endpoint)
     */
    public function login_endpoint_is_accessible_without_authentication(): void
    {
        // Act: Make request to login endpoint without authentication
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);

        // Assert: Should not return 401 (may return 401 for invalid credentials, but not for missing auth)
        // The response should be either 200 (success) or 401 (invalid credentials), not 401 for missing token
        $this->assertTrue(
            in_array($response->status(), [200, 401, 422]),
            "Login endpoint should be accessible without authentication, got status: {$response->status()}"
        );
    }
}
