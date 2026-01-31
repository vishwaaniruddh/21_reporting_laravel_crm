<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

/**
 * CloseSessionEarly Middleware
 * 
 * Closes the PHP session immediately after this middleware runs
 * to prevent session locking during long-running requests.
 * 
 * HOW IT WORKS:
 * 1. Laravel's session middleware starts the session
 * 2. This middleware closes it immediately
 * 3. The controller runs without session lock
 * 4. Other requests can proceed in parallel
 * 
 * USE CASES:
 * - Large file downloads/exports
 * - Streaming responses
 * - Long-running API endpoints
 * 
 * IMPORTANT: Apply this middleware AFTER authentication middleware
 * in the route definition, so auth can read the session first.
 */
class CloseSessionEarly
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Close the session immediately to prevent locking
        // Laravel's session middleware has already started it
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
            
            Log::debug('Session closed early for parallel requests', [
                'url' => $request->fullUrl(),
                'method' => $request->method()
            ]);
        }
        
        // Continue with the request (session is now closed, no blocking)
        return $next($request);
    }
}
