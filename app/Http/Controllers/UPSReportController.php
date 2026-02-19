<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\UPSReportService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

/**
 * UPS Report Controller
 * 
 * Handles Mains/UPS Failure reports by querying partitioned alerts and backalerts tables
 * Based on panel type (RASS, Securico, SMART-I, SEC) and specific zones/alarms
 */
class UPSReportController extends Controller
{
    protected $upsReportService;

    public function __construct(UPSReportService $upsReportService)
    {
        $this->upsReportService = $upsReportService;
    }

    /**
     * Get UPS reports with pagination and filters
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'panelid' => 'nullable|string',
                'atmid' => 'nullable|string',
                'dvrip' => 'nullable|string',
                'customer' => 'nullable|string',
                'from_date' => 'required|date',
                'to_date' => 'nullable|date|after_or_equal:from_date',
                'per_page' => 'nullable|integer|min:10|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'message' => 'Validation failed',
                        'errors' => $validator->errors()
                    ]
                ], 422);
            }

            $filters = [
                'panelid' => $request->input('panelid'),
                'atmid' => $request->input('atmid'),
                'dvrip' => $request->input('dvrip'),
                'customer' => $request->input('customer'),
                'from_date' => $request->input('from_date'),
                'to_date' => $request->input('to_date', $request->input('from_date')),
            ];

            $perPage = $request->input('per_page', 25);
            $page = $request->input('page', 1);

            $result = $this->upsReportService->getUPSReports($filters, $perPage, $page);

            return response()->json([
                'success' => true,
                'data' => [
                    'reports' => $result['data'],
                    'pagination' => $result['pagination']
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('UPS Report Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed to fetch UPS reports',
                    'details' => config('app.debug') ? $e->getMessage() : null
                ]
            ], 500);
        }
    }

    /**
     * Get filter options for dropdowns
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function filterOptions()
    {
        try {
            $options = $this->upsReportService->getFilterOptions();

            return response()->json([
                'success' => true,
                'data' => $options
            ]);

        } catch (\Exception $e) {
            Log::error('UPS Filter Options Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed to fetch filter options'
                ]
            ], 500);
        }
    }

    /**
     * Export UPS report to CSV
     * 
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportCsv(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'panelid' => 'nullable|string',
                'atmid' => 'nullable|string',
                'dvrip' => 'nullable|string',
                'customer' => 'nullable|string',
                'from_date' => 'required|date',
                'to_date' => 'nullable|date|after_or_equal:from_date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'message' => 'Validation failed',
                        'errors' => $validator->errors()
                    ]
                ], 422);
            }

            $filters = [
                'panelid' => $request->input('panelid'),
                'atmid' => $request->input('atmid'),
                'dvrip' => $request->input('dvrip'),
                'customer' => $request->input('customer'),
                'from_date' => $request->input('from_date'),
                'to_date' => $request->input('to_date', $request->input('from_date')),
            ];

            return $this->upsReportService->exportToCsv($filters);

        } catch (\Exception $e) {
            Log::error('UPS Export Error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => [
                    'message' => 'Failed to export UPS report'
                ]
            ], 500);
        }
    }
}
