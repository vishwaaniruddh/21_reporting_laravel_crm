import { useState, useEffect, useMemo } from 'react';
import { Server, Database, HardDrive, Cpu, Activity, RefreshCw, AlertCircle, CheckCircle, X, Eye, FileText, Trash2, ChevronUp, ChevronDown, Users } from 'lucide-react';
import DashboardLayout from '../components/DashboardLayout';
import systemHealthService from '../services/systemHealthService';

const SystemHealthPage = () => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [healthData, setHealthData] = useState(null);
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [showJobsModal, setShowJobsModal] = useState(false);
    const [showFailedJobsModal, setShowFailedJobsModal] = useState(false);
    const [showLogModal, setShowLogModal] = useState(false);
    const [showApiRequestsModal, setShowApiRequestsModal] = useState(false);
    const [apiRequestsFilter, setApiRequestsFilter] = useState('all'); // all, today, hour, errors
    const [selectedLog, setSelectedLog] = useState(null);
    const [logContent, setLogContent] = useState('');
    const [logLoading, setLogLoading] = useState(false);
    const [clearingLog, setClearingLog] = useState(null);
    const [logSortField, setLogSortField] = useState('name');
    const [logSortOrder, setLogSortOrder] = useState('asc');
    const [showUserRequestsModal, setShowUserRequestsModal] = useState(false);
    const [selectedUser, setSelectedUser] = useState(null);
    const [userRequests, setUserRequests] = useState([]);
    const [userRequestsLoading, setUserRequestsLoading] = useState(false);
    const [userRequestsPagination, setUserRequestsPagination] = useState({
        current_page: 1,
        last_page: 1,
        per_page: 50,
        total: 0,
    });

    const handleLogSort = (field) => {
        if (logSortField === field) {
            setLogSortOrder(logSortOrder === 'asc' ? 'desc' : 'asc');
        } else {
            setLogSortField(field);
            setLogSortOrder('asc');
        }
    };

    const sortedLogFiles = useMemo(() => {
        if (!healthData?.log_files) return [];
        return [...healthData.log_files].sort((a, b) => {
            let comparison = 0;
            if (logSortField === 'name') {
                comparison = a.name.localeCompare(b.name);
            } else if (logSortField === 'size') {
                comparison = a.size - b.size;
            } else if (logSortField === 'modified') {
                comparison = a.modified - b.modified;
            }
            return logSortOrder === 'asc' ? comparison : -comparison;
        });
    }, [healthData?.log_files, logSortField, logSortOrder]);

    const SortIcon = ({ field }) => {
        if (logSortField !== field) return <ChevronUp className="w-3 h-3 text-gray-300" />;
        return logSortOrder === 'asc' 
            ? <ChevronUp className="w-3 h-3 text-blue-600" /> 
            : <ChevronDown className="w-3 h-3 text-blue-600" />;
    };

    const fetchHealth = async () => {
        try {
            setLoading(true);
            setError(null);
            const response = await systemHealthService.getSystemHealth();
            if (response.success) {
                setHealthData(response.data);
            } else {
                setError('Failed to fetch system health');
            }
        } catch (err) {
            setError(err.response?.data?.error?.message || 'Failed to fetch system health');
        } finally {
            setLoading(false);
        }
    };

    const handleReadLog = async (filename) => {
        try {
            setLogLoading(true);
            setSelectedLog(filename);
            setShowLogModal(true);
            const response = await systemHealthService.readLog(filename, 1000);
            if (response.success) {
                setLogContent(response.data.content);
            }
        } catch (err) {
            setLogContent('Failed to read log file: ' + (err.response?.data?.error?.message || err.message));
        } finally {
            setLogLoading(false);
        }
    };

    const handleClearLog = async (filename) => {
        if (!confirm(`Are you sure you want to clear ${filename}? This action cannot be undone.`)) {
            return;
        }
        try {
            setClearingLog(filename);
            const response = await systemHealthService.clearLog(filename);
            if (response.success) {
                fetchHealth(); // Refresh to update file sizes
                if (selectedLog === filename) {
                    setLogContent('');
                }
            }
        } catch (err) {
            alert('Failed to clear log: ' + (err.response?.data?.error?.message || err.message));
        } finally {
            setClearingLog(null);
        }
    };

    const handleUserClick = async (user) => {
        setSelectedUser(user);
        setShowUserRequestsModal(true);
        await fetchUserRequests(user.user_id, 1);
    };

    const fetchUserRequests = async (userId, page = 1) => {
        try {
            setUserRequestsLoading(true);
            const response = await systemHealthService.getUserRequests(userId, page, 50);
            if (response.success) {
                setUserRequests(response.data.requests);
                setUserRequestsPagination(response.data.pagination);
            }
        } catch (err) {
            console.error('Failed to fetch user requests:', err);
            setUserRequests([]);
        } finally {
            setUserRequestsLoading(false);
        }
    };

    const handlePageChange = (page) => {
        if (selectedUser) {
            fetchUserRequests(selectedUser.user_id, page);
        }
    };

    useEffect(() => { fetchHealth(); }, []);

    useEffect(() => {
        if (autoRefresh) {
            const interval = setInterval(fetchHealth, 30000);
            return () => clearInterval(interval);
        }
    }, [autoRefresh]);

    const getStatusColor = (percent) => {
        if (percent < 60) return 'text-green-600';
        if (percent < 80) return 'text-yellow-600';
        return 'text-red-600';
    };

    const getStatusBg = (percent) => {
        if (percent < 60) return 'bg-green-500';
        if (percent < 80) return 'bg-yellow-500';
        return 'bg-red-500';
    };

    if (loading && !healthData) {
        return (
            <DashboardLayout>
                <div className="flex items-center justify-center min-h-screen">
                    <div className="text-gray-600">Loading system health...</div>
                </div>
            </DashboardLayout>
        );
    }

    if (error && !healthData) {
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
                    <div className="flex items-center justify-between">
                        <div>
                            <h2 className="text-2xl font-bold text-gray-800">System Health</h2>
                            <p className="text-sm text-gray-600 mt-1">Real-time server and application monitoring</p>
                        </div>
                        <div className="flex items-center gap-4">
                            <label className="flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" checked={autoRefresh} onChange={(e) => setAutoRefresh(e.target.checked)} className="rounded" />
                                Auto-refresh (30s)
                            </label>
                            <button onClick={fetchHealth} disabled={loading} className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
                                <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} />
                                Refresh
                            </button>
                        </div>
                    </div>
                </div>

                {/* Server Stats */}
                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div className="flex items-center gap-2 mb-4">
                        <Server className="w-5 h-5 text-blue-600" />
                        <h3 className="text-lg font-semibold text-gray-800">Server Resources</h3>
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        {healthData?.server_stats?.system_memory && (
                            <div className="p-4 border border-gray-200 rounded-lg">
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-sm font-medium text-gray-600">System Memory</span>
                                    <span className={`text-sm font-bold ${getStatusColor(healthData.server_stats.system_memory.usage_percent)}`}>
                                        {healthData.server_stats.system_memory.usage_percent}%
                                    </span>
                                </div>
                                <div className="w-full bg-gray-200 rounded-full h-2 mb-2">
                                    <div className={`h-2 rounded-full ${getStatusBg(healthData.server_stats.system_memory.usage_percent)}`} style={{ width: `${healthData.server_stats.system_memory.usage_percent}%` }} />
                                </div>
                                <div className="text-xs text-gray-500">{healthData.server_stats.system_memory.used_formatted} / {healthData.server_stats.system_memory.total_formatted}</div>
                            </div>
                        )}
                        {healthData?.server_stats?.memory && (
                            <div className="p-4 border border-gray-200 rounded-lg">
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-sm font-medium text-gray-600">PHP Memory</span>
                                    <span className="text-sm font-bold text-gray-700">{healthData.server_stats.memory.used_formatted}</span>
                                </div>
                                <div className="text-xs text-gray-500 space-y-1">
                                    <div>Peak: {healthData.server_stats.memory.peak_formatted}</div>
                                    <div>Limit: {healthData.server_stats.memory.limit}</div>
                                </div>
                            </div>
                        )}
                        {healthData?.server_stats?.cpu && (
                            <div className="p-4 border border-gray-200 rounded-lg">
                                <div className="flex items-center gap-2 mb-2">
                                    <Cpu className="w-4 h-4 text-blue-600" />
                                    <span className="text-sm font-medium text-gray-600">CPU Load Average</span>
                                </div>
                                <div className="text-xs text-gray-500 space-y-1">
                                    <div>1 min: {healthData.server_stats.cpu.load_1min}</div>
                                    <div>5 min: {healthData.server_stats.cpu.load_5min}</div>
                                    <div>15 min: {healthData.server_stats.cpu.load_15min}</div>
                                </div>
                            </div>
                        )}
                    </div>
                    {healthData?.server_stats?.php && (
                        <div className="mt-4 p-4 bg-gray-50 rounded-lg">
                            <div className="text-sm font-medium text-gray-600 mb-2">PHP Configuration</div>
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-xs text-gray-600">
                                <div><span className="font-medium">Version:</span> {healthData.server_stats.php.version}</div>
                                <div><span className="font-medium">OS:</span> {healthData.server_stats.php.os}</div>
                                <div><span className="font-medium">SAPI:</span> {healthData.server_stats.php.sapi}</div>
                                <div><span className="font-medium">Max Execution:</span> {healthData.server_stats.php.max_execution_time}s</div>
                            </div>
                        </div>
                    )}
                </div>

                {/* Database Stats */}
                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                    <div className="flex items-center gap-2 mb-4">
                        <Database className="w-5 h-5 text-blue-600" />
                        <h3 className="text-lg font-semibold text-gray-800">Database Status</h3>
                    </div>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {healthData?.database_stats?.postgresql && (
                            <div className="p-4 border border-gray-200 rounded-lg">
                                <div className="flex items-center justify-between mb-3">
                                    <span className="text-sm font-medium text-gray-700">PostgreSQL</span>
                                    {healthData.database_stats.postgresql.status === 'connected' ? (
                                        <CheckCircle className="w-4 h-4 text-green-600" />
                                    ) : (
                                        <AlertCircle className="w-4 h-4 text-red-600" />
                                    )}
                                </div>
                                {healthData.database_stats.postgresql.status === 'connected' ? (
                                    <div className="text-xs text-gray-600 space-y-1">
                                        <div>Size: {healthData.database_stats.postgresql.database_size_formatted}</div>
                                        <div>Active Connections: {healthData.database_stats.postgresql.active_connections}</div>
                                        <div>Total Connections: {healthData.database_stats.postgresql.total_connections}</div>
                                    </div>
                                ) : (
                                    <div className="text-xs text-red-600">{healthData.database_stats.postgresql.error}</div>
                                )}
                            </div>
                        )}
                        {healthData?.database_stats?.mysql && (
                            <div className="p-4 border border-gray-200 rounded-lg">
                                <div className="flex items-center justify-between mb-3">
                                    <span className="text-sm font-medium text-gray-700">MySQL</span>
                                    {healthData.database_stats.mysql.status === 'connected' ? (
                                        <CheckCircle className="w-4 h-4 text-green-600" />
                                    ) : (
                                        <AlertCircle className="w-4 h-4 text-red-600" />
                                    )}
                                </div>
                                {healthData.database_stats.mysql.status === 'connected' ? (
                                    <div className="text-xs text-gray-600 space-y-1">
                                        <div>Size: {healthData.database_stats.mysql.database_size_formatted}</div>
                                        <div>Tables: {healthData.database_stats.mysql.table_count}</div>
                                        {healthData.database_stats.mysql.active_connections && (
                                            <div>Connections: {healthData.database_stats.mysql.active_connections}</div>
                                        )}
                                    </div>
                                ) : (
                                    <div className="text-xs text-red-600">{healthData.database_stats.mysql.error}</div>
                                )}
                            </div>
                        )}
                    </div>
                </div>

                {/* Disk Usage */}
                {healthData?.disk_usage && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-2 mb-4">
                            <HardDrive className="w-5 h-5 text-blue-600" />
                            <h3 className="text-lg font-semibold text-gray-800">Disk Usage</h3>
                        </div>
                        {healthData.disk_usage.total && (
                            <div className="mb-4">
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-sm font-medium text-gray-600">Total Disk Space</span>
                                    <span className={`text-sm font-bold ${getStatusColor(healthData.disk_usage.usage_percent)}`}>{healthData.disk_usage.usage_percent}%</span>
                                </div>
                                <div className="w-full bg-gray-200 rounded-full h-2 mb-2">
                                    <div className={`h-2 rounded-full ${getStatusBg(healthData.disk_usage.usage_percent)}`} style={{ width: `${healthData.disk_usage.usage_percent}%` }} />
                                </div>
                                <div className="text-xs text-gray-500">{healthData.disk_usage.used_formatted} used / {healthData.disk_usage.total_formatted} total</div>
                            </div>
                        )}
                        {healthData.disk_usage.storage && (
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
                                <div className="p-3 bg-gray-50 rounded-lg">
                                    <div className="text-xs font-medium text-gray-600 mb-1">Logs</div>
                                    <div className="text-sm text-gray-700">{healthData.disk_usage.storage.logs.size_formatted}</div>
                                    <div className="text-xs text-gray-500">{healthData.disk_usage.storage.logs.file_count} files</div>
                                </div>
                                <div className="p-3 bg-gray-50 rounded-lg">
                                    <div className="text-xs font-medium text-gray-600 mb-1">Cache</div>
                                    <div className="text-sm text-gray-700">{healthData.disk_usage.storage.cache.size_formatted}</div>
                                    <div className="text-xs text-gray-500">{healthData.disk_usage.storage.cache.file_count} files</div>
                                </div>
                                <div className="p-3 bg-gray-50 rounded-lg">
                                    <div className="text-xs font-medium text-gray-600 mb-1">Sessions</div>
                                    <div className="text-sm text-gray-700">{healthData.disk_usage.storage.sessions.size_formatted}</div>
                                    <div className="text-xs text-gray-500">{healthData.disk_usage.storage.sessions.file_count} files</div>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* Application Stats with Queue Modal */}
                {healthData?.application_stats && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-2 mb-4">
                            <Activity className="w-5 h-5 text-blue-600" />
                            <h3 className="text-lg font-semibold text-gray-800">Application Status</h3>
                        </div>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                            <div>
                                <div className="text-gray-600 text-xs mb-1">Laravel Version</div>
                                <div className="font-medium text-gray-800">{healthData.application_stats.laravel_version}</div>
                            </div>
                            <div>
                                <div className="text-gray-600 text-xs mb-1">Environment</div>
                                <div className="font-medium text-gray-800">{healthData.application_stats.environment}</div>
                            </div>
                            <div>
                                <div className="text-gray-600 text-xs mb-1">Debug Mode</div>
                                <div className="font-medium text-gray-800">{healthData.application_stats.debug_mode ? 'Enabled' : 'Disabled'}</div>
                            </div>
                            <div>
                                <div className="text-gray-600 text-xs mb-1">Cache Driver</div>
                                <div className="font-medium text-gray-800">{healthData.cache_stats?.driver || 'N/A'}</div>
                            </div>
                        </div>

                        {healthData.application_stats.queue && healthData.application_stats.queue.status !== 'not_configured' && (
                            <div className="mt-4 p-4 bg-gray-50 rounded-lg">
                                <div className="flex items-center justify-between mb-2">
                                    <div className="text-sm font-medium text-gray-600">Queue Status</div>
                                    <button onClick={() => setShowJobsModal(true)} className="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800">
                                        <Eye className="w-3 h-3" /> View Details
                                    </button>
                                </div>
                                <div className="grid grid-cols-3 gap-4 text-xs text-gray-600">
                                    <div className="text-center p-2 bg-white rounded border">
                                        <div className="text-2xl font-bold text-gray-800">{healthData.application_stats.queue.total_jobs}</div>
                                        <div>Total Jobs</div>
                                    </div>
                                    <div className="text-center p-2 bg-white rounded border">
                                        <div className="text-2xl font-bold text-yellow-600">{healthData.application_stats.queue.pending_jobs}</div>
                                        <div>Pending</div>
                                    </div>
                                    <div className="text-center p-2 bg-white rounded border">
                                        <div className="text-2xl font-bold text-blue-600">{healthData.application_stats.queue.processing_jobs}</div>
                                        <div>Processing</div>
                                    </div>
                                </div>
                            </div>
                        )}

                        {healthData.application_stats.failed_jobs && healthData.application_stats.failed_jobs.count > 0 && (
                            <div className="mt-4 p-4 bg-red-50 rounded-lg">
                                <div className="flex items-center justify-between mb-2">
                                    <div className="text-sm font-medium text-red-600">Failed Jobs</div>
                                    <button onClick={() => setShowFailedJobsModal(true)} className="flex items-center gap-1 text-xs text-red-600 hover:text-red-800">
                                        <Eye className="w-3 h-3" /> View Details
                                    </button>
                                </div>
                                <div className="text-2xl font-bold text-red-600">{healthData.application_stats.failed_jobs.count}</div>
                            </div>
                        )}
                    </div>
                )}

                {/* API Stats */}
                {healthData?.api_stats && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-2 mb-4">
                            <Activity className="w-5 h-5 text-blue-600" />
                            <h3 className="text-lg font-semibold text-gray-800">API Usage</h3>
                        </div>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div 
                                onClick={() => { setApiRequestsFilter('today'); setShowApiRequestsModal(true); }}
                                className="p-4 border border-gray-200 rounded-lg text-center cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition-colors"
                            >
                                <div className="text-xs text-gray-600 mb-1">Requests Today</div>
                                <div className="text-2xl font-bold text-gray-800">{(healthData.api_stats.total_requests_today || 0).toLocaleString()}</div>
                                <div className="text-xs text-blue-500 mt-1">Click to view</div>
                            </div>
                            <div 
                                onClick={() => { setApiRequestsFilter('hour'); setShowApiRequestsModal(true); }}
                                className="p-4 border border-gray-200 rounded-lg text-center cursor-pointer hover:bg-blue-50 hover:border-blue-300 transition-colors"
                            >
                                <div className="text-xs text-gray-600 mb-1">Requests (Last Hour)</div>
                                <div className="text-2xl font-bold text-gray-800">{(healthData.api_stats.total_requests_hour || 0).toLocaleString()}</div>
                                <div className="text-xs text-blue-500 mt-1">Click to view</div>
                            </div>
                            <div 
                                onClick={() => { setApiRequestsFilter('errors'); setShowApiRequestsModal(true); }}
                                className="p-4 border border-gray-200 rounded-lg text-center cursor-pointer hover:bg-red-50 hover:border-red-300 transition-colors"
                            >
                                <div className="text-xs text-gray-600 mb-1">Errors Today</div>
                                <div className="text-2xl font-bold text-red-600">{(healthData.api_stats.error_count_today || 0).toLocaleString()}</div>
                                <div className="text-xs text-red-500 mt-1">Click to view</div>
                            </div>
                            <div className="p-4 border border-gray-200 rounded-lg text-center">
                                <div className="text-xs text-gray-600 mb-1">Error Rate</div>
                                <div className="text-2xl font-bold text-gray-800">{healthData.api_stats.error_rate || 0}%</div>
                            </div>
                        </div>
                        {healthData.api_stats.log_file_size && (
                            <div className="mt-4 text-xs text-gray-500">Log file size: {healthData.api_stats.log_file_size}</div>
                        )}

                        {/* User-wise API Usage */}
                        {healthData.api_stats.user_stats && healthData.api_stats.user_stats.length > 0 && (
                            <div className="mt-6">
                                <div className="text-sm font-medium text-gray-600 mb-2">User-wise API Usage (Today)</div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-xs">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-3 py-2 text-left">User</th>
                                                <th className="px-3 py-2 text-right">Requests</th>
                                                <th className="px-3 py-2 text-right">Errors</th>
                                                <th className="px-3 py-2 text-right">Error Rate</th>
                                                <th className="px-3 py-2 text-right">Avg Time</th>
                                                <th className="px-3 py-2 text-left">Last Request</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {healthData.api_stats.user_stats.map((user, i) => (
                                                <tr 
                                                    key={i} 
                                                    onClick={() => handleUserClick(user)}
                                                    className="border-t hover:bg-blue-50 cursor-pointer transition-colors"
                                                >
                                                    <td className="px-3 py-2">
                                                        <div className="flex items-center gap-1">
                                                            <Users className="w-3 h-3 text-gray-400" />
                                                            <span className="font-medium text-gray-800">{user.user_name}</span>
                                                            {user.user_id && <span className="text-gray-400 ml-1">(#{user.user_id})</span>}
                                                        </div>
                                                    </td>
                                                    <td className="px-3 py-2 text-right font-medium">{user.total_requests.toLocaleString()}</td>
                                                    <td className="px-3 py-2 text-right">
                                                        <span className={user.error_count > 0 ? 'text-red-600' : 'text-gray-600'}>
                                                            {user.error_count}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-2 text-right">
                                                        <span className={`px-1.5 py-0.5 rounded text-xs ${
                                                            user.error_rate > 10 ? 'bg-red-100 text-red-700' : 
                                                            user.error_rate > 5 ? 'bg-yellow-100 text-yellow-700' : 
                                                            'bg-green-100 text-green-700'
                                                        }`}>
                                                            {user.error_rate}%
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-2 text-right">{user.avg_response_time || 0}ms</td>
                                                    <td className="px-3 py-2 text-gray-500">
                                                        {new Date(user.last_request).toLocaleTimeString()}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {healthData.api_stats.top_endpoints && healthData.api_stats.top_endpoints.length > 0 && (
                            <div className="mt-6">
                                <div className="text-sm font-medium text-gray-600 mb-2">Top Endpoints</div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-xs">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-3 py-2 text-left">Endpoint</th>
                                                <th className="px-3 py-2 text-right">Requests</th>
                                                <th className="px-3 py-2 text-right">Avg Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {healthData.api_stats.top_endpoints.map((ep, i) => (
                                                <tr key={i} className="border-t">
                                                    <td className="px-3 py-2 text-gray-700">{ep.endpoint}</td>
                                                    <td className="px-3 py-2 text-right">{ep.count}</td>
                                                    <td className="px-3 py-2 text-right">{Math.round(ep.avg_time)}ms</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* Log Files */}
                {healthData?.log_files && (
                    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-2 mb-4">
                            <FileText className="w-5 h-5 text-blue-600" />
                            <h3 className="text-lg font-semibold text-gray-800">Log Files</h3>
                        </div>
                        {healthData.log_files.length > 0 ? (
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th 
                                                onClick={() => handleLogSort('name')}
                                                className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 select-none"
                                            >
                                                <div className="flex items-center gap-1">
                                                    File Name <SortIcon field="name" />
                                                </div>
                                            </th>
                                            <th 
                                                onClick={() => handleLogSort('size')}
                                                className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 select-none"
                                            >
                                                <div className="flex items-center justify-end gap-1">
                                                    Size <SortIcon field="size" />
                                                </div>
                                            </th>
                                            <th 
                                                onClick={() => handleLogSort('modified')}
                                                className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase cursor-pointer hover:bg-gray-100 select-none"
                                            >
                                                <div className="flex items-center gap-1">
                                                    Last Modified <SortIcon field="modified" />
                                                </div>
                                            </th>
                                            <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200">
                                        {sortedLogFiles.map((file) => (
                                            <tr key={file.name} className="hover:bg-gray-50">
                                                <td className="px-4 py-3">
                                                    <span className="font-medium text-gray-900">{file.name}</span>
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <span className={`font-medium ${file.size > 10485760 ? 'text-red-600' : file.size > 1048576 ? 'text-yellow-600' : 'text-gray-600'}`}>
                                                        {file.size_formatted}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3 text-gray-500 text-xs">
                                                    {file.modified_ago}
                                                </td>
                                                <td className="px-4 py-3 text-center">
                                                    <div className="flex items-center justify-center gap-2">
                                                        <button
                                                            onClick={() => handleReadLog(file.name)}
                                                            className="flex items-center gap-1 px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded hover:bg-blue-200"
                                                        >
                                                            <Eye className="w-3 h-3" /> View
                                                        </button>
                                                        <button
                                                            onClick={() => handleClearLog(file.name)}
                                                            disabled={clearingLog === file.name}
                                                            className="flex items-center gap-1 px-2 py-1 text-xs bg-red-100 text-red-700 rounded hover:bg-red-200 disabled:opacity-50"
                                                        >
                                                            <Trash2 className="w-3 h-3" /> {clearingLog === file.name ? 'Clearing...' : 'Clear'}
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="text-center text-gray-500 py-4">No log files found</div>
                        )}
                    </div>
                )}
            </div>

            {/* Jobs Modal */}
            {showJobsModal && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div className="bg-white rounded-lg shadow-xl max-w-5xl w-full mx-4 max-h-[80vh] overflow-hidden">
                        <div className="flex items-center justify-between p-4 border-b">
                            <h3 className="text-lg font-semibold">Queue Jobs Details</h3>
                            <button onClick={() => setShowJobsModal(false)} className="text-gray-500 hover:text-gray-700">
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <div className="p-4 overflow-y-auto max-h-[60vh]">
                            {healthData?.application_stats?.queue?.job_details?.length > 0 ? (
                                <table className="min-w-full text-sm">
                                    <thead className="bg-gray-50">
                                        <tr>
                                            <th className="px-3 py-2 text-left">ID</th>
                                            <th className="px-3 py-2 text-left">Job Type</th>
                                            <th className="px-3 py-2 text-left">Queue</th>
                                            <th className="px-3 py-2 text-center">Attempts</th>
                                            <th className="px-3 py-2 text-center">Status</th>
                                            <th className="px-3 py-2 text-left">Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {healthData.application_stats.queue.job_details.map((job) => (
                                            <tr key={job.id} className="border-t hover:bg-gray-50">
                                                <td className="px-3 py-2 font-mono text-xs">{job.id}</td>
                                                <td className="px-3 py-2">
                                                    <span className="font-medium text-gray-800">{job.job_name}</span>
                                                </td>
                                                <td className="px-3 py-2 text-gray-600">{job.queue}</td>
                                                <td className="px-3 py-2 text-center">{job.attempts}</td>
                                                <td className="px-3 py-2 text-center">
                                                    <span className={`px-2 py-1 rounded text-xs font-medium ${
                                                        job.status === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                                                        job.status === 'processing' ? 'bg-blue-100 text-blue-800' :
                                                        job.status === 'retrying' ? 'bg-orange-100 text-orange-800' :
                                                        'bg-gray-100 text-gray-800'
                                                    }`}>
                                                        {job.status}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2 text-xs text-gray-500">{job.created_at}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : (
                                <div className="text-center text-gray-500 py-8">No jobs in queue</div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* Failed Jobs Modal */}
            {showFailedJobsModal && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div className="bg-white rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[80vh] overflow-hidden">
                        <div className="flex items-center justify-between p-4 border-b">
                            <h3 className="text-lg font-semibold text-red-600">Failed Jobs</h3>
                            <button onClick={() => setShowFailedJobsModal(false)} className="text-gray-500 hover:text-gray-700">
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <div className="p-4 overflow-y-auto max-h-[60vh]">
                            {healthData?.application_stats?.failed_jobs?.details?.length > 0 ? (
                                <div className="space-y-3">
                                    {healthData.application_stats.failed_jobs.details.map((job) => (
                                        <div key={job.id} className="p-3 bg-red-50 rounded-lg border border-red-200">
                                            <div className="flex justify-between text-sm">
                                                <span className="font-medium">ID: {job.id}</span>
                                                <span className="text-gray-500">{job.failed_at}</span>
                                            </div>
                                            <div className="text-xs text-gray-600 mt-1">Queue: {job.queue}</div>
                                            <div className="text-xs text-red-600 mt-2 font-mono bg-red-100 p-2 rounded overflow-x-auto">{job.exception}</div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="text-center text-gray-500 py-8">No failed jobs</div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* Log Viewer Modal */}
            {showLogModal && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div className="bg-white rounded-lg shadow-xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-hidden">
                        <div className="flex items-center justify-between p-4 border-b bg-gray-50">
                            <div className="flex items-center gap-3">
                                <FileText className="w-5 h-5 text-blue-600" />
                                <h3 className="text-lg font-semibold text-gray-800">{selectedLog}</h3>
                            </div>
                            <div className="flex items-center gap-2">
                                <button 
                                    onClick={() => handleClearLog(selectedLog)} 
                                    disabled={clearingLog === selectedLog}
                                    className="flex items-center gap-1 px-3 py-1.5 text-sm bg-red-100 text-red-700 rounded hover:bg-red-200 disabled:opacity-50"
                                >
                                    <Trash2 className="w-4 h-4" /> {clearingLog === selectedLog ? 'Clearing...' : 'Clear Log'}
                                </button>
                                <button onClick={() => setShowLogModal(false)} className="text-gray-500 hover:text-gray-700 p-1">
                                    <X className="w-5 h-5" />
                                </button>
                            </div>
                        </div>
                        <div className="p-4 overflow-y-auto max-h-[75vh]">
                            {logLoading ? (
                                <div className="flex items-center justify-center py-12">
                                    <RefreshCw className="w-6 h-6 animate-spin text-blue-600" />
                                    <span className="ml-2 text-gray-600">Loading log content...</span>
                                </div>
                            ) : logContent ? (
                                <pre className="text-xs font-mono bg-gray-900 text-green-400 p-4 rounded-lg overflow-x-auto whitespace-pre-wrap break-words">
                                    {logContent}
                                </pre>
                            ) : (
                                <div className="text-center text-gray-500 py-12">Log file is empty</div>
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* API Requests Modal */}
            {showApiRequestsModal && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div className="bg-white rounded-lg shadow-xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-hidden">
                        <div className="flex items-center justify-between p-4 border-b bg-gray-50">
                            <div className="flex items-center gap-3">
                                <Activity className="w-5 h-5 text-blue-600" />
                                <h3 className="text-lg font-semibold text-gray-800">
                                    {apiRequestsFilter === 'today' && 'API Requests Today'}
                                    {apiRequestsFilter === 'hour' && 'API Requests (Last Hour)'}
                                    {apiRequestsFilter === 'errors' && 'Error Requests Today'}
                                </h3>
                            </div>
                            <button onClick={() => setShowApiRequestsModal(false)} className="text-gray-500 hover:text-gray-700 p-1">
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <div className="p-4 overflow-y-auto max-h-[75vh]">
                            {(() => {
                                let filteredRequests = [];
                                
                                if (apiRequestsFilter === 'today') {
                                    filteredRequests = healthData?.api_stats?.recent_requests || [];
                                } else if (apiRequestsFilter === 'hour') {
                                    filteredRequests = healthData?.api_stats?.recent_requests_hour || [];
                                } else if (apiRequestsFilter === 'errors') {
                                    filteredRequests = healthData?.api_stats?.error_requests_today || [];
                                }

                                return filteredRequests.length > 0 ? (
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full text-sm">
                                            <thead className="bg-gray-50">
                                                <tr>
                                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Endpoint</th>
                                                    <th className="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Method</th>
                                                    <th className="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                                    <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Response Time</th>
                                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-gray-200">
                                                {filteredRequests.map((req, i) => (
                                                    <tr key={i} className="hover:bg-gray-50">
                                                        <td className="px-3 py-2">
                                                            <div className="flex items-center gap-1">
                                                                <Users className="w-3 h-3 text-gray-400" />
                                                                <span className="font-medium text-gray-700">{req.user_name || 'Guest'}</span>
                                                            </div>
                                                        </td>
                                                        <td className="px-3 py-2 font-mono text-xs text-gray-600 max-w-xs truncate">{req.endpoint}</td>
                                                        <td className="px-3 py-2 text-center">
                                                            <span className={`px-2 py-0.5 rounded text-xs font-medium ${
                                                                req.method === 'GET' ? 'bg-green-100 text-green-700' :
                                                                req.method === 'POST' ? 'bg-blue-100 text-blue-700' :
                                                                req.method === 'PUT' ? 'bg-yellow-100 text-yellow-700' :
                                                                req.method === 'DELETE' ? 'bg-red-100 text-red-700' :
                                                                'bg-gray-100 text-gray-700'
                                                            }`}>
                                                                {req.method}
                                                            </span>
                                                        </td>
                                                        <td className="px-3 py-2 text-center">
                                                            <span className={`px-2 py-0.5 rounded text-xs font-medium ${
                                                                req.status_code >= 500 ? 'bg-red-100 text-red-700' :
                                                                req.status_code >= 400 ? 'bg-orange-100 text-orange-700' :
                                                                req.status_code >= 300 ? 'bg-yellow-100 text-yellow-700' :
                                                                'bg-green-100 text-green-700'
                                                            }`}>
                                                                {req.status_code}
                                                            </span>
                                                        </td>
                                                        <td className="px-3 py-2 text-right text-gray-600">{req.response_time}ms</td>
                                                        <td className="px-3 py-2 text-xs text-gray-500">
                                                            {new Date(req.created_at).toLocaleString()}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                ) : (
                                    <div className="text-center text-gray-500 py-12">
                                        {apiRequestsFilter === 'errors' 
                                            ? 'No error requests found today' 
                                            : 'No API requests found'}
                                    </div>
                                );
                            })()}
                        </div>
                    </div>
                </div>
            )}

            {/* User Requests Modal */}
            {showUserRequestsModal && selectedUser && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div className="bg-white rounded-lg shadow-xl max-w-6xl w-full mx-4 max-h-[90vh] overflow-hidden">
                        <div className="flex items-center justify-between p-4 border-b bg-gray-50">
                            <div className="flex items-center gap-3">
                                <Users className="w-5 h-5 text-blue-600" />
                                <div>
                                    <h3 className="text-lg font-semibold text-gray-800">
                                        {selectedUser.user_name}'s API Requests
                                    </h3>
                                    <p className="text-xs text-gray-500">
                                        Today's requests - Total: {userRequestsPagination.total}
                                    </p>
                                </div>
                            </div>
                            <button 
                                onClick={() => {
                                    setShowUserRequestsModal(false);
                                    setSelectedUser(null);
                                    setUserRequests([]);
                                }} 
                                className="text-gray-500 hover:text-gray-700 p-1"
                            >
                                <X className="w-5 h-5" />
                            </button>
                        </div>
                        <div className="p-4 overflow-y-auto max-h-[70vh]">
                            {userRequestsLoading ? (
                                <div className="flex items-center justify-center py-12">
                                    <RefreshCw className="w-6 h-6 animate-spin text-blue-600" />
                                    <span className="ml-2 text-gray-600">Loading requests...</span>
                                </div>
                            ) : userRequests.length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-sm">
                                        <thead className="bg-gray-50">
                                            <tr>
                                                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Endpoint</th>
                                                <th className="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Method</th>
                                                <th className="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Status</th>
                                                <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Response Time</th>
                                                <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Time</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-gray-200">
                                            {userRequests.map((req, i) => (
                                                <tr key={i} className="hover:bg-gray-50">
                                                    <td className="px-3 py-2 font-mono text-xs text-gray-600 max-w-md truncate" title={req.endpoint}>
                                                        {req.endpoint}
                                                    </td>
                                                    <td className="px-3 py-2 text-center">
                                                        <span className={`px-2 py-0.5 rounded text-xs font-medium ${
                                                            req.method === 'GET' ? 'bg-green-100 text-green-700' :
                                                            req.method === 'POST' ? 'bg-blue-100 text-blue-700' :
                                                            req.method === 'PUT' ? 'bg-yellow-100 text-yellow-700' :
                                                            req.method === 'DELETE' ? 'bg-red-100 text-red-700' :
                                                            'bg-gray-100 text-gray-700'
                                                        }`}>
                                                            {req.method}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-2 text-center">
                                                        <span className={`px-2 py-0.5 rounded text-xs font-medium ${
                                                            req.status_code >= 500 ? 'bg-red-100 text-red-700' :
                                                            req.status_code >= 400 ? 'bg-orange-100 text-orange-700' :
                                                            req.status_code >= 300 ? 'bg-yellow-100 text-yellow-700' :
                                                            'bg-green-100 text-green-700'
                                                        }`}>
                                                            {req.status_code}
                                                        </span>
                                                    </td>
                                                    <td className="px-3 py-2 text-right text-gray-600">{req.response_time}ms</td>
                                                    <td className="px-3 py-2 text-xs text-gray-500">
                                                        {new Date(req.created_at).toLocaleString()}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="text-center text-gray-500 py-12">
                                    No requests found for this user today
                                </div>
                            )}
                        </div>
                        
                        {/* Pagination */}
                        {!userRequestsLoading && userRequests.length > 0 && userRequestsPagination.last_page > 1 && (
                            <div className="border-t p-4 bg-gray-50">
                                <div className="flex items-center justify-between">
                                    <div className="text-sm text-gray-600">
                                        Showing {userRequestsPagination.from} to {userRequestsPagination.to} of {userRequestsPagination.total} requests
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <button
                                            onClick={() => handlePageChange(userRequestsPagination.current_page - 1)}
                                            disabled={userRequestsPagination.current_page === 1}
                                            className="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Previous
                                        </button>
                                        <span className="text-sm text-gray-600">
                                            Page {userRequestsPagination.current_page} of {userRequestsPagination.last_page}
                                        </span>
                                        <button
                                            onClick={() => handlePageChange(userRequestsPagination.current_page + 1)}
                                            disabled={userRequestsPagination.current_page === userRequestsPagination.last_page}
                                            className="px-3 py-1 text-sm border border-gray-300 rounded hover:bg-gray-100 disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Next
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </DashboardLayout>
    );
};

export default SystemHealthPage;
