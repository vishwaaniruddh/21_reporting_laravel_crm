import { useState, useEffect, useCallback } from 'react';
import {
    getConfigurations,
    deleteConfiguration,
    enableConfiguration,
    disableConfiguration,
    triggerSync,
} from '../services/tableSyncService';

/**
 * TableSyncConfigurationList Component
 * 
 * Displays all table sync configurations with status.
 * Shows pending count, last sync time.
 * Add/Edit/Delete actions (delete removes config from PostgreSQL only).
 * 
 * ⚠️ NO DELETION FROM MYSQL: UI manages PostgreSQL config, not MySQL data
 * 
 * Requirements: 8.4, 8.5
 */
const TableSyncConfigurationList = ({ onEdit, onAdd }) => {
    const [configurations, setConfigurations] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [actionLoading, setActionLoading] = useState({});
    const [message, setMessage] = useState(null);
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(null);

    const fetchConfigurations = useCallback(async () => {
        try {
            setLoading(true);
            const response = await getConfigurations({ with_stats: true });
            if (response.success) {
                setConfigurations(response.data.configurations);
                setError(null);
            } else {
                setError(response.error?.message || 'Failed to fetch configurations');
            }
        } catch (err) {
            setError(err.response?.data?.error?.message || 'Failed to connect to server');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchConfigurations();
    }, [fetchConfigurations]);

    const handleToggleEnabled = async (config) => {
        const action = config.is_enabled ? disableConfiguration : enableConfiguration;
        setActionLoading(prev => ({ ...prev, [config.id]: 'toggle' }));
        setMessage(null);

        try {
            const response = await action(config.id);
            if (response.success) {
                setMessage({
                    type: 'success',
                    text: `Configuration ${config.is_enabled ? 'disabled' : 'enabled'} successfully`,
                });
                fetchConfigurations();
            } else {
                setMessage({ type: 'error', text: response.error?.message });
            }
        } catch (err) {
            setMessage({
                type: 'error',
                text: err.response?.data?.error?.message || 'Failed to update configuration',
            });
        } finally {
            setActionLoading(prev => ({ ...prev, [config.id]: null }));
        }
    };


    const handleDelete = async (config) => {
        setActionLoading(prev => ({ ...prev, [config.id]: 'delete' }));
        setMessage(null);

        try {
            const response = await deleteConfiguration(config.id);
            if (response.success) {
                setMessage({
                    type: 'success',
                    text: 'Configuration deleted successfully',
                });
                setShowDeleteConfirm(null);
                fetchConfigurations();
            } else {
                setMessage({ type: 'error', text: response.error?.message });
            }
        } catch (err) {
            setMessage({
                type: 'error',
                text: err.response?.data?.error?.message || 'Failed to delete configuration',
            });
        } finally {
            setActionLoading(prev => ({ ...prev, [config.id]: null }));
        }
    };

    const handleSync = async (config) => {
        setActionLoading(prev => ({ ...prev, [config.id]: 'sync' }));
        setMessage(null);

        try {
            const response = await triggerSync(config.id);
            if (response.success) {
                setMessage({
                    type: 'success',
                    text: `Sync completed: ${response.data.records_synced} records synced`,
                });
                fetchConfigurations();
            } else {
                setMessage({
                    type: 'warning',
                    text: response.data?.message || 'Sync completed with issues',
                });
            }
        } catch (err) {
            setMessage({
                type: 'error',
                text: err.response?.data?.error?.message || 'Failed to trigger sync',
            });
        } finally {
            setActionLoading(prev => ({ ...prev, [config.id]: null }));
        }
    };

    const getStatusBadge = (config) => {
        if (config.last_sync_status === 'running') {
            return <span className="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">Running</span>;
        }
        if (config.last_sync_status === 'failed') {
            return <span className="px-2 py-1 text-xs rounded-full bg-red-100 text-red-800">Failed</span>;
        }
        if (config.last_sync_status === 'completed') {
            return <span className="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">Completed</span>;
        }
        if (config.last_sync_status === 'partial') {
            return <span className="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800">Partial</span>;
        }
        return <span className="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800">Never Synced</span>;
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'Never';
        return new Date(dateString).toLocaleString();
    };

    if (loading) {
        return (
            <div className="bg-white rounded-lg shadow p-6">
                <div className="animate-pulse">
                    <div className="h-6 bg-gray-200 rounded w-1/4 mb-4"></div>
                    <div className="space-y-3">
                        <div className="h-12 bg-gray-200 rounded"></div>
                        <div className="h-12 bg-gray-200 rounded"></div>
                        <div className="h-12 bg-gray-200 rounded"></div>
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
                    <span className="font-medium">Error loading configurations</span>
                </div>
                <p className="text-gray-600 text-sm mb-4">{error}</p>
                <button
                    onClick={fetchConfigurations}
                    className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm"
                >
                    Retry
                </button>
            </div>
        );
    }


    return (
        <div className="bg-white rounded-lg shadow">
            {/* Header */}
            <div className="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <div>
                    <h2 className="text-lg font-semibold text-gray-900">Table Sync Configurations</h2>
                    <p className="text-sm text-gray-500 mt-1">
                        Manage table synchronization from MySQL to PostgreSQL
                    </p>
                </div>
                <button
                    onClick={onAdd}
                    className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm flex items-center"
                >
                    <PlusIcon className="w-4 h-4 mr-2" />
                    Add Configuration
                </button>
            </div>

            {/* Messages */}
            {message && (
                <div className={`mx-6 mt-4 p-3 rounded-md ${
                    message.type === 'success' ? 'bg-green-50 border border-green-200 text-green-700' :
                    message.type === 'warning' ? 'bg-yellow-50 border border-yellow-200 text-yellow-700' :
                    'bg-red-50 border border-red-200 text-red-700'
                }`}>
                    <p className="text-sm">{message.text}</p>
                </div>
            )}

            {/* Configuration List */}
            <div className="p-6">
                {configurations.length === 0 ? (
                    <div className="text-center py-8">
                        <DatabaseIcon className="w-12 h-12 text-gray-400 mx-auto mb-4" />
                        <p className="text-gray-500">No configurations found</p>
                        <p className="text-sm text-gray-400 mt-1">
                            Create a new configuration to start syncing tables
                        </p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {configurations.map((config) => (
                            <div
                                key={config.id}
                                className={`border rounded-lg p-4 ${
                                    config.is_enabled ? 'border-gray-200' : 'border-gray-100 bg-gray-50'
                                }`}
                            >
                                <div className="flex items-start justify-between">
                                    <div className="flex-1">
                                        <div className="flex items-center space-x-3">
                                            <h3 className="font-medium text-gray-900">{config.name}</h3>
                                            {getStatusBadge(config)}
                                            {!config.is_enabled && (
                                                <span className="px-2 py-1 text-xs rounded-full bg-gray-200 text-gray-600">
                                                    Disabled
                                                </span>
                                            )}
                                        </div>
                                        <div className="mt-2 grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
                                            <div>
                                                <p className="text-gray-500">Source Table</p>
                                                <p className="font-mono text-gray-900">{config.source_table}</p>
                                                <p className="text-xs text-blue-600 font-medium">
                                                    {config.source_count?.toLocaleString() ?? '-'} records
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-gray-500">Target Table</p>
                                                <p className="font-mono text-gray-900">
                                                    {config.target_table || config.source_table}
                                                </p>
                                                <p className="text-xs text-green-600 font-medium">
                                                    {config.target_count?.toLocaleString() ?? '-'} records
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-gray-500">Pending Sync</p>
                                                <p className={`font-medium ${config.unsynced_count > 0 ? 'text-orange-600' : 'text-green-600'}`}>
                                                    {config.unsynced_count?.toLocaleString() ?? '-'}
                                                </p>
                                                {config.sync_progress !== undefined && (
                                                    <div className="mt-1">
                                                        <div className="w-full bg-gray-200 rounded-full h-1.5">
                                                            <div 
                                                                className={`h-1.5 rounded-full ${config.sync_progress === 100 ? 'bg-green-500' : 'bg-blue-500'}`}
                                                                style={{ width: `${config.sync_progress}%` }}
                                                            ></div>
                                                        </div>
                                                        <p className="text-xs text-gray-500 mt-0.5">{config.sync_progress}% synced</p>
                                                    </div>
                                                )}
                                            </div>
                                            <div>
                                                <p className="text-gray-500">Difference</p>
                                                <p className={`font-medium ${(config.source_count - config.target_count) > 0 ? 'text-red-600' : 'text-green-600'}`}>
                                                    {config.source_count !== undefined && config.target_count !== undefined 
                                                        ? (config.source_count - config.target_count).toLocaleString()
                                                        : '-'}
                                                </p>
                                            </div>
                                            <div>
                                                <p className="text-gray-500">Last Sync</p>
                                                <p className="text-gray-900">{formatDate(config.last_sync_at)}</p>
                                            </div>
                                        </div>
                                        {config.schedule && (
                                            <div className="mt-2 text-sm">
                                                <span className="text-gray-500">Schedule: </span>
                                                <span className="font-mono text-gray-700">{config.schedule}</span>
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex items-center space-x-2 ml-4">
                                        {/* Sync Now Button */}
                                        <button
                                            onClick={() => handleSync(config)}
                                            disabled={actionLoading[config.id] || !config.is_enabled}
                                            className="p-2 text-blue-600 hover:bg-blue-50 rounded-md disabled:opacity-50 disabled:cursor-not-allowed"
                                            title="Sync Now"
                                        >
                                            {actionLoading[config.id] === 'sync' ? (
                                                <RefreshIcon className="w-5 h-5 animate-spin" />
                                            ) : (
                                                <RefreshIcon className="w-5 h-5" />
                                            )}
                                        </button>
                                        {/* Toggle Enable/Disable */}
                                        <button
                                            onClick={() => handleToggleEnabled(config)}
                                            disabled={actionLoading[config.id]}
                                            className={`p-2 rounded-md ${
                                                config.is_enabled
                                                    ? 'text-yellow-600 hover:bg-yellow-50'
                                                    : 'text-green-600 hover:bg-green-50'
                                            } disabled:opacity-50`}
                                            title={config.is_enabled ? 'Disable' : 'Enable'}
                                        >
                                            {config.is_enabled ? (
                                                <PauseIcon className="w-5 h-5" />
                                            ) : (
                                                <PlayIcon className="w-5 h-5" />
                                            )}
                                        </button>
                                        {/* Edit Button */}
                                        <button
                                            onClick={() => onEdit(config)}
                                            disabled={actionLoading[config.id]}
                                            className="p-2 text-gray-600 hover:bg-gray-100 rounded-md disabled:opacity-50"
                                            title="Edit"
                                        >
                                            <EditIcon className="w-5 h-5" />
                                        </button>
                                        {/* Delete Button */}
                                        <button
                                            onClick={() => setShowDeleteConfirm(config)}
                                            disabled={actionLoading[config.id]}
                                            className="p-2 text-red-600 hover:bg-red-50 rounded-md disabled:opacity-50"
                                            title="Delete"
                                        >
                                            <TrashIcon className="w-5 h-5" />
                                        </button>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>


            {/* Delete Confirmation Modal */}
            {showDeleteConfirm && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div className="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
                        <div className="p-6">
                            <div className="flex items-center mb-4">
                                <div className="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center mr-3">
                                    <TrashIcon className="w-6 h-6 text-red-600" />
                                </div>
                                <h3 className="text-lg font-semibold text-gray-900">
                                    Delete Configuration?
                                </h3>
                            </div>
                            <div className="text-sm text-gray-600 space-y-2">
                                <p>
                                    Are you sure you want to delete the configuration for{' '}
                                    <strong>{showDeleteConfirm.name}</strong>?
                                </p>
                                <p className="text-gray-500">
                                    This will remove the configuration and all associated sync logs and errors
                                    from PostgreSQL.
                                </p>
                                <p className="text-green-600 font-medium">
                                    ✓ No data will be deleted from MySQL source table.
                                </p>
                            </div>
                        </div>
                        <div className="px-6 py-4 bg-gray-50 rounded-b-lg flex justify-end space-x-3">
                            <button
                                onClick={() => setShowDeleteConfirm(null)}
                                disabled={actionLoading[showDeleteConfirm.id]}
                                className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                onClick={() => handleDelete(showDeleteConfirm)}
                                disabled={actionLoading[showDeleteConfirm.id]}
                                className="px-4 py-2 text-sm bg-red-600 text-white rounded-md hover:bg-red-700 disabled:opacity-50"
                            >
                                {actionLoading[showDeleteConfirm.id] === 'delete' ? 'Deleting...' : 'Delete'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

// Icon Components
const XCircleIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const PlusIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
    </svg>
);

const DatabaseIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
    </svg>
);

const RefreshIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
);

const PauseIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const PlayIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const EditIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
    </svg>
);

const TrashIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
    </svg>
);

export default TableSyncConfigurationList;
