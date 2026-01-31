<?php

namespace App\Http\Controllers;

use App\Models\Analytics;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;

class AnalyticsController extends Controller
{
    /**
     * Display a listing of analytics from PostgreSQL database.
     */
    public function index(): JsonResponse
    {
        try {
            $analytics = Analytics::orderBy('created_at', 'desc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $analytics,
                'database' => 'postgresql'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
                'database' => 'postgresql'
            ], 500);
        }
    }

    /**
     * Store a newly created analytics record in PostgreSQL database.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'event_type' => 'required|string|max:255',
            'event_data' => 'required|array',
            'user_id' => 'nullable|integer',
            'session_id' => 'nullable|string|max:255',
            'ip_address' => 'nullable|ip',
            'user_agent' => 'nullable|string',
            'occurred_at' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
                'database' => 'postgresql'
            ], 422);
        }

        try {
            $analytics = Analytics::create([
                'event_type' => $request->event_type,
                'event_data' => $request->event_data,
                'user_id' => $request->user_id,
                'session_id' => $request->session_id,
                'ip_address' => $request->ip_address ?? $request->ip(),
                'user_agent' => $request->user_agent ?? $request->userAgent(),
                'occurred_at' => $request->occurred_at ?? now(),
            ]);

            return response()->json([
                'success' => true,
                'data' => $analytics,
                'message' => 'Analytics record created successfully',
                'database' => 'postgresql'
            ], 201);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
                'database' => 'postgresql'
            ], 500);
        }
    }

    /**
     * Display the specified analytics record from PostgreSQL database.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $analytics = Analytics::find($id);

            if (!$analytics) {
                return response()->json([
                    'success' => false,
                    'message' => 'Analytics record not found',
                    'database' => 'postgresql'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $analytics,
                'database' => 'postgresql'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
                'database' => 'postgresql'
            ], 500);
        }
    }

    /**
     * Update the specified analytics record in PostgreSQL database.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $analytics = Analytics::find($id);

            if (!$analytics) {
                return response()->json([
                    'success' => false,
                    'message' => 'Analytics record not found',
                    'database' => 'postgresql'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'event_type' => 'sometimes|required|string|max:255',
                'event_data' => 'sometimes|required|array',
                'user_id' => 'sometimes|nullable|integer',
                'session_id' => 'sometimes|nullable|string|max:255',
                'ip_address' => 'sometimes|nullable|ip',
                'user_agent' => 'sometimes|nullable|string',
                'occurred_at' => 'sometimes|nullable|date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                    'database' => 'postgresql'
                ], 422);
            }

            $analytics->update($request->only([
                'event_type', 'event_data', 'user_id', 'session_id', 
                'ip_address', 'user_agent', 'occurred_at'
            ]));

            return response()->json([
                'success' => true,
                'data' => $analytics,
                'message' => 'Analytics record updated successfully',
                'database' => 'postgresql'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
                'database' => 'postgresql'
            ], 500);
        }
    }

    /**
     * Remove the specified analytics record from PostgreSQL database.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $analytics = Analytics::find($id);

            if (!$analytics) {
                return response()->json([
                    'success' => false,
                    'message' => 'Analytics record not found',
                    'database' => 'postgresql'
                ], 404);
            }

            $analytics->delete();

            return response()->json([
                'success' => true,
                'message' => 'Analytics record deleted successfully',
                'database' => 'postgresql'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
                'database' => 'postgresql'
            ], 500);
        }
    }

    /**
     * Get database connection status for PostgreSQL.
     */
    public function status(): JsonResponse
    {
        try {
            Analytics::count(); // Simple query to test connection
            
            return response()->json([
                'success' => true,
                'database' => 'postgresql',
                'status' => 'connected',
                'message' => 'PostgreSQL database connection is healthy'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'database' => 'postgresql',
                'status' => 'disconnected',
                'message' => 'PostgreSQL database connection failed'
            ], 500);
        }
    }

    /**
     * Get analytics summary and statistics.
     */
    public function summary(): JsonResponse
    {
        try {
            $totalRecords = Analytics::count();
            $eventTypes = Analytics::select('event_type')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('event_type')
                ->get();
            
            $recentActivity = Analytics::orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'total_records' => $totalRecords,
                    'event_types' => $eventTypes,
                    'recent_activity' => $recentActivity
                ],
                'database' => 'postgresql'
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection error',
                'database' => 'postgresql'
            ], 500);
        }
    }
}