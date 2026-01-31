<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;

/**
 * User Management Controller with RBAC support
 * 
 * Requirements: 9.1-9.6
 * - GET /api/users - list users (filtered by role permissions)
 * - POST /api/users - create new users
 * - GET /api/users/{id} - get user details
 * - PUT /api/users/{id} - update user details
 * - DELETE /api/users/{id} - deactivate users
 */
class UserController extends Controller
{
    /**
     * Display a listing of users filtered by the current user's role.
     * 
     * Requirements: 9.1, 2.4, 4.4
     * - Superadmin sees all users
     * - Admin sees only Managers
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $currentUser = $request->user();
            
            $query = User::with(['roles' => function ($query) {
                $query->select('roles.id', 'roles.name', 'roles.display_name');
            }]);

            // Role-based filtering (Requirements 2.4, 4.4)
            if ($currentUser->isSuperadmin()) {
                // Superadmin sees all users
                $users = $query->get();
            } elseif ($currentUser->isAdmin()) {
                // Admin sees only Managers
                $users = $query->whereHas('roles', function ($q) {
                    $q->where('name', 'manager');
                })->get();
            } else {
                // Managers can only see themselves
                $users = $query->where('id', $currentUser->id)->get();
            }

            $formattedUsers = $users->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'contact' => $user->contact,
                    'is_active' => $user->is_active,
                    'role' => $user->getPrimaryRole()?->name,
                    'role_display_name' => $user->getPrimaryRole()?->display_name,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedUsers,
                'database' => 'mysql',
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
                'database' => 'mysql',
            ], 500);
        }
    }

    /**
     * Store a newly created user with role assignment.
     * 
     * Requirements: 9.2, 2.1, 4.1, 4.3
     * - Superadmin can create users with any role
     * - Admin can only create Managers
     */
    public function store(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => 'required|string|exists:roles,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Admin scope restriction (Requirements 4.1, 4.3)
        $requestedRole = $request->role;
        if ($currentUser->isAdmin()) {
            if ($requestedRole !== 'manager') {
                return response()->json([
                    'success' => false,
                    'error' => 'You cannot create users of this role',
                ], 403);
            }
        }

        try {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'is_active' => true,
                'created_by' => $currentUser->id,
            ]);

            // Assign role to user
            $role = Role::where('name', $requestedRole)->first();
            $user->roles()->attach($role->id, [
                'assigned_by' => $currentUser->id,
                'created_at' => now(),
            ]);

            // Reload user with role
            $user->load('roles');

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'role' => $user->getPrimaryRole()?->name,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
                'message' => 'User created successfully',
                'database' => 'mysql',
            ], 201);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
                'database' => 'mysql',
            ], 500);
        }
    }

    /**
     * Display the specified user.
     * 
     * Requirements: 9.1
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $currentUser = $request->user();
            $user = User::with('roles')->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Check if current user can view this user
            if (!$this->canViewUser($currentUser, $user)) {
                return response()->json([
                    'success' => false,
                    'error' => 'You do not have permission to view this user',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'role' => $user->getPrimaryRole()?->name,
                    'role_display_name' => $user->getPrimaryRole()?->display_name,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
            ], 500);
        }
    }

    /**
     * Update the specified user.
     * 
     * Requirements: 9.3, 4.3
     * - Admin cannot modify Superadmin or other Admin accounts
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $currentUser = $request->user();
            $user = User::with('roles')->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Admin scope restriction (Requirement 4.3)
            if (!$this->canModifyUser($currentUser, $user)) {
                return response()->json([
                    'success' => false,
                    'error' => 'You cannot modify users of this role',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $id,
                'password' => 'sometimes|required|string|min:8',
                'is_active' => 'sometimes|boolean',
                'role' => 'sometimes|string|exists:roles,name',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Check role change restrictions for Admin
            if ($request->has('role') && $currentUser->isAdmin()) {
                if ($request->role !== 'manager') {
                    return response()->json([
                        'success' => false,
                        'error' => 'You can only assign the manager role',
                    ], 403);
                }
            }

            $updateData = $request->only(['name', 'email', 'is_active']);
            if ($request->has('password')) {
                $updateData['password'] = Hash::make($request->password);
            }

            $user->update($updateData);

            // Update role if provided
            if ($request->has('role')) {
                $role = Role::where('name', $request->role)->first();
                $user->roles()->sync([$role->id => [
                    'assigned_by' => $currentUser->id,
                    'created_at' => now(),
                ]]);
            }

            // Reload user with role
            $user->load('roles');

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'role' => $user->getPrimaryRole()?->name,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ],
                'message' => 'User updated successfully',
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
            ], 500);
        }
    }

    /**
     * Deactivate the specified user (soft delete).
     * 
     * Requirements: 9.4, 2.3, 4.3
     * - Deactivates user instead of hard delete
     * - Admin cannot deactivate Superadmin or other Admin accounts
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $currentUser = $request->user();
            $user = User::with('roles')->find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }

            // Prevent self-deactivation
            if ($user->id === $currentUser->id) {
                return response()->json([
                    'success' => false,
                    'error' => 'You cannot deactivate your own account',
                ], 403);
            }

            // Admin scope restriction (Requirement 4.3)
            if (!$this->canModifyUser($currentUser, $user)) {
                return response()->json([
                    'success' => false,
                    'error' => 'You cannot modify users of this role',
                ], 403);
            }

            // Deactivate user instead of hard delete (Requirement 2.3)
            $user->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'User deactivated successfully',
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
            ], 500);
        }
    }

    /**
     * Check if the current user can view the target user.
     * 
     * Requirements: 2.4, 4.4
     */
    private function canViewUser(User $currentUser, User $targetUser): bool
    {
        // Superadmin can view all users
        if ($currentUser->isSuperadmin()) {
            return true;
        }

        // Admin can only view Managers
        if ($currentUser->isAdmin()) {
            return $targetUser->isManager();
        }

        // Managers can only view themselves
        return $currentUser->id === $targetUser->id;
    }

    /**
     * Check if the current user can modify the target user.
     * 
     * Requirements: 4.1, 4.3
     * - Superadmin can modify all users
     * - Admin can only modify Managers
     */
    private function canModifyUser(User $currentUser, User $targetUser): bool
    {
        // Superadmin can modify all users
        if ($currentUser->isSuperadmin()) {
            return true;
        }

        // Admin can only modify Managers (not Superadmin or other Admins)
        if ($currentUser->isAdmin()) {
            return $targetUser->isManager();
        }

        return false;
    }

    /**
     * Get database connection status.
     */
    public function status(): JsonResponse
    {
        try {
            User::count();
            
            return response()->json([
                'success' => true,
                'status' => 'connected',
                'message' => 'Database connection is healthy'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'status' => 'disconnected',
                'message' => 'Database connection failed'
            ], 500);
        }
    }
}