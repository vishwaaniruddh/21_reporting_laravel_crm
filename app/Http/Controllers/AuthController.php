<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Handle user login and return authentication token.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws ValidationException
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        // Check if user exists and password is correct
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'error' => 'Invalid email or password'
            ], 401);
        }

        // Check if user account is active
        if (!$user->is_active) {
            return response()->json([
                'error' => 'Account is deactivated'
            ], 403);
        }

        // Revoke all existing tokens for this user
        $user->tokens()->delete();

        // Create new token
        $token = $user->createToken('auth-token')->plainTextToken;

        // Load user roles and permissions
        $user->load('roles.permissions');
        $permissions = $user->permissions()->pluck('name')->toArray();
        $primaryRole = $user->getPrimaryRole();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $primaryRole ? $primaryRole->name : null,
                'is_active' => $user->is_active,
            ],
            'token' => $token,
            'permissions' => $permissions,
        ]);
    }


    /**
     * Handle user logout and invalidate token.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function logout(Request $request): JsonResponse
    {
        // Revoke the current access token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Successfully logged out'
        ]);
    }

    /**
     * Get the authenticated user's information with permissions.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Load user roles and permissions
        $user->load('roles.permissions');
        $permissions = $user->permissions()->pluck('name')->toArray();
        $primaryRole = $user->getPrimaryRole();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $primaryRole ? $primaryRole->name : null,
                'is_active' => $user->is_active,
            ],
            'permissions' => $permissions,
        ]);
    }
}
