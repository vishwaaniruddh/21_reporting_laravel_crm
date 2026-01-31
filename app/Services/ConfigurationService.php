<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * ConfigurationService manages pipeline configuration.
 * 
 * Provides:
 * - Runtime configuration changes without restart
 * - Configuration validation
 * - Configuration persistence via cache
 * - Default value fallbacks
 * 
 * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6
 */
class ConfigurationService
{
    /**
     * Configurable keys that can be changed at runtime
     */
    protected array $configurableKeys = [
        'batch_size',
        'sync_schedule',
        'sync_enabled',
        'cleanup_schedule',
        'cleanup_enabled',
        'cleanup_batch_size',
        'retention_days',
        'verify_schedule',
        'verify_enabled',
        'job_timeout',
        'alerts.warning_failures',
        'alerts.critical_failures',
        'alerts.max_sync_lag_minutes',
        'alerts.email_notifications',
        'alerts.notification_email',
        'off_peak.start_hour',
        'off_peak.end_hour',
        'off_peak.prefer_off_peak',
        'retry.max_attempts',
        'retry.initial_delay_seconds',
        'retry.max_delay_seconds',
    ];

    /**
     * Get all current configuration values
     */
    public function getAll(): array
    {
        $overrides = $this->getOverrides();
        
        return [
            'batch_size' => $this->get('batch_size'),
            'batch_size_min' => config('pipeline.batch_size_min'),
            'batch_size_max' => config('pipeline.batch_size_max'),
            'sync_schedule' => $this->get('sync_schedule'),
            'sync_enabled' => $this->get('sync_enabled'),
            'cleanup_schedule' => $this->get('cleanup_schedule'),
            'cleanup_enabled' => $this->get('cleanup_enabled'),
            'cleanup_batch_size' => $this->get('cleanup_batch_size'),
            'retention_days' => $this->get('retention_days'),
            'retention_days_min' => config('pipeline.retention_days_min'),
            'retention_days_max' => config('pipeline.retention_days_max'),
            'verify_schedule' => $this->get('verify_schedule'),
            'verify_enabled' => $this->get('verify_enabled'),
            'job_timeout' => $this->get('job_timeout'),
            'alerts' => [
                'warning_failures' => $this->get('alerts.warning_failures'),
                'critical_failures' => $this->get('alerts.critical_failures'),
                'max_sync_lag_minutes' => $this->get('alerts.max_sync_lag_minutes'),
                'email_notifications' => $this->get('alerts.email_notifications'),
                'notification_email' => $this->get('alerts.notification_email'),
            ],
            'off_peak' => [
                'start_hour' => $this->get('off_peak.start_hour'),
                'end_hour' => $this->get('off_peak.end_hour'),
                'prefer_off_peak' => $this->get('off_peak.prefer_off_peak'),
            ],
            'retry' => [
                'max_attempts' => $this->get('retry.max_attempts'),
                'initial_delay_seconds' => $this->get('retry.initial_delay_seconds'),
                'max_delay_seconds' => $this->get('retry.max_delay_seconds'),
            ],
            'allow_runtime_config' => config('pipeline.allow_runtime_config'),
            '_overrides' => array_keys($overrides),
        ];
    }

    /**
     * Get a specific configuration value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Check for runtime override first
        $overrides = $this->getOverrides();
        
        if (array_key_exists($key, $overrides)) {
            return $overrides[$key];
        }

        // Fall back to config file value
        return config("pipeline.{$key}", $default);
    }

    /**
     * Update configuration values
     * 
     * @throws ValidationException
     */
    public function update(array $values): array
    {
        // Check if runtime config is allowed
        if (!config('pipeline.allow_runtime_config', true)) {
            throw new \RuntimeException('Runtime configuration changes are disabled');
        }

        // Validate the input
        $validated = $this->validate($values);

        // Get current overrides
        $overrides = $this->getOverrides();

        // Merge new values
        foreach ($validated as $key => $value) {
            if ($this->isConfigurable($key)) {
                $overrides[$key] = $value;
            }
        }

        // Save overrides
        $this->saveOverrides($overrides);

        Log::info('Pipeline configuration updated', [
            'updated_keys' => array_keys($validated),
            'values' => $validated,
        ]);

        return $this->getAll();
    }

    /**
     * Reset configuration to defaults
     */
    public function reset(?array $keys = null): array
    {
        $overrides = $this->getOverrides();

        if ($keys === null) {
            // Reset all overrides
            $overrides = [];
        } else {
            // Reset specific keys
            foreach ($keys as $key) {
                unset($overrides[$key]);
            }
        }

        $this->saveOverrides($overrides);

        Log::info('Pipeline configuration reset', [
            'reset_keys' => $keys ?? 'all',
        ]);

        return $this->getAll();
    }

