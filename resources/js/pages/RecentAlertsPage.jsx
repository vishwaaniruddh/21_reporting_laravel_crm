import { useState, useEffect } from 'react';
import DashboardLayout from '../components/DashboardLayout';
import api from '../services/api';

/**
 * Recent Alerts Page
 * Shows alerts from MySQL from the last 15 minutes
 * READ-ONLY - No updates or deletes
 */
const RecentAlertsPage = () => {
    const [alerts, setAlerts] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [pagination, setPagination] = useState({});
    const [timeRange, setTimeRange] = useState({});
    
    // Filters
    const [panelid, setPanelid] = useState('');
    const [atmid, setAtmid] = useState('');
    const [currentPage, setCurrentPage] = useState(1);
    const [perPage, setPerPage] = useState(25);

    // Auto-refresh
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [lastRefresh, setLastRefresh] = useState(new Date());

    useEffect(() => {
        fetchAlerts();
    }, [currentPage, perPage]);

    // Auto-refresh every 30 seconds
    useEffect(() => {
        if (!autoRefresh) return;

        const interval = setInterval(() => {
            fetchAlerts();
        }, 30000); // 30 seconds

        return () => clearInterval(interval);
    }, [autoRefresh, panelid, atmid, currentPage, perPage]);

    const fetchAlerts = async () => {
        try {
            setLoading(true);
            const response = await api.get('/recent-alerts', {
                params: {
                    page: currentPage,
                    per_page: perPage,
                    panelid: panelid || undefined,
                    atmid: atmid || undefined,
                }
            });

            setAlerts(response.data.data || []);
            setPagination(response.data.pagination || {});
            setTimeRange(response.data.time_range || {});
            setLastRefresh(new Date());
            setError(null);
        } catch (err) {
            setError('Failed to load recent alerts');
            console.error('Error fetching recent alerts:', err);
        } finally {
            setLoading(false);
        }
    };

    const handleSearch = () => {
        setCurrentPage(1);
        fetchAlerts();
    };

    const handleReset = () => {
        setPanelid('');
        setAtmid('');
        setCurrentPage(1);
        fetchAlerts();
    };

    const formatDateTime = (dateString) => {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleString();
    };

    const getStatusBadge = (status) => {
        const statusMap = {
            'O': { label: 'Open', class: 'bg-red-100 text-red-800' },
            'C': { label: 'Closed', class: 'bg-green-100 text-green-800' },
            'N': { label: 'New', class: 'bg-blue-100 text-blue-800' },
        };
        const statusInfo = statusMap[status] || { label: status || '-', class: 'bg-gray-100 text-gray-800' };
        return (
            <span className={`px-2 py-0.5 text-xs font-medium rounded-full ${statusInfo.class}`}>
                {statusInfo.label}
            </span>
        );
    };

    return (
        <DashboardLayout>
            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold text-gray-900">Last 15 Minutes Alerts</h1>
                        <p className="text-xs text-gray-500 mt-1">
                            Real-time alerts from MySQL (READ-ONLY)
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <div className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                id="autoRefresh"
                                checked={autoRefresh}
                                onChange={(e) => setAutoRefresh(e.target.checked)}
                                className="rounded border-gray-300"
                            />
                            <label htmlFor="autoRefresh" className="text-xs text-gray-600">
                                Auto-refresh (30s)
                            </label>
                        </div>
                        <button
                            onClick={fetchAlerts}
                            disabled={loading}
                            className="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
                        >
                            <RefreshIcon className="w-4 h-4 mr-1.5" />
                            Refresh
                        </button>
                    </div>
                </div>

                {/* Time Range Info */}
                {timeRange.from && (
                    <div className="bg-blue-50 border border-blue-200 rounded-md p-3">
                        <div className="flex items-center justify-between text-xs">
                            <div>
                                <span className="font-medium text-blue-900">Time Range:</span>
                                <span className="text-blue-700 ml-2">
                                    {formatDateTime(timeRange.from)} - {formatDateTime(timeRange.to)}
                                </span>
                            </div>
                            <div className="text-blue-600">
                                Last updated: {lastRefresh.toLocaleTimeString()}
                            </div>
                        </div>
                    </div>
                )}

                {/* Filters */}
                <div className="bg-white shadow rounded-lg p-4">
                    <h2 className="text-sm font-medium text-gray-900 mb-3">Filters</h2>
                    <div className="grid grid-cols-1 md:grid-cols-4 gap-3">
                        <div>
                            <label className="block text-xs font-medium text-gray-700 mb-1">
                                Panel ID
                            </label>
                            <input
                                type="text"
                                value={panelid}
                                onChange={(e) => setPanelid(e.target.value)}
                                placeholder="Enter Panel ID"
                                className="w-full px-3 py-1.5 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            />
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-gray-700 mb-1">
                                ATM ID
                            </label>
                            <input
                                type="text"
                                value={atmid}
                                onChange={(e) => setAtmid(e.target.value)}
                                placeholder="Enter ATM ID"
                                className="w-full px-3 py-1.5 text-xs border border-gray-300 rounded-md focus:ring-indigo-500 focus:border-indigo-500"
                            />
                        </div>
                        <div className="flex items-end gap-2">
                            <button
                                onClick={handleSearch}
                                className="px-4 py-1.5 text-xs font-medium text-white bg-indigo-600 rounded-md hover:bg-indigo-700"
                            >
                                Search
                            </button>
                            <button
                                onClick={handleReset}
                                className="px-4 py-1.5 text-xs font-medium text-gray-700 bg-gray-100 rounded-md hover:bg-gray-200"
                            >
                                Reset
                            </button>
                        </div>
                    </div>
                </div>

                {/* Error Message */}
                {error && (
                    <div className="bg-red-50 border border-red-200 rounded-md p-3">
                        <p className="text-xs text-red-600">{error}</p>
                    </div>
                )}

                {/* Alerts Table */}
                <div className="bg-white shadow rounded-lg overflow-hidden">
                    {loading && alerts.length === 0 ? (
                        <div className="flex items-center justify-center py-12">
                            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                            <span className="ml-3 text-sm text-gray-600">Loading alerts...</span>
                        </div>
                    ) : alerts.length === 0 ? (
                        <div className="text-center py-12">
                            <p className="text-sm text-gray-500">No alerts found in the last 15 minutes</p>
                        </div>
                    ) : (
                        <>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">ID</th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Panel ID</th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">ATM ID</th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Customer</th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Zone</th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Alarm</th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Alert Type</th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Status</th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Received Time</th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Address</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {alerts.map((alert) => (
                                            <tr key={alert.id} className="hover:bg-gray-50">
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{alert.id}</td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{alert.panelid || '-'}</td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{alert.ATMID || '-'}</td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{alert.Customer || '-'}</td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{alert.zone || '-'}</td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{alert.alarm || '-'}</td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{alert.alerttype || '-'}</td>
                                                <td className="px-3 py-2 text-xs whitespace-nowrap">{getStatusBadge(alert.status)}</td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{formatDateTime(alert.receivedtime)}</td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{alert.SiteAddress || '-'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            {/* Pagination */}
                            {pagination.last_page > 1 && (
                                <div className="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                                    <div className="flex items-center justify-between">
                                        <div className="text-xs text-gray-700">
                                            Showing {pagination.from} to {pagination.to} of {pagination.total} results
                                        </div>
                                        <div className="flex gap-2">
                                            <button
                                                onClick={() => setCurrentPage(currentPage - 1)}
                                                disabled={currentPage === 1}
                                                className="px-3 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                Previous
                                            </button>
                                            <span className="px-3 py-1 text-xs text-gray-700">
                                                Page {currentPage} of {pagination.last_page}
                                            </span>
                                            <button
                                                onClick={() => setCurrentPage(currentPage + 1)}
                                                disabled={currentPage === pagination.last_page}
                                                className="px-3 py-1 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                Next
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>
            </div>
        </DashboardLayout>
    );
};

// Icon component
const RefreshIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
);

export default RecentAlertsPage;
