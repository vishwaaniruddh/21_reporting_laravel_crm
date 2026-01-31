<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to check if the authenticated user has the required role.
 * 
 * Requirements: 3.4
 * - Prevents users from accessing features beyond their role's permissions
 * - Supports checking for multiple roles (any match allows access)
 */
class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  ...$roles  One or more role names (user must have at least one)
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Extract user from authenticated session
        $user = $request->user();

        // Check if user is authenticated
        if (!$user) {
            return response()->json([
                'error' => 'Unauthenticated'
            ], 401);
        }

        // Check if user has any of the required roles
        $hasRequiredRole = false;
        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                $hasRequiredRole = true;
                break;
            }
        }

        if (!$hasRequiredRole) {
            // Log role denial for audit purposes
            Log::warning('Role access denied', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'required_roles' => $roles,
                'user_roles' => $user->roles->pluck('name')->toArray(),
                'ip_address' => $request->ip(),
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'error' => 'You do not have the required role to perform this action'
            ], 403);
        }

        return $next($request);
    }
}
