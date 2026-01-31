import { useState, useEffect } from 'react';
import { 
    Calendar,
    Download,
    Printer,
    RefreshCw,
    Loader2,
    AlertTriangle,
    TrendingUp,
    TrendingDown,
    Minus
} from 'lucide-react';
import { format, startOfWeek, endOfWeek } from 'date-fns';
import DashboardLayout from '../components/DashboardLayout';
import weeklyReportService from '../services/weeklyReportService';

/**
 * WeeklyReportPage Component
 * Comprehensive weekly operational report with trends
 */
const WeeklyReportPage = () => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [reportData, setReportData] = useState(null);
    const [weekStart, setWeekStart] = useState(format(startOfWeek(new Date()), 'yyyy-MM-dd'));
    const [filters, setFilters] = useState({
        customer: '',
        zone: '',
    });
    const [filterOptions, setFilterOptions] = useState({
        customers: [],
        zones: [],
    });

    // Fetch filter options
    useEffect(() => {
        const fetchFilterOptions = async () => {
            try {
                const response = await weeklyReportService.getFilterOptions();
                if (response.success) {
                    setFilterOptions({
                        customers: response.data.customers || [],
                        zones: response.data.zones || [],
                    });
                }
            } catch (err) {
                console.error('Failed to fetch filter options:', err);
            }
        };
        fetchFilterOptions();
    }, []);

    // Fetch report data
    const fetchReport = async () => {
        try {
            setLoading(true);
            setError(null);

            const params = {
                week_start: weekStart,
                ...(filters.customer && { customer: filters.customer }),
                ...(filters.zone && { zone: filters.zone }),
            };

            const response = await weeklyReportService.getWeeklyReport(params);

            if (response.success) {
                setReportData(response.data);
            } else {
                setError(response.error?.message || 'Failed to fetch report');
            }
        } catch (err) {
            console.error('Report fetch error:', err);
            setError(err.response?.data?.error?.message || 'Failed to fetch report');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchReport();
    }, [weekStart]);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
    };

    const handleApplyFilters = () => {
        fetchReport();
    };

    const handleClearFilters = () => {
        setFilters({ customer: '', zone: '' });
    };

    const handlePrint = () => {
        window.print();
    };

    const handleExport = () => {
        alert('Export functionality coming soon');
    };

    const renderChangeIndicator = (change) => {
        if (change > 0) {
            return (
                <span className="inline-flex items-center text-red-600 text-sm">
                    <TrendingUp className="w-4 h-4 mr-1" />
                    +{change}%
                </span>
            );
        } else if (change < 0) {
            return (
                <span className="inline-flex items-center text-green-600 text-sm">
                    <TrendingDown className="w-4 h-4 mr-1" />
                    {change}%
                </span>
            );
        }
        return (
            <span className="inline-flex items-center text-gray-500 text-sm">
                <Minus className="w-4 h-4 mr-1" />
                No change
            </span>
        );
    };

    if (loading) {
        return (
            <DashboardLayout>
                <div className="flex items-center justify-center min-h-screen">
                    <div className="text-center">
                        <Loader2 className="w-12 h-12 animate-spin text-blue-600 mx-auto mb-4" />
                        <p className="text-gray-600">Loading weekly report...</p>
                    </div>
                </div>
            </DashboardLayout>
        );
    }

    if (error) {
        return (
            <DashboardLayout>
                <div className="flex items-center justify-center min-h-screen">
                    <div className="text-center">
                        <AlertTriangle className="w-12 h-12 text-red-500 mx-auto mb-4" />
                        <p className="text-red-600 font-semibold mb-2">Error Loading Report</p>
                        <p className="text-gray-600 mb-4">{error}</p>
                        <button
                            onClick={fetchReport}
                            className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                        >
                            Retry
                        </button>
                    </div>
                </div>
            </DashboardLayout>
        );
    }

    if (!reportData) {
        return null;
    }

    const weekEndDate = format(endOfWeek(new Date(weekStart)), 'yyyy-MM-dd');

    return (
        <DashboardLayout>
            <div className="space-y-6">
                {/* Header */}
                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-4">
                            <Calendar className="w-5 h-5 text-gray-400" />
                            <div className="flex items-center gap-2">
                                <label className="text-sm text-gray-600">Week Starting:</label>
                                <input
                                    type="date"
                                    value={weekStart}
                                    onChange={(e) => setWeekStart(e.target.value)}
                                    className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                />
                                <span className="text-sm text-gray-500">
                                    ({format(new Date(weekStart), 'MMM dd')} - {format(new Date(weekEndDate), 'MMM dd, yyyy')})
                                </span>
                            </div>
                            
                            {/* Filters */}
                            <select
                                value={filters.customer}
                                onChange={(e) => handleFilterChange('customer', e.target.value)}
                                className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">All Customers</option>
                                {filterOptions.customers.map(customer => (
                                    <option key={customer} value={customer}>{customer}</option>
                                ))}
                            </select>

                            <select
                                value={filters.zone}
                                onChange={(e) => handleFilterChange('zone', e.target.value)}
                                className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
                            >
                                <option value="">All Zones</option>
                                {filterOptions.zones.map(zone => (
                                    <option key={zone} value={zone}>{zone}</option>
                                ))}
                            </select>

                            {(filters.customer || filters.zone) && (
                                <>
                                    <button
                                        onClick={handleApplyFilters}
                                        className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 text-sm"
                                    >
                                        Apply
                                    </button>
                                    <button
                                        onClick={handleClearFilters}
                                        className="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 text-sm"
                                    >
                                        Clear
                                    </button>
                                </>
                            )}
                        </div>

                        <div className="flex items-center gap-2">
                            <button
                                onClick={fetchReport}
                                className="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                            >
                                <RefreshCw className="w-4 h-4 mr-2" />
                                Refresh
                            </button>
                            <button
                                onClick={handlePrint}
                                className="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50"
                            >
                                <Printer className="w-4 h-4 mr-2" />
                                Print
                            </button>
                            <button
                                onClick={handleExport}
                                className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700"
                            >
                                <Download className="w-4 h-4 mr-2" />
                                Export
                            </button>
                        </div>
                    </div>
                </div>

                {/* Week Summary Cards */}
                {reportData.week_summary && (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-sm font-medium text-gray-600 mb-2">Total Alerts</h3>
                            <p className="text-3xl font-bold text-gray-900">{(reportData.week_summary.total_alerts || 0).toLocaleString()}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-sm font-medium text-gray-600 mb-2">VM Alerts</h3>
                            <p className="text-3xl font-bold text-orange-600">{(reportData.week_summary.vm_alerts || 0).toLocaleString()}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-sm font-medium text-gray-600 mb-2">Avg Daily Alerts</h3>
                            <p className="text-3xl font-bold text-blue-600">{(reportData.week_summary.avg_daily_alerts || 0).toLocaleString()}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-sm font-medium text-gray-600 mb-2">Peak Day</h3>
                            <p className="text-lg font-bold text-red-600">
                                {reportData.week_summary.peak_day ? format(new Date(reportData.week_summary.peak_day), 'EEE, MMM dd') : 'N/A'}
                            </p>
                            <p className="text-sm text-gray-600">{(reportData.week_summary.peak_day_count || 0).toLocaleString()} alerts</p>
                        </div>
                    </div>
                )}

                {/* Week Comparison */}
                {reportData.week_comparison && reportData.week_comparison.changes && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Week-over-Week Comparison</h3>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <h4 className="text-sm font-medium text-gray-600 mb-2">Total Alerts</h4>
                                <div className="flex items-center justify-between">
                                    <span className="text-2xl font-bold text-gray-900">
                                        {(reportData.week_comparison.this_week?.total_alerts || 0).toLocaleString()}
                                    </span>
                                    {renderChangeIndicator(reportData.week_comparison.changes.total_alerts || 0)}
                                </div>
                                <p className="text-xs text-gray-500 mt-1">
                                    Last week: {(reportData.week_comparison.last_week?.total_alerts || 0).toLocaleString()}
                                </p>
                            </div>
                            <div>
                                <h4 className="text-sm font-medium text-gray-600 mb-2">VM Alerts</h4>
                                <div className="flex items-center justify-between">
                                    <span className="text-2xl font-bold text-orange-600">
                                        {(reportData.week_comparison.this_week?.vm_alerts || 0).toLocaleString()}
                                    </span>
                                    {renderChangeIndicator(reportData.week_comparison.changes.vm_alerts || 0)}
                                </div>
                                <p className="text-xs text-gray-500 mt-1">
                                    Last week: {(reportData.week_comparison.last_week?.vm_alerts || 0).toLocaleString()}
                                </p>
                            </div>
                            <div>
                                <h4 className="text-sm font-medium text-gray-600 mb-2">Avg Daily</h4>
                                <div className="flex items-center justify-between">
                                    <span className="text-2xl font-bold text-blue-600">
                                        {(reportData.week_comparison.this_week?.avg_daily_alerts || 0).toFixed(0)}
                                    </span>
                                    {renderChangeIndicator(reportData.week_comparison.changes.avg_daily || 0)}
                                </div>
                                <p className="text-xs text-gray-500 mt-1">
                                    Last week: {(reportData.week_comparison.last_week?.avg_daily_alerts || 0).toFixed(0)}
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {/* Daily Breakdown Chart */}
                {reportData.daily_breakdown && reportData.daily_breakdown.length > 0 && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Daily Breakdown</h3>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Day</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Alerts</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">VM Alerts</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Change</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {reportData.daily_breakdown.map((day, index) => (
                                        <tr key={index} className="hover:bg-gray-50">
                                            <td className="px-4 py-3 text-sm text-gray-900">{format(new Date(day.date), 'MMM dd')}</td>
                                            <td className="px-4 py-3 text-sm text-gray-600">{day.day_name}</td>
                                            <td className="px-4 py-3 text-sm font-semibold text-right text-gray-900">{day.total_alerts.toLocaleString()}</td>
                                            <td className="px-4 py-3 text-sm font-semibold text-right text-orange-600">{day.vm_alerts.toLocaleString()}</td>
                                            <td className="px-4 py-3 text-sm text-right">
                                                {day.change_from_previous !== undefined ? renderChangeIndicator(day.change_from_previous) : '-'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Patterns */}
                {reportData.patterns && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Weekly Patterns</h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 className="text-sm font-medium text-gray-600 mb-3">Busiest Day of Week</h4>
                                <p className="text-2xl font-bold text-blue-600">{reportData.patterns.busiest_day_of_week}</p>
                                <p className="text-sm text-gray-600">{(reportData.patterns.busiest_day_count || 0).toLocaleString()} alerts</p>
                            </div>
                            <div>
                                <h4 className="text-sm font-medium text-gray-600 mb-3">Busiest Hour</h4>
                                <p className="text-2xl font-bold text-purple-600">{reportData.patterns.busiest_hour}:00</p>
                                <p className="text-sm text-gray-600">{(reportData.patterns.busiest_hour_count || 0).toLocaleString()} alerts</p>
                            </div>
                        </div>
                    </div>
                )}

                {/* Top Sites */}
                {reportData.top_sites && reportData.top_sites.length > 0 && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Top 10 Sites (Weekly Total)</h3>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ATM ID</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Site Name</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Zone</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Alerts</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {reportData.top_sites.map((site, index) => (
                                        <tr key={index} className="hover:bg-gray-50">
                                            <td className="px-4 py-3 text-sm text-gray-900">{site.atmid}</td>
                                            <td className="px-4 py-3 text-sm text-gray-900">{site.site_name}</td>
                                            <td className="px-4 py-3 text-sm text-gray-600">{site.customer}</td>
                                            <td className="px-4 py-3 text-sm text-gray-600">{site.zone}</td>
                                            <td className="px-4 py-3 text-sm font-semibold text-right text-red-600">{site.alert_count}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Customer & Zone Breakdown */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {reportData.customer_breakdown && reportData.customer_breakdown.length > 0 && (
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">Top Customers</h3>
                            <div className="space-y-3">
                                {reportData.customer_breakdown.slice(0, 10).map((item, index) => (
                                    <div key={index} className="flex justify-between items-center">
                                        <span className="text-sm text-gray-700">{item.customer}</span>
                                        <span className="text-sm font-semibold text-gray-900">{item.alert_count.toLocaleString()}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {reportData.zone_breakdown && reportData.zone_breakdown.length > 0 && (
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">Top Zones</h3>
                            <div className="space-y-3">
                                {reportData.zone_breakdown.slice(0, 10).map((item, index) => (
                                    <div key={index} className="flex justify-between items-center">
                                        <span className="text-sm text-gray-700">{item.zone}</span>
                                        <span className="text-sm font-semibold text-gray-900">{item.alert_count.toLocaleString()}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* Alert Type Trends */}
                {reportData.alert_type_trends && reportData.alert_type_trends.length > 0 && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Top Alert Types</h3>
                        <div className="space-y-3">
                            {reportData.alert_type_trends.map((item, index) => (
                                <div key={index}>
                                    <div className="flex justify-between items-center mb-1">
                                        <span className="text-sm text-gray-700">{item.type}</span>
                                        <span className="text-sm font-semibold text-gray-900">{item.count.toLocaleString()} ({item.percentage}%)</span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-2">
                                        <div 
                                            className="bg-blue-600 h-2 rounded-full" 
                                            style={{ width: `${item.percentage}%` }}
                                        ></div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* Site Health Trends */}
                {reportData.site_health_trends && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Site Health Trends</h3>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div className="text-center p-4 bg-red-50 rounded-lg">
                                <p className="text-sm text-gray-600 mb-1">Increasing Alerts</p>
                                <p className="text-3xl font-bold text-red-600">{reportData.site_health_trends.increasing_alerts || 0}</p>
                                <p className="text-xs text-gray-500 mt-1">sites trending up</p>
                            </div>
                            <div className="text-center p-4 bg-green-50 rounded-lg">
                                <p className="text-sm text-gray-600 mb-1">Decreasing Alerts</p>
                                <p className="text-3xl font-bold text-green-600">{reportData.site_health_trends.decreasing_alerts || 0}</p>
                                <p className="text-xs text-gray-500 mt-1">sites improving</p>
                            </div>
                            <div className="text-center p-4 bg-blue-50 rounded-lg">
                                <p className="text-sm text-gray-600 mb-1">Consistent Sites</p>
                                <p className="text-3xl font-bold text-blue-600">{reportData.site_health_trends.consistent_sites || 0}</p>
                                <p className="text-xs text-gray-500 mt-1">sites stable</p>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
};

export default WeeklyReportPage;
