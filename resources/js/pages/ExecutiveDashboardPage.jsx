import { useState, useEffect } from 'react';
import { 
    Building2, 
    AlertTriangle, 
    Activity, 
    Target,
    RefreshCw,
    Download,
    Calendar,
    Loader2
} from 'lucide-react';
import { format, subDays } from 'date-fns';
import DashboardLayout from '../components/DashboardLayout';
import executiveDashboardService from '../services/executiveDashboardService';
import KPICard from '../components/dashboard/KPICard';
import HealthScoreGauge from '../components/dashboard/HealthScoreGauge';
import AlertTrendChart from '../components/dashboard/AlertTrendChart';
import SiteStatusDonut from '../components/dashboard/SiteStatusDonut';
import TopSitesBar from '../components/dashboard/TopSitesBar';
import CriticalAlertsList from '../components/dashboard/CriticalAlertsList';

/**
 * ExecutiveDashboardPage Component
 * Comprehensive executive dashboard with 20 data points
 */
const ExecutiveDashboardPage = () => {
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState(null);
    const [dashboardData, setDashboardData] = useState(null);
    const [dateRange, setDateRange] = useState({
        start_date: format(subDays(new Date(), 30), 'yyyy-MM-dd'),
        end_date: format(new Date(), 'yyyy-MM-dd'),
    });

    // Fetch dashboard data
    const fetchDashboardData = async (refresh = false) => {
        try {
            if (refresh) {
                setRefreshing(true);
            } else {
                setLoading(true);
            }
            setError(null);

            const response = await executiveDashboardService.getDashboardData({
                ...dateRange,
                refresh,
            });

            if (response.success) {
                setDashboardData(response.data);
            } else {
                setError(response.error?.message || 'Failed to fetch dashboard data');
            }
        } catch (err) {
            console.error('Dashboard fetch error:', err);
            setError(err.response?.data?.error?.message || 'Failed to fetch dashboard data');
        } finally {
            setLoading(false);
            setRefreshing(false);
        }
    };

    // Initial load
    useEffect(() => {
        fetchDashboardData();
    }, [dateRange]);

    // Auto-refresh every 5 minutes
    useEffect(() => {
        const interval = setInterval(() => {
            fetchDashboardData(true);
        }, 5 * 60 * 1000); // 5 minutes

        return () => clearInterval(interval);
    }, [dateRange]);

    // Handle refresh
    const handleRefresh = () => {
        fetchDashboardData(true);
    };

    // Handle export
    const handleExport = async (type) => {
        try {
            let blob;
            let filename;

            if (type === 'pdf') {
                blob = await executiveDashboardService.exportToPDF(dateRange);
                filename = `executive-dashboard-${format(new Date(), 'yyyy-MM-dd')}.pdf`;
            } else {
                blob = await executiveDashboardService.exportToExcel(dateRange);
                filename = `executive-dashboard-${format(new Date(), 'yyyy-MM-dd')}.xlsx`;
            }

            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);
        } catch (err) {
            console.error('Export error:', err);
            alert('Failed to export dashboard');
        }
    };

    if (loading) {
        return (
            <DashboardLayout>
                <div className="flex items-center justify-center min-h-screen">
                    <div className="text-center">
                        <Loader2 className="w-12 h-12 animate-spin text-blue-600 mx-auto mb-4" />
                        <p className="text-gray-600">Loading executive dashboard...</p>
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
                        <p className="text-red-600 font-semibold mb-2">Error Loading Dashboard</p>
                        <p className="text-gray-600 mb-4">{error}</p>
                        <button
                            onClick={() => fetchDashboardData()}
                            className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
                        >
                            Retry
                        </button>
                    </div>
                </div>
            </DashboardLayout>
        );
    }

    if (!dashboardData) {
        return null;
    }

    return (
        <DashboardLayout>
            <div className="space-y-4">
            {/* Date Range Selector */}
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-3">
                <div className="flex items-center gap-3">
                    <Calendar className="w-4 h-4 text-gray-400" />
                    <div className="flex items-center gap-2">
                        <input
                            type="date"
                            value={dateRange.start_date}
                            onChange={(e) => setDateRange({ ...dateRange, start_date: e.target.value })}
                            className="px-2 py-1 border border-gray-300 rounded text-xs focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        />
                        <span className="text-gray-500 text-xs">to</span>
                        <input
                            type="date"
                            value={dateRange.end_date}
                            onChange={(e) => setDateRange({ ...dateRange, end_date: e.target.value })}
                            className="px-2 py-1 border border-gray-300 rounded text-xs focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                        />
                    </div>
                    <span className="text-xs text-gray-500 ml-auto">
                        Last updated: {format(new Date(), 'MMM dd, yyyy HH:mm')}
                    </span>
                    <button
                        onClick={handleRefresh}
                        disabled={refreshing}
                        className="inline-flex items-center px-3 py-1.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 disabled:opacity-50"
                    >
                        <RefreshCw className={`w-3.5 h-3.5 mr-1.5 ${refreshing ? 'animate-spin' : ''}`} />
                        {refreshing ? 'Refreshing...' : 'Refresh'}
                    </button>
                    <button
                        onClick={() => handleExport('excel')}
                        className="inline-flex items-center px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700"
                    >
                        <Download className="w-3.5 h-3.5 mr-1.5" />
                        Export
                    </button>
                </div>
            </div>

            {/* KPI Cards Row */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <KPICard
                    title="Total Sites"
                    value={dashboardData.critical_metrics.total_sites.toLocaleString()}
                    icon={Building2}
                    color="blue"
                />
                <KPICard
                    title="Active Alerts"
                    value={dashboardData.critical_metrics.active_alerts.toLocaleString()}
                    icon={AlertTriangle}
                    color="red"
                />
                <KPICard
                    title="Uptime"
                    value={dashboardData.critical_metrics.uptime_percent}
                    icon={Activity}
                    color="green"
                    suffix="%"
                />
                <KPICard
                    title="SLA Compliance"
                    value={dashboardData.critical_metrics.sla_compliance}
                    icon={Target}
                    color="purple"
                    suffix="%"
                />
            </div>

            {/* Additional Alert Metrics Row */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <KPICard
                    title="Today's Alerts"
                    value={(dashboardData.critical_metrics.today_alerts || 0).toLocaleString()}
                    icon={AlertTriangle}
                    color="orange"
                />
                <KPICard
                    title="VM Alerts"
                    value={(dashboardData.critical_metrics.vm_alerts || 0).toLocaleString()}
                    icon={AlertTriangle}
                    color="yellow"
                />
                <KPICard
                    title="Last 15 Min Alerts"
                    value={(dashboardData.critical_metrics.last_15min_alerts || 0).toLocaleString()}
                    icon={AlertTriangle}
                    color="red"
                />
            </div>

            {/* Site Breakdown Cards */}
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <h3 className="text-sm font-semibold text-gray-700 mb-3">PostgreSQL Sites</h3>
                    <div className="space-y-2">
                        <div className="flex justify-between items-center">
                            <span className="text-xs text-gray-600">Active</span>
                            <span className="text-sm font-bold text-green-600">
                                {dashboardData.site_status.breakdown.sites.active.toLocaleString()}
                            </span>
                        </div>
                        <div className="flex justify-between items-center">
                            <span className="text-xs text-gray-600">Inactive</span>
                            <span className="text-sm font-bold text-red-600">
                                {dashboardData.site_status.breakdown.sites.inactive.toLocaleString()}
                            </span>
                        </div>
                        <div className="flex justify-between items-center pt-2 border-t">
                            <span className="text-xs font-semibold text-gray-700">Total</span>
                            <span className="text-sm font-bold text-gray-900">
                                {dashboardData.site_status.breakdown.sites.total.toLocaleString()}
                            </span>
                        </div>
                    </div>
                </div>

                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <h3 className="text-sm font-semibold text-gray-700 mb-3">DVR Sites</h3>
                    <div className="space-y-2">
                        <div className="flex justify-between items-center">
                            <span className="text-xs text-gray-600">Active</span>
                            <span className="text-sm font-bold text-green-600">
                                {dashboardData.site_status.breakdown.dvrsite.active.toLocaleString()}
                            </span>
                        </div>
                        <div className="flex justify-between items-center">
                            <span className="text-xs text-gray-600">Inactive</span>
                            <span className="text-sm font-bold text-red-600">
                                {dashboardData.site_status.breakdown.dvrsite.inactive.toLocaleString()}
                            </span>
                        </div>
                        <div className="flex justify-between items-center pt-2 border-t">
                            <span className="text-xs font-semibold text-gray-700">Total</span>
                            <span className="text-sm font-bold text-gray-900">
                                {dashboardData.site_status.breakdown.dvrsite.total.toLocaleString()}
                            </span>
                        </div>
                    </div>
                </div>

                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
                    <h3 className="text-sm font-semibold text-gray-700 mb-3">DVR Online</h3>
                    <div className="space-y-2">
                        <div className="flex justify-between items-center">
                            <span className="text-xs text-gray-600">Active</span>
                            <span className="text-sm font-bold text-green-600">
                                {dashboardData.site_status.breakdown.dvronline.active.toLocaleString()}
                            </span>
                        </div>
                        <div className="flex justify-between items-center">
                            <span className="text-xs text-gray-600">Inactive</span>
                            <span className="text-sm font-bold text-red-600">
                                {dashboardData.site_status.breakdown.dvronline.inactive.toLocaleString()}
                            </span>
                        </div>
                        <div className="flex justify-between items-center pt-2 border-t">
                            <span className="text-xs font-semibold text-gray-700">Total</span>
                            <span className="text-sm font-bold text-gray-900">
                                {dashboardData.site_status.breakdown.dvronline.total.toLocaleString()}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Health Score and Site Status Row */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <HealthScoreGauge
                    score={dashboardData.health_score.score}
                    status={dashboardData.health_score.status}
                    components={dashboardData.health_score.components}
                />
                <SiteStatusDonut data={dashboardData.site_status} />
            </div>

            {/* Alert Trends */}
            <AlertTrendChart data={dashboardData.alert_trends} />

            {/* Top Sites and Critical Issues Row */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <TopSitesBar data={dashboardData.top_sites} />
                <CriticalAlertsList data={dashboardData.critical_issues} />
            </div>

            {/* Down Communication Summary */}
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">Down Communication Summary</h3>
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div className="text-center p-4 bg-red-50 rounded-lg">
                        <p className="text-sm text-gray-600 mb-1">Down Sites</p>
                        <p className="text-2xl font-bold text-red-600">
                            {dashboardData.down_communication.down_sites}
                        </p>
                    </div>
                    <div className="text-center p-4 bg-yellow-50 rounded-lg">
                        <p className="text-sm text-gray-600 mb-1">Avg Downtime</p>
                        <p className="text-2xl font-bold text-yellow-600">
                            {dashboardData.down_communication.avg_downtime_hours}h
                        </p>
                    </div>
                    <div className="text-center p-4 bg-orange-50 rounded-lg">
                        <p className="text-sm text-gray-600 mb-1">Critical Sites</p>
                        <p className="text-2xl font-bold text-orange-600">
                            {dashboardData.down_communication.critical_sites}
                        </p>
                    </div>
                    <div className="text-center p-4 bg-blue-50 rounded-lg">
                        <p className="text-sm text-gray-600 mb-1">Impact Level</p>
                        <p className="text-2xl font-bold text-blue-600 capitalize">
                            {dashboardData.down_communication.impact_level}
                        </p>
                    </div>
                </div>
            </div>

            {/* Month-over-Month Comparison */}
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">Month-over-Month Comparison</h3>
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div className="p-4 border border-gray-200 rounded-lg">
                        <p className="text-sm text-gray-600 mb-1">Alerts Change</p>
                        <p className={`text-2xl font-bold ${dashboardData.month_comparison.changes.alerts >= 0 ? 'text-red-600' : 'text-green-600'}`}>
                            {dashboardData.month_comparison.changes.alerts > 0 ? '+' : ''}
                            {dashboardData.month_comparison.changes.alerts}%
                        </p>
                    </div>
                    <div className="p-4 border border-gray-200 rounded-lg">
                        <p className="text-sm text-gray-600 mb-1">Tickets Change</p>
                        <p className={`text-2xl font-bold ${dashboardData.month_comparison.changes.tickets >= 0 ? 'text-red-600' : 'text-green-600'}`}>
                            {dashboardData.month_comparison.changes.tickets > 0 ? '+' : ''}
                            {dashboardData.month_comparison.changes.tickets}%
                        </p>
                    </div>
                    <div className="p-4 border border-gray-200 rounded-lg">
                        <p className="text-sm text-gray-600 mb-1">SLA Change</p>
                        <p className={`text-2xl font-bold ${dashboardData.month_comparison.changes.sla_compliance >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                            {dashboardData.month_comparison.changes.sla_compliance > 0 ? '+' : ''}
                            {dashboardData.month_comparison.changes.sla_compliance}%
                        </p>
                    </div>
                    <div className="p-4 border border-gray-200 rounded-lg">
                        <p className="text-sm text-gray-600 mb-1">Uptime Change</p>
                        <p className={`text-2xl font-bold ${dashboardData.month_comparison.changes.uptime >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                            {dashboardData.month_comparison.changes.uptime > 0 ? '+' : ''}
                            {dashboardData.month_comparison.changes.uptime}%
                        </p>
                    </div>
                </div>
            </div>
        </div>
        </DashboardLayout>
    );
};

export default ExecutiveDashboardPage;
