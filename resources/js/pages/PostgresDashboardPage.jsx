import { useState, useEffect } from 'react';
import DashboardLayout from '../components/DashboardLayout';
import AlertDetailsModal from '../components/AlertDetailsModal';
import api from '../services/api';

/**
 * PostgreSQL Dashboard Page
 * Displays server alert count distribution using PostgreSQL partitioned tables
 * Features:
 * - Shift-based time filtering (auto-detected)
 * - Real-time data updates every 5 seconds
 * - Terminal-wise alert counts (Open, Close, Critical)
 * - Grand totals
 * - Interactive drill-down (to be implemented in task 19)
 * 
 * Requirements: 10.1, 10.2, 10.5, 4.1
 */
const PostgresDashboardPage = () => {
    // State management
    const [data, setData] = useState([]);
    const [totals, setTotals] = useState({
        grandtotalOpenAlerts: 0,
        grandtotalCloseAlerts: 0,
        grandtotalAlerts: 0,
        grandtoalCriticalOpen: 0,
        grandtotalCloseCriticalAlert: 0,
        grandtotalCritical: 0,
    });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [shift, setShift] = useState(null);
    const [shiftTimeRange, setShiftTimeRange] = useState({ start: '', end: '' });
    const [lastRefresh, setLastRefresh] = useState(new Date());

    // Modal state - Requirement 5.1, 10.4
    const [modalOpen, setModalOpen] = useState(false);
    const [selectedCell, setSelectedCell] = useState({
        terminal: '',
        status: '',
    });

    /**
     * Fetch dashboard data from API
     * Requirement 4.1: Fetch initial data immediately on load
     */
    const fetchDashboardData = async () => {
        try {
            setLoading(true);
            const response = await api.get('/dashboard/postgres/data');

            if (response.data.success) {
                setData(response.data.data || []);
                setTotals({
                    grandtotalOpenAlerts: response.data.grandtotalOpenAlerts || 0,
                    grandtotalCloseAlerts: response.data.grandtotalCloseAlerts || 0,
                    grandtotalAlerts: response.data.grandtotalAlerts || 0,
                    grandtoalCriticalOpen: response.data.grandtoalCriticalOpen || 0,
                    grandtotalCloseCriticalAlert: response.data.grandtotalCloseCriticalAlert || 0,
                    grandtotalCritical: response.data.grandtotalCritical || 0,
                });
                setShift(response.data.shift);
                setShiftTimeRange(response.data.shift_time_range || { start: '', end: '' });
                setLastRefresh(new Date());
                setError(null);
            }
        } catch (err) {
            // Requirement 10.5: Display error message if fetch fails
            setError('Failed to load dashboard data');
            console.error('Error fetching dashboard data:', err);
        } finally {
            setLoading(false);
        }
    };

    /**
     * Requirement 4.1: Call fetchDashboardData() on component mount
     */
    useEffect(() => {
        fetchDashboardData();
    }, []);

    /**
     * Auto-refresh functionality
     * Requirements: 4.2, 4.3, 4.4, 4.5
     * - Set up 5-second interval (4.2)
     * - Update table data without full page reload (4.3)
     * - Add new terminal rows when they appear (4.4)
     * - Update grand total values with each refresh (4.5)
     */
    useEffect(() => {
        // Set up interval to fetch data every 5 seconds
        const intervalId = setInterval(() => {
            fetchDashboardData();
        }, 5000);

        // Clear interval on component unmount
        return () => {
            clearInterval(intervalId);
        };
    }, []);

    /**
     * Format datetime for display
     */
    const formatDateTime = (dateString) => {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleString();
    };

    /**
     * Get shift label
     */
    const getShiftLabel = (shiftNum) => {
        const shiftLabels = {
            1: 'Shift 1 (07:00 - 14:59)',
            2: 'Shift 2 (15:00 - 22:59)',
            3: 'Shift 3 (23:00 - 06:59)',
        };
        return shiftLabels[shiftNum] || `Shift ${shiftNum}`;
    };

    /**
     * Handle cell click to open modal
     * Requirements: 5.1, 10.4
     * Opens modal with detailed alert information for selected terminal and status
     */
    const handleCellClick = (terminal, status) => {
        setSelectedCell({ terminal, status });
        setModalOpen(true);
    };

    /**
     * Handle modal close
     */
    const handleModalClose = () => {
        setModalOpen(false);
        setSelectedCell({ terminal: '', status: '' });
    };

    return (
        <DashboardLayout>
            <div className="space-y-4">
                {/* Header - Requirement 10.2 */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-lg font-semibold text-gray-900">
                            Server Alert Count Distribution (PostgreSQL)
                        </h1>
                        <p className="text-xs text-gray-500 mt-1">
                            Real-time alert monitoring from PostgreSQL partitioned tables
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <button
                            onClick={fetchDashboardData}
                            disabled={loading}
                            className="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50"
                        >
                            <RefreshIcon className="w-4 h-4 mr-1.5" />
                            Refresh
                        </button>
                    </div>
                </div>

                {/* Shift Info */}
                {shift && shiftTimeRange.start && (
                    <div className="bg-blue-50 border border-blue-200 rounded-md p-3">
                        <div className="flex items-center justify-between text-xs">
                            <div>
                                <span className="font-medium text-blue-900">Current Shift:</span>
                                <span className="text-blue-700 ml-2">{getShiftLabel(shift)}</span>
                                <span className="text-blue-600 ml-3">
                                    {formatDateTime(shiftTimeRange.start)} - {formatDateTime(shiftTimeRange.end)}
                                </span>
                            </div>
                            <div className="text-blue-600">
                                Last updated: {lastRefresh.toLocaleTimeString()}
                            </div>
                        </div>
                    </div>
                )}

                {/* Error Message - Requirement 10.5 */}
                {error && (
                    <div className="bg-red-50 border border-red-200 rounded-md p-3">
                        <p className="text-xs text-red-600">{error}</p>
                    </div>
                )}

                {/* Dashboard Table - Requirements: 1.1, 1.5, 10.3 */}
                <div className="bg-white shadow rounded-lg overflow-hidden">
                    {/* Requirement 10.5: Display loading indicator while fetching */}
                    {loading && data.length === 0 ? (
                        <div className="flex items-center justify-center py-12">
                            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                            <span className="ml-3 text-sm text-gray-600">Loading dashboard data...</span>
                        </div>
                    ) : data.length === 0 ? (
                        <div className="text-center py-12">
                            <p className="text-sm text-gray-500">No alert data available for current shift</p>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            {/* Bootstrap-style table with 8 columns: Terminal, User, Open, Close, Total, Critical Open, Critical Close, Total Critical */}
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Terminal
                                        </th>
                                        <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            User
                                        </th>
                                        <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Open
                                        </th>
                                        <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Close
                                        </th>
                                        <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Total
                                        </th>
                                        <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Critical Open
                                        </th>
                                        <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Critical Close
                                        </th>
                                        <th className="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            Total Critical
                                        </th>
                                    </tr>
                                </thead>
                                {/* Table body with striped rows (even rows have bg-gray-50) and hover effect */}
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {data.map((row, index) => (
                                        <tr 
                                            key={index} 
                                            className={`hover:bg-gray-100 transition-colors ${index % 2 === 1 ? 'bg-gray-50' : 'bg-white'}`}
                                        >
                                            {/* Non-clickable columns: Terminal and User */}
                                            <td className="px-4 py-3 text-xs text-gray-900 whitespace-nowrap">
                                                {row.terminal || '-'}
                                            </td>
                                            <td className="px-4 py-3 text-xs text-gray-900 whitespace-nowrap">
                                                {row.username || '-'}
                                            </td>
                                            {/* Clickable columns with data-terminal and data-status attributes */}
                                            {/* Requirement 10.4: Show pointer cursor on hover for clickable cells */}
                                            <td 
                                                className="px-4 py-3 text-xs text-gray-900 text-center whitespace-nowrap cursor-pointer hover:bg-blue-100"
                                                data-terminal={row.terminal}
                                                data-status="open"
                                                onClick={() => handleCellClick(row.terminal, 'open')}
                                            >
                                                {row.open || 0}
                                            </td>
                                            <td 
                                                className="px-4 py-3 text-xs text-gray-900 text-center whitespace-nowrap cursor-pointer hover:bg-blue-100"
                                                data-terminal={row.terminal}
                                                data-status="close"
                                                onClick={() => handleCellClick(row.terminal, 'close')}
                                            >
                                                {row.close || 0}
                                            </td>
                                            <td 
                                                className="px-4 py-3 text-xs text-gray-900 text-center whitespace-nowrap font-medium cursor-pointer hover:bg-blue-100"
                                                data-terminal={row.terminal}
                                                data-status="total"
                                                onClick={() => handleCellClick(row.terminal, 'total')}
                                            >
                                                {row.total || 0}
                                            </td>
                                            <td 
                                                className="px-4 py-3 text-xs text-red-600 text-center whitespace-nowrap cursor-pointer hover:bg-red-100"
                                                data-terminal={row.terminal}
                                                data-status="criticalopen"
                                                onClick={() => handleCellClick(row.terminal, 'criticalopen')}
                                            >
                                                {row.criticalopen || 0}
                                            </td>
                                            <td 
                                                className="px-4 py-3 text-xs text-red-600 text-center whitespace-nowrap cursor-pointer hover:bg-red-100"
                                                data-terminal={row.terminal}
                                                data-status="criticalClose"
                                                onClick={() => handleCellClick(row.terminal, 'criticalClose')}
                                            >
                                                {row.criticalClose || 0}
                                            </td>
                                            <td 
                                                className="px-4 py-3 text-xs text-red-600 text-center whitespace-nowrap font-medium cursor-pointer hover:bg-red-100"
                                                data-terminal={row.terminal}
                                                data-status="totalCritical"
                                                onClick={() => handleCellClick(row.terminal, 'totalCritical')}
                                            >
                                                {row.totalCritical || 0}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                {/* Grand Totals Footer - Requirement 1.5 */}
                                <tfoot className="bg-gray-100 border-t-2 border-gray-300">
                                    <tr>
                                        <td colSpan="2" className="px-4 py-3 text-xs font-bold text-gray-900 uppercase">
                                            Grand Total
                                        </td>
                                        <td className="px-4 py-3 text-xs font-bold text-gray-900 text-center">
                                            {totals.grandtotalOpenAlerts}
                                        </td>
                                        <td className="px-4 py-3 text-xs font-bold text-gray-900 text-center">
                                            {totals.grandtotalCloseAlerts}
                                        </td>
                                        <td className="px-4 py-3 text-xs font-bold text-gray-900 text-center">
                                            {totals.grandtotalAlerts}
                                        </td>
                                        <td className="px-4 py-3 text-xs font-bold text-red-700 text-center">
                                            {totals.grandtoalCriticalOpen}
                                        </td>
                                        <td className="px-4 py-3 text-xs font-bold text-red-700 text-center">
                                            {totals.grandtotalCloseCriticalAlert}
                                        </td>
                                        <td className="px-4 py-3 text-xs font-bold text-red-700 text-center">
                                            {totals.grandtotalCritical}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    )}
                </div>

                {/* Alert Details Modal - Requirements: 5.1, 10.4 */}
                <AlertDetailsModal
                    isOpen={modalOpen}
                    terminal={selectedCell.terminal}
                    status={selectedCell.status}
                    shift={shift}
                    onClose={handleModalClose}
                />
            </div>
        </DashboardLayout>
    );
};

// Refresh Icon component
const RefreshIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
);

export default PostgresDashboardPage;
