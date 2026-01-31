import { useState, useEffect } from 'react';
import { 
    Calendar,
    Download,
    Printer,
    RefreshCw,
    Loader2,
    AlertTriangle,
    TrendingUp,
    TrendingDown
} from 'lucide-react';
import { format } from 'date-fns';
import DashboardLayout from '../components/DashboardLayout';
import dailyReportService from '../services/dailyReportService';

/**
 * DailyReportPage Component
 * Comprehensive daily operational report
 */
const DailyReportPage = () => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [reportData, setReportData] = useState(null);
    const [selectedDate, setSelectedDate] = useState(format(new Date(), 'yyyy-MM-dd'));
    const [filters, setFilters] = useState({
        customer: '',
        zone: '',
        panel_type: '',
    });
    const [filterOptions, setFilterOptions] = useState({
        customers: [],
        zones: [],
        panelTypes: [],
    });

    // Fetch filter options
    useEffect(() => {
        const fetchFilterOptions = async () => {
            try {
                const response = await dailyReportService.getFilterOptions();
                if (response.success) {
                    setFilterOptions({
                        customers: response.data.customers || [],
                        zones: response.data.zones || [],
                        panelTypes: response.data.panel_types || [],
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
                date: selectedDate,
                ...(filters.customer && { customer: filters.customer }),
                ...(filters.zone && { zone: filters.zone }),
                ...(filters.panel_type && { panel_type: filters.panel_type }),
            };

            const response = await dailyReportService.getDailyReport(params);

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
    }, [selectedDate]);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value }));
    };

    const handleApplyFilters = () => {
        fetchReport();
    };

    const handleClearFilters = () => {
        setFilters({ customer: '', zone: '', panel_type: '' });
    };

    const handlePrint = () => {
        window.print();
    };

    const handleExport = () => {
        // TODO: Implement export functionality
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
        return <span className="text-gray-500 text-sm">No change</span>;
    };

    if (loading) {
        return (
            <DashboardLayout>
                <div className="flex items-center justify-center min-h-screen">
                    <div className="text-center">
                        <Loader2 className="w-12 h-12 animate-spin text-blue-600 mx-auto mb-4" />
                        <p className="text-gray-600">Loading daily report...</p>
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

    return (
        <DashboardLayout>
            <div className="space-y-6">
                {/* Header with Date Picker and Actions */}
                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-4">
                            <Calendar className="w-5 h-5 text-gray-400" />
                            <input
                                type="date"
                                value={selectedDate}
                                onChange={(e) => setSelectedDate(e.target.value)}
                                className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            />
                            
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

                            {(filters.customer || filters.zone || filters.panel_type) && (
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

                {/* Summary Cards */}
                {reportData.summary && (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-sm font-medium text-gray-600 mb-2">Total Alerts</h3>
                            <p className="text-3xl font-bold text-gray-900">{(reportData.summary.total_alerts || 0).toLocaleString()}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-sm font-medium text-gray-600 mb-2">VM Alerts</h3>
                            <p className="text-3xl font-bold text-orange-600">{(reportData.summary.vm_alerts || 0).toLocaleString()}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-sm font-medium text-gray-600 mb-2">Sites with Alerts</h3>
                            <p className="text-3xl font-bold text-blue-600">{(reportData.summary.unique_sites_with_alerts || 0).toLocaleString()}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-sm font-medium text-gray-600 mb-2">Healthy Sites</h3>
                            <p className="text-3xl font-bold text-green-600">{(reportData.summary.healthy_sites || 0).toLocaleString()}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-sm font-medium text-gray-600 mb-2">Down Sites</h3>
                            <p className="text-3xl font-bold text-red-600">{(reportData.summary.down_sites || 0).toLocaleString()}</p>
                        </div>
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-sm font-medium text-gray-600 mb-2">Total Sites</h3>
                            <p className="text-3xl font-bold text-gray-900">{(reportData.summary.total_sites || 0).toLocaleString()}</p>
                        </div>
                    </div>
                )}

                {/* Comparisons */}
                {reportData.comparisons && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Comparisons</h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h4 className="text-sm font-medium text-gray-600 mb-3">vs Yesterday</h4>
                                <div className="space-y-2">
                                    <div className="flex justify-between items-center">
                                        <span className="text-sm text-gray-600">Total Alerts</span>
                                        {renderChangeIndicator(reportData.comparisons.vs_yesterday?.alerts_change || 0)}
                                    </div>
                                    <div className="flex justify-between items-center">
                                        <span className="text-sm text-gray-600">VM Alerts</span>
                                        {renderChangeIndicator(reportData.comparisons.vs_yesterday?.vm_alerts_change || 0)}
                                    </div>
                                </div>
                            </div>
                            <div>
                                <h4 className="text-sm font-medium text-gray-600 mb-3">vs Last Week (Same Day)</h4>
                                <div className="space-y-2">
                                    <div className="flex justify-between items-center">
                                        <span className="text-sm text-gray-600">Total Alerts</span>
                                        {renderChangeIndicator(reportData.comparisons.vs_last_week?.alerts_change || 0)}
                                    </div>
                                    <div className="flex justify-between items-center">
                                        <span className="text-sm text-gray-600">VM Alerts</span>
                                        {renderChangeIndicator(reportData.comparisons.vs_last_week?.vm_alerts_change || 0)}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Hourly Distribution Chart */}
                {reportData.hourly_distribution && reportData.hourly_distribution.length > 0 && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Hourly Distribution</h3>
                        <div className="h-64 flex items-end justify-between gap-1">
                            {reportData.hourly_distribution.map((item) => {
                                const maxValue = Math.max(...reportData.hourly_distribution.map(d => d.total));
                                const height = maxValue > 0 ? (item.total / maxValue) * 100 : 0;
                                
                                return (
                                    <div key={item.hour} className="flex-1 flex flex-col items-center">
                                        <div className="w-full flex flex-col justify-end" style={{ height: '200px' }}>
                                            <div 
                                                className="w-full bg-blue-500 rounded-t hover:bg-blue-600 transition-colors relative group"
                                                style={{ height: `${height}%` }}
                                            >
                                                <div className="absolute bottom-full mb-2 left-1/2 transform -translate-x-1/2 bg-gray-900 text-white text-xs rounded py-1 px-2 opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap">
                                                    {item.total} alerts<br/>
                                                    {item.vm_alerts} VM
                                                </div>
                                            </div>
                                        </div>
                                        <span className="text-xs text-gray-600 mt-2">{item.hour}h</span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {/* Top Sites */}
                {reportData.top_sites && reportData.top_sites.length > 0 && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Top 10 Sites by Alert Count</h3>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ATM ID</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Site Name</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">City</th>
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
                                            <td className="px-4 py-3 text-sm text-gray-600">{site.city}</td>
                                            <td className="px-4 py-3 text-sm text-gray-600">{site.zone}</td>
                                            <td className="px-4 py-3 text-sm font-semibold text-right text-red-600">{site.alert_count}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Customer Breakdown */}
                {reportData.customer_breakdown && reportData.customer_breakdown.length > 0 && (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-4">Alerts by Customer</h3>
                            <div className="space-y-3">
                                {reportData.customer_breakdown.slice(0, 10).map((item, index) => (
                                    <div key={index} className="flex justify-between items-center">
                                        <span className="text-sm text-gray-700">{item.customer}</span>
                                        <span className="text-sm font-semibold text-gray-900">{item.alert_count}</span>
                                    </div>
                                ))}
                            </div>
                        </div>

                        {/* Zone Breakdown */}
                        {reportData.zone_breakdown && reportData.zone_breakdown.length > 0 && (
                            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                                <h3 className="text-lg font-semibold text-gray-900 mb-4">Alerts by Zone</h3>
                                <div className="space-y-3">
                                    {reportData.zone_breakdown.slice(0, 10).map((item, index) => (
                                        <div key={index} className="flex justify-between items-center">
                                            <span className="text-sm text-gray-700">{item.zone}</span>
                                            <span className="text-sm font-semibold text-gray-900">{item.alert_count}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* Alert Type Distribution */}
                {reportData.alert_type_distribution && reportData.alert_type_distribution.length > 0 && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4">Alert Type Distribution</h3>
                        <div className="space-y-3">
                            {reportData.alert_type_distribution.slice(0, 10).map((item, index) => (
                                <div key={index}>
                                    <div className="flex justify-between items-center mb-1">
                                        <span className="text-sm text-gray-700">{item.type}</span>
                                        <span className="text-sm font-semibold text-gray-900">{item.count} ({item.percentage}%)</span>
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
            </div>
        </DashboardLayout>
    );
};

export default DailyReportPage;
