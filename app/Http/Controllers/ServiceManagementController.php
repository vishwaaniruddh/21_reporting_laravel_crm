<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Service Management Controller
 * 
 * Manages Windows NSSM services through the UI
 * Provides start, stop, restart, and status operations
 */
class ServiceManagementController extends Controller
{
    /**
     * List of managed services (Alert, BackAlert, and Sites services)
     */
    protected array $managedServices = [
        'AlertInitialSyncNew',
        'AlertUpdateSync',
        'AlertBackupSync',
        'AlertCleanup',
        'AlertMysqlBackup',
        'AlertPortal',
        'AlertViteDev',
        'BackAlertUpdateSync',
        'SitesUpdateSync',
    ];

    /**
     * Get all services with their status
     */
    public function index()
    {
        $services = [];

        foreach ($this->managedServices as $serviceName) {
            $services[] = $this->getServiceInfo($serviceName);
        }

        return response()->json([
            'success' => true,
            'services' => $services,
        ]);
    }

    /**
     * Get information about a specific service
     */
    protected function getServiceInfo(string $serviceName): array
    {
        try {
            // Get service status using PowerShell
            $command = "powershell -Command \"Get-Service -Name '{$serviceName}' -ErrorAction SilentlyContinue | Select-Object Name, DisplayName, Status, StartType | ConvertTo-Json\"";
            
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(10);
            $process->run();

            if ($process->isSuccessful()) {
                $output = $process->getOutput();
                $serviceData = json_decode($output, true);

                if ($serviceData) {
                    // Convert numeric status to string
                    $statusMap = [
                        1 => 'Stopped',
                        2 => 'StartPending',
                        3 => 'StopPending',
                        4 => 'Running',
                        5 => 'ContinuePending',
                        6 => 'PausePending',
                        7 => 'Paused',
                    ];
                    
                    // Convert numeric start type to string
                    $startTypeMap = [
                        0 => 'Boot',
                        1 => 'System',
                        2 => 'Automatic',
                        3 => 'Manual',
                        4 => 'Disabled',
                    ];
                    
                    $status = is_numeric($serviceData['Status']) 
                        ? ($statusMap[$serviceData['Status']] ?? 'Unknown')
                        : $serviceData['Status'];
                        
                    $startType = is_numeric($serviceData['StartType'])
                        ? ($startTypeMap[$serviceData['StartType']] ?? 'Unknown')
                        : $serviceData['StartType'];

                    return [
                        'name' => $serviceData['Name'],
                        'display_name' => $serviceData['DisplayName'] ?? $serviceName,
                        'status' => $status,
                        'start_type' => $startType,
                        'exists' => true,
                    ];
                }
            }

            // Service not found
            return [
                'name' => $serviceName,
                'display_name' => $serviceName,
                'status' => 'Not Found',
                'start_type' => 'N/A',
                'exists' => false,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get service info', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
            ]);

            return [
                'name' => $serviceName,
                'display_name' => $serviceName,
                'status' => 'Error',
                'start_type' => 'N/A',
                'exists' => false,
            ];
        }
    }

    /**
     * Start a service
     */
    public function start(Request $request)
    {
        $serviceName = $request->input('service');

        if (!in_array($serviceName, $this->managedServices)) {
            return response()->json([
                'success' => false,
                'message' => 'Service not allowed',
            ], 403);
        }

        try {
            $command = "powershell -Command \"Start-Service -Name '{$serviceName}'\"";
            
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(30);
            $process->run();

            if ($process->isSuccessful()) {
                Log::info('Service started', ['service' => $serviceName]);

                return response()->json([
                    'success' => true,
                    'message' => "Service {$serviceName} started successfully",
                    'service' => $this->getServiceInfo($serviceName),
                ]);
            } else {
                $error = $process->getErrorOutput();
                Log::error('Failed to start service', [
                    'service' => $serviceName,
                    'error' => $error,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => "Failed to start service: {$error}",
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Exception starting service', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error starting service: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Stop a service
     */
    public function stop(Request $request)
    {
        $serviceName = $request->input('service');

        if (!in_array($serviceName, $this->managedServices)) {
            return response()->json([
                'success' => false,
                'message' => 'Service not allowed',
            ], 403);
        }

        try {
            $command = "powershell -Command \"Stop-Service -Name '{$serviceName}' -Force\"";
            
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(30);
            $process->run();

            if ($process->isSuccessful()) {
                Log::info('Service stopped', ['service' => $serviceName]);

                return response()->json([
                    'success' => true,
                    'message' => "Service {$serviceName} stopped successfully",
                    'service' => $this->getServiceInfo($serviceName),
                ]);
            } else {
                $error = $process->getErrorOutput();
                Log::error('Failed to stop service', [
                    'service' => $serviceName,
                    'error' => $error,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => "Failed to stop service: {$error}",
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Exception stopping service', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error stopping service: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restart a service
     */
    public function restart(Request $request)
    {
        $serviceName = $request->input('service');

        if (!in_array($serviceName, $this->managedServices)) {
            return response()->json([
                'success' => false,
                'message' => 'Service not allowed',
            ], 403);
        }

        try {
            $command = "powershell -Command \"Restart-Service -Name '{$serviceName}' -Force\"";
            
            $process = Process::fromShellCommandline($command);
            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful()) {
                Log::info('Service restarted', ['service' => $serviceName]);

                return response()->json([
                    'success' => true,
                    'message' => "Service {$serviceName} restarted successfully",
                    'service' => $this->getServiceInfo($serviceName),
                ]);
            } else {
                $error = $process->getErrorOutput();
                Log::error('Failed to restart service', [
                    'service' => $serviceName,
                    'error' => $error,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => "Failed to restart service: {$error}",
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Exception restarting service', [
                'service' => $serviceName,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error restarting service: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get service logs
     */
    public function logs(Request $request)
    {
        $serviceName = $request->input('service');
        $lines = $request->input('lines', 50);

        if (!in_array($serviceName, $this->managedServices)) {
            return response()->json([
                'success' => false,
                'message' => 'Service not allowed',
            ], 403);
        }

        // Map service names to log files
        $logFiles = [
            'AlertInitialSync' => 'initial-sync-service.log',
            'AlertUpdateSync' => 'update-sync-service.log',
            'AlertBackupSync' => 'backup-sync-service.log',
            'AlertCleanup' => 'cleanup-service.log',
            'AlertMysqlBackup' => 'backup-service.log',
            'AlertPortal' => 'portal-service.log',
            'AlertViteDev' => 'vite-service.log',
            'BackAlertUpdateSync' => 'backalert-update-sync-service.log',
            'SitesUpdateSync' => 'sites-update-sync-service.log',
        ];

        $logFile = $logFiles[$serviceName] ?? null;

        if (!$logFile) {
            return response()->json([
                'success' => false,
                'message' => 'Log file not configured for this service',
            ], 404);
        }

        $logPath = storage_path("logs/{$logFile}");

        if (!file_exists($logPath)) {
            return response()->json([
                'success' => true,
                'logs' => "No logs found for {$serviceName}",
            ]);
        }

        try {
            // Read last N lines using PHP
            $file = new \SplFileObject($logPath, 'r');
            $file->seek(PHP_INT_MAX);
            $totalLines = $file->key() + 1;
            
            $startLine = max(0, $totalLines - $lines);
            $logLines = [];
            
            $file->seek($startLine);
            while (!$file->eof()) {
                $line = $file->current();
                if ($line !== false && trim($line) !== '') {
                    $logLines[] = rtrim($line);
                }
                $file->next();
            }
            
            $logs = implode("\n", $logLines);

            return response()->json([
                'success' => true,
                'logs' => $logs ?: 'No recent logs',
            ]);

        } catch (\Exception $e) {
            Log::error('Exception reading service logs', [
                'service' => $serviceName,
                'log_path' => $logPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error reading logs: ' . $e->getMessage(),
            ], 500);
        }
    }
}

