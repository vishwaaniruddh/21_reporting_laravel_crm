import { useState, useEffect } from 'react';
import { Download, FileText, Calendar, AlertCircle, Loader2 } from 'lucide-react';
import DashboardLayout from '../components/DashboardLayout';
import api from '../services/api';

/**
 * Downloads Page - Centralized download interface for reports
 * 
 * Features:
 * - Tabbed interface for All Alerts and VM Alerts
 * - Shows available partition dates with record counts
 * - Batch download for large datasets (600k records per file)
 * - CSV download only
 */
const DownloadsPage = () => {
    const [activeTab, setActiveTab] = useState('all-alerts');
    const [partitions, setPartitions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [downloading, setDownloading] = useState({});
    const [selectedPartitions, setSelectedPartitions] = useState([]);

    // Fetch available partitions on mount and tab change
    useEffect(() => {
        fetchPartitions();
    }, [activeTab]);

    const fetchPartitions = async () => {
        setLoading(true);
        try {
            const response = await api.get('/downloads/partitions', {
                params: { type: activeTab }
            });

            if (response.data.success) {
                setPartitions(response.data.data);
            }
        } catch (error) {
            console.error('Failed to fetch partitions:', error);
            alert('Failed to load partition data. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    const calculateBatches = (records) => {
        const batchSize = 600000; // 600k records per batch
        return Math.ceil(records / batchSize);
    };

    const formatNumber = (num) => {
        return new Intl.NumberFormat('en-US').format(num);
    };

    const handleDownload = async (partition, format, batchIndex = null) => {
        const key = `${partition.date}-${format}-${batchIndex || 'all'}`;
        setDownloading(prev => ({ ...prev, [key]: true }));

        try {
            // Determine API endpoint based on tab
            const tokenEndpoint = activeTab === 'vm-alerts' 
                ? '/vm-alerts/export/csv/token'
                : '/alerts-reports/export/csv/token';
            
            const downloadEndpoint = activeTab === 'vm-alerts' 
                ? '/vm-alerts/export/csv'
                : '/alerts-reports/export/csv';
            
            // Calculate offset and limit for batch downloads
            const batchSize = 600000;
            const offset = batchIndex ? (batchIndex - 1) * batchSize : 0;
            const limit = batchIndex ? batchSize : Math.min(partition.records, 1000000);
            
            // Step 1: Request a download token from backend
            const tokenResponse = await api.post(tokenEndpoint, {
                from_date: partition.date,
                limit: limit,
                offset: offset,
            });
            
            if (!tokenResponse.data.success) {
                throw new Error('Failed to generate download token');
            }
            
            const token = tokenResponse.data.token;
            
            // Step 2: Use token in a simple window.open() which doesn't block
            const downloadUrl = `/api${downloadEndpoint}?token=${token}`;
            
            // Open download in new window/tab - this doesn't block the UI
            window.open(downloadUrl, '_blank');
            
            // Clear downloading state immediately since we're not waiting for the download
            setDownloading(prev => ({ ...prev, [key]: false }));
            
        } catch (error) {
            console.error('Download failed:', error);
            alert('Download failed. Please try again.');
            setDownloading(prev => ({ ...prev, [key]: false }));
        }
    };

    const handleBulkDownload = async (format) => {
        if (selectedPartitions.length === 0) {
            alert('Please select at least one date to download');
            return;
        }

        for (const date of selectedPartitions) {
            const partition = partitions.find(p => p.date === date);
            if (partition) {
                const batches = calculateBatches(partition.records);
                
                // Download all batches for this partition
                if (batches > 1) {
                    for (let i = 1; i <= batches; i++) {
                        handleDownload(partition, format, i);
                        // Small delay between downloads to avoid overwhelming the server
                        await new Promise(resolve => setTimeout(resolve, 500));
                    }
                } else {
                    handleDownload(partition, format);
                    await new Promise(resolve => setTimeout(resolve, 500));
                }
            }
        }
    };

    const togglePartitionSelection = (date) => {
        setSelectedPartitions(prev => 
            prev.includes(date) 
                ? prev.filter(d => d !== date)
                : [...prev, date]
        );
    };

    const tabs = [
        { id: 'all-alerts', label: 'All Alerts', icon: FileText },
        { id: 'vm-alerts', label: 'VM Alerts', icon: AlertCircle },
    ];

    return (
        <DashboardLayout>
            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Downloads</h1>
                        <p className="text-sm text-gray-600 mt-1">Download alert reports by date</p>
                    </div>
                </div>

                {/* Tabs */}
                <div className="bg-white rounded-lg shadow-sm border border-gray-200">
                    <div className="border-b border-gray-200">
                        <nav className="flex -mb-px">
                            {tabs.map((tab) => {
                                const Icon = tab.icon;
                                return (
                                    <button
                                        key={tab.id}
                                        onClick={() => setActiveTab(tab.id)}
                                        className={`
                                            flex items-center gap-2 px-6 py-3 text-sm font-medium border-b-2 transition-colors
                                            ${activeTab === tab.id
                                                ? 'border-blue-500 text-blue-600'
                                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                            }
                                        `}
                                    >
                                        <Icon className="w-4 h-4" />
                                        {tab.label}
                                    </button>
                                );
                            })}
                        </nav>
                    </div>

                    {/* Tab Content */}
                    <div className="p-4">
                        {loading ? (
                            <div className="flex items-center justify-center py-12">
                                <Loader2 className="w-6 h-6 animate-spin text-blue-600" />
                                <span className="ml-2 text-sm text-gray-600">Loading partitions...</span>
                            </div>
                        ) : (
                            <>
                                {/* Bulk Actions Bar */}
                                {selectedPartitions.length > 0 && (
                                    <div className="mb-4 bg-blue-50 border border-blue-200 rounded-lg px-4 py-2 flex items-center justify-between">
                                        <span className="text-sm font-medium text-blue-900">
                                            {selectedPartitions.length} date(s) selected
                                        </span>
                                        <button
                                            onClick={() => handleBulkDownload('csv')}
                                            className="px-3 py-1.5 bg-green-600 text-white text-sm rounded hover:bg-green-700 transition-colors flex items-center gap-1.5"
                                        >
                                            <Download className="w-3.5 h-3.5" />
                                            Download All
                                        </button>
                                    </div>
                                )}

                                {/* Partitions Table */}
                                <div className="space-y-3">
                                    {partitions.map((partition) => {
                                        const batches = calculateBatches(partition.records);
                                        const needsBatching = batches > 1;
                                        const isSelected = selectedPartitions.includes(partition.date);

                                        return (
                                            <div
                                                key={partition.date}
                                                className={`border rounded-lg transition-all ${
                                                    isSelected ? 'border-blue-400 bg-blue-50' : 'border-gray-200'
                                                }`}
                                            >
                                                <div className="p-4">
                                                    <div className="flex items-center gap-3">
                                                        {/* Checkbox */}
                                                        <input
                                                            type="checkbox"
                                                            checked={isSelected}
                                                            onChange={() => togglePartitionSelection(partition.date)}
                                                            className="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500"
                                                        />
                                                        
                                                        {/* Date Icon */}
                                                        <Calendar className="w-5 h-5 text-gray-400" />
                                                        
                                                        {/* Date and Records */}
                                                        <div className="flex-1">
                                                            <div className="flex items-center gap-2">
                                                                <span className="font-semibold text-gray-900">{partition.date}</span>
                                                                {needsBatching && (
                                                                    <span className="px-2 py-0.5 bg-orange-100 text-orange-700 text-xs font-medium rounded">
                                                                        {batches} Batches
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <p className="text-xs text-gray-500 mt-0.5">
                                                                {formatNumber(partition.records)} records
                                                                {needsBatching && ` (~${formatNumber(Math.ceil(partition.records / batches))} per batch)`}
                                                            </p>
                                                        </div>

                                                        {/* Download Buttons */}
                                                        <div className="flex gap-2">
                                                            {needsBatching ? (
                                                                <>
                                                                    {Array.from({ length: batches }, (_, i) => i + 1).map(batchNum => {
                                                                        const key = `${partition.date}-csv-${batchNum}`;
                                                                        return (
                                                                            <button
                                                                                key={batchNum}
                                                                                onClick={() => handleDownload(partition, 'csv', batchNum)}
                                                                                disabled={downloading[key]}
                                                                                className="px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-medium rounded transition-colors disabled:bg-gray-400 flex items-center gap-1.5 min-w-[100px] justify-center"
                                                                            >
                                                                                {downloading[key] ? (
                                                                                    <>
                                                                                        <Loader2 className="w-3 h-3 animate-spin" />
                                                                                        <span>Loading...</span>
                                                                                    </>
                                                                                ) : (
                                                                                    <>
                                                                                        <Download className="w-3 h-3" />
                                                                                        <span>Batch {batchNum} of {batches}</span>
                                                                                    </>
                                                                                )}
                                                                            </button>
                                                                        );
                                                                    })}
                                                                </>
                                                            ) : (
                                                                <button
                                                                    onClick={() => handleDownload(partition, 'csv')}
                                                                    disabled={downloading[`${partition.date}-csv-all`]}
                                                                    className="px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white text-sm font-medium rounded transition-colors disabled:bg-gray-400 flex items-center gap-2"
                                                                >
                                                                    {downloading[`${partition.date}-csv-all`] ? (
                                                                        <>
                                                                            <Loader2 className="w-4 h-4 animate-spin" />
                                                                            <span>Downloading...</span>
                                                                        </>
                                                                    ) : (
                                                                        <>
                                                                            <Download className="w-4 h-4" />
                                                                            <span>Download CSV</span>
                                                                        </>
                                                                    )}
                                                                </button>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                {/* Info Box */}
                                <div className="mt-4 bg-blue-50 border border-blue-200 rounded-lg p-3">
                                    <div className="flex gap-2">
                                        <AlertCircle className="w-4 h-4 text-blue-600 flex-shrink-0 mt-0.5" />
                                        <div className="text-xs text-blue-800">
                                            <p className="font-semibold mb-1">Download Information</p>
                                            <ul className="space-y-0.5">
                                                <li>• Datasets over 600k records are split into batches</li>
                                                <li>• Maximum 1 million records per date</li>
                                                <li>• {activeTab === 'vm-alerts' ? 'VM Alerts: status O/C with sendtoclient=S only' : 'All Alerts: includes both alerts and backalerts'}</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
};

export default DownloadsPage;
