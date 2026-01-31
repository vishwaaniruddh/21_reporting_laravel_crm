<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class DatabaseStatusController extends Controller
{
    /**
     * Get the status of all database connections.
     */
    public function index(): JsonResponse
    {
        $connections = [
            'mysql' => $this->testConnection('mysql'),
            'postgresql' => $this->testConnection('pgsql')
        ];

        $allConnected = collect($connections)->every(fn($conn) => $conn['connected']);

        return response()->json([
            'success' => $allConnected,
            'connections' => $connections,
            'message' => $allConnected 
                ? 'All database connections are healthy' 
                : 'Some database connections are failing'
        ], $allConnected ? 200 : 500);
    }

    /**
     * Test a specific database connection.
     */
    public function show(string $database): JsonResponse
    {
        $validDatabases = ['mysql', 'postgresql'];
        
        if (!in_array($database, $validDatabases)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid database specified. Valid options: ' . implode(', ', $validDatabases)
            ], 400);
        }

        $connectionName = $database === 'postgresql' ? 'pgsql' : $database;
        $connectionStatus = $this->testConnection($connectionName);

        return response()->json([
            'success' => $connectionStatus['connected'],
            'database' => $database,
            'status' => $connectionStatus['status'],
            'message' => $connectionStatus['message'],
            'details' => $connectionStatus['details'] ?? null
        ], $connectionStatus['connected'] ? 200 : 500);
    }

    /**
     * Health check endpoint for database connections.
     * Returns detailed health information for monitoring purposes.
     */
    public function health(): JsonResponse
    {
        $mysqlStatus = $this->testConnection('mysql');
        $pgsqlStatus = $this->testConnection('pgsql');
        
        $overallHealthy = $mysqlStatus['connected'] && $pgsqlStatus['connected'];
        
        return response()->json([
            'healthy' => $overallHealthy,
            'timestamp' => now()->toISOString(),
            'databases' => [
                'mysql' => [
                    'healthy' => $mysqlStatus['connected'],
                    'status' => $mysqlStatus['status'],
                    'message' => $mysqlStatus['message'],
                    'details' => $mysqlStatus['details'] ?? null,
                    'latency_ms' => $this->measureLatency('mysql')
                ],
                'postgresql' => [
                    'healthy' => $pgsqlStatus['connected'],
                    'status' => $pgsqlStatus['status'],
                    'message' => $pgsqlStatus['message'],
                    'details' => $pgsqlStatus['details'] ?? null,
                    'latency_ms' => $this->measureLatency('pgsql')
                ]
            ],
            'summary' => [
                'total_databases' => 2,
                'healthy_count' => ($mysqlStatus['connected'] ? 1 : 0) + ($pgsqlStatus['connected'] ? 1 : 0),
                'unhealthy_count' => (!$mysqlStatus['connected'] ? 1 : 0) + (!$pgsqlStatus['connected'] ? 1 : 0)
            ]
        ], $overallHealthy ? 200 : 503);
    }

    /**
     * Measure database connection latency in milliseconds.
     */
    private function measureLatency(string $connection): ?float
    {
        try {
            $start = microtime(true);
            DB::connection($connection)->select('SELECT 1');
            $end = microtime(true);
            return round(($end - $start) * 1000, 2);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Route a query to the appropriate database based on operation type.
     */
    public function route(string $operation): JsonResponse
    {
        $routingMap = [
            'users' => 'mysql',
            'analytics' => 'pgsql',
            'user' => 'mysql',
            'analytic' => 'pgsql'
        ];

        $database = $routingMap[$operation] ?? null;

        if (!$database) {
            return response()->json([
                'success' => false,
                'message' => 'Unknown operation type',
                'available_operations' => array_keys($routingMap)
            ], 400);
        }

        $connectionStatus = $this->testConnection($database);

        return response()->json([
            'success' => $connectionStatus['connected'],
            'operation' => $operation,
            'routed_to' => $database === 'pgsql' ? 'postgresql' : $database,
            'connection_status' => $connectionStatus['status'],
            'message' => $connectionStatus['connected'] 
                ? "Operation '$operation' routed to $database successfully"
                : "Operation '$operation' cannot be routed - $database connection failed"
        ], $connectionStatus['connected'] ? 200 : 500);
    }

    /**
     * Test a database connection.
     */
    private function testConnection(string $connection): array
    {
        try {
            $pdo = DB::connection($connection)->getPdo();
            $databaseName = DB::connection($connection)->getDatabaseName();
            
            // Test with a simple query
            DB::connection($connection)->select('SELECT 1');
            
            return [
                'connected' => true,
                'status' => 'connected',
                'message' => ucfirst($connection) . ' database connection is healthy',
                'details' => [
                    'database_name' => $databaseName,
                    'driver' => $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),
                    'server_version' => $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION)
                ]
            ];
        } catch (QueryException $e) {
            return [
                'connected' => false,
                'status' => 'disconnected',
                'message' => ucfirst($connection) . ' database connection failed',
                'details' => [
                    'error' => $e->getMessage()
                ]
            ];
        } catch (\Exception $e) {
            return [
                'connected' => false,
                'status' => 'error',
                'message' => ucfirst($connection) . ' database connection error',
                'details' => [
                    'error' => $e->getMessage()
                ]
            ];
        }
    }
}