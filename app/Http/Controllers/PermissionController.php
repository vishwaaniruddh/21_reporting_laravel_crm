<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;

/**
 * Permission Controller for RBAC system
 * 
 * Requirements: 9.6
 * - GET /api/permissions - list available permissions
 */
class PermissionController extends Controller
{
    /**
     * Display a listing of all permissions.
     * 
     * Requirements: 9.6
     * Returns all permissions grouped by module.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $permissions = Permission::orderBy('module')
                ->orderBy('name')
                ->get();

            $formattedPermissions = $permissions->map(function ($permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'display_name' => $permission->display_name,
                    'module' => $permission->module,
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedPermissions,
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
            ], 500);
        }
    }
}
