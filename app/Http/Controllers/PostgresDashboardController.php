<?php

namespace App\Http\Controllers;

use App\Services\PostgresDashboardService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * PostgresDashboardController
 * 
 * Provides API endpoints for the PostgreSQL-based dashboard that displays
 * alert count distribution across terminals using date-partitioned tables.
 * 
 * All queries are READ-ONLY:
 * - PostgreSQL: Read from partitioned alert tables
 * - MySQL: Read from alertscount, loginusers, and sites tables
 * 
 * Authentication: Requires auth:sanctum middleware
 * Authorization: Requires dashboard.view permission
 */
class PostgresDashboardController extends Controller
{
    /**
     * @var PostgresDashboardService
     */
    protected $dashboardService;

    /**
     * Constructor - inject PostgresDashboardService dependency
     * 
     * @param PostgresDashboardService $dashboardService
     */
    public function __construct(PostgresDashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Get alert count distribution for dashboard
     * 
     * Fetches alert counts grouped by terminal and status for the current or specified shift.
     * Returns data with grand totals and shift information.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function data(Request $request): JsonResponse
    {
        try {
            // Validate shift parameter
            $validated = $request->validate([
                'shift' => 'nullable|integer|in:1,2,3'
            ]);

            $shift = $validated['shift'] ?? null;

            // Get alert distribution from service
            $result = $this->dashboardService->getAlertDistribution($shift);

            // Return JSON response with data, totals, shift, and shift_time_range
            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'grandtotalOpenAlerts' => $result['grandtotalOpenAlerts'],
                'grandtotalCloseAlerts' => $result['grandtotalCloseAlerts'],
                'grandtotalAlerts' => $result['grandtotalAlerts'],
                'grandtoalCriticalOpen' => $result['grandtoalCriticalOpen'],
                'grandtotalCloseCriticalAlert' => $result['grandtotalCloseCriticalAlert'],
                'grandtotalCritical' => $result['grandtotalCritical'],
                'shift' => $result['shift'],
                'shift_time_range' => $result['shift_time_range']
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Re-throw validation exceptions to let Laravel handle them
            throw $e;
        } catch (\Exception $e) {
            // Log error with context
            Log::error('Dashboard data endpoint failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'shift' => $request->input('shift')
            ]);

            // Return HTTP 500 error response
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard data: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get detailed alerts for a specific terminal and status
     * 
     * Fetches detailed alert information for display in the modal popup.
     * Includes site information (ATMID, Zone, City) joined from MySQL sites table.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function details(Request $request): JsonResponse
    {
        try {
            // Validate parameters
            $validated = $request->validate([
                'terminal' => 'required|string',
                'status' => 'required|string|in:open,close,total,criticalopen,criticalClose,totalCritical',
                'shift' => 'required|integer|in:1,2,3'
            ]);

            $terminal = $validated['terminal'];
            $status = $validated['status'];
            $shift = $validated['shift'];

            // Get alert details from service
            $alerts = $this->dashboardService->getAlertDetails($terminal, $status, $shift);

            // Return JSON response with alert details array
            return response()->json([
                'success' => true,
                'data' => $alerts->toArray()
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Re-throw validation exceptions to let Laravel handle them
            throw $e;
        } catch (\Exception $e) {
            // Log error with context
            Log::error('Dashboard details endpoint failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'terminal' => $request->input('terminal'),
                'status' => $request->input('status'),
                'shift' => $request->input('shift')
            ]);

            // Return HTTP 500 error response
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch alert details: ' . $e->getMessage()
            ], 500);
        }
    }
}
