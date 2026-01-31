import { useState, useEffect, useCallback } from 'react';
import {
    getOverview,
    triggerSync,
    triggerSyncAll,
    resumeSync,
    forceUnlock,
} from '../services/tableSyncService';
import DashboardLayout from './DashboardLayout';
import TableSyncConfigurationList from './TableSyncConfigurationList';
import TableSyncConfigurationForm from './TableSyncConfigurationForm';
import TableSyncLogsView from './TableSyncLogsView';

/**
 * TableSyncDashboard Component
 * 
 * Overview of all table syncs with:
 * - Status indicators (idle, running, failed)
 * - Quick actions (sync now, view logs)
 * 
 * ⚠️ NO DELETION FROM MYSQL: Dashboard displays status, sync action reads MySQL
 * 
 * Requirements: 6.5, 8.5
 */
const TableSyncDashboard = () => {
    const [overview, setOverview] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [activeTab, setActiveTab] = useState('overview');
    const [editingConfig, setEditingConfig] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [syncingAll, setSyncingAll] = useState(false);
    const [message, setMessage] = useState(null);

    const fetchOverview = useCallback(async () => {
        try {
            const response = await getOverview();
            if (response.success) {
                setOverview(response.data);
                setError(null);
            } else {
                setError(response.error?.message || 'Failed to fetch overview');
            }
        } catch (err) {
            setError(err.response?.data?.error?.message || 'Failed to connect to server');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchOverview();
        // Auto-refresh every 15 seconds
        const interval = setInterval(fetchOverview, 15000);
        return () => clearInterval(interval);
    }, [fetchOverview]);

    const handleSyncAll = async () => {
        setSyncingAll(true);
        setMessage(null);

        try {
            const response = await triggerSyncAll();
            if (response.success) {
                setMessage({
                    type: 'success',
                    text: `Sync completed: ${response.data.summary.total_records_synced} records synced across ${response.data.summary.successful} tables`,
                });
                fetchOverview();
            } else {
                setMessage({
                    type: 'warning',
                    text: response.data?.message || 'Some syncs completed with issues',
                });
            }
        } catch (err) {
            setMessage({
                type: 'error',
                text: err.response?.data?.error?.message || 'Failed to trigger sync',
            });
        } finally {
            setSyncingAll(false);
        }
    };

    const handleEdit = (config) => {
        setEditingConfig(config);
        setShowForm(true);
    };

    const handleAdd = () => {
        setEditingConfig(null);
        setShowForm(true);
    };

    const handleFormSave = () => {
        setShowForm(false);
        setEditingConfig(null);
        fetchOverview();
    };

    const handleFormCancel = () => {
        setShowForm(false);
        setEditingConfig(null);
    };

    const getStatusColor = (status) => {
        switch (status) {
            case 'running': return 'bg-blue-100 text-blue-800';
            case 'idle': return 'bg-green-100 text-green-800';
            case 'failed': return 'bg-red-100 text-red-800';
            case 'paused': return 'bg-yellow-100 text-yellow-800';
            default: return 'bg-gray-100 text-gray-800';
        }
    };


    if (loading) {
        return (
            <DashboardLayout>
                <div className="space-y-6">
                    <div className="bg-white rounded-lg shadow p-6">
                        <div className="animate-pulse">
                            <div className="h-6 bg-gray-200 rounded w-1/4 mb-4"></div>
                            <div className="grid grid-cols-4 gap-4">
                                <div className="h-20 bg-gray-200 rounded"></div>
                                <div className="h-20 bg-gray-200 rounded"></div>
                                <div className="h-20 bg-gray-200 rounded"></div>
                                <div className="h-20 bg-gray-200 rounded"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </DashboardLayout>
        );
    }

    if (error) {
        return (
            <DashboardLayout>
                <div className="bg-white rounded-lg shadow p-6">
                    <div className="flex items-center text-red-600 mb-4">
                        <XCircleIcon className="w-5 h-5 mr-2" />
                        <span className="font-medium">Error loading dashboard</span>
                    </div>
                    <p className="text-gray-600 text-sm mb-4">{error}</p>
                    <button
                        onClick={fetchOverview}
                        className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm"
                    >
                        Retry
                    </button>
                </div>
            </DashboardLayout>
        );
    }

    // Show form if adding/editing
    if (showForm) {
        return (
            <DashboardLayout>
                <TableSyncConfigurationForm
                    configuration={editingConfig}
                    onSave={handleFormSave}
                    onCancel={handleFormCancel}
                />
            </DashboardLayout>
        );
    }

    return (
        <DashboardLayout>
        <div className="space-y-6">
            {/* Header with Tabs */}
            <div className="bg-white rounded-lg shadow">
                <div className="px-6 py-4 border-b border-gray-200">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-xl font-semibold text-gray-900">Table Sync Dashboard</h1>
                            <p className="text-sm text-gray-500 mt-1">
                                Manage and monitor table synchronization from MySQL to PostgreSQL
                            </p>
                        </div>
                        <button
                            onClick={handleSyncAll}
                            disabled={syncingAll || !overview?.tables?.some(t => t.is_enabled)}
                            className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center"
                        >
                            {syncingAll ? (
                                <>
                                    <RefreshIcon className="w-4 h-4 mr-2 animate-spin" />
                                    Syncing All...
                                </>
                            ) : (
                                <>
                                    <RefreshIcon className="w-4 h-4 mr-2" />
                                    Sync All Tables
                                </>
                            )}
                        </button>
                    </div>
                </div>

                {/* Tabs */}
                <div className="px-6 border-b border-gray-200">
                    <nav className="-mb-px flex space-x-8">
                        <button
                            onClick={() => setActiveTab('overview')}
                            className={`py-4 px-1 border-b-2 font-medium text-sm ${
                                activeTab === 'overview'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                        >
                            Overview
                        </button>
                        <button
                            onClick={() => setActiveTab('configurations')}
                            className={`py-4 px-1 border-b-2 font-medium text-sm ${
                                activeTab === 'configurations'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                        >
                            Configurations
                        </button>
                        <button
                            onClick={() => setActiveTab('logs')}
                            className={`py-4 px-1 border-b-2 font-medium text-sm ${
                                activeTab === 'logs'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                            }`}
                        >
                            Sync Logs
                        </button>
                    </nav>
                </div>
            </div>

            {/* Messages */}
            {message && (
                <div className={`p-4 rounded-md ${
                    message.type === 'success' ? 'bg-green-50 border border-green-200 text-green-700' :
                    message.type === 'warning' ? 'bg-yellow-50 border border-yellow-200 text-yellow-700' :
                    'bg-red-50 border border-red-200 text-red-700'
                }`}>
                    <p className="text-sm">{message.text}</p>
                </div>
            )}


            {/* Tab Content */}
            {activeTab === 'overview' && (
                <div className="space-y-6">
                    {/* Statistics Cards */}
                    <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
                        <StatCard
                            label="Total Tables"
                            value={overview?.tables?.length || 0}
                            color="gray"
                        />
                        <StatCard
                            label="Enabled"
                            value={overview?.tables?.filter(t => t.is_enabled).length || 0}
                            color="green"
                        />
                        <StatCard
                            label="Total Source Records"
                            value={overview?.tables?.reduce((sum, t) => sum + (t.source_count || 0), 0) || 0}
                            color="blue"
                        />
                        <StatCard
                            label="Total Target Records"
                            value={overview?.tables?.reduce((sum, t) => sum + (t.target_count || 0), 0) || 0}
                            color="green"
                        />
                        <StatCard
                            label="Pending Sync"
                            value={overview?.tables?.reduce((sum, t) => sum + (t.unsynced_count || 0), 0) || 0}
                            color={overview?.tables?.reduce((sum, t) => sum + (t.unsynced_count || 0), 0) > 0 ? 'yellow' : 'green'}
                        />
                    </div>

                    {/* Table Status Grid */}
                    <div className="bg-white rounded-lg shadow">
                        <div className="px-6 py-4 border-b border-gray-200">
                            <h2 className="text-lg font-semibold text-gray-900">Table Status</h2>
                        </div>
                        <div className="p-6">
                            {overview?.tables?.length === 0 ? (
                                <div className="text-center py-8">
                                    <DatabaseIcon className="w-12 h-12 text-gray-400 mx-auto mb-4" />
                                    <p className="text-gray-500">No table sync configurations</p>
                                    <button
                                        onClick={handleAdd}
                                        className="mt-4 px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm"
                                    >
                                        Create First Configuration
                                    </button>
                                </div>
                            ) : (
                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    {overview.tables.map((table) => (
                                        <TableStatusCard
                                            key={table.id}
                                            table={table}
                                            onSync={() => handleTableSync(table.id)}
                                            onViewLogs={() => {
                                                setActiveTab('logs');
                                            }}
                                            getStatusColor={getStatusColor}
                                            onRefresh={fetchOverview}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Recent Activity */}
                    {overview?.overall_statistics?.recent_syncs?.length > 0 && (
                        <div className="bg-white rounded-lg shadow">
                            <div className="px-6 py-4 border-b border-gray-200">
                                <h2 className="text-lg font-semibold text-gray-900">Recent Activity</h2>
                            </div>
                            <div className="p-6">
                                <div className="space-y-3">
                                    {overview.overall_statistics.recent_syncs.slice(0, 5).map((sync, index) => (
                                        <div key={index} className="flex items-center justify-between text-sm">
                                            <div className="flex items-center">
                                                <span className={`w-2 h-2 rounded-full mr-3 ${
                                                    sync.status === 'completed' ? 'bg-green-500' :
                                                    sync.status === 'failed' ? 'bg-red-500' :
                                                    'bg-yellow-500'
                                                }`}></span>
                                                <span className="font-mono text-gray-700">{sync.source_table}</span>
                                            </div>
                                            <div className="flex items-center space-x-4 text-gray-500">
                                                <span>{sync.records_synced} records</span>
                                                <span>{new Date(sync.completed_at).toLocaleString()}</span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {activeTab === 'configurations' && (
                <TableSyncConfigurationList
                    onEdit={handleEdit}
                    onAdd={handleAdd}
                />
            )}

            {activeTab === 'logs' && (
                <TableSyncLogsView />
            )}
        </div>
        </DashboardLayout>
    );
};

// Sub-components
const StatCard = ({ label, value, color }) => {
    const colorClasses = {
        gray: 'bg-gray-50 border-gray-200',
        green: 'bg-green-50 border-green-200',
        blue: 'bg-blue-50 border-blue-200',
        red: 'bg-red-50 border-red-200',
        yellow: 'bg-yellow-50 border-yellow-200',
    };

    return (
        <div className={`p-4 rounded-lg border ${colorClasses[color] || colorClasses.gray}`}>
            <p className="text-xs text-gray-500 uppercase tracking-wide">{label}</p>
            <p className="text-2xl font-bold text-gray-900">{value?.toLocaleString() ?? '-'}</p>
        </div>
    );
};


const TableStatusCard = ({ table, onSync, onViewLogs, getStatusColor, onRefresh }) => {
    const [syncing, setSyncing] = useState(false);
    const [unlocking, setUnlocking] = useState(false);

    const handleSync = async () => {
        setSyncing(true);
        try {
            await triggerSync(table.id);
            if (onRefresh) onRefresh();
        } finally {
            setSyncing(false);
        }
    };

    const handleForceUnlock = async () => {
        if (!confirm('Are you sure you want to force unlock this sync? This will release the lock and allow a new sync to start.')) {
            return;
        }
        setUnlocking(true);
        try {
            const response = await forceUnlock(table.id);
            if (response.success) {
                alert('Sync unlocked successfully. You can now start a new sync.');
                if (onRefresh) onRefresh();
            } else {
                alert(response.error?.message || 'Failed to unlock sync');
            }
        } catch (err) {
            alert('Failed to unlock sync: ' + (err.response?.data?.error?.message || err.message));
        } finally {
            setUnlocking(false);
        }
    };

    const difference = (table.source_count || 0) - (table.target_count || 0);
    const isLocked = table.status === 'running' || table.status === 'locked';

    return (
        <div className={`border rounded-lg p-4 ${table.is_enabled ? 'border-gray-200' : 'border-gray-100 bg-gray-50'}`}>
            <div className="flex items-start justify-between mb-3">
                <div>
                    <h3 className="font-medium text-gray-900">{table.name}</h3>
                    <p className="text-xs font-mono text-gray-500">{table.source_table}</p>
                </div>
                <span className={`px-2 py-1 text-xs rounded-full ${getStatusColor(table.status)}`}>
                    {table.status?.toUpperCase() || 'UNKNOWN'}
                </span>
            </div>
            
            <div className="space-y-2 text-sm">
                <div className="flex justify-between">
                    <span className="text-gray-500">MySQL (Source)</span>
                    <span className="font-medium text-blue-600">{table.source_count?.toLocaleString() || 0}</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-gray-500">PostgreSQL (Target)</span>
                    <span className="font-medium text-green-600">{table.target_count?.toLocaleString() || 0}</span>
                </div>
                <div className="flex justify-between">
                    <span className="text-gray-500">Difference</span>
                    <span className={`font-medium ${difference > 0 ? 'text-red-600' : 'text-green-600'}`}>
                        {difference.toLocaleString()}
                    </span>
                </div>
                <div className="flex justify-between">
                    <span className="text-gray-500">Pending Sync</span>
                    <span className={`font-medium ${table.unsynced_count > 0 ? 'text-orange-600' : 'text-green-600'}`}>
                        {table.unsynced_count?.toLocaleString() || 0}
                    </span>
                </div>
                {table.sync_progress !== undefined && (
                    <div className="mt-2">
                        <div className="flex justify-between text-xs mb-1">
                            <span className="text-gray-500">Progress</span>
                            <span className={table.sync_progress === 100 ? 'text-green-600' : 'text-blue-600'}>
                                {table.sync_progress}%
                            </span>
                        </div>
                        <div className="w-full bg-gray-200 rounded-full h-2">
                            <div 
                                className={`h-2 rounded-full transition-all ${table.sync_progress === 100 ? 'bg-green-500' : 'bg-blue-500'}`}
                                style={{ width: `${table.sync_progress}%` }}
                            ></div>
                        </div>
                    </div>
                )}
                <div className="flex justify-between pt-1 border-t border-gray-100">
                    <span className="text-gray-500">Last Sync</span>
                    <span className="text-gray-700 text-xs">
                        {table.last_sync_at ? new Date(table.last_sync_at).toLocaleString() : 'Never'}
                    </span>
                </div>
                {table.unresolved_errors > 0 && (
                    <div className="flex justify-between text-red-600">
                        <span>Errors</span>
                        <span className="font-medium">{table.unresolved_errors}</span>
                    </div>
                )}
            </div>

            <div className="mt-4 flex space-x-2">
                {isLocked ? (
                    <button
                        onClick={handleForceUnlock}
                        disabled={unlocking}
                        className="flex-1 px-3 py-1.5 text-xs bg-red-600 text-white rounded hover:bg-red-700 disabled:bg-gray-300 disabled:cursor-not-allowed"
                    >
                        {unlocking ? 'Unlocking...' : 'Force Unlock'}
                    </button>
                ) : (
                    <button
                        onClick={handleSync}
                        disabled={syncing || !table.is_enabled}
                        className="flex-1 px-3 py-1.5 text-xs bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:bg-gray-300 disabled:cursor-not-allowed"
                    >
                        {syncing ? 'Syncing...' : 'Sync Now'}
                    </button>
                )}
                <button
                    onClick={onViewLogs}
                    className="px-3 py-1.5 text-xs border border-gray-300 text-gray-700 rounded hover:bg-gray-50"
                >
                    Logs
                </button>
            </div>
        </div>
    );
};

// Icon Components
const XCircleIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const RefreshIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
);

const DatabaseIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
    </svg>
);

export default TableSyncDashboard;
