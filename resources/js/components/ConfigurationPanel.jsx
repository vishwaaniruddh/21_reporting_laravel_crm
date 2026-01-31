import { useState, useEffect, useCallback } from 'react';
import {
    getConfiguration,
    updateConfiguration,
    resetConfiguration,
    getSchedules,
} from '../services/configService';

/**
 * ConfigurationPanel Component
 * 
 * Display current pipeline settings.
 * Allow editing batch size, schedules, retention.
 * ⚠️ Cleanup enable/disable requires confirmation.
 * 
 * Requirements: 6.5
 */
const ConfigurationPanel = () => {
    const [config, setConfig] = useState(null);
    const [schedules, setSchedules] = useState(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [successMessage, setSuccessMessage] = useState(null);
    const [showCleanupConfirm, setShowCleanupConfirm] = useState(false);
    const [hasChanges, setHasChanges] = useState(false);
    
    // Editable form state
    const [formData, setFormData] = useState({
        batch_size: 10000,
        retention_days: 7,
        sync_enabled: true,
        cleanup_enabled: false,
        verify_enabled: true,
        sync_schedule: '*/15 * * * *',
        cleanup_schedule: '0 2 * * *',
        verify_schedule: '*/30 * * * *',
    });

    // Original data for comparison
    const [originalData, setOriginalData] = useState(null);

    const fetchConfig = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const [configResponse, schedulesResponse] = await Promise.all([
                getConfiguration(),
                getSchedules(),
            ]);

            if (configResponse.success) {
                setConfig(configResponse.data);
                const cfg = configResponse.data.configuration;
                const newFormData = {
                    batch_size: cfg.batch_size || 10000,
                    retention_days: cfg.retention_days || 7,
                    sync_enabled: cfg.sync_enabled ?? true,
                    cleanup_enabled: cfg.cleanup_enabled ?? false,
                    verify_enabled: cfg.verify_enabled ?? true,
                    sync_schedule: cfg.sync_schedule || '*/15 * * * *',
                    cleanup_schedule: cfg.cleanup_schedule || '0 2 * * *',
                    verify_schedule: cfg.verify_schedule || '*/30 * * * *',
                };
                setFormData(newFormData);
                setOriginalData(newFormData);
            }

            if (schedulesResponse.success) {
                setSchedules(schedulesResponse.data);
            }
        } catch (err) {
            setError(err.message || 'Failed to load configuration');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchConfig();
    }, [fetchConfig]);

    // Check for changes
    useEffect(() => {
        if (originalData) {
            const changed = Object.keys(formData).some(
                key => formData[key] !== originalData[key]
            );
            setHasChanges(changed);
        }
    }, [formData, originalData]);

    const handleInputChange = (field, value) => {
        setFormData(prev => ({
            ...prev,
            [field]: value,
        }));
        setSuccessMessage(null);
    };

    const handleToggle = (field) => {
        // Special handling for cleanup_enabled - requires confirmation
        if (field === 'cleanup_enabled' && !formData.cleanup_enabled) {
            setShowCleanupConfirm(true);
            return;
        }
        handleInputChange(field, !formData[field]);
    };

    const confirmEnableCleanup = () => {
        handleInputChange('cleanup_enabled', true);
        setShowCleanupConfirm(false);
    };

    const cancelEnableCleanup = () => {
        setShowCleanupConfirm(false);
    };

    const handleSave = async () => {
        setSaving(true);
        setError(null);
        setSuccessMessage(null);

        try {
            // Check if cleanup is being enabled
            const enablingCleanup = formData.cleanup_enabled && !originalData?.cleanup_enabled;
            
            const response = await updateConfiguration(formData, enablingCleanup);
            
            if (response.success) {
                setSuccessMessage('Configuration saved successfully');
                setOriginalData({ ...formData });
                setHasChanges(false);
                // Refresh config
                await fetchConfig();
            } else {
                setError(response.message || 'Failed to save configuration');
            }
        } catch (err) {
            setError(err.message || 'Failed to save configuration');
        } finally {
            setSaving(false);
        }
    };

    const handleReset = async () => {
        if (!window.confirm('Are you sure you want to reset all settings to defaults?')) {
            return;
        }

        setSaving(true);
        setError(null);
        try {
            const response = await resetConfiguration();
            if (response.success) {
                setSuccessMessage('Configuration reset to defaults');
                await fetchConfig();
            } else {
                setError(response.message || 'Failed to reset configuration');
            }
        } catch (err) {
            setError(err.message || 'Failed to reset configuration');
        } finally {
            setSaving(false);
        }
    };

    const handleCancel = () => {
        if (originalData) {
            setFormData({ ...originalData });
            setHasChanges(false);
        }
    };

    if (loading) {
        return (
            <div className="bg-white rounded-lg shadow p-6">
                <div className="animate-pulse">
                    <div className="h-6 bg-gray-200 rounded w-1/4 mb-4"></div>
                    <div className="space-y-3">
                        <div className="h-4 bg-gray-200 rounded w-3/4"></div>
                        <div className="h-4 bg-gray-200 rounded w-1/2"></div>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="bg-white rounded-lg shadow">
            {/* Header */}
            <div className="px-6 py-4 border-b border-gray-200">
                <h2 className="text-lg font-semibold text-gray-900">Pipeline Configuration</h2>
                <p className="text-sm text-gray-500 mt-1">
                    Configure sync, verification, and cleanup settings
                </p>
            </div>

            {/* Messages */}
            {error && (
                <div className="mx-6 mt-4 p-3 bg-red-50 border border-red-200 rounded-md">
                    <p className="text-sm text-red-700">{error}</p>
                </div>
            )}
            {successMessage && (
                <div className="mx-6 mt-4 p-3 bg-green-50 border border-green-200 rounded-md">
                    <p className="text-sm text-green-700">{successMessage}</p>
                </div>
            )}

            <div className="p-6 space-y-6">
                {/* Batch Settings */}
                <div>
                    <h3 className="text-sm font-medium text-gray-900 mb-3">Batch Settings</h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Batch Size
                            </label>
                            <input
                                type="number"
                                min="100"
                                max="100000"
                                step="100"
                                value={formData.batch_size}
                                onChange={(e) => handleInputChange('batch_size', parseInt(e.target.value) || 10000)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            <p className="text-xs text-gray-500 mt-1">
                                Records per batch (100 - 100,000)
                            </p>
                        </div>
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Retention Period (days)
                            </label>
                            <input
                                type="number"
                                min="1"
                                max="365"
                                value={formData.retention_days}
                                onChange={(e) => handleInputChange('retention_days', parseInt(e.target.value) || 7)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                            <p className="text-xs text-gray-500 mt-1">
                                Days to keep synced records before cleanup (1 - 365)
                            </p>
                        </div>
                    </div>
                </div>

                {/* Job Toggles */}
                <div>
                    <h3 className="text-sm font-medium text-gray-900 mb-3">Job Controls</h3>
                    <div className="space-y-3">
                        {/* Sync Toggle */}
                        <div className="flex items-center justify-between p-3 bg-gray-50 rounded-md">
                            <div>
                                <p className="text-sm font-medium text-gray-900">Sync Job</p>
                                <p className="text-xs text-gray-500">
                                    Automatically sync alerts from MySQL to PostgreSQL
                                </p>
                            </div>
                            <button
                                onClick={() => handleToggle('sync_enabled')}
                                className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${
                                    formData.sync_enabled ? 'bg-blue-600' : 'bg-gray-300'
                                }`}
                            >
                                <span
                                    className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                                        formData.sync_enabled ? 'translate-x-6' : 'translate-x-1'
                                    }`}
                                />
                            </button>
                        </div>

                        {/* Verify Toggle */}
                        <div className="flex items-center justify-between p-3 bg-gray-50 rounded-md">
                            <div>
                                <p className="text-sm font-medium text-gray-900">Verification Job</p>
                                <p className="text-xs text-gray-500">
                                    Verify synced batches for data integrity
                                </p>
                            </div>
                            <button
                                onClick={() => handleToggle('verify_enabled')}
                                className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${
                                    formData.verify_enabled ? 'bg-blue-600' : 'bg-gray-300'
                                }`}
                            >
                                <span
                                    className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                                        formData.verify_enabled ? 'translate-x-6' : 'translate-x-1'
                                    }`}
                                />
                            </button>
                        </div>

                        {/* Cleanup Toggle - with warning */}
                        <div className={`flex items-center justify-between p-3 rounded-md ${
                            formData.cleanup_enabled ? 'bg-red-50 border border-red-200' : 'bg-gray-50'
                        }`}>
                            <div>
                                <p className="text-sm font-medium text-gray-900 flex items-center">
                                    Cleanup Job
                                    {formData.cleanup_enabled && (
                                        <span className="ml-2 px-2 py-0.5 text-xs bg-red-100 text-red-700 rounded">
                                            ENABLED
                                        </span>
                                    )}
                                </p>
                                <p className="text-xs text-gray-500">
                                    ⚠️ Delete verified records from MySQL after retention period
                                </p>
                            </div>
                            <button
                                onClick={() => handleToggle('cleanup_enabled')}
                                className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors ${
                                    formData.cleanup_enabled ? 'bg-red-600' : 'bg-gray-300'
                                }`}
                            >
                                <span
                                    className={`inline-block h-4 w-4 transform rounded-full bg-white transition-transform ${
                                        formData.cleanup_enabled ? 'translate-x-6' : 'translate-x-1'
                                    }`}
                                />
                            </button>
                        </div>
                    </div>
                </div>

                {/* Schedule Settings */}
                <div>
                    <h3 className="text-sm font-medium text-gray-900 mb-3">Schedules (Cron Format)</h3>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Sync Schedule
                            </label>
                            <input
                                type="text"
                                value={formData.sync_schedule}
                                onChange={(e) => handleInputChange('sync_schedule', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                                placeholder="*/15 * * * *"
                            />
                            <p className="text-xs text-gray-500 mt-1">
                                Default: Every 15 minutes
                            </p>
                        </div>
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Verify Schedule
                            </label>
                            <input
                                type="text"
                                value={formData.verify_schedule}
                                onChange={(e) => handleInputChange('verify_schedule', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                                placeholder="*/30 * * * *"
                            />
                            <p className="text-xs text-gray-500 mt-1">
                                Default: Every 30 minutes
                            </p>
                        </div>
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Cleanup Schedule
                            </label>
                            <input
                                type="text"
                                value={formData.cleanup_schedule}
                                onChange={(e) => handleInputChange('cleanup_schedule', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm"
                                placeholder="0 2 * * *"
                            />
                            <p className="text-xs text-gray-500 mt-1">
                                Default: Daily at 2 AM
                            </p>
                        </div>
                    </div>
                </div>

                {/* Current Status */}
                {config && (
                    <div>
                        <h3 className="text-sm font-medium text-gray-900 mb-3">Current Status</h3>
                        <div className="bg-gray-50 rounded-md p-4">
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                <div>
                                    <p className="text-gray-500">Last Sync</p>
                                    <p className="font-medium">
                                        {config.last_sync_at 
                                            ? new Date(config.last_sync_at).toLocaleString()
                                            : 'Never'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-gray-500">Last Verify</p>
                                    <p className="font-medium">
                                        {config.last_verify_at
                                            ? new Date(config.last_verify_at).toLocaleString()
                                            : 'Never'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-gray-500">Last Cleanup</p>
                                    <p className="font-medium">
                                        {config.last_cleanup_at
                                            ? new Date(config.last_cleanup_at).toLocaleString()
                                            : 'Never'}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-gray-500">Pending Records</p>
                                    <p className="font-medium">
                                        {config.pending_count?.toLocaleString() || '0'}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Schedule Info */}
                {schedules && (
                    <div>
                        <h3 className="text-sm font-medium text-gray-900 mb-3">Next Scheduled Runs</h3>
                        <div className="bg-blue-50 rounded-md p-4">
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                {schedules.sync_next_run && (
                                    <div>
                                        <p className="text-blue-600">Next Sync</p>
                                        <p className="font-medium text-blue-900">
                                            {new Date(schedules.sync_next_run).toLocaleString()}
                                        </p>
                                    </div>
                                )}
                                {schedules.verify_next_run && (
                                    <div>
                                        <p className="text-blue-600">Next Verify</p>
                                        <p className="font-medium text-blue-900">
                                            {new Date(schedules.verify_next_run).toLocaleString()}
                                        </p>
                                    </div>
                                )}
                                {schedules.cleanup_next_run && (
                                    <div>
                                        <p className="text-blue-600">Next Cleanup</p>
                                        <p className="font-medium text-blue-900">
                                            {new Date(schedules.cleanup_next_run).toLocaleString()}
                                        </p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* Actions */}
            <div className="px-6 py-4 border-t border-gray-200 flex justify-between">
                <button
                    onClick={handleReset}
                    disabled={saving}
                    className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 disabled:opacity-50"
                >
                    Reset to Defaults
                </button>
                <div className="space-x-3">
                    {hasChanges && (
                        <button
                            onClick={handleCancel}
                            disabled={saving}
                            className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 disabled:opacity-50"
                        >
                            Cancel
                        </button>
                    )}
                    <button
                        onClick={handleSave}
                        disabled={saving || !hasChanges}
                        className={`px-4 py-2 text-sm rounded-md ${
                            hasChanges
                                ? 'bg-blue-600 text-white hover:bg-blue-700'
                                : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                        } disabled:opacity-50`}
                    >
                        {saving ? 'Saving...' : 'Save Changes'}
                    </button>
                </div>
            </div>

            {/* Cleanup Confirmation Modal */}
            {showCleanupConfirm && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
                        <div className="p-6">
                            <div className="flex items-center mb-4">
                                <div className="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center mr-3">
                                    <svg className="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                    </svg>
                                </div>
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Enable Cleanup Job?
                                </h3>
                            </div>
                            <div className="text-sm text-gray-600 space-y-2">
                                <p>
                                    <strong>⚠️ Warning:</strong> Enabling the cleanup job will allow 
                                    automatic deletion of records from the MySQL alerts table.
                                </p>
                                <p>
                                    Records will only be deleted if they:
                                </p>
                                <ul className="list-disc list-inside ml-2 space-y-1">
                                    <li>Have been synced to PostgreSQL</li>
                                    <li>Have been verified successfully</li>
                                    <li>Are older than the retention period ({formData.retention_days} days)</li>
                                </ul>
                                <p className="text-red-600 font-medium">
                                    This action cannot be undone. Deleted records are permanently removed.
                                </p>
                            </div>
                        </div>
                        <div className="px-6 py-4 bg-gray-50 rounded-b-lg flex justify-end space-x-3">
                            <button
                                onClick={cancelEnableCleanup}
                                className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800"
                            >
                                Cancel
                            </button>
                            <button
                                onClick={confirmEnableCleanup}
                                className="px-4 py-2 text-sm bg-red-600 text-white rounded-md hover:bg-red-700"
                            >
                                Enable Cleanup
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

export default ConfigurationPanel;
