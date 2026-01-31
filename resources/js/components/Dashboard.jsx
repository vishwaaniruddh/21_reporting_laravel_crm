import { useState, useEffect } from 'react';
import { useAuth } from '../contexts/AuthContext';
import DashboardLayout from './DashboardLayout';
import { getDashboardStats } from '../services/dashboardService';

/**
 * Professional Dashboard with statistics and metrics
 * Shows sites data, alerts count, and other statistics
 */
const Dashboard = () => {
    const { user, loading: authLoading } = useAuth();
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        const fetchStats = async () => {
            try {
                setLoading(true);
                const response = await getDashboardStats();
                if (response.success) {
                    setStats(response.data);
                } else {
                    setError('Failed to load dashboard statistics');
                }
            } catch (err) {
                console.error('Dashboard stats error:', err);
                setError('Failed to connect to server');
            } finally {
                setLoading(false);
            }
        };

        if (!authLoading) {
            fetchStats();
            // Refresh every 5 minutes
            const interval = setInterval(fetchStats, 300000);
            return () => clearInterval(interval);
        }
    }, [authLoading]);

    if (authLoading || loading) {
        return (
            <DashboardLayout>
                <div className="flex items-center justify-center h-64">
                    <div className="flex items-center gap-2 text-gray-600">
                        <LoadingSpinner className="h-6 w-6" />
                        <span>Loading dashboard...</span>
                    </div>
                </div>
            </DashboardLayout>
        );
    }

    if (error) {
        return (
            <DashboardLayout>
                <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700">
                    {error}
                </div>
            </DashboardLayout>
        );
    }

    return (
        <DashboardLayout>
            <div className="space-y-6">
                {/* Sites Statistics */}
                <div>
                    <h2 className="text-lg font-semibold text-gray-900 mb-3">Sites Overview</h2>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <StatCard
                            title="Total RMS Sites"
                            value={stats?.sites?.total || 0}
                            icon={<BuildingIcon className="w-8 h-8" />}
                            color="blue"
                            subtitle={`${stats?.sites?.live || 0} live, ${stats?.sites?.offline || 0} offline`}
                            percentage={stats?.sites?.live_percentage}
                        />
                        <StatCard
                            title="Live RMS Sites"
                            value={stats?.sites?.live || 0}
                            icon={<CheckCircleIcon className="w-8 h-8" />}
                            color="green"
                            subtitle={`${stats?.sites?.live_percentage || 0}% operational`}
                        />
                        <StatCard
                            title="Total DVR Sites"
                            value={stats?.dvr_sites?.total || 0}
                            icon={<VideoIcon className="w-8 h-8" />}
                            color="purple"
                            subtitle={`${stats?.dvr_sites?.live || 0} live, ${stats?.dvr_sites?.offline || 0} offline`}
                            percentage={stats?.dvr_sites?.live_percentage}
                        />
                        <StatCard
                            title="Live DVR Sites"
                            value={stats?.dvr_sites?.live || 0}
                            icon={<CheckCircleIcon className="w-8 h-8" />}
                            color="green"
                            subtitle={`${stats?.dvr_sites?.live_percentage || 0}% operational`}
                        />
                    </div>
                </div>

                {/* Today's Alerts Statistics */}
                <div>
                    <h2 className="text-lg font-semibold text-gray-900 mb-3">Today's Alerts</h2>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                        <StatCard
                            title="Total Alerts"
                            value={stats?.today_alerts?.total || 0}
                            icon={<BellIcon className="w-8 h-8" />}
                            color="indigo"
                            subtitle="All alerts today"
                        />
                        <StatCard
                            title="Open Alerts"
                            value={stats?.today_alerts?.open || 0}
                            icon={<ExclamationIcon className="w-8 h-8" />}
                            color="red"
                            subtitle="Pending action"
                        />
                        <StatCard
                            title="Closed Alerts"
                            value={stats?.today_alerts?.closed || 0}
                            icon={<CheckIcon className="w-8 h-8" />}
                            color="green"
                            subtitle="Resolved today"
                        />
                        <StatCard
                            title="VM Alerts"
                            value={stats?.today_alerts?.vm_alerts || 0}
                            icon={<FireIcon className="w-8 h-8" />}
                            color="purple"
                            subtitle="Video monitoring"
                        />
                        <StatCard
                            title="Reactive Alerts"
                            value={stats?.today_alerts?.reactive || 0}
                            icon={<FireIcon className="w-8 h-8" />}
                            color="orange"
                            subtitle="Require attention"
                        />
                    </div>
                </div>

                {/* Charts Row */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Top Alert Types */}
                    <div className="bg-white rounded-lg shadow p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                            <ChartIcon className="w-5 h-5 mr-2 text-blue-600" />
                            Top Alert Types Today
                        </h3>
                        {stats?.top_alert_types && stats.top_alert_types.length > 0 ? (
                            <div className="space-y-3">
                                {stats.top_alert_types.map((item, index) => (
                                    <div key={index} className="flex items-center justify-between">
                                        <div className="flex-1">
                                            <div className="flex items-center justify-between mb-1">
                                                <span className="text-sm font-medium text-gray-700">{item.alerttype || 'Unknown'}</span>
                                                <span className="text-sm font-semibold text-gray-900">{item.count}</span>
                                            </div>
                                            <div className="w-full bg-gray-200 rounded-full h-2">
                                                <div 
                                                    className="bg-blue-600 h-2 rounded-full transition-all"
                                                    style={{ width: `${(item.count / stats.today_alerts.total) * 100}%` }}
                                                ></div>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="text-center py-8 text-gray-500">
                                <ChartIcon className="w-12 h-12 mx-auto mb-2 text-gray-300" />
                                <p>No alert data available for today</p>
                            </div>
                        )}
                    </div>

                    {/* Alerts by Status */}
                    <div className="bg-white rounded-lg shadow p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                            <PieChartIcon className="w-5 h-5 mr-2 text-green-600" />
                            Alerts by Status
                        </h3>
                        {stats?.alerts_by_status && stats.alerts_by_status.length > 0 ? (
                            <div className="space-y-4">
                                {stats.alerts_by_status.map((item, index) => {
                                    const statusColors = {
                                        'O': { bg: 'bg-red-100', text: 'text-red-800', bar: 'bg-red-600', label: 'Open' },
                                        'C': { bg: 'bg-green-100', text: 'text-green-800', bar: 'bg-green-600', label: 'Closed' },
                                        'P': { bg: 'bg-yellow-100', text: 'text-yellow-800', bar: 'bg-yellow-600', label: 'Pending' },
                                    };
                                    const color = statusColors[item.status] || { bg: 'bg-gray-100', text: 'text-gray-800', bar: 'bg-gray-600', label: item.status };
                                    
                                    return (
                                        <div key={index} className="flex items-center gap-4">
                                            <div className={`px-3 py-1 rounded-full ${color.bg} ${color.text} text-sm font-medium min-w-[80px] text-center`}>
                                                {color.label}
                                            </div>
                                            <div className="flex-1">
                                                <div className="flex items-center justify-between mb-1">
                                                    <div className="w-full bg-gray-200 rounded-full h-3">
                                                        <div 
                                                            className={`${color.bar} h-3 rounded-full transition-all`}
                                                            style={{ width: `${(item.count / stats.today_alerts.total) * 100}%` }}
                                                        ></div>
                                                    </div>
                                                    <span className="ml-3 text-sm font-semibold text-gray-900 min-w-[40px] text-right">{item.count}</span>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="text-center py-8 text-gray-500">
                                <PieChartIcon className="w-12 h-12 mx-auto mb-2 text-gray-300" />
                                <p>No status data available</p>
                            </div>
                        )}
                    </div>
                </div>

                {/* Sites Distribution */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Sites by Customer */}
                    <div className="bg-white rounded-lg shadow p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                            <UsersIcon className="w-5 h-5 mr-2 text-purple-600" />
                            Top Customers by Sites
                        </h3>
                        {stats?.sites_by_customer && stats.sites_by_customer.length > 0 ? (
                            <div className="space-y-3">
                                {stats.sites_by_customer.map((item, index) => (
                                    <div key={index} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center font-semibold text-sm">
                                                {index + 1}
                                            </div>
                                            <span className="font-medium text-gray-900">{item.Customer}</span>
                                        </div>
                                        <span className="px-3 py-1 bg-purple-100 text-purple-800 rounded-full text-sm font-semibold">
                                            {item.count} sites
                                        </span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="text-center py-8 text-gray-500">No customer data available</div>
                        )}
                    </div>

                    {/* Sites by Bank */}
                    <div className="bg-white rounded-lg shadow p-6">
                        <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                            <BankIcon className="w-5 h-5 mr-2 text-indigo-600" />
                            Top Banks by Sites
                        </h3>
                        {stats?.sites_by_bank && stats.sites_by_bank.length > 0 ? (
                            <div className="space-y-3">
                                {stats.sites_by_bank.map((item, index) => (
                                    <div key={index} className="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors">
                                        <div className="flex items-center gap-3">
                                            <div className="w-8 h-8 rounded-full bg-indigo-100 text-indigo-600 flex items-center justify-center font-semibold text-sm">
                                                {index + 1}
                                            </div>
                                            <span className="font-medium text-gray-900">{item.Bank}</span>
                                        </div>
                                        <span className="px-3 py-1 bg-indigo-100 text-indigo-800 rounded-full text-sm font-semibold">
                                            {item.count} sites
                                        </span>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="text-center py-8 text-gray-500">No bank data available</div>
                        )}
                    </div>
                </div>

                {/* System Info */}
                {!stats?.partition_exists && (
                    <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div className="flex items-start gap-3">
                            <ExclamationIcon className="w-5 h-5 text-yellow-600 mt-0.5" />
                            <div>
                                <h4 className="font-medium text-yellow-900">No Partition Table for Today</h4>
                                <p className="text-sm text-yellow-700 mt-1">
                                    Partition table <code className="bg-yellow-100 px-1 rounded">{stats?.partition_table}</code> does not exist yet. 
                                    Alert statistics will be available once the partition is created.
                                </p>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
};

// Stat Card Component
const StatCard = ({ title, value, icon, color, subtitle, percentage }) => {
    const colorClasses = {
        blue: 'bg-blue-100 text-blue-600',
        green: 'bg-green-100 text-green-600',
        red: 'bg-red-100 text-red-600',
        purple: 'bg-purple-100 text-purple-600',
        indigo: 'bg-indigo-100 text-indigo-600',
        orange: 'bg-orange-100 text-orange-600',
        gray: 'bg-gray-100 text-gray-600',
    };

    return (
        <div className="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow">
            <div className="flex items-center justify-between">
                <div className="flex-1">
                    <p className="text-sm font-medium text-gray-600">{title}</p>
                    <p className="text-3xl font-bold text-gray-900 mt-2">{value.toLocaleString()}</p>
                    {subtitle && <p className="text-xs text-gray-500 mt-1">{subtitle}</p>}
                    {percentage !== undefined && (
                        <div className="mt-2">
                            <div className="w-full bg-gray-200 rounded-full h-1.5">
                                <div 
                                    className={`h-1.5 rounded-full ${colorClasses[color]?.replace('text-', 'bg-')}`}
                                    style={{ width: `${percentage}%` }}
                                ></div>
                            </div>
                        </div>
                    )}
                </div>
                <div className={`p-3 rounded-lg ${colorClasses[color] || colorClasses.blue}`}>
                    {icon}
                </div>
            </div>
        </div>
    );
};

// Icon Components
const LoadingSpinner = ({ className }) => (
    <svg className={`animate-spin ${className}`} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
    </svg>
);

const CalendarIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>;
const BuildingIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>;
const CheckCircleIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>;
const VideoIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>;
const BellIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" /></svg>;
const ExclamationIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>;
const CheckIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" /></svg>;
const FireIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.777 17.656 7.343A7.975 7.975 0 0120 13a7.975 7.975 0 01-2.343 5.657z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9.879 16.121A3 3 0 1012.015 11L11 14H9c0 .768.293 1.536.879 2.121z" /></svg>;
const ShieldIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>;
const ChartIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>;
const PieChartIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" /></svg>;
const UsersIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>;
const BankIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" /></svg>;

export default Dashboard;
