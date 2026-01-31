import React, { useState, useEffect, useCallback } from 'react';
import DashboardLayout from './DashboardLayout';
import {
    listPartitions,
    triggerPartitionedSync,
    getPartitionInfo,
} from '../services/partitionService';

/**
 * PartitionManagementDashboard Component
 * 
 * Displays and manages date-partitioned alert tables:
 * - List of all partitions with metadata
 * - Record counts and dates
 * - Manual sync trigger
 * - Partition visualization
 * 
 * Requirements: 9.4
 */
const PartitionManagementDashboard = () => {
    const [partitions, setPartitions] = useState([]);
    const [summary, setSummary] = useState(null);
    const [pagination, setPagination] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [syncing, setSyncing] = useState(false);
    const [message, setMessage] = useState(null);
    const [activeTab, setActiveTab] = useState('list');
    const [filters, setFilters] = useState({
        per_page: 50,
        order_by: 'partition_date',
        order_direction: 'desc',
    });

    const fetchPartitions = useCallback(async () => {
        try {
            setLoading(true);
            setError(null); // Clear any previous errors
            
            // Add table_type=combined to get the new combined view by default
            const params = { ...filters, table_type: 'combined' };
            const response = await listPartitions(params);
            
            if (response.success) {
                // Handle both old format (partitions) and new format (combined_partitions)
                const partitionData = response.data.combined_partitions || response.data.partitions || [];
                
                // Ensure we always have an array
                const safePartitionData = Array.isArray(partitionData) ? partitionData : [];
                
                setPartitions(safePartitionData);
                setPagination(response.data.pagination || null);
                setSummary(response.data.summary || null);
                setError(null);
            } else {
                setError(response.error?.message || 'Failed to fetch partitions');
                setPartitions([]); // Ensure partitions is always an array
            }
        } catch (err) {
            console.error('Error fetching partitions:', err);
            setError(err.response?.data?.error?.message || 'Failed to connect to server');
            setPartitions([]); // Ensure partitions is always an array
        } finally {
            setLoading(false);
        }
    }, [filters]);

    useEffect(() => {
        fetchPartitions();
        // Auto-refresh every 30 seconds
        const interval = setInterval(fetchPartitions, 30000);
        return () => clearInterval(interval);
    }, [fetchPartitions]);

    const handleTriggerSync = async () => {
        setSyncing(true);
        setMessage(null);

        try {
            const response = await triggerPartitionedSync();
            
            if (response.success) {
                setMessage({
                    type: 'success',
                    text: `Sync completed: ${response.data.total_records_processed} records processed across ${response.data.date_groups?.length || 0} date groups`,
                });
                fetchPartitions();
            } else {
                setMessage({
                    type: 'warning',
                    text: response.data?.message || 'Sync completed with issues',
                });
            }
        } catch (err) {
            setMessage({
                type: 'error',
                text: err.response?.data?.error?.message || 'Failed to trigger sync',
            });
        } finally {
            setSyncing(false);
        }
    };

    const handleSortChange = (field) => {
        setFilters(prev => ({
            ...prev,
            order_by: field,
            order_direction: prev.order_by === field && prev.order_direction === 'desc' ? 'asc' : 'desc',
        }));
    };

    if (loading && (!partitions || partitions.length === 0)) {
        return (
            <DashboardLayout>
                <div className="space-y-6">
                    <div className="bg-white rounded-lg shadow p-6">
                        <div className="animate-pulse">
                            <div className="h-6 bg-gray-200 rounded w-1/4 mb-4"></div>
                            <div className="grid grid-cols-3 gap-4">
                                <div className="h-20 bg-gray-200 rounded"></div>
                                <div className="h-20 bg-gray-200 rounded"></div>
                                <div className="h-20 bg-gray-200 rounded"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </DashboardLayout>
        );
    }

    if (error && (!partitions || partitions.length === 0)) {
        return (
            <DashboardLayout>
                <div className="bg-white rounded-lg shadow p-6">
                    <div className="flex items-center text-red-600 mb-4">
                        <XCircleIcon className="w-5 h-5 mr-2" />
                        <span className="font-medium">Error loading partitions</span>
                    </div>
                    <p className="text-gray-600 text-sm mb-4">{error}</p>
                    <button
                        onClick={fetchPartitions}
                        className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm"
                    >
                        Retry
                    </button>
                </div>
            </DashboardLayout>
        );
    }

    return (
        <DashboardLayout>
            <div className="space-y-6">
                {/* Header */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-6 py-4 border-b border-gray-200">
                        <div className="flex items-center justify-between">
                            <div>
                                <h1 className="text-xl font-semibold text-gray-900">Partition Management</h1>
                                <p className="text-sm text-gray-500 mt-1">
                                    Date-partitioned alert tables (alerts_YYYY_MM_DD)
                                </p>
                            </div>
                            <div className="flex items-center space-x-3">
                                <button
                                    onClick={fetchPartitions}
                                    disabled={loading}
                                    className="px-4 py-2 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 text-sm disabled:opacity-50 flex items-center"
                                >
                                    <RefreshIcon className={`w-4 h-4 mr-2 ${loading ? 'animate-spin' : ''}`} />
                                    Refresh
                                </button>
                                <button
                                    onClick={handleTriggerSync}
                                    disabled={syncing}
                                    className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm disabled:bg-gray-400 disabled:cursor-not-allowed flex items-center"
                                >
                                    {syncing ? (
                                        <>
                                            <RefreshIcon className="w-4 h-4 mr-2 animate-spin" />
                                            Syncing...
                                        </>
                                    ) : (
                                        <>
                                            <SyncIcon className="w-4 h-4 mr-2" />
                                            Trigger Sync
                                        </>
                                    )}
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* Tabs */}
                    <div className="px-6 border-b border-gray-200">
                        <nav className="-mb-px flex space-x-8">
                            <button
                                onClick={() => setActiveTab('list')}
                                className={`py-4 px-1 border-b-2 font-medium text-sm ${
                                    activeTab === 'list'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                }`}
                            >
                                Partition List
                            </button>
                            <button
                                onClick={() => setActiveTab('visualization')}
                                className={`py-4 px-1 border-b-2 font-medium text-sm ${
                                    activeTab === 'visualization'
                                        ? 'border-indigo-500 text-indigo-600'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                }`}
                            >
                                Visualization
                            </button>
                        </nav>
                    </div>
                </div>

                {/* Messages */}
                {message && (
                    <div className={`p-4 rounded-md ${
                        message.type === 'success' ? 'bg-green-50 border border-green-200 text-green-700' :
                        message.type === 'warning' ? 'bg-yellow-50 border border-yellow-200 text-yellow-700' :
                        'bg-red-50 border border-red-200 text-red-700'
                    }`}>
                        <div className="flex items-center justify-between">
                            <p className="text-sm">{message.text}</p>
                            <button
                                onClick={() => setMessage(null)}
                                className="text-gray-400 hover:text-gray-600"
                            >
                                <XIcon className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* Summary Statistics */}
                {summary && (
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <StatCard
                            label="Total Partitions"
                            value={summary.total_partitions}
                            icon={<DatabaseIcon className="w-6 h-6 text-indigo-600" />}
                            color="indigo"
                        />
                        <StatCard
                            label="Total Records"
                            value={summary.total_records}
                            icon={<DocumentIcon className="w-6 h-6 text-green-600" />}
                            color="green"
                            subtitle={summary.alerts_records && summary.backalerts_records 
                                ? `${(summary.alerts_records / 1000000).toFixed(1)}M alerts + ${(summary.backalerts_records / 1000000).toFixed(1)}M backalerts`
                                : undefined
                            }
                        />
                        {summary.alerts_records && (
                            <StatCard
                                label="Alerts Records"
                                value={summary.alerts_records}
                                icon={<DocumentIcon className="w-6 h-6 text-blue-600" />}
                                color="blue"
                            />
                        )}
                        {summary.backalerts_records && (
                            <StatCard
                                label="Backalerts Records"
                                value={summary.backalerts_records}
                                icon={<DocumentIcon className="w-6 h-6 text-purple-600" />}
                                color="purple"
                            />
                        )}
                        <StatCard
                            label="Stale Partitions"
                            value={summary.stale_partitions}
                            icon={<ClockIcon className="w-6 h-6 text-yellow-600" />}
                            color="yellow"
                            subtitle="Not synced in 24h"
                        />
                    </div>
                )}

                {/* Tab Content */}
                {activeTab === 'list' && (
                    <ErrorBoundary>
                        <PartitionList
                            partitions={partitions || []}
                            pagination={pagination}
                            loading={loading}
                            onSortChange={handleSortChange}
                            currentSort={filters.order_by}
                            sortDirection={filters.order_direction}
                        />
                    </ErrorBoundary>
                )}

                {activeTab === 'visualization' && (
                    <ErrorBoundary>
                        <PartitionVisualization
                            partitions={partitions || []}
                            summary={summary}
                        />
                    </ErrorBoundary>
                )}
            </div>
        </DashboardLayout>
    );
};

/**
 * StatCard Component
 * Displays a summary statistic
 */
const StatCard = ({ label, value, icon, color, subtitle }) => {
    const colorClasses = {
        indigo: 'bg-indigo-50 border-indigo-200',
        green: 'bg-green-50 border-green-200',
        yellow: 'bg-yellow-50 border-yellow-200',
        red: 'bg-red-50 border-red-200',
        blue: 'bg-blue-50 border-blue-200',
        purple: 'bg-purple-50 border-purple-200',
    };

    return (
        <div className={`p-6 rounded-lg border ${colorClasses[color] || colorClasses.indigo}`}>
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-sm text-gray-500 uppercase tracking-wide">{label}</p>
                    <p className="text-3xl font-bold text-gray-900 mt-2">
                        {value?.toLocaleString() ?? '-'}
                    </p>
                    {subtitle && (
                        <p className="text-xs text-gray-500 mt-1">{subtitle}</p>
                    )}
                </div>
                <div className="flex-shrink-0">
                    {icon}
                </div>
            </div>
        </div>
    );
};

/**
 * PartitionList Component
 * Displays the list of partitions in a table
 */
const PartitionList = ({ partitions, pagination, loading, onSortChange, currentSort, sortDirection }) => {
    // Multiple safety checks to prevent undefined errors
    if (!partitions) {
        console.warn('PartitionList: partitions prop is undefined, using empty array');
        partitions = [];
    }
    
    if (!Array.isArray(partitions)) {
        console.warn('PartitionList: partitions prop is not an array, converting to array');
        partitions = [];
    }

    const getSortIcon = (field) => {
        if (currentSort !== field) {
            return <ChevronUpDownIcon className="w-4 h-4 text-gray-400" />;
        }
        return sortDirection === 'asc' 
            ? <ChevronUpIcon className="w-4 h-4 text-indigo-600" />
            : <ChevronDownIcon className="w-4 h-4 text-indigo-600" />;
    };

    // Safety check: ensure partitions is an array
    const safePartitions = Array.isArray(partitions) ? partitions : [];

    if (safePartitions.length === 0 && !loading) {
        return (
            <div className="bg-white rounded-lg shadow p-12 text-center">
                <DatabaseIcon className="w-16 h-16 text-gray-400 mx-auto mb-4" />
                <h3 className="text-lg font-medium text-gray-900 mb-2">No Partitions Found</h3>
                <p className="text-gray-500 mb-6">
                    No date-partitioned tables exist yet. Trigger a sync to create partitions.
                </p>
            </div>
        );
    }

    return (
        <div className="bg-white rounded-lg shadow overflow-hidden">
            <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th
                                scope="col"
                                className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100"
                                onClick={() => onSortChange('partition_date')}
                            >
                                <div className="flex items-center space-x-1">
                                    <span>Date</span>
                                    {getSortIcon('partition_date')}
                                </div>
                            </th>
                            <th
                                scope="col"
                                className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Table Name
                            </th>
                            <th
                                scope="col"
                                className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100"
                                onClick={() => onSortChange('record_count')}
                            >
                                <div className="flex items-center justify-end space-x-1">
                                    <span>Records</span>
                                    {getSortIcon('record_count')}
                                </div>
                            </th>
                            <th
                                scope="col"
                                className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100"
                                onClick={() => onSortChange('last_synced_at')}
                            >
                                <div className="flex items-center space-x-1">
                                    <span>Last Synced</span>
                                    {getSortIcon('last_synced_at')}
                                </div>
                            </th>
                            <th
                                scope="col"
                                className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
                            >
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {safePartitions.map((partition, index) => {
                            // Handle both old format (individual partitions) and new format (combined partitions)
                            const isCombinable = partition.date && partition.total_count !== undefined;
                            
                            if (isCombinable) {
                                // New combined format
                                return (
                                    <tr key={partition.date || index} className="hover:bg-gray-50">
                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {partition.date}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-500">
                                            <div className="space-y-1">
                                                {partition.alerts_table && (
                                                    <div className="text-blue-600">{partition.alerts_table}</div>
                                                )}
                                                {partition.backalerts_table && (
                                                    <div className="text-purple-600">{partition.backalerts_table}</div>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                            <div className="space-y-1">
                                                <div className="font-semibold text-gray-900">
                                                    {partition.total_count.toLocaleString()} total
                                                </div>
                                                {partition.alerts_count > 0 && (
                                                    <div className="text-blue-600 text-xs">
                                                        {partition.alerts_count.toLocaleString()} alerts
                                                    </div>
                                                )}
                                                {partition.backalerts_count > 0 && (
                                                    <div className="text-purple-600 text-xs">
                                                        {partition.backalerts_count.toLocaleString()} backalerts
                                                    </div>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            Combined View
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            <span className="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                                                Combined
                                            </span>
                                        </td>
                                    </tr>
                                );
                            } else {
                                // Old individual partition format
                                return (
                                    <tr key={partition.table_name || index} className="hover:bg-gray-50">
                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {partition.partition_date}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm font-mono text-gray-500">
                                            <span className={partition.table_type === 'backalerts' ? 'text-purple-600' : 'text-blue-600'}>
                                                {partition.table_name}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                                            {partition.record_count?.toLocaleString() || '0'}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {partition.last_synced_at 
                                                ? new Date(partition.last_synced_at).toLocaleString()
                                                : 'Never'}
                                        </td>
                                        <td className="px-6 py-4 whitespace-nowrap">
                                            <span className={`px-2 py-1 text-xs rounded-full ${
                                                partition.is_stale
                                                    ? 'bg-yellow-100 text-yellow-800'
                                                    : 'bg-green-100 text-green-800'
                                            }`}>
                                                {partition.is_stale ? 'Stale' : 'Current'}
                                            </span>
                                        </td>
                                    </tr>
                                );
                            }
                        })}
                    </tbody>
                </table>
            </div>

            {/* Pagination */}
            {pagination && pagination.last_page > 1 && (
                <div className="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                    <div className="flex items-center justify-between">
                        <div className="text-sm text-gray-700">
                            Showing <span className="font-medium">{pagination.from}</span> to{' '}
                            <span className="font-medium">{pagination.to}</span> of{' '}
                            <span className="font-medium">{pagination.total}</span> partitions
                        </div>
                        <div className="text-sm text-gray-500">
                            Page {pagination.current_page} of {pagination.last_page}
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
};

/**
 * PartitionVisualization Component
 * Displays charts and timeline view of partitions
 */
const PartitionVisualization = ({ partitions, summary }) => {
    // Safety check: ensure partitions is an array
    const safePartitions = Array.isArray(partitions) ? partitions : [];
    
    // Calculate statistics for visualization
    const totalRecords = safePartitions.reduce((sum, p) => {
        // Handle both old format (record_count) and new format (total_count)
        return sum + (p.total_count || p.record_count || 0);
    }, 0);
    
    const avgRecordsPerPartition = safePartitions.length > 0 
        ? Math.round(totalRecords / safePartitions.length)
        : 0;

    // Find max record count for scaling
    const maxRecords = Math.max(...partitions.map(p => p.record_count), 1);

    // Detect missing date ranges
    const detectMissingDates = () => {
        if (partitions.length < 2) return [];
        
        const sortedPartitions = [...partitions].sort((a, b) => 
            new Date(a.partition_date) - new Date(b.partition_date)
        );
        
        const missingRanges = [];
        for (let i = 0; i < sortedPartitions.length - 1; i++) {
            const currentDate = new Date(sortedPartitions[i].partition_date);
            const nextDate = new Date(sortedPartitions[i + 1].partition_date);
            const daysDiff = Math.round((nextDate - currentDate) / (1000 * 60 * 60 * 24));
            
            if (daysDiff > 1) {
                missingRanges.push({
                    start: sortedPartitions[i].partition_date,
                    end: sortedPartitions[i + 1].partition_date,
                    days: daysDiff - 1,
                });
            }
        }
        return missingRanges;
    };

    const missingDateRanges = detectMissingDates();

    return (
        <div className="space-y-6">
            {/* Records Distribution Chart */}
            <div className="bg-white rounded-lg shadow p-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">Records per Partition</h3>
                <p className="text-sm text-gray-500 mb-6">
                    Average: {avgRecordsPerPartition.toLocaleString()} records per partition
                </p>
                
                <div className="space-y-3">
                    {partitions.slice(0, 20).map((partition) => {
                        const percentage = (partition.record_count / maxRecords) * 100;
                        
                        return (
                            <div key={partition.table_name} className="flex items-center">
                                <div className="w-32 text-sm font-mono text-gray-600 flex-shrink-0">
                                    {partition.partition_date}
                                </div>
                                <div className="flex-1 mx-4">
                                    <div className="w-full bg-gray-200 rounded-full h-6 relative">
                                        <div
                                            className={`h-6 rounded-full transition-all ${
                                                partition.is_stale ? 'bg-yellow-500' : 'bg-indigo-600'
                                            }`}
                                            style={{ width: `${percentage}%` }}
                                        >
                                            <span className="absolute inset-0 flex items-center justify-center text-xs font-medium text-white">
                                                {partition.record_count.toLocaleString()}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        );
                    })}
                </div>

                {partitions.length > 20 && (
                    <p className="text-sm text-gray-500 mt-4 text-center">
                        Showing first 20 partitions. Total: {partitions.length}
                    </p>
                )}
            </div>

            {/* Timeline View */}
            <div className="bg-white rounded-lg shadow p-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">Partition Timeline</h3>
                <p className="text-sm text-gray-500 mb-6">
                    Visual representation of partition coverage over time
                </p>

                <div className="space-y-2">
                    {partitions.slice(0, 30).map((partition, index) => (
                        <div key={partition.table_name} className="flex items-center">
                            <div className="w-32 text-xs font-mono text-gray-600 flex-shrink-0">
                                {partition.partition_date}
                            </div>
                            <div className="flex-1 flex items-center space-x-2">
                                <div
                                    className={`h-8 rounded flex items-center justify-center text-xs font-medium text-white ${
                                        partition.is_stale ? 'bg-yellow-500' : 'bg-green-500'
                                    }`}
                                    style={{ width: `${Math.max((partition.record_count / maxRecords) * 100, 10)}%` }}
                                >
                                    {partition.record_count > 0 && (
                                        <span>{partition.record_count.toLocaleString()}</span>
                                    )}
                                </div>
                                {partition.is_stale && (
                                    <span className="text-xs text-yellow-600">⚠ Stale</span>
                                )}
                            </div>
                        </div>
                    ))}
                </div>

                {partitions.length > 30 && (
                    <p className="text-sm text-gray-500 mt-4 text-center">
                        Showing first 30 partitions. Total: {partitions.length}
                    </p>
                )}

                {/* Legend */}
                <div className="mt-6 pt-6 border-t border-gray-200">
                    <div className="flex items-center justify-center space-x-6 text-sm">
                        <div className="flex items-center">
                            <div className="w-4 h-4 bg-green-500 rounded mr-2"></div>
                            <span className="text-gray-600">Current (synced within 24h)</span>
                        </div>
                        <div className="flex items-center">
                            <div className="w-4 h-4 bg-yellow-500 rounded mr-2"></div>
                            <span className="text-gray-600">Stale (not synced in 24h)</span>
                        </div>
                    </div>
                </div>
            </div>

            {/* Missing Date Ranges */}
            {partitions.length > 1 && (
                <div className="bg-white rounded-lg shadow p-6">
                    <h3 className="text-lg font-semibold text-gray-900 mb-4">Coverage Analysis</h3>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div className="p-4 bg-blue-50 rounded-lg">
                            <p className="text-sm text-gray-600">Oldest Partition</p>
                            <p className="text-lg font-semibold text-gray-900 mt-1">
                                {partitions[partitions.length - 1]?.partition_date || 'N/A'}
                            </p>
                        </div>
                        <div className="p-4 bg-green-50 rounded-lg">
                            <p className="text-sm text-gray-600">Newest Partition</p>
                            <p className="text-lg font-semibold text-gray-900 mt-1">
                                {partitions[0]?.partition_date || 'N/A'}
                            </p>
                        </div>
                        <div className="p-4 bg-indigo-50 rounded-lg">
                            <p className="text-sm text-gray-600">Date Range</p>
                            <p className="text-lg font-semibold text-gray-900 mt-1">
                                {partitions.length} days
                            </p>
                        </div>
                    </div>

                    {/* Missing Date Ranges Detection */}
                    {missingDateRanges.length > 0 && (
                        <div className="mt-6 pt-6 border-t border-gray-200">
                            <div className="flex items-center mb-4">
                                <ExclamationIcon className="w-5 h-5 text-yellow-600 mr-2" />
                                <h4 className="text-md font-semibold text-gray-900">
                                    Missing Date Ranges Detected
                                </h4>
                            </div>
                            <div className="space-y-2">
                                {missingDateRanges.map((range, index) => (
                                    <div key={index} className="flex items-center justify-between p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
                                        <div className="flex items-center">
                                            <span className="text-sm font-medium text-gray-900">
                                                Gap between {range.start} and {range.end}
                                            </span>
                                        </div>
                                        <span className="px-2 py-1 text-xs font-semibold bg-yellow-200 text-yellow-800 rounded">
                                            {range.days} day{range.days > 1 ? 's' : ''} missing
                                        </span>
                                    </div>
                                ))}
                            </div>
                            <p className="text-sm text-gray-500 mt-4">
                                These date ranges have no partition tables. Run a sync to create missing partitions.
                            </p>
                        </div>
                    )}

                    {missingDateRanges.length === 0 && (
                        <div className="mt-6 pt-6 border-t border-gray-200">
                            <div className="flex items-center justify-center p-4 bg-green-50 border border-green-200 rounded-lg">
                                <CheckCircleIcon className="w-5 h-5 text-green-600 mr-2" />
                                <span className="text-sm font-medium text-green-900">
                                    No missing date ranges detected - continuous coverage
                                </span>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
};

// Icon Components
const XCircleIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const RefreshIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
);

const SyncIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
    </svg>
);

const XIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
    </svg>
);

const DatabaseIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
    </svg>
);

const DocumentIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
    </svg>
);

const ClockIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const ChevronUpDownIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
    </svg>
);

const ChevronUpIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 15l7-7 7 7" />
    </svg>
);

const ChevronDownIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
    </svg>
);

const ExclamationIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
    </svg>
);

const CheckCircleIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

/**
 * Simple Error Boundary Component
 * Catches JavaScript errors in child components
 */
class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    componentDidCatch(error, errorInfo) {
        console.error('PartitionManagementDashboard Error:', error, errorInfo);
    }

    render() {
        if (this.state.hasError) {
            return (
                <div className="bg-red-50 border border-red-200 rounded-lg p-6">
                    <div className="flex items-center">
                        <div className="flex-shrink-0">
                            <svg className="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                            </svg>
                        </div>
                        <div className="ml-3">
                            <h3 className="text-sm font-medium text-red-800">
                                Component Error
                            </h3>
                            <div className="mt-2 text-sm text-red-700">
                                <p>Something went wrong loading the partition data. Please refresh the page.</p>
                            </div>
                        </div>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}

export default PartitionManagementDashboard;
