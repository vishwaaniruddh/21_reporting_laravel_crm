<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Recent Alerts Controller
 * 
 * Shows alerts from MySQL alerts table from the last 15 minutes
 * READ-ONLY - No updates or deletes to MySQL
 * 
 * CRITICAL: This controller queries MySQL alerts table (READ-ONLY)
 */
class RecentAlertsController extends Controller
{
    /**
     * Get recent alerts from last 15 minutes
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $validated = $request->validate([
                'page' => 'nullable|integer|min:1',
                'per_page' => 'nullable|integer|min:10|max:100',
                'panelid' => 'nullable|string|max:255',
                'atmid' => 'nullable|string|max:255',
            ]);

            $perPage = $validated['per_page'] ?? 25;
            
            // Calculate 15 minutes ago
            $fifteenMinutesAgo = Carbon::now()->subMinutes(15);

            // Query MySQL alerts table (READ-ONLY)
            $query = Alert::on('mysql')
                ->where('receivedtime', '>=', $fifteenMinutesAgo)
                ->orderBy('receivedtime', 'desc');

            // Apply panelid filter
            if (!empty($validated['panelid'])) {
                $query->where('panelid', 'like', '%' . $validated['panelid'] . '%');
            }

            // Apply atmid filter (requires JOIN with sites table)
            if (!empty($validated['atmid'])) {
                $query->where(function($q) use ($validated) {
                    $q->whereExists(function($subquery) use ($validated) {
                        $subquery->from('sites')
                            ->whereRaw('alerts.panelid = sites.OldPanelID')
                            ->where('ATMID', 'like', '%' . $validated['atmid'] . '%');
                    })->orWhereExists(function($subquery) use ($validated) {
                        $subquery->from('sites')
                            ->whereRaw('alerts.panelid = sites.NewPanelID')
                            ->where('ATMID', 'like', '%' . $validated['atmid'] . '%');
                    });
                });
            }

            // Get paginated results
            $alerts = $query->paginate($perPage);

            // Enrich alerts with site information
            $enrichedAlerts = $this->enrichAlertsWithSiteInfo($alerts->items());

            return response()->json([
                'success' => true,
                'data' => $enrichedAlerts,
                'pagination' => [
                    'current_page' => $alerts->currentPage(),
                    'per_page' => $alerts->perPage(),
                    'total' => $alerts->total(),
                    'last_page' => $alerts->lastPage(),
                    'from' => $alerts->firstItem(),
                    'to' => $alerts->lastItem(),
                ],
                'time_range' => [
                    'from' => $fifteenMinutesAgo->toIso8601String(),
                    'to' => Carbon::now()->toIso8601String(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching recent alerts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Enrich alerts with site information
     * 
     * @param array $alerts
     * @return array
     */
    protected function enrichAlertsWithSiteInfo(array $alerts): array
    {
        if (empty($alerts)) {
            return [];
        }

        // Get unique panel IDs
        $panelIds = collect($alerts)->pluck('panelid')->unique()->filter()->values()->toArray();

        if (empty($panelIds)) {
            return $alerts;
        }

        // Fetch site information from MySQL sites table (READ-ONLY)
        // Join on both OldPanelID and NewPanelID
        $sites = DB::connection('mysql')
            ->table('sites')
            ->where(function($query) use ($panelIds) {
                $query->whereIn('OldPanelID', $panelIds)
                      ->orWhereIn('NewPanelID', $panelIds);
            })
            ->select(['OldPanelID', 'NewPanelID', 'Customer', 'Zone', 'ATMID', 'SiteAddress', 'City', 'State', 'DVRIP', 'Panel_Make', 'Bank'])
            ->get();

        // Create lookup maps for both OldPanelID and NewPanelID
        $sitesByOldPanel = [];
        $sitesByNewPanel = [];
        
        foreach ($sites as $site) {
            if ($site->OldPanelID) {
                $sitesByOldPanel[$site->OldPanelID] = $site;
            }
            if ($site->NewPanelID) {
                $sitesByNewPanel[$site->NewPanelID] = $site;
            }
        }

        // Enrich each alert with site information
        foreach ($alerts as $alert) {
            $panelId = $alert->panelid;
            
            // Try to find site by NewPanelID first, then OldPanelID
            $site = $sitesByNewPanel[$panelId] ?? $sitesByOldPanel[$panelId] ?? null;
            
            if ($site) {
                $alert->Customer = $site->Customer ?? null;
                $alert->site_zone = $site->Zone ?? null;
                $alert->ATMID = $site->ATMID ?? null;
                $alert->SiteAddress = $site->SiteAddress ?? null;
                $alert->City = $site->City ?? null;
                $alert->State = $site->State ?? null;
                $alert->DVRIP = $site->DVRIP ?? null;
                $alert->Panel_Make = $site->Panel_Make ?? null;
                $alert->Bank = $site->Bank ?? null;
            } else {
                $alert->Customer = null;
                $alert->site_zone = null;
                $alert->ATMID = null;
                $alert->SiteAddress = null;
                $alert->City = null;
                $alert->State = null;
                $alert->DVRIP = null;
                $alert->Panel_Make = null;
                $alert->Bank = null;
            }
        }

        return $alerts;
    }

    /**
     * Get filter options for panelid and atmid
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function filterOptions()
    {
        try {
            // Get unique panel IDs from last 15 minutes
            $fifteenMinutesAgo = Carbon::now()->subMinutes(15);
            
            $panelIds = Alert::on('mysql')
                ->where('receivedtime', '>=', $fifteenMinutesAgo)
                ->distinct()
                ->pluck('panelid')
                ->filter()
                ->sort()
                ->values();

            // Get ATMIDs from sites table for these panel IDs
            $atmIds = [];
            if ($panelIds->isNotEmpty()) {
                $atmIds = DB::connection('mysql')
                    ->table('sites')
                    ->where(function($query) use ($panelIds) {
                        $query->whereIn('OldPanelID', $panelIds)
                              ->orWhereIn('NewPanelID', $panelIds);
                    })
                    ->whereNotNull('ATMID')
                    ->distinct()
                    ->pluck('ATMID')
                    ->filter()
                    ->sort()
                    ->values();
            }

            return response()->json([
                'success' => true,
                'panel_ids' => $panelIds,
                'atm_ids' => $atmIds,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching filter options: ' . $e->getMessage(),
            ], 500);
        }
    }
}
