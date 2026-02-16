<?php

namespace App\Jobs;

use App\Services\PartitionQueryRouter;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * GenerateCsvExportJobV2 - Redis-based CSV export job
 * 
 * Key differences from V1:
 * - Updates job status in Redis for real-time progress
 * - Uses Redis for progress tracking
 * - Publishes progress updates to Redis pub/sub
 * - Faster job processing with Redis queue
 */
class GenerateCsvExportJobV2 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes
    public $tries = 3;
    public $queue = 'exports-v2'; // Separate queue for V2

    protected string $type;
    protected string $date;
    protected int $userId;
    protected string $jobId;

    public function __construct(string $type, string $date, int $userId, string $jobId)
    {
        $this->type = $type;
        $this->date = $date;
        $this->userId = $userId;
        $this->jobId = $jobId;
    }

    public function handle()
    {
        $redisKey = "export_job_v2:{$this->jobId}";
        
        try {
            // Update status to processing in both Redis and database
            $this->updateStatus('processing', [
                'started_at' => now()->toISOString(),
                'progress_percent' => 0,
            ]);

            Log::info("Downloads V2: CSV export job started", [
                'job_id' => $this->jobId,
                'type' => $this->type,
                'date' => $this->date,
                'user_id' => $this->userId
            ]);

            $fromDate = Carbon::parse($this->date)->startOfDay();
            $toDate = $fromDate->copy()->endOfDay();
            
            // Generate filename in format: "21 Server Alert Report – DD-MM-YYYY.csv"
            $reportType = $this->type === 'vm-alerts' ? 'VM Alerts Report' : 'Alert Report';
            $filename = '21 Server ' . $reportType . ' – ' . $fromDate->format('d-m-Y') . '.csv';
            $filepath = 'exports/' . $filename;

            // Generate CSV file
            $tempFile = tmpfile();
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            
            $file = fopen($tempPath, 'w');
            fwrite($file, "\xEF\xBB\xBF"); // UTF-8 BOM

            // Write headers
            $headers = $this->getHeaders();
            fputcsv($file, $headers);

            // Get total count for progress calculation
            $router = new PartitionQueryRouter();
            $filters = $this->getFilters();
            $tablePrefixes = ['alerts', 'backalerts'];
            
            $totalCount = $router->countDateRange(
                $fromDate,
                $toDate,
                $filters,
                $tablePrefixes
            );
            
            // Update total count in Redis
            Redis::hset($redisKey, 'total_count', $totalCount);

            // Fetch and write data in chunks
            $chunkSize = 5000;
            $offset = 0;
            $totalRecords = 0;

            while (true) {
                $results = $router->queryDateRange(
                    $fromDate,
                    $toDate,
                    $filters,
                    $tablePrefixes,
                    $chunkSize,
                    $offset
                );

                if ($results->isEmpty()) {
                    break;
                }

                foreach ($results as $record) {
                    $row = $this->formatRow($record, $totalRecords + 1);
                    fputcsv($file, $row);
                    $totalRecords++;
                }

                $offset += $results->count();

                // Calculate progress percentage
                $progressPercent = $totalCount > 0 ? round(($totalRecords / $totalCount) * 100, 2) : 0;

                // Update progress in Redis (real-time)
                Redis::hmset($redisKey, [
                    'progress_percent' => $progressPercent,
                    'records_processed' => $totalRecords,
                ]);

                // Publish progress update to Redis pub/sub
                Redis::publish("export_progress:{$this->jobId}", json_encode([
                    'job_id' => $this->jobId,
                    'progress_percent' => $progressPercent,
                    'records_processed' => $totalRecords,
                    'total_count' => $totalCount,
                ]));

                if ($totalRecords % 25000 === 0) {
                    Log::info("Downloads V2: CSV export progress", [
                        'job_id' => $this->jobId,
                        'records' => $totalRecords,
                        'progress' => $progressPercent . '%',
                        'offset' => $offset
                    ]);
                }
            }

            fclose($file);

            // Store file
            Storage::disk('public')->put($filepath, file_get_contents($tempPath));
            unlink($tempPath);

            Log::info("Downloads V2: CSV export job completed", [
                'job_id' => $this->jobId,
                'total_records' => $totalRecords,
                'filepath' => $filepath
            ]);

            // Update status to completed
            $this->updateStatus('completed', [
                'filepath' => $filepath,
                'total_records' => $totalRecords,
                'completed_at' => now()->toISOString(),
                'progress_percent' => 100,
            ]);

            // Publish completion notification
            Redis::publish("export_complete:{$this->jobId}", json_encode([
                'job_id' => $this->jobId,
                'status' => 'completed',
                'total_records' => $totalRecords,
                'filepath' => $filepath,
            ]));

        } catch (\Exception $e) {
            Log::error("Downloads V2: CSV export job failed", [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update status to failed
            $this->updateStatus('failed', [
                'error_message' => $e->getMessage(),
                'completed_at' => now()->toISOString(),
            ]);

            // Publish failure notification
            Redis::publish("export_failed:{$this->jobId}", json_encode([
                'job_id' => $this->jobId,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]));

            throw $e;
        }
    }

    /**
     * Update job status in both Redis and database
     */
    private function updateStatus(string $status, array $additionalData = [])
    {
        $redisKey = "export_job_v2:{$this->jobId}";
        
        // Update Redis
        $redisData = array_merge([
            'status' => $status,
            'updated_at' => now()->toISOString(),
        ], $additionalData);
        
        Redis::hmset($redisKey, $redisData);
        Redis::expire($redisKey, 86400); // Keep for 24 hours
        
        // Update database
        $dbData = array_merge([
            'status' => $status,
            'updated_at' => now(),
        ], $additionalData);
        
        // Convert ISO strings to Carbon for database
        if (isset($dbData['completed_at'])) {
            $dbData['completed_at'] = Carbon::parse($dbData['completed_at']);
        }
        if (isset($dbData['started_at'])) {
            $dbData['started_at'] = Carbon::parse($dbData['started_at']);
        }
        
        DB::table('export_jobs_v2')->where('job_id', $this->jobId)->update($dbData);
    }

    private function getHeaders(): array
    {
        return [
            '#', 'Alert ID', 'Site', 'Zone', 'User', 'Message', 'Status',
            'Priority', 'Date/Time', 'Closed Date/Time', 'Closed By',
            'Reactive', 'Reactive Date/Time', 'Reactive By', 'Send to Client',
            'Client Remarks', 'Alarm Type', 'Partition', 'Account',
            'Receiver', 'Line', 'Reported', 'Cross', 'Round', 'Dialer',
            'Modem', 'Prefix'
        ];
    }

    private function getFilters(): array
    {
        if ($this->type === 'vm-alerts') {
            return [
                'vm_status' => ['O', 'C'],
                'vm_sendtoclient' => 'S',
            ];
        }
        return [];
    }

    private function formatRow($record, int $serialNumber): array
    {
        return [
            $serialNumber,
            $record->alertid ?? '',
            $record->site ?? '',
            $record->zone ?? '',
            $record->user ?? '',
            $record->msg ?? '',
            $record->status ?? '',
            $record->priority ?? '',
            $record->datetime ?? '',
            $record->closedatetime ?? '',
            $record->closeby ?? '',
            $record->reactive ?? '',
            $record->reactivedatetime ?? '',
            $record->reactiveby ?? '',
            $record->sendtoclient ?? '',
            $record->clientremarks ?? '',
            $record->alarmtype ?? '',
            $record->partition ?? '',
            $record->account ?? '',
            $record->receiver ?? '',
            $record->line ?? '',
            $record->reported ?? '',
            $record->cross ?? '',
            $record->round ?? '',
            $record->dialer ?? '',
            $record->modem ?? '',
            $record->prefix ?? '',
        ];
    }
}
