import { useState, useEffect } from 'react';
import api from '../services/api';

/**
 * AlertDetailsModal component
 * Displays detailed alert information in a modal popup
 * 
 * Props:
 * - isOpen: boolean - Controls modal visibility
 * - terminal: string - Terminal IP address
 * - status: string - Status filter (open, close, total, criticalopen, criticalClose, totalCritical)
 * - shift: number - Shift number (1, 2, or 3)
 * - onClose: function - Callback to close the modal
 * 
 * Requirements: 5.1, 5.5
 */
const AlertDetailsModal = ({ isOpen, terminal, status, shift, onClose }) => {
    // State management
    const [alerts, setAlerts] = useState([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    /**
     * Fetch alert details from API
     * Requirement 5.5: Fetch data when modal opens
     */
    const fetchAlertDetails = async () => {
        if (!terminal || !status || !shift) {
            return;
        }

        try {
            setLoading(true);
            setError(null);

            const response = await api.get('/dashboard/postgres/details', {
                params: {
                    terminal,
                    status,
                    shift,
                },
            });

            if (response.data.success) {
                setAlerts(response.data.data || []);
            }
        } catch (err) {
            setError('Failed to load alert details');
            console.error('Error fetching alert details:', err);
        } finally {
            setLoading(false);
        }
    };

    /**
     * Fetch data when modal opens
     * Requirement 5.5: Fetch data when modal opens
     */
    useEffect(() => {
        if (isOpen) {
            fetchAlertDetails();
        }
    }, [isOpen, terminal, status, shift]);

    /**
     * Format datetime for display
     */
    const formatDateTime = (dateString) => {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleString();
    };

    /**
     * Get status label for display
     */
    const getStatusLabel = (statusValue) => {
        const statusLabels = {
            open: 'Open Alerts',
            close: 'Closed Alerts',
            total: 'All Alerts',
            criticalopen: 'Critical Open Alerts',
            criticalClose: 'Critical Closed Alerts',
            totalCritical: 'All Critical Alerts',
        };
        return statusLabels[statusValue] || statusValue;
    };

    /**
     * Handle modal close
     */
    const handleClose = () => {
        setAlerts([]);
        setError(null);
        onClose?.();
    };

    // Don't render if modal is not open
    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                {/* Backdrop */}
                <div 
                    className="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" 
                    onClick={handleClose} 
                />
                
                {/* Modal Content */}
                <div className="relative inline-block w-full max-w-6xl p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-lg">
                    {/* Modal Header */}
                    <div className="flex items-center justify-between mb-4 pb-3 border-b border-gray-200">
                        <div>
                            <h3 className="text-lg font-medium leading-6 text-gray-900">
                                Alert Details
                            </h3>
                            <p className="mt-1 text-sm text-gray-500">
                                Terminal: <span className="font-medium text-gray-700">{terminal}</span>
                                {' | '}
                                Status: <span className="font-medium text-gray-700">{getStatusLabel(status)}</span>
                                {' | '}
                                Shift: <span className="font-medium text-gray-700">{shift}</span>
                            </p>
                        </div>
                        <button 
                            onClick={handleClose} 
                            className="text-gray-400 hover:text-gray-500 transition-colors"
                        >
                            <CloseIcon className="w-6 h-6" />
                        </button>
                    </div>

                    {/* Error Message */}
                    {error && (
                        <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-md">
                            <p className="text-sm text-red-600">{error}</p>
                        </div>
                    )}

                    {/* Loading Indicator - Requirement 5.5 */}
                    {loading ? (
                        <div className="flex items-center justify-center py-12">
                            <LoadingSpinner className="w-8 h-8 mr-3" />
                            <span className="text-sm text-gray-600">Loading alert details...</span>
                        </div>
                    ) : alerts.length === 0 ? (
                        <div className="text-center py-12">
                            <p className="text-sm text-gray-500">No alerts found for the selected criteria</p>
                        </div>
                    ) : (
                        <>
                            {/* Alert Count */}
                            <div className="mb-3 text-sm text-gray-600">
                                Showing <span className="font-medium text-gray-900">{alerts.length}</span> alert{alerts.length !== 1 ? 's' : ''}
                            </div>

                            {/* Alert Details Table */}
                            <div className="overflow-x-auto max-h-96 border border-gray-200 rounded-md">
                                <table className="min-w-full divide-y divide-gray-200">
                                    <thead className="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Sr No
                                            </th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                ATMID
                                            </th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Panel ID
                                            </th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Zone
                                            </th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                City
                                            </th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Received At
                                            </th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Alert Type
                                            </th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Comment
                                            </th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Close By
                                            </th>
                                            <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                Closed At
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="bg-white divide-y divide-gray-200">
                                        {alerts.map((alert, index) => (
                                            <tr 
                                                key={alert.id || index} 
                                                className={`hover:bg-gray-50 transition-colors ${index % 2 === 1 ? 'bg-gray-50' : 'bg-white'}`}
                                            >
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">
                                                    {index + 1}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">
                                                    {alert.ATMID || '-'}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">
                                                    {alert.panelid || '-'}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">
                                                    {alert.Zone || '-'}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">
                                                    {alert.City || '-'}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">
                                                    {formatDateTime(alert.receivedtime)}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-gray-900">
                                                    {alert.alerttype || '-'}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-gray-900 max-w-xs truncate" title={alert.comment}>
                                                    {alert.comment || '-'}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">
                                                    {alert.closedBy || '-'}
                                                </td>
                                                <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">
                                                    {formatDateTime(alert.closedtime)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </>
                    )}

                    {/* Modal Footer */}
                    <div className="flex items-center justify-end gap-3 pt-4 mt-4 border-t border-gray-200">
                        <button
                            type="button"
                            onClick={handleClose}
                            className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
};

// Close Icon component
const CloseIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
    </svg>
);

// Loading Spinner component
const LoadingSpinner = ({ className }) => (
    <svg className={`animate-spin ${className}`} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
    </svg>
);

export default AlertDetailsModal;
