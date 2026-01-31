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

class GenerateCsvExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes
    public $tries = 3;

    protected string $type; // 'all-alerts' or 'vm-alerts'
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
        try {
            // Update status to processing
            DB::table('export_jobs')->where('job_id', $this->jobId)->update([
                'status' => 'processing',
                'updated_at' => now(),
            ]);

            Log::info("CSV export job started", [
                'job_id' => $this->jobId,
                'type' => $this->type,
                'date' => $this->date,
                'user_id' => $this->userId
            ]);

            $fromDate = Carbon::parse($this->date)->startOfDay();
            $toDate = $fromDate->copy()->endOfDay();
            
            $filename = $this->type . '_' . $fromDate->format('Y-m-d') . '_' . $this->jobId . '.csv';
            $filepath = 'exports/' . $filename;

            // Generate CSV file
            $tempFile = tmpfile();
            $tempPath = stream_get_meta_data($tempFile)['uri'];
            
            $file = fopen($tempPath, 'w');
            fwrite($file, "\xEF\xBB\xBF"); // UTF-8 BOM

            // Write headers
            $headers = $this->getHeaders();
            fputcsv($file, $headers);

            // Fetch and write data in chunks
            $router = new PartitionQueryRouter();
            $chunkSize = 5000;
            $offset = 0;
            $totalRecords = 0;

            while (true) {
                $filters = $this->getFilters();
                $tablePrefixes = ['alerts', 'backalerts'];
                
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

                if ($totalRecords % 25000 === 0) {
                    Log::info("CSV export progress", [
                        'job_id' => $this->jobId,
                        'records' => $totalRecords,
                        'offset' => $offset
                    ]);
                }
            }

            fclose($file);

            // Store file
            Storage::disk('public')->put($filepath, file_get_contents($tempPath));
            unlink($tempPath);

            Log::info("CSV export job completed", [
                'job_id' => $this->jobId,
                'total_records' => $totalRecords,
                'filepath' => $filepath
            ]);

            // Update job status in database
            DB::table('export_jobs')->where('job_id', $this->jobId)->update([
                'status' => 'completed',
                'filepath' => $filepath,
                'total_records' => $totalRecords,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        } catch (\Exception $e) {
            Log::error("CSV export job failed", [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update job status
            DB::table('export_jobs')->where('job_id', $this->jobId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

            throw $e;
        }
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
