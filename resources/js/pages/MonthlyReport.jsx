import { useState, useEffect } from 'react';
import DashboardLayout from '../components/DashboardLayout';
import monthlyReportService from '../services/monthlyReportService';

const MonthlyReport = () => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [reportData, setReportData] = useState(null);

    useEffect(() => {
        const fetchReport = async () => {
            try {
                setLoading(true);
                const response = await monthlyReportService.getMonthlyReport({
                    year: new Date().getFullYear(),
                    month: new Date().getMonth() + 1
                });
                if (response.success) {
                    setReportData(response.data);
                }
            } catch (err) {
                setError('Failed to load report');
            } finally {
                setLoading(false);
            }
        };
        fetchReport();
    }, []);

    if (loading) {
        return (
            <DashboardLayout>
                <div className="flex items-center justify-center min-h-screen">
                    <div className="text-gray-600">Loading...</div>
                </div>
            </DashboardLayout>
        );
    }

    if (error) {
        return (
            <DashboardLayout>
                <div className="flex items-center justify-center min-h-screen">
                    <div className="text-red-600">{error}</div>
                </div>
            </DashboardLayout>
        );
    }

    return (
        <DashboardLayout>
            <div className="space-y-6">
                {/* Header */}
                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <h2 className="text-2xl font-bold text-gray-800">Monthly Report</h2>
                    <p className="text-sm text-gray-600 mt-1">
                        {reportData?.meta?.month_name || 'Current Month'}
                    </p>
                </div>

                {/* Summary Cards */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                        <div className="text-sm text-gray-600">Total Alerts</div>
                        <div className="text-2xl font-bold text-gray-900 mt-1">
                            {(reportData?.month_summary?.total_alerts || 0).toLocaleString()}
                        </div>
                    </div>
                    
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                        <div className="text-sm text-gray-600">VM Alerts</div>
                        <div className="text-2xl font-bold text-blue-600 mt-1">
                            {(reportData?.month_summary?.vm_alerts || 0).toLocaleString()}
                        </div>
                    </div>
                    
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                        <div className="text-sm text-gray-600">Avg Daily Alerts</div>
                        <div className="text-2xl font-bold text-gray-900 mt-1">
                            {(reportData?.month_summary?.avg_daily_alerts || 0).toLocaleString()}
                        </div>
                    </div>
                    
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                        <div className="text-sm text-gray-600">Total Sites</div>
                        <div className="text-2xl font-bold text-gray-900 mt-1">
                            {(reportData?.month_summary?.total_sites || 0).toLocaleString()}
                        </div>
                    </div>
                </div>

                {/* Weekly Breakdown */}
                {reportData?.weekly_breakdown && reportData.weekly_breakdown.length > 0 && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-800 mb-4">Weekly Breakdown</h3>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Week</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total Alerts</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">VM Alerts</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {reportData.weekly_breakdown.map((week, index) => (
                                        <tr key={index}>
                                            <td className="px-4 py-3 text-sm text-gray-900">
                                                Week {week.week_number} ({week.week_start} - {week.week_end})
                                            </td>
                                            <td className="px-4 py-3 text-sm text-right text-gray-900">
                                                {(week.total_alerts || 0).toLocaleString()}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-right text-blue-600">
                                                {(week.vm_alerts || 0).toLocaleString()}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Top Sites */}
                {reportData?.top_sites && reportData.top_sites.length > 0 && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-semibold text-gray-800 mb-4">Top 20 Sites by Alert Count</h3>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rank</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Site</th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                                        <th className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Alerts</th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {reportData.top_sites.slice(0, 20).map((site, index) => (
                                        <tr key={index}>
                                            <td className="px-4 py-3 text-sm text-gray-500">{index + 1}</td>
                                            <td className="px-4 py-3 text-sm text-gray-900">{site.site_name}</td>
                                            <td className="px-4 py-3 text-sm text-gray-600">{site.customer}</td>
                                            <td className="px-4 py-3 text-sm text-right text-gray-900">
                                                {(site.alert_count || 0).toLocaleString()}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
};

export default MonthlyReport;
