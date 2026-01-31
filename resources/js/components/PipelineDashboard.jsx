import { useState, useEffect, useCallback } from 'react';
import { getPipelineStatus, triggerSync } from '../services/pipelineService';

/**
 * PipelineDashboard Component
 * 
 * Displays sync status (idle/running/failed), progress for active sync jobs,
 * and records synced, pending, last sync time.
 * 
 * Requirements: 2.2, 2.3
 */
const PipelineDashboard = () => {
    const [status, setStatus] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [triggering, setTriggering] = useState(false);
    const [triggerMessage, setTriggerMessage] = useState(null);

    const fetchStatus = useCallback(async () => {
        try {
            const response = await getPipelineStatus();
            if (response.success) {
                setStatus(response.data);
                setError(null);
            } else {
                setError(response.error?.message || 'Failed to fetch status');
            }
        } catch (err) {
            setError(err.response?.data?.error?.message || 'Failed to connect to server');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchStatus();
        // Auto-refresh every 10 seconds
        const interval = setInterval(fetchStatus, 10000);
        return () => clearInterval(interval);
    }, [fetchStatus]);

    const handleTriggerSync = async () => {
        setTriggering(true);
        setTriggerMessage(null);
        try {
            const response = await triggerSync();
            if (response.success) {
                setTriggerMessage({ type: 'success', text: response.data.message });
                fetchStatus();
            } else {
                setTriggerMessage({ type: 'error', text: response.error?.message });
            }
        } catch (err) {
            setTriggerMessage({ 
                type: 'error', 
                text: err.response?.data?.error?.message || 'Failed to trigger sync' 
            });
        } finally {
            setTriggering(false);
        }
    };

    const getStatusColor = (status) => {
        switch (status) {
            case 'healthy': return 'bg-green-100 text-green-800';
            case 'running': return 'bg-blue-100 text-blue-800';
            case 'warning': return 'bg-yellow-100 text-yellow-800';
            case 'failed': return 'bg-red-100 text-red-800';
            case 'degraded': return 'bg-orange-100 text-orange-800';
            default: return 'bg-gray-100 text-gray-800';
        }
    };

    const getStatusIcon = (status) => {
        switch (status) {
            case 'healthy':
                return <CheckCircleIcon className="w-5 h-5 text-green-500" />;
            case 'running':
                return <RefreshIcon className="w-5 h-5 text-blue-500 animate-spin" />;
            case 'warning':
                return <ExclamationIcon className="w-5 h-5 text-yellow-500" />;
            case 'failed':
                return <XCircleIcon className="w-5 h-5 text-red-500" />;
            case 'degraded':
                return <ExclamationIcon className="w-5 h-5 text-orange-500" />;
            default:
                return <QuestionIcon className="w-5 h-5 text-gray-500" />;
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

    if (error) {
        return (
            <div className="bg-white rounded-lg shadow p-6">
                <div className="flex items-center text-red-600 mb-4">
                    <XCircleIcon className="w-5 h-5 mr-2" />
                    <span className="font-medium">Error loading pipeline status</span>
                </div>
                <p className="text-gray-600 text-sm mb-4">{error}</p>
                <button
                    onClick={fetchStatus}
                    className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm"
                >
                    Retry
                </button>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            {/* Overall Status Card */}
            <div className="bg-white rounded-lg shadow p-6">
                <div className="flex items-center justify-between mb-4">
                    <h2 className="text-lg font-semibold text-gray-900">Pipeline Status</h2>
                    <div className="flex items-center space-x-2">
                        <span className={`px-3 py-1 rounded-full text-sm font-medium ${getStatusColor(status?.overall_status)}`}>
                            {status?.overall_status?.toUpperCase() || 'UNKNOWN'}
                        </span>
                        {getStatusIcon(status?.overall_status)}
                    </div>
                </div>

                {/* Trigger Message */}
                {triggerMessage && (
                    <div className={`mb-4 p-3 rounded-md ${triggerMessage.type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'}`}>
                        {triggerMessage.text}
                    </div>
                )}

                {/* Sync Job Status */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="border rounded-lg p-4">
                        <div className="flex items-center justify-between mb-3">
                            <h3 className="font-medium text-gray-700">Sync Job</h3>
                            <span className={`px-2 py-1 rounded text-xs font-medium ${getStatusColor(status?.sync_job?.status)}`}>
                                {status?.sync_job?.status?.toUpperCase() || 'IDLE'}
                            </span>
                        </div>
                        {status?.sync_job?.status === 'running' && (
                            <div className="mb-3">
                                <div className="flex justify-between text-sm text-gray-600 mb-1">
                                    <span>Progress</span>
                                    <span>{status?.sync_job?.batches_processed || 0} batches</span>
                                </div>
                                <div className="w-full bg-gray-200 rounded-full h-2">
                                    <div className="bg-blue-600 h-2 rounded-full animate-pulse" style={{ width: '60%' }}></div>
                                </div>
                                <p className="text-xs text-gray-500 mt-1">
                                    {status?.sync_job?.total_processed?.toLocaleString() || 0} records processed
                                </p>
                            </div>
                        )}
                        {status?.sync_job?.error && (
                            <p className="text-sm text-red-600 mb-2">{status.sync_job.error}</p>
                        )}
                        <button
                            onClick={handleTriggerSync}
                            disabled={triggering || status?.sync_job?.status === 'running'}
                            className="w-full px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 disabled:bg-gray-400 disabled:cursor-not-allowed text-sm"
                        >
                            {triggering ? 'Triggering...' : status?.sync_job?.status === 'running' ? 'Sync Running...' : 'Trigger Sync'}
                        </button>
                    </div>

                    <div className="border rounded-lg p-4">
                        <div className="flex items-center justify-between mb-3">
                            <h3 className="font-medium text-gray-700">Cleanup Job</h3>
                            <span className={`px-2 py-1 rounded text-xs font-medium ${getStatusColor(status?.cleanup_job?.status)}`}>
                                {status?.cleanup_job?.status?.toUpperCase() || 'IDLE'}
                            </span>
                        </div>
                        {status?.cleanup_job?.records_deleted > 0 && (
                            <p className="text-sm text-gray-600 mb-2">
                                {status.cleanup_job.records_deleted.toLocaleString()} records deleted
                            </p>
                        )}
                        {status?.cleanup_job?.error && (
                            <p className="text-sm text-red-600">{status.cleanup_job.error}</p>
                        )}
                        <p className="text-xs text-gray-500">
                            {status?.configuration?.cleanup_enabled ? '⚠️ Cleanup enabled' : 'Cleanup disabled'}
                        </p>
                    </div>
                </div>
            </div>

            {/* Records Statistics */}
            <div className="bg-white rounded-lg shadow p-6">
                <h2 className="text-lg font-semibold text-gray-900 mb-4">Record Statistics</h2>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <StatCard
                        label="MySQL Unsynced"
                        value={status?.records?.mysql_unsynced}
                        color="yellow"
                    />
                    <StatCard
                        label="MySQL Synced"
                        value={status?.records?.mysql_synced}
                        color="blue"
                    />
                    <StatCard
                        label="MySQL Total"
                        value={status?.records?.mysql_total}
                        color="gray"
                    />
                    <StatCard
                        label="PostgreSQL Total"
                        value={status?.records?.postgresql_total}
                        color="green"
                    />
                </div>
            </div>

            {/* Last Sync Info */}
            {status?.last_sync && (
                <div className="bg-white rounded-lg shadow p-6">
                    <h2 className="text-lg font-semibold text-gray-900 mb-4">Last Successful Sync</h2>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div>
                            <p className="text-gray-500">Timestamp</p>
                            <p className="font-medium">{new Date(status.last_sync.timestamp).toLocaleString()}</p>
                        </div>
                        <div>
                            <p className="text-gray-500">Records Synced</p>
                            <p className="font-medium">{status.last_sync.records_affected?.toLocaleString()}</p>
                        </div>
                        <div>
                            <p className="text-gray-500">Duration</p>
                            <p className="font-medium">{status.last_sync.duration_ms}ms</p>
                        </div>
                        <div>
                            <p className="text-gray-500">Batch ID</p>
                            <p className="font-medium">#{status.last_sync.batch_id}</p>
                        </div>
                    </div>
                </div>
            )}

            {/* Batch Statistics */}
            <div className="bg-white rounded-lg shadow p-6">
                <h2 className="text-lg font-semibold text-gray-900 mb-4">Batch Statistics</h2>
                <div className="grid grid-cols-3 md:grid-cols-6 gap-4">
                    <BatchStatCard label="Pending" value={status?.batches?.pending} color="gray" />
                    <BatchStatCard label="Processing" value={status?.batches?.processing} color="blue" />
                    <BatchStatCard label="Completed" value={status?.batches?.completed} color="indigo" />
                    <BatchStatCard label="Verified" value={status?.batches?.verified} color="green" />
                    <BatchStatCard label="Failed" value={status?.batches?.failed} color="red" />
                    <BatchStatCard label="Cleaned" value={status?.batches?.cleaned} color="purple" />
                </div>
            </div>

            {/* Database Health */}
            <div className="bg-white rounded-lg shadow p-6">
                <h2 className="text-lg font-semibold text-gray-900 mb-4">Database Health</h2>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <DatabaseHealthCard
                        name="MySQL"
                        health={status?.health?.databases?.mysql}
                    />
                    <DatabaseHealthCard
                        name="PostgreSQL"
                        health={status?.health?.databases?.postgresql}
                    />
                </div>
                {status?.health?.has_recent_failures && (
                    <div className="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded-md">
                        <p className="text-sm text-yellow-800">
                            ⚠️ {status.health.consecutive_failures} consecutive failures detected
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
};

// Sub-components
const StatCard = ({ label, value, color }) => {
    const colorClasses = {
        yellow: 'bg-yellow-50 border-yellow-200',
        blue: 'bg-blue-50 border-blue-200',
        green: 'bg-green-50 border-green-200',
        gray: 'bg-gray-50 border-gray-200',
    };

    return (
        <div className={`p-4 rounded-lg border ${colorClasses[color] || colorClasses.gray}`}>
            <p className="text-xs text-gray-500 uppercase tracking-wide">{label}</p>
            <p className="text-2xl font-bold text-gray-900">{value?.toLocaleString() ?? '-'}</p>
        </div>
    );
};

const BatchStatCard = ({ label, value, color }) => {
    const colorClasses = {
        gray: 'text-gray-600',
        blue: 'text-blue-600',
        indigo: 'text-indigo-600',
        green: 'text-green-600',
        red: 'text-red-600',
        purple: 'text-purple-600',
    };

    return (
        <div className="text-center">
            <p className={`text-2xl font-bold ${colorClasses[color] || colorClasses.gray}`}>
                {value ?? 0}
            </p>
            <p className="text-xs text-gray-500">{label}</p>
        </div>
    );
};

const DatabaseHealthCard = ({ name, health }) => {
    const isHealthy = health?.status === 'healthy';
    
    return (
        <div className={`p-4 rounded-lg border ${isHealthy ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200'}`}>
            <div className="flex items-center justify-between">
                <span className="font-medium text-gray-700">{name}</span>
                <span className={`px-2 py-1 rounded text-xs font-medium ${isHealthy ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
                    {health?.status?.toUpperCase() || 'UNKNOWN'}
                </span>
            </div>
            {health?.latency_ms && (
                <p className="text-sm text-gray-600 mt-1">Latency: {health.latency_ms}ms</p>
            )}
            {health?.error && (
                <p className="text-sm text-red-600 mt-1">{health.error}</p>
            )}
        </div>
    );
};

// Icon components
const CheckCircleIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const XCircleIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const ExclamationIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
    </svg>
);

const RefreshIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
);

const QuestionIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

export default PipelineDashboard;
