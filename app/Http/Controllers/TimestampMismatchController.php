<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TimestampMismatchController extends Controller
{
    /**
     * Display the timestamp mismatch checker page
     */
    public function index()
    {
        return view('timestamp-mismatches.index');
    }
    
    /**
     * Check for timestamp mismatches for a specific date
     */
    public function check(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'mode' => 'nullable|in:quick,full',
            'sample_size' => 'nullable|integer|min:10|max:1000',
        ]);
        
        $checkDate = Carbon::parse($request->date);
        $dateStr = $checkDate->toDateString();
        $partitionTable = 'alerts_' . $checkDate->format('Y_m_d');
        $mode = $request->input('mode', 'quick');
        $sampleSize = $request->input('sample_size', 100);
        
        // Check if partition table exists
        $tableExists = DB::connection('pgsql')
            ->select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)", [$partitionTable]);
        
        if (!$tableExists[0]->exists) {
            return response()->json([
                'success' => false,
                'message' => "Partition table '{$partitionTable}' does not exist in PostgreSQL.",
            ], 404);
        }
        
        // Count records first
        $mysqlCount = DB::connection('mysql')
            ->table('alerts')
            ->whereDate('receivedtime', $dateStr)
            ->count();
        
        $pgCount = DB::connection('pgsql')
            ->table($partitionTable)
            ->count();
        
        if ($mysqlCount === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No alerts found in MySQL for this date.',
            ], 404);
        }
        
        if ($mode === 'quick') {
            return $this->quickCheck($checkDate, $dateStr, $partitionTable, $mysqlCount, $pgCount, $sampleSize);
        } else {
            return $this->fullCheck($checkDate, $dateStr, $partitionTable, $mysqlCount, $pgCount);
        }
    }
    
    /**
     * Quick check using sampling
     */
    private function quickCheck($checkDate, $dateStr, $partitionTable, $mysqlCount, $pgCount, $sampleSize)
    {
        $tolerance = 1;
        
        $sampleIds = DB::connection('mysql')
            ->table('alerts')
            ->whereDate('receivedtime', $dateStr)
            ->inRandomOrder()
            ->limit($sampleSize)
            ->pluck('id');
        
        if ($sampleIds->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No sample data available.',
            ], 404);
        }
        
        $mysqlSample = DB::connection('mysql')
            ->table('alerts')
            ->whereIn('id', $sampleIds)
            ->get()
            ->keyBy('id');
        
        $pgSample = DB::connection('pgsql')
            ->table($partitionTable)
            ->whereIn('id', $sampleIds)
            ->get()
            ->keyBy('id');
        
        $matched = 0;
        $mismatches = [];
        
        foreach ($sampleIds as $id) {
            if (!$mysqlSample->has($id) || !$pgSample->has($id)) {
                $mismatches[] = [
                    'id' => $id,
                    'issue' => $mysqlSample->has($id) ? 'missing_in_postgres' : 'missing_in_mysql',
                    'mysql_createtime' => $mysqlSample->get($id)->createtime ?? null,
                    'mysql_receivedtime' => $mysqlSample->get($id)->receivedtime ?? null,
                    'mysql_closedtime' => $mysqlSample->get($id)->closedtime ?? null,
                    'pg_createtime' => $pgSample->get($id)->createtime ?? null,
                    'pg_receivedtime' => $pgSample->get($id)->receivedtime ?? null,
                    'pg_closedtime' => $pgSample->get($id)->closedtime ?? null,
                    'panelid' => $mysqlSample->get($id)->panelid ?? $pgSample->get($id)->panelid ?? null,
                    'zone' => $mysqlSample->get($id)->zone ?? $pgSample->get($id)->zone ?? null,
                    'alarm' => $mysqlSample->get($id)->alarm ?? $pgSample->get($id)->alarm ?? null,
                ];
                continue;
            }
            
            $mysql = $mysqlSample->get($id);
            $pg = $pgSample->get($id);
            
            $createtimeMismatch = false;
            $receivedtimeMismatch = false;
            $closedtimeMismatch = false;
            
            if ($mysql->createtime && $pg->createtime) {
                $diff = abs(strtotime($mysql->createtime) - strtotime($pg->createtime));
                if ($diff > $tolerance) {
                    $createtimeMismatch = true;
                }
            }
            
            if ($mysql->receivedtime && $pg->receivedtime) {
                $diff = abs(strtotime($mysql->receivedtime) - strtotime($pg->receivedtime));
                if ($diff > $tolerance) {
                    $receivedtimeMismatch = true;
                }
            }
            
            if ($mysql->closedtime && $pg->closedtime) {
                $diff = abs(strtotime($mysql->closedtime) - strtotime($pg->closedtime));
                if ($diff > $tolerance) {
                    $closedtimeMismatch = true;
                }
            } elseif (($mysql->closedtime && !$pg->closedtime) || (!$mysql->closedtime && $pg->closedtime)) {
                $closedtimeMismatch = true;
            }
            
            if ($createtimeMismatch || $receivedtimeMismatch || $closedtimeMismatch) {
                $mismatches[] = [
                    'id' => $id,
                    'issue' => 'timestamp_mismatch',
                    'createtime_mismatch' => $createtimeMismatch,
                    'receivedtime_mismatch' => $receivedtimeMismatch,
                    'closedtime_mismatch' => $closedtimeMismatch,
                    'mysql_createtime' => $mysql->createtime,
                    'mysql_receivedtime' => $mysql->receivedtime,
                    'mysql_closedtime' => $mysql->closedtime,
                    'pg_createtime' => $pg->createtime,
                    'pg_receivedtime' => $pg->receivedtime,
                    'pg_closedtime' => $pg->closedtime,
                    'createtime_diff_hours' => $createtimeMismatch ? 
                        round((strtotime($mysql->createtime) - strtotime($pg->createtime)) / 3600, 2) : 0,
                    'receivedtime_diff_hours' => $receivedtimeMismatch ? 
                        round((strtotime($mysql->receivedtime) - strtotime($pg->receivedtime)) / 3600, 2) : 0,
                    'closedtime_diff_hours' => ($closedtimeMismatch && $mysql->closedtime && $pg->closedtime) ? 
                        round((strtotime($mysql->closedtime) - strtotime($pg->closedtime)) / 3600, 2) : 0,
                    'panelid' => $mysql->panelid,
                    'zone' => $mysql->zone,
                    'alarm' => $mysql->alarm,
                ];
            } else {
                $matched++;
            }
        }
        
        $mismatchCount = count($mismatches);
        $estimatedTotalMismatches = round(($mismatchCount / $sampleSize) * $mysqlCount);
        $matchPercentage = round(($matched / $sampleSize) * 100, 1);
        
        return response()->json([
            'success' => true,
            'mode' => 'quick',
            'date' => $dateStr,
            'partition_table' => $partitionTable,
            'summary' => [
                'total_mysql' => $mysqlCount,
                'total_postgres' => $pgCount,
                'sample_size' => $sampleSize,
                'sample_matched' => $matched,
                'sample_mismatched' => $mismatchCount,
                'match_percentage' => $matchPercentage,
                'estimated_total_mismatches' => $estimatedTotalMismatches,
            ],
            'mismatches' => $mismatches,
        ]);
    }
    
    /**
     * Full check processing all records in batches
     */
    private function fullCheck($checkDate, $dateStr, $partitionTable, $mysqlCount, $pgCount)
    {
        $tolerance = 1;
        $batchSize = 1000;
        $mismatches = [];
        $matched = 0;
        
        DB::connection('mysql')
            ->table('alerts')
            ->whereDate('receivedtime', $dateStr)
            ->orderBy('id')
            ->chunk($batchSize, function ($mysqlChunk) use (&$mismatches, &$matched, $tolerance, $partitionTable) {
                $ids = $mysqlChunk->pluck('id')->toArray();
                
                $pgAlerts = DB::connection('pgsql')
                    ->table($partitionTable)
                    ->whereIn('id', $ids)
                    ->get()
                    ->keyBy('id');
                
                foreach ($mysqlChunk as $mysqlAlert) {
                    $id = $mysqlAlert->id;
                    
                    if (!$pgAlerts->has($id)) {
                        $mismatches[] = [
                            'id' => $id,
                            'issue' => 'missing_in_postgres',
                            'mysql_createtime' => $mysqlAlert->createtime,
                            'mysql_receivedtime' => $mysqlAlert->receivedtime,
                            'mysql_closedtime' => $mysqlAlert->closedtime,
                            'pg_createtime' => null,
                            'pg_receivedtime' => null,
                            'pg_closedtime' => null,
                            'panelid' => $mysqlAlert->panelid,
                            'zone' => $mysqlAlert->zone,
                            'alarm' => $mysqlAlert->alarm,
                        ];
                        continue;
                    }
                    
                    $pgAlert = $pgAlerts->get($id);
                    
                    $createtimeMismatch = false;
                    $receivedtimeMismatch = false;
                    $closedtimeMismatch = false;
                    
                    if ($mysqlAlert->createtime && $pgAlert->createtime) {
                        $diff = abs(strtotime($mysqlAlert->createtime) - strtotime($pgAlert->createtime));
                        if ($diff > $tolerance) {
                            $createtimeMismatch = true;
                        }
                    }
                    
                    if ($mysqlAlert->receivedtime && $pgAlert->receivedtime) {
                        $diff = abs(strtotime($mysqlAlert->receivedtime) - strtotime($pgAlert->receivedtime));
                        if ($diff > $tolerance) {
                            $receivedtimeMismatch = true;
                        }
                    }
                    
                    if ($mysqlAlert->closedtime && $pgAlert->closedtime) {
                        $diff = abs(strtotime($mysqlAlert->closedtime) - strtotime($pgAlert->closedtime));
                        if ($diff > $tolerance) {
                            $closedtimeMismatch = true;
                        }
                    } elseif (($mysqlAlert->closedtime && !$pgAlert->closedtime) || 
                              (!$mysqlAlert->closedtime && $pgAlert->closedtime)) {
                        $closedtimeMismatch = true;
                    }
                    
                    if ($createtimeMismatch || $receivedtimeMismatch || $closedtimeMismatch) {
                        $mismatches[] = [
                            'id' => $id,
                            'issue' => 'timestamp_mismatch',
                            'createtime_mismatch' => $createtimeMismatch,
                            'receivedtime_mismatch' => $receivedtimeMismatch,
                            'closedtime_mismatch' => $closedtimeMismatch,
                            'mysql_createtime' => $mysqlAlert->createtime,
                            'mysql_receivedtime' => $mysqlAlert->receivedtime,
                            'mysql_closedtime' => $mysqlAlert->closedtime,
                            'pg_createtime' => $pgAlert->createtime,
                            'pg_receivedtime' => $pgAlert->receivedtime,
                            'pg_closedtime' => $pgAlert->closedtime,
                            'createtime_diff_hours' => $createtimeMismatch ? 
                                round((strtotime($mysqlAlert->createtime) - strtotime($pgAlert->createtime)) / 3600, 2) : 0,
                            'receivedtime_diff_hours' => $receivedtimeMismatch ? 
                                round((strtotime($mysqlAlert->receivedtime) - strtotime($pgAlert->receivedtime)) / 3600, 2) : 0,
                            'closedtime_diff_hours' => ($closedtimeMismatch && $mysqlAlert->closedtime && $pgAlert->closedtime) ? 
                                round((strtotime($mysqlAlert->closedtime) - strtotime($pgAlert->closedtime)) / 3600, 2) : 0,
                            'panelid' => $mysqlAlert->panelid,
                            'zone' => $mysqlAlert->zone,
                            'alarm' => $mysqlAlert->alarm,
                        ];
                    } else {
                        $matched++;
                    }
                }
                
                unset($pgAlerts);
                gc_collect_cycles();
            });
        
        return response()->json([
            'success' => true,
            'mode' => 'full',
            'date' => $dateStr,
            'partition_table' => $partitionTable,
            'summary' => [
                'total_mysql' => $mysqlCount,
                'total_postgres' => $pgCount,
                'matched' => $matched,
                'mismatched' => count($mismatches),
            ],
            'mismatches' => $mismatches,
        ]);
    }
    
    /**
     * Fix timestamp mismatches by adding timezone offset
     */
    public function fix(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'alert_ids' => 'nullable|array',
            'alert_ids.*' => 'integer',
            'fix_all' => 'nullable|boolean',
        ]);
        
        $checkDate = Carbon::parse($request->date);
        $dateStr = $checkDate->toDateString();
        $partitionTable = 'alerts_' . $checkDate->format('Y_m_d');
        $alertIds = $request->input('alert_ids', []);
        $fixAll = $request->input('fix_all', false);
        
        // Check if partition table exists
        $tableExists = DB::connection('pgsql')
            ->select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = ?)", [$partitionTable]);
        
        if (!$tableExists[0]->exists) {
            return response()->json([
                'success' => false,
                'message' => "Partition table '{$partitionTable}' does not exist.",
            ], 404);
        }
        
        try {
            DB::connection('pgsql')->beginTransaction();
            
            $query = DB::connection('pgsql')->table($partitionTable);
            
            if ($fixAll) {
                // Fix all records in the partition
                $updated = $query->update([
                    'createtime' => DB::raw("createtime + INTERVAL '5 hours 30 minutes'"),
                    'receivedtime' => DB::raw("receivedtime + INTERVAL '5 hours 30 minutes'"),
                    'closedtime' => DB::raw("CASE WHEN closedtime IS NOT NULL THEN closedtime + INTERVAL '5 hours 30 minutes' ELSE NULL END"),
                ]);
            } else {
                // Fix only specified alert IDs
                if (empty($alertIds)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No alert IDs provided.',
                    ], 400);
                }
                
                $updated = $query->whereIn('id', $alertIds)->update([
                    'createtime' => DB::raw("createtime + INTERVAL '5 hours 30 minutes'"),
                    'receivedtime' => DB::raw("receivedtime + INTERVAL '5 hours 30 minutes'"),
                    'closedtime' => DB::raw("CASE WHEN closedtime IS NOT NULL THEN closedtime + INTERVAL '5 hours 30 minutes' ELSE NULL END"),
                ]);
            }
            
            DB::connection('pgsql')->commit();
            
            return response()->json([
                'success' => true,
                'message' => "Successfully fixed {$updated} records.",
                'records_updated' => $updated,
            ]);
            
        } catch (\Exception $e) {
            DB::connection('pgsql')->rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fix timestamps: ' . $e->getMessage(),
            ], 500);
        }
    }
}
