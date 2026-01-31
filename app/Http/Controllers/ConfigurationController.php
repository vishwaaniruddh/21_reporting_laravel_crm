<?php

namespace App\Http\Controllers;

use App\Services\ConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * ConfigurationController handles pipeline configuration management.
 * 
 * Provides endpoints for:
 * - GET /api/config/pipeline - Get current configuration
 * - PUT /api/config/pipeline - Update configuration
 * - POST /api/config/pipeline/reset - Reset configuration to defaults
 * 
 * Requirements: 6.5
 */
class ConfigurationController extends Controller
{
    protected ConfigurationService $configService;

    public function __construct(ConfigurationService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * GET /api/config/pipeline
     * 
     * Returns current pipeline configuration including:
     * - Batch size settings
     * - Schedule configurations (sync, cleanup, verify)
     * - Retention period
     * - Alert thresholds
     * - Off-peak hours settings
     * - Retry configuration
     * 
     * Requirements: 6.5
     */
    public function show(): JsonResponse
    {
        try {
            $config = $this->configService->getAll();

            return response()->json([
                'success' => true,
                'data' => [
                    'configuration' => $config,
                    'is_off_peak' => $this->configService->isOffPeakHours(),
                    'environment' => [
                        'app_env' => config('app.env'),
                        'allow_runtime_config' => config('pipeline.allow_runtime_config'),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get pipeline configuration', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CONFIG_ERROR',
                    'message' => 'Failed to retrieve pipeline configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * PUT /api/config/pipeline
     * 
     * Update pipeline configuration.
     * Changes are applied without restart (runtime configuration).
     * 
     * Requirements: 6.5, 6.6
     */
    public function update(Request $request): JsonResponse
    {
        try {
            // Check if runtime config is allowed
            if (!config('pipeline.allow_runtime_config', true)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'CONFIG_DISABLED',
                        'message' => 'Runtime configuration changes are disabled',
                        'details' => 'Set PIPELINE_ALLOW_RUNTIME_CONFIG=true to enable runtime configuration.',
                    ],
                ], 403);
            }

            $input = $request->all();

            // Log the configuration change attempt
            Log::info('Pipeline configuration update requested', [
                'user_id' => $request->user()?->id,
                'keys' => array_keys($input),
            ]);

            // Special handling for cleanup_enabled - require explicit confirmation
            if (isset($input['cleanup_enabled']) && $input['cleanup_enabled'] === true) {
                if (!$request->boolean('confirm_cleanup_enable')) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'CONFIRMATION_REQUIRED',
                            'message' => 'Enabling cleanup requires explicit confirmation',
                            'details' => [
                                'warning' => '⚠️ Enabling cleanup will allow DELETION of records from MySQL alerts table!',
                                'hint' => 'Include "confirm_cleanup_enable": true in your request to confirm.',
                            ],
                        ],
                    ], 400);
                }

                Log::warning('Cleanup enabled via configuration', [
                    'user_id' => $request->user()?->id,
                ]);
            }

            // Remove confirmation flag from input before processing
            unset($input['confirm_cleanup_enable']);

            $updatedConfig = $this->configService->update($input);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => 'Configuration updated successfully',
                    'configuration' => $updatedConfig,
                    'is_off_peak' => $this->configService->isOffPeakHours(),
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
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CONFIG_DISABLED',
                    'message' => $e->getMessage(),
                ],
            ], 403);
        } catch (\Exception $e) {
            Log::error('Failed to update pipeline configuration', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CONFIG_ERROR',
                    'message' => 'Failed to update pipeline configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * POST /api/config/pipeline/reset
     * 
     * Reset configuration to default values.
     * Can reset all or specific keys.
     * 
     * Requirements: 6.5
     */
    public function reset(Request $request): JsonResponse
    {
        try {
            // Check if runtime config is allowed
            if (!config('pipeline.allow_runtime_config', true)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'CONFIG_DISABLED',
                        'message' => 'Runtime configuration changes are disabled',
                    ],
                ], 403);
            }

            $validated = $request->validate([
                'keys' => 'nullable|array',
                'keys.*' => 'string',
            ]);

            $keys = $validated['keys'] ?? null;

            Log::info('Pipeline configuration reset requested', [
                'user_id' => $request->user()?->id,
                'keys' => $keys ?? 'all',
            ]);

            $config = $this->configService->reset($keys);

            return response()->json([
                'success' => true,
                'data' => [
                    'message' => $keys ? 'Specified configuration keys reset to defaults' : 'All configuration reset to defaults',
                    'reset_keys' => $keys ?? 'all',
                    'configuration' => $config,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reset pipeline configuration', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CONFIG_ERROR',
                    'message' => 'Failed to reset pipeline configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/config/pipeline/schedules
     * 
     * Get schedule-specific configuration with human-readable descriptions.
     */
    public function schedules(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'sync' => [
                        'schedule' => $this->configService->get('sync_schedule'),
                        'enabled' => $this->configService->get('sync_enabled'),
                        'description' => $this->describeCronExpression($this->configService->get('sync_schedule')),
                    ],
                    'cleanup' => [
                        'schedule' => $this->configService->get('cleanup_schedule'),
                        'enabled' => $this->configService->get('cleanup_enabled'),
                        'description' => $this->describeCronExpression($this->configService->get('cleanup_schedule')),
                        'warning' => '⚠️ Cleanup deletes records from MySQL alerts table!',
                    ],
                    'verify' => [
                        'schedule' => $this->configService->get('verify_schedule'),
                        'enabled' => $this->configService->get('verify_enabled'),
                        'description' => $this->describeCronExpression($this->configService->get('verify_schedule')),
                    ],
                    'off_peak' => [
                        'start_hour' => $this->configService->get('off_peak.start_hour'),
                        'end_hour' => $this->configService->get('off_peak.end_hour'),
                        'prefer_off_peak' => $this->configService->get('off_peak.prefer_off_peak'),
                        'is_currently_off_peak' => $this->configService->isOffPeakHours(),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CONFIG_ERROR',
                    'message' => 'Failed to retrieve schedule configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * GET /api/config/pipeline/alerts
     * 
     * Get alert threshold configuration.
     */
    public function alerts(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => [
                    'thresholds' => [
                        'warning_failures' => $this->configService->get('alerts.warning_failures'),
                        'critical_failures' => $this->configService->get('alerts.critical_failures'),
                        'max_sync_lag_minutes' => $this->configService->get('alerts.max_sync_lag_minutes'),
                    ],
                    'notifications' => [
                        'email_enabled' => $this->configService->get('alerts.email_notifications'),
                        'notification_email' => $this->configService->get('alerts.notification_email'),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'CONFIG_ERROR',
                    'message' => 'Failed to retrieve alert configuration',
                    'details' => $e->getMessage(),
                ],
            ], 500);
        }
    }

    /**
     * Provide a human-readable description of a cron expression
     */
    protected function describeCronExpression(string $expression): string
    {
        $parts = preg_split('/\s+/', trim($expression));
        
        if (count($parts) !== 5) {
            return 'Invalid cron expression';
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        // Common patterns
        if ($expression === '* * * * *') {
            return 'Every minute';
        }

        if (preg_match('/^\*\/(\d+) \* \* \* \*$/', $expression, $matches)) {
            return "Every {$matches[1]} minutes";
        }

        if (preg_match('/^(\d+) \* \* \* \*$/', $expression, $matches)) {
            return "Every hour at minute {$matches[1]}";
        }

        if (preg_match('/^(\d+) (\d+) \* \* \*$/', $expression, $matches)) {
            $h = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $m = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            return "Daily at {$h}:{$m}";
        }

        if (preg_match('/^\*\/(\d+) \* \* \* \*$/', $expression, $matches)) {
            return "Every {$matches[1]} minutes";
        }

        return "Custom schedule: {$expression}";
    }
}
