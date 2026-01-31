<?php

namespace App\Http\Controllers;

use App\Services\TableSyncConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * TableSyncConfigurationController handles CRUD operations for table sync configurations.
 * 
 * Provides endpoints for:
 * - GET /api/table-sync/configurations - List all configurations
 * - POST /api/table-sync/configurations - Create new configuration
 * - GET /api/table-sync/configurations/{id} - Get single configuration
 * - PUT /api/table-sync/configurations/{id} - Update configuration
 * - DELETE /api/table-sync/configurations/{id} - Delete configuration (from PostgreSQL only)
 * 
 * ⚠️ NO DELETION FROM MYSQL: All CRUD operates on PostgreSQL config table
 * 
 * Requirements: 8.1
 */
class TableSyncConfigurationController extends Controller
{
    protected TableSyncConfigurationService $configService;

    public function __construct(TableSyncConfigurationService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * GET /api/table-sync/configurations
     * 
     * List all table sync configurations.
     * Supports filtering by enabled status and includes statistics.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Use filter to convert string 'true'/'false' to boolean
            $enabledOnly = filter_var($request->input('enabled_only', false), FILTER_VALIDATE_BOOLEAN);
            $withStats = filter_var($request->input('with_stats', false), FILTER_VALIDATE_BOOLEAN);

            if ($withStats) {
                $configurations = $this->configService->getAllWithStats();
            } else {
                $configurations = $this->configService->getAll($enabledOnly);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'configurations' => $configurations,
                    'total' => $configurations->count(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list table sync configurations', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'LIST_ERROR',
                    'message' => 'Failed to retrieve table sync configurations',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/configurations
     * 
     * Create a new table sync configuration.
     * Validates source table exists in MySQL and column mappings are valid.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Creates config in PostgreSQL only
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            Log::info('Creating table sync configuration', [
                'user_id' => $request->user()?->id,
                'source_table' => $data['source_table'] ?? null,
            ]);

            $configuration = $this->configService->create($data);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Table sync configuration created successfully',
                    'configuration' => $configuration,
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Configuration validation failed',
                    'details' => $e->errors(),
                ],
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to create table sync configuration', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CREATE_ERROR',
                    'message' => 'Failed to create table sync configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/table-sync/configurations/{id}
     * 
     * Get a single table sync configuration by ID.
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $configuration = $this->configService->getById($id);

            if (!$configuration) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => "Configuration with ID {$id} not found",
                    ],
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'configuration' => $configuration,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get table sync configuration', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'FETCH_ERROR',
                    'message' => 'Failed to retrieve table sync configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * PUT /api/table-sync/configurations/{id}
     * 
     * Update an existing table sync configuration.
     * Validates changes before applying.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Updates config in PostgreSQL only
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $data = $request->all();

            Log::info('Updating table sync configuration', [
                'id' => $id,
                'user_id' => $request->user()?->id,
                'updated_fields' => array_keys($data),
            ]);

            $configuration = $this->configService->update($id, $data);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Table sync configuration updated successfully',
                    'configuration' => $configuration,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Configuration validation failed',
                    'details' => $e->errors(),
                ],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => $e->getMessage(),
                ],
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to update table sync configuration', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UPDATE_ERROR',
                    'message' => 'Failed to update table sync configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * DELETE /api/table-sync/configurations/{id}
     * 
     * Delete a table sync configuration.
     * Also deletes associated logs and errors from PostgreSQL.
     * 
     * ⚠️ NO DELETION FROM MYSQL: Only deletes from PostgreSQL tables
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            Log::info('Deleting table sync configuration', ['id' => $id]);

            $this->configService->delete($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Table sync configuration deleted successfully',
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => $e->getMessage(),
                ],
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to delete table sync configuration', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DELETE_ERROR',
                    'message' => 'Failed to delete table sync configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/configurations/{id}/enable
     * 
     * Enable a table sync configuration.
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function enable(int $id): JsonResponse
    {
        try {
            $configuration = $this->configService->enable($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Table sync configuration enabled',
                    'configuration' => $configuration,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => $e->getMessage(),
                ],
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to enable table sync configuration', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ENABLE_ERROR',
                    'message' => 'Failed to enable table sync configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/configurations/{id}/disable
     * 
     * Disable a table sync configuration.
     * 
     * @param int $id
     * @return JsonResponse
     */
    public function disable(int $id): JsonResponse
    {
        try {
            $configuration = $this->configService->disable($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Table sync configuration disabled',
                    'configuration' => $configuration,
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => $e->getMessage(),
                ],
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to disable table sync configuration', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DISABLE_ERROR',
                    'message' => 'Failed to disable table sync configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/configurations/test
     * 
     * Test a configuration without creating it.
     * Validates source table, column mappings, and returns schema info.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function test(Request $request): JsonResponse
    {
        try {
            $data = $request->all();
            $result = $this->configService->testConfiguration($data);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to test table sync configuration', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'TEST_ERROR',
                    'message' => 'Failed to test table sync configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/table-sync/configurations/{id}/duplicate
     * 
     * Duplicate an existing configuration with a new name and source table.
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function duplicate(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'source_table' => 'required|string|max:255',
            ]);

            $configuration = $this->configService->duplicate(
                $id,
                $validated['name'],
                $validated['source_table']
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Table sync configuration duplicated successfully',
                    'configuration' => $configuration,
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Validation failed',
                    'details' => $e->errors(),
                ],
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => $e->getMessage(),
                ],
            ], 404);
        } catch (\Exception $e) {
            Log::error('Failed to duplicate table sync configuration', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DUPLICATE_ERROR',
                    'message' => 'Failed to duplicate table sync configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }
}
