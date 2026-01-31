import { useState, useEffect, useCallback } from 'react';
import {
    getDailyReport,
    getSummaryReport,
    getFilterOptions,
    exportCsv,
    exportPdf,
    downloadBlob,
} from '../services/reportService';

/**
 * ReportingDashboard Component
 * 
 * Report generation interface with filter controls for date, type, severity.
 * Export buttons for CSV/PDF.
 * 
 * Requirements: 5.2, 5.4
 */
const ReportingDashboard = () => {
    const [reportType, setReportType] = useState('daily');
    const [reportData, setReportData] = useState(null);
    const [filterOptions, setFilterOptions] = useState(null);
    const [loading, setLoading] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [error, setError] = useState(null);

    // Filters
    const [filters, setFilters] = useState({
        date: new Date().toISOString().split('T')[0],
        date_from: '',
        date_to: '',
        alert_type: '',
        priority: '',
        panel_id: '',
    });

    // Fetch filter options on mount
    useEffect(() => {
        const fetchFilterOptions = async () => {
            try {
                const response = await getFilterOptions();
                if (response.success) {
                    setFilterOptions(response.data);
                }
            } catch (err) {
                console.error('Failed to fetch filter options:', err);
            }
        };
        fetchFilterOptions();
    }, []);

    const generateReport = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            let response;
            if (reportType === 'daily') {
                response = await getDailyReport(filters.date);
            } else {
                const summaryFilters = {
                    date_from: filters.date_from,
                    date_to: filters.date_to,
                    alert_type: filters.alert_type,
                    priority: filters.priority,
                    panel_id: filters.panel_id,
                };
                // Remove empty filters
                Object.keys(summaryFilters).forEach(key => {
                    if (!summaryFilters[key]) delete summaryFilters[key];
                });
                response = await getSummaryReport(summaryFilters);
            }

            if (response.success) {
                setReportData(response.data);
            } else {
                setError(response.error?.message || 'Failed to generate report');
            }
        } catch (err) {
            setError(err.response?.data?.error?.message || 'Failed to connect to server');
        } finally {
            setLoading(false);
        }
    }, [reportType, filters]);

    const handleExport = async (format) => {
        setExporting(true);
        try {
            const exportFilters = {
                date_from: reportType === 'daily' ? filters.date : filters.date_from,
                date_to: reportType === 'daily' ? filters.date : filters.date_to,
                alert_type: filters.alert_type,
                priority: filters.priority,
                panel_id: filters.panel_id,
            };
            // Remove empty filters
            Object.keys(exportFilters).forEach(key => {
                if (!exportFilters[key]) delete exportFilters[key];
            });

            let blob;
            let filename;
            if (format === 'csv') {
                blob = await exportCsv(exportFilters);
                filename = `alerts_report_${new Date().toISOString().split('T')[0]}.csv`;
            } else {
                blob = await exportPdf(exportFilters);
                filename = `alerts_report_${new Date().toISOString().split('T')[0]}.pdf`;
            }
            downloadBlob(blob, filename);
        } catch (err) {
            setError(err.response?.data?.error?.message || `Failed to export ${format.toUpperCase()}`);
        } finally {
            setExporting(false);
        }
    };

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
    };

    return (
        <div className="space-y-6">
            {/* Report Type Selection */}
            <div className="bg-white rounded-lg shadow p-6">
                <h2 className="text-lg font-semibold text-gray-900 mb-4">Generate Report</h2>
                
                <div className="flex gap-4 mb-6">
                    <button
                        onClick={() => setReportType('daily')}
                        className={`px-4 py-2 rounded-md text-sm font-medium ${
                            reportType === 'daily'
                                ? 'bg-indigo-600 text-white'
                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                        }`}
                    >
                        Daily Report
                    </button>
                    <button
                        onClick={() => setReportType('summary')}
                        className={`px-4 py-2 rounded-md text-sm font-medium ${
                            reportType === 'summary'
                                ? 'bg-indigo-600 text-white'
                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                        }`}
                    >
                        Summary Report
                    </button>
                </div>

                {/* Filters */}
                <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
                    {reportType === 'daily' ? (
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Date</label>
                            <input
                                type="date"
                                value={filters.date}
                                onChange={(e) => handleFilterChange('date', e.target.value)}
                                className="w-full px-3 py-2 border rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                            />
                        </div>
                    ) : (
                        <>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">From Date</label>
                                <input
                                    type="date"
                                    value={filters.date_from}
                                    onChange={(e) => handleFilterChange('date_from', e.target.value)}
                                    className="w-full px-3 py-2 border rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">To Date</label>
                                <input
                                    type="date"
                                    value={filters.date_to}
                                    onChange={(e) => handleFilterChange('date_to', e.target.value)}
                                    className="w-full px-3 py-2 border rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                                />
                            </div>
                        </>
                    )}

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Alert Type</label>
                        <select
                            value={filters.alert_type}
                            onChange={(e) => handleFilterChange('alert_type', e.target.value)}
                            className="w-full px-3 py-2 border rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option value="">All Types</option>
                            {filterOptions?.alert_types?.map(type => (
                                <option key={type} value={type}>{type}</option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Priority</label>
                        <select
                            value={filters.priority}
                            onChange={(e) => handleFilterChange('priority', e.target.value)}
                            className="w-full px-3 py-2 border rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option value="">All Priorities</option>
                            {filterOptions?.priorities?.map(priority => (
                                <option key={priority} value={priority}>{priority}</option>
                            ))}
                        </select>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-gray-700 mb-1">Panel ID</label>
                        <select
                            value={filters.panel_id}
                            onChange={(e) => handleFilterChange('panel_id', e.target.value)}
                            className="w-full px-3 py-2 border rounded-md text-sm focus:ring-indigo-500 focus:border-indigo-500"
                        >
                            <option value="">All Panels</option>
                            {filterOptions?.panel_ids?.map(panel => (
                                <option key={panel} value={panel}>{panel}</option>
                            ))}
                        </select>
                    </div>
                </div>

                {/* Action Buttons */}
                <div className="flex flex-wrap gap-3">
                    <button
                        onClick={generateReport}
                        disabled={loading}
                        className="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 disabled:bg-gray-400 disabled:cursor-not-allowed"
                    >
                        {loading ? 'Generating...' : 'Generate Report'}
                    </button>
                    <button
                        onClick={() => handleExport('csv')}
                        disabled={exporting}
                        className="px-4 py-2 bg-green-600 text-white rounded-md text-sm font-medium hover:bg-green-700 disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center gap-2"
                    >
                        <DownloadIcon className="w-4 h-4" />
                        Export CSV
                    </button>
                    <button
                        onClick={() => handleExport('pdf')}
                        disabled={exporting}
                        className="px-4 py-2 bg-red-600 text-white rounded-md text-sm font-medium hover:bg-red-700 disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center gap-2"
                    >
                        <DocumentIcon className="w-4 h-4" />
                        Export PDF
                    </button>
                </div>

                {error && (
                    <div className="mt-4 p-3 bg-red-50 border border-red-200 rounded-md text-red-800 text-sm">
                        {error}
                    </div>
                )}
            </div>

            {/* Report Results */}
            {reportData && (
                <div className="bg-white rounded-lg shadow p-6">
                    <h3 className="text-lg font-semibold text-gray-900 mb-4">
                        {reportType === 'daily' ? 'Daily Report' : 'Summary Report'}
                        {reportData.date && ` - ${reportData.date}`}
                    </h3>

                    {/* Statistics Cards */}
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                        <StatCard
                            label="Total Alerts"
                            value={reportData.total_alerts ?? reportData.statistics?.total_alerts}
                            color="blue"
                        />
                        <StatCard
                            label="High Priority"
                            value={reportData.statistics?.by_priority?.high ?? reportData.alerts_by_priority?.high}
                            color="red"
                        />
                        <StatCard
                            label="Medium Priority"
                            value={reportData.statistics?.by_priority?.medium ?? reportData.alerts_by_priority?.medium}
                            color="yellow"
                        />
                        <StatCard
                            label="Low Priority"
                            value={reportData.statistics?.by_priority?.low ?? reportData.alerts_by_priority?.low}
                            color="green"
                        />
                    </div>

                    {/* Alerts by Type */}
                    {(reportData.alerts_by_type || reportData.statistics?.by_type) && (
                        <div className="mb-6">
                            <h4 className="text-sm font-medium text-gray-700 mb-3">Alerts by Type</h4>
                            <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
                                {Object.entries(reportData.alerts_by_type || reportData.statistics?.by_type || {}).map(([type, count]) => (
                                    <div key={type} className="p-3 bg-gray-50 rounded-lg">
                                        <p className="text-lg font-bold text-gray-900">{count}</p>
                                        <p className="text-xs text-gray-500 truncate" title={type}>{type}</p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Recent Alerts Table */}
                    {reportData.recent_alerts && reportData.recent_alerts.length > 0 && (
                        <div>
                            <h4 className="text-sm font-medium text-gray-700 mb-3">Recent Alerts</h4>
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Priority</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Panel</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Message</th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {reportData.recent_alerts.slice(0, 10).map((alert, index) => (
                                            <tr key={alert.id || index} className="hover:bg-gray-50">
                                                <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                                                    {alert.created_at ? new Date(alert.created_at).toLocaleString() : '-'}
                                                </td>
                                                <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-600">
                                                    {alert.alert_type || '-'}
                                                </td>
                                                <td className="px-4 py-2 whitespace-nowrap">
                                                    <PriorityBadge priority={alert.priority} />
                                                </td>
                                                <td className="px-4 py-2 whitespace-nowrap text-sm text-gray-600">
                                                    {alert.panel_id || '-'}
                                                </td>
                                                <td className="px-4 py-2 text-sm text-gray-500 max-w-xs truncate">
                                                    {alert.message || '-'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

// Sub-components
const StatCard = ({ label, value, color }) => {
    const colorClasses = {
        blue: 'bg-blue-50 border-blue-200 text-blue-800',
        red: 'bg-red-50 border-red-200 text-red-800',
        yellow: 'bg-yellow-50 border-yellow-200 text-yellow-800',
        green: 'bg-green-50 border-green-200 text-green-800',
    };

    return (
        <div className={`p-4 rounded-lg border ${colorClasses[color] || 'bg-gray-50 border-gray-200'}`}>
            <p className="text-2xl font-bold">{value?.toLocaleString() ?? 0}</p>
            <p className="text-xs uppercase tracking-wide opacity-75">{label}</p>
        </div>
    );
};

const PriorityBadge = ({ priority }) => {
    const colors = {
        high: 'bg-red-100 text-red-800',
        medium: 'bg-yellow-100 text-yellow-800',
        low: 'bg-green-100 text-green-800',
        critical: 'bg-purple-100 text-purple-800',
    };

    return (
        <span className={`px-2 py-1 rounded text-xs font-medium ${colors[priority?.toLowerCase()] || 'bg-gray-100 text-gray-800'}`}>
            {priority?.toUpperCase() || 'UNKNOWN'}
        </span>
    );
};

// Icons
const DownloadIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
    </svg>
);

const DocumentIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
    </svg>
);

export default ReportingDashboard;
