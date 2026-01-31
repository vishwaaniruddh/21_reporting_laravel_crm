import { useState, useEffect, useCallback } from 'react';
import { getSyncLogs } from '../services/pipelineService';

/**
 * SyncHistoryTable Component
 * 
 * Displays sync log history with pagination.
 * Shows batch details, status, duration.
 * Supports filtering by date range and status.
 * 
 * Requirements: 2.5
 */
const SyncHistoryTable = () => {
    const [logs, setLogs] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [statistics, setStatistics] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    
    // Filters
    const [filters, setFilters] = useState({
        operation: '',
        status: '',
        date_from: '',
        date_to: '',
        per_page: 20,
    });

    const fetchLogs = useCallback(async (page = 1) => {
        setLoading(true);
        try {
            const params = {
                ...filters,
                page,
            };
            // Remove empty filters
            Object.keys(params).forEach(key => {
                if (params[key] === '' || params[key] === null) {
                    delete params[key];
                }
            });

            const response = await getSyncLogs(params);
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
        fetchLogs();
    }, [fetchLogs]);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
    };

    const handlePageChange = (page) => {
        fetchLogs(page);
    };

    const clearFilters = () => {
        setFilters({
            operation: '',
            status: '',
            date_from: '',
            date_to: '',
            per_page: 20,
        });
    };

    const getStatusBadge = (status) => {
        const colors = {
            success: 'bg-green-100 text-green-800',
            failed: 'bg-red-100 text-red-800',
            partial: 'bg-yellow-100 text-yellow-800',
        };
        return colors[status] || 'bg-gray-100 text-gray-800';
    };

    const getOperationBadge = (operation) => {
        const colors = {
            sync: 'bg-blue-100 text-blue-800',
            verify: 'bg-purple-100 text-purple-800',
            cleanup: 'bg-orange-100 text-orange-800',
        };
        return colors[operation] || 'bg-gray-100 text-gray-800';
    };

    const formatDuration = (ms) => {
        if (!ms) return '-';
        if (ms < 1000) return `${ms}ms`;
        if (ms < 60000) return `${(ms / 1000).toFixed(1)}s`;
        return `${(ms / 60000).toFixed(1)}m`;
    };

    return (
        <div className="bg-white rounded-lg shadow">
            {/* Header with Filters */}
            <div className="p-4 border-b">
                <div className="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <h2 className="text-lg font-semibold text-gray-900">Sync History</h2>
                    
                    <div className="flex flex-wrap gap-2">
                        <select
                            value={filters.operation}
                            onChange={(e) => handleFilterChange('operation', e.target.value)}
                            className="px-3 py-1.5 border rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option value="">All Operations</option>
                            <option value="sync">Sync</option>
                            <option value="verify">Verify</option>
                            <option value="cleanup">Cleanup</option>
                        </select>

                        <select
                            value={filters.status}
                            onChange={(e) => handleFilterChange('status', e.target.value)}
                            className="px-3 py-1.5 border rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option value="">All Statuses</option>
                            <option value="success">Success</option>
                            <option value="failed">Failed</option>
                            <option value="partial">Partial</option>
                        </select>

                        <input
                            type="date"
                            value={filters.date_from}
                            onChange={(e) => handleFilterChange('date_from', e.target.value)}
                            className="px-3 py-1.5 border rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                            placeholder="From"
                        />

                        <input
                            type="date"
                            value={filters.date_to}
                            onChange={(e) => handleFilterChange('date_to', e.target.value)}
                            className="px-3 py-1.5 border rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                            placeholder="To"
                        />

                        <button
                            onClick={clearFilters}
                            className="px-3 py-1.5 text-sm text-gray-600 hover:text-gray-900"
                        >
                            Clear
                        </button>

                        <button
                            onClick={() => fetchLogs()}
                            className="px-3 py-1.5 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700"
                        >
                            Refresh
                        </button>
                    </div>
                </div>

                {/* Statistics Summary */}
                {statistics && (
                    <div className="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div className="text-center p-2 bg-gray-50 rounded">
                            <p className="text-2xl font-bold text-gray-900">{statistics.total_operations ?? 0}</p>
                            <p className="text-xs text-gray-500">Total Operations</p>
                        </div>
                        <div className="text-center p-2 bg-green-50 rounded">
                            <p className="text-2xl font-bold text-green-600">{statistics.successful ?? 0}</p>
                            <p className="text-xs text-gray-500">Successful</p>
                        </div>
                        <div className="text-center p-2 bg-red-50 rounded">
                            <p className="text-2xl font-bold text-red-600">{statistics.failed ?? 0}</p>
                            <p className="text-xs text-gray-500">Failed</p>
                        </div>
                        <div className="text-center p-2 bg-blue-50 rounded">
                            <p className="text-2xl font-bold text-blue-600">{statistics.total_records?.toLocaleString() ?? 0}</p>
                            <p className="text-xs text-gray-500">Records Processed</p>
                        </div>
                    </div>
                )}
            </div>

            {/* Table */}
            <div className="overflow-x-auto">
                {loading ? (
                    <div className="p-8 text-center">
                        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
                        <p className="mt-2 text-gray-500">Loading...</p>
                    </div>
                ) : error ? (
                    <div className="p-8 text-center">
                        <p className="text-red-600">{error}</p>
                        <button
                            onClick={() => fetchLogs()}
                            className="mt-2 px-4 py-2 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700"
                        >
                            Retry
                        </button>
                    </div>
                ) : logs.length === 0 ? (
                    <div className="p-8 text-center text-gray-500">
                        No sync logs found
                    </div>
                ) : (
                    <table className="min-w-full divide-y divide-gray-200">
                        <thead className="bg-gray-50">
                            <tr>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Timestamp
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Operation
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Batch ID
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Records
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Duration
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Status
                                </th>
                                <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Details
                                </th>
                            </tr>
                        </thead>
                        <tbody className="bg-white divide-y divide-gray-200">
                            {logs.map((log) => (
                                <tr key={log.id} className="hover:bg-gray-50">
                                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        {new Date(log.created_at).toLocaleString()}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        <span className={`px-2 py-1 rounded text-xs font-medium ${getOperationBadge(log.operation)}`}>
                                            {log.operation?.toUpperCase()}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
                                        #{log.batch_id}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        {log.records_affected?.toLocaleString() ?? '-'}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-600">
                                        {formatDuration(log.duration_ms)}
                                    </td>
                                    <td className="px-4 py-3 whitespace-nowrap">
                                        <span className={`px-2 py-1 rounded text-xs font-medium ${getStatusBadge(log.status)}`}>
                                            {log.status?.toUpperCase()}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 text-sm text-gray-500 max-w-xs truncate">
                                        {log.error_message || '-'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            {/* Pagination */}
            {pagination && pagination.last_page > 1 && (
                <div className="px-4 py-3 border-t flex items-center justify-between">
                    <div className="text-sm text-gray-500">
                        Showing {((pagination.current_page - 1) * pagination.per_page) + 1} to{' '}
                        {Math.min(pagination.current_page * pagination.per_page, pagination.total)} of{' '}
                        {pagination.total} results
                    </div>
                    <div className="flex gap-2">
                        <button
                            onClick={() => handlePageChange(pagination.current_page - 1)}
                            disabled={pagination.current_page === 1}
                            className="px-3 py-1 border rounded text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                        >
                            Previous
                        </button>
                        
                        {/* Page numbers */}
                        {Array.from({ length: Math.min(5, pagination.last_page) }, (_, i) => {
                            let pageNum;
                            if (pagination.last_page <= 5) {
                                pageNum = i + 1;
                            } else if (pagination.current_page <= 3) {
                                pageNum = i + 1;
                            } else if (pagination.current_page >= pagination.last_page - 2) {
                                pageNum = pagination.last_page - 4 + i;
                            } else {
                                pageNum = pagination.current_page - 2 + i;
                            }
                            return (
                                <button
                                    key={pageNum}
                                    onClick={() => handlePageChange(pageNum)}
                                    className={`px-3 py-1 border rounded text-sm ${
                                        pagination.current_page === pageNum
                                            ? 'bg-indigo-600 text-white border-indigo-600'
                                            : 'hover:bg-gray-50'
                                    }`}
                                >
                                    {pageNum}
                                </button>
                            );
                        })}

                        <button
                            onClick={() => handlePageChange(pagination.current_page + 1)}
                            disabled={pagination.current_page === pagination.last_page}
                            className="px-3 py-1 border rounded text-sm disabled:opacity-50 disabled:cursor-not-allowed hover:bg-gray-50"
                        >
                            Next
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
};

export default SyncHistoryTable;
