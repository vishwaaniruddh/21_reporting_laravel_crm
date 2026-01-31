import { useState, useEffect, useCallback } from 'react';
import { getLogs, getConfigurations } from '../services/tableSyncService';

/**
 * TableSyncLogsView Component
 * 
 * Filterable log table with:
 * - Filter by table, date range, status
 * - Error details expansion
 * 
 * ⚠️ NO DELETION FROM MYSQL: Logs are read from PostgreSQL
 * 
 * Requirements: 6.2, 6.6
 */
const TableSyncLogsView = ({ configurationId = null }) => {
    const [logs, setLogs] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [statistics, setStatistics] = useState(null);
    const [configurations, setConfigurations] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [expandedLog, setExpandedLog] = useState(null);
    
    // Filters
    const [filters, setFilters] = useState({
        configuration_id: configurationId || '',
        status: '',
        date_from: '',
        date_to: '',
        per_page: 20,
    });

    const fetchConfigurations = useCallback(async () => {
        try {
            const response = await getConfigurations();
            if (response.success) {
                setConfigurations(response.data.configurations);
            }
        } catch (err) {
            console.error('Failed to fetch configurations:', err);
        }
    }, []);

    const fetchLogs = useCallback(async (page = 1) => {
        try {
            setLoading(true);
            const params = {
                ...Object.fromEntries(
                    Object.entries(filters).filter(([_, v]) => v !== '')
                ),
                page,
            };
            
            const response = await getLogs(params);
            if (response.success) {
                setLogs(response.data.logs);
                setPagination(response.data.pagination);
                setStatistics(response.data.statistics);
                setError(null);
            } else {
                setError(response.error?.message || 'Failed to fetch logs');
            }
        } catch (err) {
            setError(err.response?.data?.error?.message || 'Failed to connect to server');
        } finally {
            setLoading(false);
        }
    }, [filters]);

    useEffect(() => {
        fetchConfigurations();
    }, [fetchConfigurations]);

    useEffect(() => {
        fetchLogs();
    }, [fetchLogs]);

    const handleFilterChange = (field, value) => {
        setFilters(prev => ({ ...prev, [field]: value }));
    };

    const handleApplyFilters = () => {
        fetchLogs(1);
    };

    const handleClearFilters = () => {
        setFilters({
            configuration_id: '',
            status: '',
            date_from: '',
            date_to: '',
            per_page: 20,
        });
    };

    const handlePageChange = (page) => {
        fetchLogs(page);
    };

    const getStatusBadge = (status) => {
        const styles = {
            running: 'bg-blue-100 text-blue-800',
            completed: 'bg-green-100 text-green-800',
            failed: 'bg-red-100 text-red-800',
            partial: 'bg-yellow-100 text-yellow-800',
        };
        return (
            <span className={`px-2 py-1 text-xs rounded-full ${styles[status] || 'bg-gray-100 text-gray-800'}`}>
                {status?.toUpperCase() || 'UNKNOWN'}
            </span>
        );
    };

    const formatDuration = (ms) => {
        if (!ms) return '-';
        if (ms < 1000) return `${ms}ms`;
        if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
        return `${(ms / 60000).toFixed(1)}m`;
    };

    const formatDate = (dateString) => {
        if (!dateString) return '-';
        return new Date(dateString).toLocaleString();
    };


    if (loading && logs.length === 0) {
        return (
            <div className="bg-white rounded-lg shadow p-6">
                <div className="animate-pulse">
                    <div className="h-6 bg-gray-200 rounded w-1/4 mb-4"></div>
                    <div className="space-y-3">
                        <div className="h-10 bg-gray-200 rounded"></div>
                        <div className="h-10 bg-gray-200 rounded"></div>
                        <div className="h-10 bg-gray-200 rounded"></div>
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
                    <span className="font-medium">Error loading logs</span>
                </div>
                <p className="text-gray-600 text-sm mb-4">{error}</p>
                <button
                    onClick={() => fetchLogs()}
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
            <div className="px-6 py-4 border-b border-gray-200">
                <h2 className="text-lg font-semibold text-gray-900">Sync Logs</h2>
                <p className="text-sm text-gray-500 mt-1">
                    View synchronization history and details
                </p>
            </div>

            {/* Filters */}
            <div className="px-6 py-4 bg-gray-50 border-b border-gray-200">
                <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label className="block text-xs text-gray-500 mb-1">Table</label>
                        <select
                            value={filters.configuration_id}
                            onChange={(e) => handleFilterChange('configuration_id', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            <option value="">All Tables</option>
                            {configurations.map((config) => (
                                <option key={config.id} value={config.id}>
                                    {config.name} ({config.source_table})
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs text-gray-500 mb-1">Status</label>
                        <select
                            value={filters.status}
                            onChange={(e) => handleFilterChange('status', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        >
                            <option value="">All Statuses</option>
                            <option value="running">Running</option>
                            <option value="completed">Completed</option>
                            <option value="failed">Failed</option>
                            <option value="partial">Partial</option>
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs text-gray-500 mb-1">From Date</label>
                        <input
                            type="date"
                            value={filters.date_from}
                            onChange={(e) => handleFilterChange('date_from', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>
                    <div>
                        <label className="block text-xs text-gray-500 mb-1">To Date</label>
                        <input
                            type="date"
                            value={filters.date_to}
                            onChange={(e) => handleFilterChange('date_to', e.target.value)}
                            className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>
                    <div className="flex items-end space-x-2">
                        <button
                            onClick={handleApplyFilters}
                            className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm"
                        >
                            Apply
                        </button>
                        <button
                            onClick={handleClearFilters}
                            className="px-4 py-2 text-gray-600 hover:text-gray-800 text-sm"
                        >
                            Clear
                        </button>
                    </div>
                </div>
            </div>

            {/* Statistics */}
            {statistics && (
                <div className="px-6 py-3 bg-blue-50 border-b border-blue-100">
                    <div className="flex items-center space-x-6 text-sm">
                        <span className="text-blue-700">
                            <strong>{statistics.total_syncs || 0}</strong> total syncs
                        </span>
                        <span className="text-green-700">
                            <strong>{statistics.successful_syncs || 0}</strong> successful
                        </span>
                        <span className="text-red-700">
                            <strong>{statistics.failed_syncs || 0}</strong> failed
                        </span>
                        <span className="text-gray-700">
                            <strong>{(statistics.total_records_synced || 0).toLocaleString()}</strong> records synced
                        </span>
                    </div>
                </div>
            )}


            {/* Logs Table */}
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Table
                            </th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Status
                            </th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Records
                            </th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Duration
                            </th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Started
                            </th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {logs.length === 0 ? (
                            <tr>
                                <td colSpan="6" className="px-6 py-8 text-center text-gray-500">
                                    No sync logs found
                                </td>
                            </tr>
                        ) : (
                            logs.map((log) => (
                                <>
                                    <tr key={log.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            <span className="font-mono text-sm text-gray-900">
                                                {log.source_table}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            {getStatusBadge(log.status)}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm">
                                            <span className="text-green-600">{log.records_synced || 0}</span>
                                            {log.records_failed > 0 && (
                                                <span className="text-red-600 ml-2">
                                                    ({log.records_failed} failed)
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {formatDuration(log.duration_ms)}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {formatDate(log.started_at)}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm">
                                            <button
                                                onClick={() => setExpandedLog(expandedLog === log.id ? null : log.id)}
                                                className="text-indigo-600 hover:text-indigo-900"
                                            >
                                                {expandedLog === log.id ? 'Hide Details' : 'View Details'}
                                            </button>
                                        </td>
                                    </tr>
                                    {expandedLog === log.id && (
                                        <tr key={`${log.id}-details`}>
                                            <td colSpan="6" className="px-6 py-4 bg-gray-50">
                                                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                                    <div>
                                                        <p className="text-gray-500">Log ID</p>
                                                        <p className="font-medium">{log.id}</p>
                                                    </div>
                                                    <div>
                                                        <p className="text-gray-500">Configuration ID</p>
                                                        <p className="font-medium">{log.configuration_id}</p>
                                                    </div>
                                                    <div>
                                                        <p className="text-gray-500">ID Range</p>
                                                        <p className="font-medium">
                                                            {log.start_id || '-'} → {log.end_id || '-'}
                                                        </p>
                                                    </div>
                                                    <div>
                                                        <p className="text-gray-500">Completed</p>
                                                        <p className="font-medium">{formatDate(log.completed_at)}</p>
                                                    </div>
                                                </div>
                                                {log.error_message && (
                                                    <div className="mt-4 p-3 bg-red-50 border border-red-200 rounded-md">
                                                        <p className="text-xs text-red-600 font-medium mb-1">Error Message:</p>
                                                        <p className="text-sm text-red-800 font-mono whitespace-pre-wrap">
                                                            {log.error_message}
                                                        </p>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    )}
                                </>
                            ))
                        )}
                    </tbody>
                </table>
            </div>


            {/* Pagination */}
            {pagination && pagination.last_page > 1 && (
                <div className="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                    <div className="text-sm text-gray-500">
                        Showing {((pagination.current_page - 1) * pagination.per_page) + 1} to{' '}
                        {Math.min(pagination.current_page * pagination.per_page, pagination.total)} of{' '}
                        {pagination.total} results
                    </div>
                    <div className="flex space-x-2">
                        <button
                            onClick={() => handlePageChange(pagination.current_page - 1)}
                            disabled={pagination.current_page === 1}
                            className="px-3 py-1 border border-gray-300 rounded text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                        >
                            Previous
                        </button>
                        {[...Array(Math.min(5, pagination.last_page))].map((_, i) => {
                            let page;
                            if (pagination.last_page <= 5) {
                                page = i + 1;
                            } else if (pagination.current_page <= 3) {
                                page = i + 1;
                            } else if (pagination.current_page >= pagination.last_page - 2) {
                                page = pagination.last_page - 4 + i;
                            } else {
                                page = pagination.current_page - 2 + i;
                            }
                            
                            return (
                                <button
                                    key={page}
                                    onClick={() => handlePageChange(page)}
                                    className={`px-3 py-1 border rounded text-sm ${
                                        pagination.current_page === page
                                            ? 'bg-indigo-600 text-white border-indigo-600'
                                            : 'border-gray-300 hover:bg-gray-50'
                                    }`}
                                >
                                    {page}
                                </button>
                            );
                        })}
                        <button
                            onClick={() => handlePageChange(pagination.current_page + 1)}
                            disabled={pagination.current_page === pagination.last_page}
                            className="px-3 py-1 border border-gray-300 rounded text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                        >
                            Next
                        </button>
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

export default TableSyncLogsView;
