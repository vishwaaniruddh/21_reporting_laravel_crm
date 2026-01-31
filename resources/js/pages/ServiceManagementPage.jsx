import { useState, useEffect } from 'react';
import DashboardLayout from '../components/DashboardLayout';
import api from '../services/api';

/**
 * ServiceManagementPage component
 * Manages Windows NSSM services (start, stop, restart, view logs)
 */
const ServiceManagementPage = () => {
    const [services, setServices] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [actionLoading, setActionLoading] = useState({});
    const [selectedService, setSelectedService] = useState(null);
    const [logs, setLogs] = useState('');
    const [logsLoading, setLogsLoading] = useState(false);
    const [showLogsModal, setShowLogsModal] = useState(false);

    useEffect(() => {
        fetchServices();
        // Auto-refresh every 10 seconds
        const interval = setInterval(fetchServices, 10000);
        return () => clearInterval(interval);
    }, []);

    const fetchServices = async () => {
        try {
            setLoading(true);
            const response = await api.get('/services');
            setServices(response.data.services || []);
            setError(null);
        } catch (err) {
            setError('Failed to load services');
            console.error('Error fetching services:', err);
        } finally {
            setLoading(false);
        }
    };

    const handleServiceAction = async (serviceName, action) => {
        try {
            setActionLoading(prev => ({ ...prev, [serviceName]: action }));
            
            const response = await api.post(`/services/${action}`, { service: serviceName });
            
            if (response.data.success) {
                // Update the service in the list
                setServices(prev => prev.map(s => 
                    s.name === serviceName ? response.data.service : s
                ));
            } else {
                alert(`Failed to ${action} service: ${response.data.message}`);
            }
        } catch (err) {
            alert(`Error: ${err.response?.data?.message || err.message}`);
            console.error(`Error ${action} service:`, err);
        } finally {
            setActionLoading(prev => ({ ...prev, [serviceName]: null }));
        }
    };

    const handleViewLogs = async (serviceName) => {
        try {
            setSelectedService(serviceName);
            setShowLogsModal(true);
            setLogsLoading(true);
            
            const response = await api.get('/services/logs', {
                params: { service: serviceName, lines: 100 }
            });
            
            setLogs(response.data.logs || 'No logs available');
        } catch (err) {
            setLogs(`Error loading logs: ${err.response?.data?.message || err.message}`);
            console.error('Error fetching logs:', err);
        } finally {
            setLogsLoading(false);
        }
    };

    const getStatusColor = (status) => {
        const statusLower = status?.toLowerCase() || '';
        if (statusLower.includes('running')) return 'bg-green-100 text-green-800';
        if (statusLower.includes('stopped')) return 'bg-red-100 text-red-800';
        if (statusLower.includes('not found')) return 'bg-gray-100 text-gray-800';
        return 'bg-yellow-100 text-yellow-800';
    };

    const getServiceDescription = (serviceName) => {
        const descriptions = {
            'AlertInitialSync': 'Syncs alerts from MySQL to PostgreSQL partitioned tables',
            'AlertUpdateSync': 'Processes alert updates from alert_pg_update_log table',
            'AlertCleanup': 'Deletes old records from MySQL alerts_2 table (48 hours retention)',
            'AlertMysqlBackup': 'Daily backup of MySQL data files at 2 AM',
            'AlertPortal': 'Laravel application server (port 9000)',
            'AlertViteDev': 'Vite development server for frontend assets',
        };
        return descriptions[serviceName] || 'Windows NSSM service';
    };

    return (
        <DashboardLayout>
            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold text-gray-900">Service Management</h1>
                        <p className="text-xs text-gray-500 mt-1">Manage Windows NSSM services</p>
                    </div>
                    <button
                        onClick={fetchServices}
                        disabled={loading}
                        className="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
                    >
                        <RefreshIcon className="w-4 h-4 mr-1.5" />
                        Refresh
                    </button>
                </div>

                {error && (
                    <div className="p-3 bg-red-50 border border-red-200 rounded-md">
                        <p className="text-xs text-red-600">{error}</p>
                    </div>
                )}

                {loading && services.length === 0 ? (
                    <div className="flex items-center justify-center py-8">
                        <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600"></div>
                        <span className="ml-2 text-xs text-gray-600">Loading services...</span>
                    </div>
                ) : (
                    <div className="grid gap-4">
                        {services.map((service) => (
                            <div key={service.name} className="bg-white shadow rounded-lg overflow-hidden">
                                <div className="p-4">
                                    <div className="flex items-start justify-between">
                                        <div className="flex-1">
                                            <div className="flex items-center gap-3">
                                                <h3 className="text-sm font-semibold text-gray-900">
                                                    {service.display_name || service.name}
                                                </h3>
                                                <span className={`px-2 py-0.5 text-xs font-medium rounded-full ${getStatusColor(service.status)}`}>
                                                    {service.status}
                                                </span>
                                            </div>
                                            <p className="text-xs text-gray-600 mt-1">
                                                {getServiceDescription(service.name)}
                                            </p>
                                            <div className="flex items-center gap-4 mt-2 text-xs text-gray-500">
                                                <span>Service: <span className="font-mono">{service.name}</span></span>
                                                <span>Start Type: {service.start_type}</span>
                                            </div>
                                        </div>
                                        
                                        <div className="flex items-center gap-2 ml-4">
                                            {service.exists && (
                                                <>
                                                    {service.status?.toLowerCase().includes('stopped') ? (
                                                        <button
                                                            onClick={() => handleServiceAction(service.name, 'start')}
                                                            disabled={actionLoading[service.name]}
                                                            className="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md text-white bg-green-600 hover:bg-green-700 disabled:opacity-50"
                                                        >
                                                            {actionLoading[service.name] === 'start' ? (
                                                                <>
                                                                    <LoadingSpinner className="w-3 h-3 mr-1.5" />
                                                                    Starting...
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <PlayIcon className="w-3 h-3 mr-1.5" />
                                                                    Start
                                                                </>
                                                            )}
                                                        </button>
                                                    ) : (
                                                        <button
                                                            onClick={() => handleServiceAction(service.name, 'stop')}
                                                            disabled={actionLoading[service.name]}
                                                            className="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md text-white bg-red-600 hover:bg-red-700 disabled:opacity-50"
                                                        >
                                                            {actionLoading[service.name] === 'stop' ? (
                                                                <>
                                                                    <LoadingSpinner className="w-3 h-3 mr-1.5" />
                                                                    Stopping...
                                                                </>
                                                            ) : (
                                                                <>
                                                                    <StopIcon className="w-3 h-3 mr-1.5" />
                                                                    Stop
                                                                </>
                                                            )}
                                                        </button>
                                                    )}
                                                    
                                                    <button
                                                        onClick={() => handleServiceAction(service.name, 'restart')}
                                                        disabled={actionLoading[service.name]}
                                                        className="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md text-gray-700 bg-gray-100 hover:bg-gray-200 disabled:opacity-50"
                                                    >
                                                        {actionLoading[service.name] === 'restart' ? (
                                                            <>
                                                                <LoadingSpinner className="w-3 h-3 mr-1.5" />
                                                                Restarting...
                                                            </>
                                                        ) : (
                                                            <>
                                                                <RestartIcon className="w-3 h-3 mr-1.5" />
                                                                Restart
                                                            </>
                                                        )}
                                                    </button>
                                                    
                                                    <button
                                                        onClick={() => handleViewLogs(service.name)}
                                                        className="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md text-gray-700 bg-gray-100 hover:bg-gray-200"
                                                    >
                                                        <LogsIcon className="w-3 h-3 mr-1.5" />
                                                        Logs
                                                    </button>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        ))}

                        {services.length === 0 && (
                            <div className="bg-white shadow rounded-lg p-8 text-center">
                                <p className="text-xs text-gray-500">No services found</p>
                            </div>
                        )}
                    </div>
                )}
            </div>

            {/* Logs Modal */}
            {showLogsModal && (
                <div className="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                    <div className="flex items-end justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                        {/* Background overlay */}
                        <div 
                            className="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" 
                            aria-hidden="true"
                            onClick={() => setShowLogsModal(false)}
                        ></div>

                        {/* Center modal */}
                        <span className="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                        <div className="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
                            <div className="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                <div className="flex items-center justify-between mb-4">
                                    <h3 className="text-lg font-medium text-gray-900">
                                        Service Logs: {selectedService}
                                    </h3>
                                    <button
                                        onClick={() => setShowLogsModal(false)}
                                        className="text-gray-400 hover:text-gray-500"
                                    >
                                        <CloseIcon className="w-5 h-5" />
                                    </button>
                                </div>

                                {logsLoading ? (
                                    <div className="flex items-center justify-center py-8">
                                        <LoadingSpinner className="w-6 h-6" />
                                        <span className="ml-2 text-sm text-gray-600">Loading logs...</span>
                                    </div>
                                ) : (
                                    <div className="bg-gray-900 rounded-md p-4 overflow-auto max-h-96">
                                        <pre className="text-xs text-green-400 font-mono whitespace-pre-wrap">{logs}</pre>
                                    </div>
                                )}
                            </div>
                            <div className="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                                <button
                                    onClick={() => setShowLogsModal(false)}
                                    className="w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 sm:ml-3 sm:w-auto"
                                >
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </DashboardLayout>
    );
};

// Icon components
const RefreshIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
);

const PlayIcon = ({ className }) => (
    <svg className={className} fill="currentColor" viewBox="0 0 24 24">
        <path d="M8 5v14l11-7z" />
    </svg>
);

const StopIcon = ({ className }) => (
    <svg className={className} fill="currentColor" viewBox="0 0 24 24">
        <path d="M6 6h12v12H6z" />
    </svg>
);

const RestartIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
);

const LogsIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
    </svg>
);

const LoadingSpinner = ({ className }) => (
    <svg className={`animate-spin ${className}`} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
    </svg>
);

const CloseIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
    </svg>
);

export default ServiceManagementPage;
