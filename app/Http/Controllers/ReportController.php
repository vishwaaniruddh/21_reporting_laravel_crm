<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * ReportController handles report generation from PostgreSQL.
 * 
 * Provides endpoints for:
 * - GET /api/reports/daily - Generate daily report
 * - GET /api/reports/summary - Generate summary report with filters
 * - GET /api/reports/export/csv - Export report as CSV
 * - GET /api/reports/export/pdf - Export report as PDF
 * 
 * ⚠️ All reports query PostgreSQL ONLY - never MySQL alerts
 * 
 * Requirements: 5.2, 5.4
 */
class ReportController extends Controller
{
    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * GET /api/reports/daily
     * 
     * Generate a daily report for a specific date.
     * Defaults to today if no date is provided.
     * 
     * Requirements: 5.2
     */
    public function daily(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date' => 'nullable|date',
            ]);

            $date = !empty($validated['date']) 
                ? Carbon::parse($validated['date']) 
                : Carbon::today();

            $report = $this->reportService->generateDailyReport($date);

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate daily report', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REPORT_ERROR',
                    'message' => 'Failed to generate daily report',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/reports/summary
     * 
     * Generate a summary report with optional filters.
     * Supports filtering by date range, alert type, priority, and panel.
     * 
     * Requirements: 5.2, 5.3
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'alert_type' => 'nullable|string|max:100',
                'priority' => 'nullable|string|max:50',
                'panel_id' => 'nullable|string|max:50',
            ]);

            $startDate = !empty($validated['date_from']) 
                ? Carbon::parse($validated['date_from'])->startOfDay() 
                : null;
            $endDate = !empty($validated['date_to']) 
                ? Carbon::parse($validated['date_to'])->endOfDay() 
                : null;

            $report = $this->reportService->generateSummaryReport(
                $startDate,
                $endDate,
                $validated['alert_type'] ?? null,
                $validated['priority'] ?? null,
                $validated['panel_id'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate summary report', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REPORT_ERROR',
                    'message' => 'Failed to generate summary report',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/reports/alerts
     * 
     * Get filtered alerts with pagination.
     * 
     * Requirements: 5.2
     */
    public function alerts(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'alert_type' => 'nullable|string|max:100',
                'priority' => 'nullable|string|max:50',
                'panel_id' => 'nullable|string|max:50',
                'status' => 'nullable|string|max:50',
                'per_page' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
            ]);

            $filters = array_filter([
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'alert_type' => $validated['alert_type'] ?? null,
                'priority' => $validated['priority'] ?? null,
                'panel_id' => $validated['panel_id'] ?? null,
                'status' => $validated['status'] ?? null,
            ]);

            $perPage = $validated['per_page'] ?? 50;
            $page = $validated['page'] ?? 1;

            $result = $this->reportService->getFilteredAlerts($filters, $perPage, $page);

            return response()->json([
                'success' => true,
                'data' => $result['data'],
                'pagination' => $result['pagination'],
                'filters' => $filters,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get filtered alerts', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REPORT_ERROR',
                    'message' => 'Failed to get filtered alerts',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/reports/filter-options
     * 
     * Get available filter options (distinct values for dropdowns).
     */
    public function filterOptions(): JsonResponse
    {
        try {
            $options = $this->reportService->getFilterOptions();

            return response()->json([
                'success' => true,
                'data' => $options,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get filter options', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'REPORT_ERROR',
                    'message' => 'Failed to get filter options',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/reports/export/csv
     * 
     * Export report data as CSV file.
     * 
     * Requirements: 5.4
     */
    public function exportCsv(Request $request): Response
    {
        try {
            $validated = $request->validate([
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'alert_type' => 'nullable|string|max:100',
                'priority' => 'nullable|string|max:50',
                'panel_id' => 'nullable|string|max:50',
                'limit' => 'nullable|integer|min:1|max:50000',
            ]);

            $filters = array_filter([
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'alert_type' => $validated['alert_type'] ?? null,
                'priority' => $validated['priority'] ?? null,
                'panel_id' => $validated['panel_id'] ?? null,
            ]);

            $limit = $validated['limit'] ?? 10000;

            $csv = $this->reportService->exportToCsv($filters, $limit);

            $filename = 'alerts_report_' . now()->format('Y-m-d_His') . '.csv';

            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to export CSV', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EXPORT_ERROR',
                    'message' => 'Failed to export CSV',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/reports/export/pdf
     * 
     * Export report data as PDF file.
     * 
     * Requirements: 5.4
     */
    public function exportPdf(Request $request): Response
    {
        try {
            $validated = $request->validate([
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'alert_type' => 'nullable|string|max:100',
                'priority' => 'nullable|string|max:50',
                'panel_id' => 'nullable|string|max:50',
                'limit' => 'nullable|integer|min:1|max:1000',
            ]);

            $filters = array_filter([
                'date_from' => $validated['date_from'] ?? null,
                'date_to' => $validated['date_to'] ?? null,
                'alert_type' => $validated['alert_type'] ?? null,
                'priority' => $validated['priority'] ?? null,
                'panel_id' => $validated['panel_id'] ?? null,
            ]);

            $limit = $validated['limit'] ?? 100;

            $reportData = $this->reportService->exportToPdf($filters, $limit);

            // Check if DomPDF is available
            if (class_exists('Barryvdh\DomPDF\Facade\Pdf')) {
                $pdf = Pdf::loadView('reports.pdf', $reportData);
                $filename = 'alerts_report_' . now()->format('Y-m-d_His') . '.pdf';
                return $pdf->download($filename);
            }

            // Fallback: return JSON data if PDF library not available
            return response()->json([
                'success' => true,
                'message' => 'PDF library not installed. Returning report data as JSON.',
                'data' => $reportData,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to export PDF', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EXPORT_ERROR',
                    'message' => 'Failed to export PDF',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/reports/statistics
     * 
     * Get statistics only (without full report).
     * 
     * Requirements: 5.3
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date',
                'alert_type' => 'nullable|string|max:100',
                'priority' => 'nullable|string|max:50',
                'panel_id' => 'nullable|string|max:50',
            ]);

            $startDate = !empty($validated['date_from']) 
                ? Carbon::parse($validated['date_from'])->startOfDay() 
                : null;
            $endDate = !empty($validated['date_to']) 
                ? Carbon::parse($validated['date_to'])->endOfDay() 
                : null;

            $filters = array_filter([
                'alert_type' => $validated['alert_type'] ?? null,
                'priority' => $validated['priority'] ?? null,
                'panel_id' => $validated['panel_id'] ?? null,
            ]);

            $statistics = $this->reportService->generateStatistics($startDate, $endDate, $filters);

            return response()->json([
                'success' => true,
                'data' => [
                    'statistics' => $statistics,
                    'date_range' => [
                        'start' => $startDate?->toIso8601String(),
                        'end' => $endDate?->toIso8601String(),
                    ],
                    'filters' => $filters,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get statistics', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'STATISTICS_ERROR',
                    'message' => 'Failed to get statistics',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}