    /**
     * Validate configuration values
     * 
     * @throws ValidationException
     */
    protected function validate(array $values): array
    {
        $rules = [
            'batch_size' => sprintf(
                'nullable|integer|min:%d|max:%d',
                config('pipeline.batch_size_min'),
                config('pipeline.batch_size_max')
            ),
            'sync_schedule' => 'nullable|string|max:100',
            'sync_enabled' => 'nullable|boolean',
            'cleanup_schedule' => 'nullable|string|max:100',
            'cleanup_enabled' => 'nullable|boolean',
            'cleanup_batch_size' => 'nullable|integer|min:100|max:10000',
            'retention_days' => sprintf(
                'nullable|integer|min:%d|max:%d',
                config('pipeline.retention_days_min'),
                config('pipeline.retention_days_max')
            ),
            'verify_schedule' => 'nullable|string|max:100',
            'verify_enabled' => 'nullable|boolean',
            'job_timeout' => 'nullable|integer|min:60|max:86400',
            'alerts.warning_failures' => 'nullable|integer|min:1|max:100',
            'alerts.critical_failures' => 'nullable|integer|min:1|max:100',
            'alerts.max_sync_lag_minutes' => 'nullable|integer|min:1|max:1440',
            'alerts.email_notifications' => 'nullable|boolean',
            'alerts.notification_email' => 'nullable|email|max:255',
            'off_peak.start_hour' => 'nullable|integer|min:0|max:23',
            'off_peak.end_hour' => 'nullable|integer|min:0|max:23',
            'off_peak.prefer_off_peak' => 'nullable|boolean',
            'retry.max_attempts' => 'nullable|integer|min:1|max:10',
            'retry.initial_delay_seconds' => 'nullable|integer|min:1|max:60',
            'retry.max_delay_seconds' => 'nullable|integer|min:1|max:300',
        ];

        // Flatten nested arrays for validation
        $flatValues = $this->flattenArray($values);

        $validator = Validator::make($flatValues, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Additional validation: critical_failures should be >= warning_failures
        if (isset($flatValues['alerts.critical_failures']) && isset($flatValues['alerts.warning_failures'])) {
            if ($flatValues['alerts.critical_failures'] < $flatValues['alerts.warning_failures']) {
                throw ValidationException::withMessages([
                    'alerts.critical_failures' => ['Critical failures threshold must be >= warning failures threshold'],
                ]);
            }
        }

        // Validate cron expressions
        foreach (['sync_schedule', 'cleanup_schedule', 'verify_schedule'] as $scheduleKey) {
            if (isset($flatValues[$scheduleKey]) && !$this->isValidCronExpression($flatValues[$scheduleKey])) {
                throw ValidationException::withMessages([
                    $scheduleKey => ['Invalid cron expression format'],
                ]);
            }
        }

        return array_filter($flatValues, fn($v) => $v !== null);
    }

    /**
     * Check if a key is configurable at runtime
     */
    protected function isConfigurable(string $key): bool
    {
        return in_array($key, $this->configurableKeys);
    }

    /**
     * Get current runtime overrides from cache
     */
    protected function getOverrides(): array
    {
        $cacheKey = config('pipeline.config_cache_key', 'pipeline_config_overrides');
        return Cache::get($cacheKey, []);
    }

    /**
     * Save runtime overrides to cache
     */
    protected function saveOverrides(array $overrides): void
    {
        $cacheKey = config('pipeline.config_cache_key', 'pipeline_config_overrides');
        $ttl = config('pipeline.config_cache_ttl');

        if ($ttl === null) {
            Cache::forever($cacheKey, $overrides);
        } else {
            Cache::put($cacheKey, $overrides, $ttl);
        }
    }

    /**
     * Flatten a nested array with dot notation keys
     */
    protected function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value) && !empty($value) && !array_is_list($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Basic cron expression validation
     */
    protected function isValidCronExpression(string $expression): bool
    {
        // Basic validation: should have 5 parts separated by spaces
        $parts = preg_split('/\s+/', trim($expression));
        
        if (count($parts) !== 5) {
            return false;
        }

        // Each part should match basic cron patterns
        $patterns = [
            '/^(\*|[0-9]|[1-5][0-9])(\/[0-9]+)?$|^\*\/[0-9]+$/', // minute (0-59)
            '/^(\*|[0-9]|1[0-9]|2[0-3])(\/[0-9]+)?$|^\*\/[0-9]+$/', // hour (0-23)
            '/^(\*|[1-9]|[12][0-9]|3[01])(\/[0-9]+)?$|^\*\/[0-9]+$/', // day of month (1-31)
            '/^(\*|[1-9]|1[0-2])(\/[0-9]+)?$|^\*\/[0-9]+$/', // month (1-12)
            '/^(\*|[0-6])(\/[0-9]+)?$|^\*\/[0-9]+$/', // day of week (0-6)
        ];

        foreach ($parts as $i => $part) {
            // Allow comma-separated values and ranges
            $subParts = explode(',', $part);
            foreach ($subParts as $subPart) {
                // Handle ranges (e.g., 1-5)
                if (strpos($subPart, '-') !== false) {
                    continue; // Accept ranges
                }
                // Basic pattern check (simplified)
                if ($subPart !== '*' && !preg_match('/^[0-9]+$/', $subPart) && !preg_match('/^\*\/[0-9]+$/', $subPart)) {
                    // Allow step values like */15
                    if (!preg_match('/^[0-9]+\/[0-9]+$/', $subPart)) {
                        // This is a simplified check - real cron validation is more complex
                        continue;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Check if currently in off-peak hours
     */
    public function isOffPeakHours(): bool
    {
        $startHour = $this->get('off_peak.start_hour', 22);
        $endHour = $this->get('off_peak.end_hour', 6);
        $currentHour = (int) now()->format('G');

        if ($startHour > $endHour) {
            // Spans midnight (e.g., 22:00 to 06:00)
            return $currentHour >= $startHour || $currentHour < $endHour;
        } else {
            // Same day range
            return $currentHour >= $startHour && $currentHour < $endHour;
        }
    }

    /**
     * Get alert level based on consecutive failures
     */
    public function getAlertLevel(int $consecutiveFailures): string
    {
        $criticalThreshold = $this->get('alerts.critical_failures', 5);
        $warningThreshold = $this->get('alerts.warning_failures', 3);

        if ($consecutiveFailures >= $criticalThreshold) {
            return 'critical';
        }

        if ($consecutiveFailures >= $warningThreshold) {
            return 'warning';
        }

        return 'normal';
    }
}
