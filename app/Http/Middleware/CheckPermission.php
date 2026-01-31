<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to check if the authenticated user has the required permission.
 * 
 * Requirements: 5.1, 5.2, 5.3
 * - Verifies user has required permission before allowing access
 * - Returns 403 Forbidden if unauthorized
 * - Uses middleware to enforce access control consistently
 */
class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $permission  The required permission name
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        // Extract user from authenticated session
        $user = $request->user();

        // Check if user is authenticated
        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated'
            ], 401);
        }

        // Check if user's role has the required permission
        if (!$user->hasPermission($permission)) {
            // Log permission denial for audit purposes (Requirement 5.4)
            Log::warning('Permission denied', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'required_permission' => $permission,
                'user_permissions' => $user->permissions()->pluck('name')->toArray(),
                'ip_address' => $request->ip(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'error' => 'You do not have permission to perform this action'
            ], 403);
        }

        return $next($request);
    }
}
