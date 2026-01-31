<?php

namespace App\Http\Controllers;

use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

/**
 * Role Controller for RBAC system
 * 
 * Requirements: 9.5
 * - GET /api/roles - list available roles
 */
class RoleController extends Controller
{
    /**
     * Display a listing of all roles.
     * 
     * Requirements: 9.5
     * Returns all roles with their associated permissions.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $roles = Role::with(['permissions' => function ($query) {
                $query->select('permissions.id', 'permissions.name', 'permissions.display_name', 'permissions.module');
            }])->get();

            $formattedRoles = $roles->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'description' => $role->description,
                    'permissions' => $role->permissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'display_name' => $permission->display_name,
                            'module' => $permission->module,
                        ];
                    }),
                    'created_at' => $role->created_at,
                    'updated_at' => $role->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedRoles,
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
            ], 500);
        }
    }

    /**
     * Update permissions for a role.
     * 
     * POST /api/roles/{role}/permissions
     * 
     * @param Request $request
     * @param Role $role
     * @return JsonResponse
     */
    public function updatePermissions(Request $request, Role $role): JsonResponse
    {
        try {
            $validated = $request->validate([
                'permission_ids' => 'required|array',
                'permission_ids.*' => 'exists:permissions,id',
            ]);

            // Sync permissions (removes old ones and adds new ones)
            $role->permissions()->sync($validated['permission_ids']);

            // Reload role with permissions
            $role->load('permissions');

            return response()->json([
                'success' => true,
                'message' => 'Permissions updated successfully',
                'data' => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'permissions' => $role->permissions->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'display_name' => $permission->display_name,
                            'module' => $permission->module,
                        ];
                    }),
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update permissions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
