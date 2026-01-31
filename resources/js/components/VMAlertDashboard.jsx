import { useState, useEffect, useCallback } from 'react';
import { getVMAlerts, getVMFilterOptions, exportVMCsv } from '../services/vmAlertService';
import DashboardLayout from './DashboardLayout';
import api from '../services/api';

const VMAlertDashboard = () => {
    const [alerts, setAlerts] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [filterOptions, setFilterOptions] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [exporting, setExporting] = useState(false);
    const [serverDate, setServerDate] = useState(null);
    
    // Get current date from server
    const fetchServerDate = useCallback(async () => {
        try {
            const response = await api.get('/server-time');
            if (response.data.success) {
                setServerDate(response.data.date);
            }
        } catch (err) {
            console.error('Failed to fetch server date:', err);
            // Fallback to browser date if server request fails
            const today = new Date();
            setServerDate(today.toISOString().split('T')[0]);
        }
    }, []);

    const [filters, setFilters] = useState({
        panelid: '',
        dvrip: '',
        customer: '',
        panel_type: '',
        atmid: '',
        status: '', // Status filter: '' = All, 'O' = Open, 'C' = Closed
        from_date: '', // Will be set after fetching server date
        per_page: 25,
    });

    // Fetch server date on mount
    useEffect(() => {
        fetchServerDate();
    }, [fetchServerDate]);

    // Update from_date when serverDate is available
    useEffect(() => {
        if (serverDate && !filters.from_date) {
            setFilters(prev => ({ ...prev, from_date: serverDate }));
        }
    }, [serverDate, filters.from_date]);

    const fetchAlerts = useCallback(async (page = 1) => {
        setLoading(true);
        setError(null);
        try {
            const params = { ...filters, page };
            Object.keys(params).forEach(key => {
                if (params[key] === '' || params[key] === null) delete params[key];
            });
            const response = await getVMAlerts(params);
            if (response.success) {
                setAlerts(response.data.alerts);
                setPagination(response.data.pagination);
            } else {
                setError(response.error?.message || 'Failed to fetch alerts');
            }
        } catch (err) {
            setError(err.response?.data?.error?.message || err.message || 'Failed to connect to server');
        } finally {
            setLoading(false);
        }
    }, [filters]);

    const fetchFilterOptions = useCallback(async () => {
        try {
            const response = await getVMFilterOptions();
            if (response.success) setFilterOptions(response.data);
        } catch (err) {
            console.error('Failed to fetch filter options:', err);
        }
    }, []);

    useEffect(() => { fetchFilterOptions(); }, [fetchFilterOptions]);
    
    // Only fetch alerts when from_date is available
    useEffect(() => { 
        if (filters.from_date) {
            fetchAlerts(1); 
        }
    }, [fetchAlerts, filters.from_date]);

    const handleFilterChange = (key, value) => setFilters(prev => ({ ...prev, [key]: value }));
    const handleSearch = (e) => { e.preventDefault(); fetchAlerts(1); };
    const handleClearFilters = () => {
        setFilters({ panelid: '', dvrip: '', customer: '', panel_type: '', atmid: '', status: '', from_date: serverDate || '', per_page: 25 });
    };
    const handleExport = async () => {
        setExporting(true);
        setError(''); // Clear any previous errors
        
        try {
            // Only send the date - no filters
            const params = { from_date: filters.from_date };
            
            // Show progress message
            console.log('Starting VM alerts export for date:', filters.from_date);
            
            await exportVMCsv(params);
            
            // Success message could be added here if needed
            console.log('VM alerts export completed successfully');
            
        } catch (err) {
            console.error('Export failed:', err);
            
            // More specific error messages based on error type
            if (err.code === 'ECONNABORTED' || err.message?.includes('timeout')) {
                setError('Export is taking longer than expected. The file will download when ready. Please wait...');
            } else if (err.response?.status === 500) {
                setError('Server error during export. Please try again or contact support.');
            } else {
                setError('Failed to export CSV. Please check your connection and try again.');
            }
        } finally {
            setExporting(false);
        }
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return '-';
        return new Date(dateStr).toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    };
    const formatDateOnly = (dateStr) => {
        if (!dateStr) return '-';
        return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
    };
    const isRestoral = (alarm) => alarm && alarm.endsWith('R');

    return (
        <DashboardLayout>
            <div className="space-y-3">
                {/* Filter Section */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-4 py-2 border-b border-gray-200">
                        <h2 className="text-sm font-medium text-gray-900 flex items-center">
                            <FilterIcon className="w-4 h-4 mr-2" />Filter VM Reports
                        </h2>
                    </div>
                    <div className="p-3">
                        <form onSubmit={handleSearch} className="grid grid-cols-2 md:grid-cols-6 gap-2">
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">Panel ID</label>
                                <input type="text" value={filters.panelid} onChange={(e) => handleFilterChange('panelid', e.target.value)}
                                    placeholder="Enter Panel ID" className="w-full px-2 py-1 border border-gray-300 rounded text-sm" />
                            </div>
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">DVR IP</label>
                                <input type="text" value={filters.dvrip} onChange={(e) => handleFilterChange('dvrip', e.target.value)}
                                    placeholder="Enter DVR IP" className="w-full px-2 py-1 border border-gray-300 rounded text-sm" />
                            </div>
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">Customer</label>
                                <select value={filters.customer} onChange={(e) => handleFilterChange('customer', e.target.value)}
                                    className="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                    <option value="">All Customers</option>
                                    {filterOptions?.customers?.map(c => <option key={c} value={c}>{c}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">Panel Type</label>
                                <select value={filters.panel_type} onChange={(e) => handleFilterChange('panel_type', e.target.value)}
                                    className="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                    <option value="">All Panels</option>
                                    {filterOptions?.panel_makes?.map(p => <option key={p} value={p}>{p}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">ATM ID</label>
                                <input type="text" value={filters.atmid} onChange={(e) => handleFilterChange('atmid', e.target.value)}
                                    placeholder="Enter ATM ID" className="w-full px-2 py-1 border border-gray-300 rounded text-sm" />
                            </div>
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">Status</label>
                                <select 
                                    value={filters.status} 
                                    onChange={(e) => handleFilterChange('status', e.target.value)}
                                    className="w-full px-2 py-1 border border-gray-300 rounded text-sm"
                                >
                                    <option value="">All Status</option>
                                    <option value="O">Open</option>
                                    <option value="C">Closed</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">
                                    From Date <span className="text-red-500">*</span>
                                </label>
                                <input 
                                    type="date" 
                                    value={filters.from_date} 
                                    onChange={(e) => handleFilterChange('from_date', e.target.value)}
                                    required
                                    className="w-full px-2 py-1 border border-gray-300 rounded text-sm" 
                                />
                            </div>
                            <div className="col-span-2 md:col-span-6 flex gap-2 mt-1">
                                <button type="submit" className="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 flex items-center">
                                    <SearchIcon className="w-3 h-3 mr-1" />Filter
                                </button>
                                <button type="button" onClick={handleClearFilters} className="px-3 py-1 border border-gray-300 text-gray-600 rounded text-sm hover:bg-gray-50 flex items-center">
                                    <XIcon className="w-3 h-3 mr-1" />Clear
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                {/* Results Table */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-4 py-2 border-b border-gray-200 flex justify-between items-center">
                        <div className="flex items-center gap-3">
                            <h2 className="text-sm font-medium text-gray-900 flex items-center">
                                <ListIcon className="w-4 h-4 mr-2" />Reports
                            </h2>
                            {pagination && <span className="px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded">{pagination.total.toLocaleString()} Total</span>}
                        </div>
                        <div className="flex items-center gap-3">
                            <div className="flex items-center gap-1">
                                <label className="text-xs text-gray-500">Show:</label>
                                <select value={filters.per_page} onChange={(e) => handleFilterChange('per_page', parseInt(e.target.value))}
                                    className="px-1 py-0.5 border border-gray-300 rounded text-xs">
                                    <option value={10}>10</option>
                                    <option value={25}>25</option>
                                    <option value={50}>50</option>
                                    <option value={100}>100</option>
                                </select>
                            </div>
                            
                            {/* REDIRECT TO DOWNLOADS PAGE - Prevents portal blocking */}
                            <a 
                                href="/reports/downloads"
                                className="px-3 py-1.5 bg-green-600 text-white rounded text-xs hover:bg-green-700 flex items-center gap-1.5 transition-colors"
                                title="Go to Downloads page for batched downloads that won't block other users"
                            >
                                <DownloadIcon className="w-4 h-4" />
                                <span className="font-medium">Go to Downloads Page</span>
                            </a>
                            
                            {/* COMMENTED OUT: Direct CSV Download - Blocks portal for other users
                            <button 
                                onClick={handleExport} 
                                disabled={exporting || !filters.from_date}
                                className="px-3 py-1.5 bg-blue-600 text-white rounded text-xs hover:bg-blue-700 flex items-center gap-1.5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                title={`Generate and download VM alerts for ${filters.from_date || 'selected date'}. WARNING: Large datasets may take several minutes and block other users.`}
                            >
                                {exporting ? (
                                    <>
                                        <SpinnerIcon className="w-4 h-4 animate-spin" />
                                        <span className="font-medium">Generating...</span>
                                    </>
                                ) : (
                                    <>
                                        <DownloadIcon className="w-4 h-4" />
                                        <span className="font-medium">Download CSV</span>
                                        {filters.from_date && <span className="text-blue-200">({filters.from_date})</span>}
                                    </>
                                )}
                            </button>
                            */}
                            
                            {/* Warning for large datasets */}
                            {pagination && pagination.total > 500000 && (
                                <div className="flex items-center gap-1 px-2 py-1 bg-yellow-50 border border-yellow-200 rounded text-xs text-yellow-800">
                                    <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                                    </svg>
                                    <span className="font-medium">Large dataset ({pagination.total.toLocaleString()} records)</span>
                                    <span>- Use Downloads page for better performance</span>
                                </div>
                            )}
                        </div>
                    </div>

                    {error && <div className="p-3 bg-red-50 border-b border-red-200 text-red-700 text-sm">{error}</div>}

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-xs">
                            <thead className="bg-gray-50">
                                <tr>
                                    {['#','Client','Incident #','Region','ATM ID','Address','City','State','Zone','Alarm','Category','Message','Created','Received','Closed','DVR IP','Panel','Panel ID','Bank','Type','Closed By','Closed Date','Aging (hrs)','Remark','Send IP','Testing','Testing Remark'].map(h => (
                                        <th key={h} className="px-2 py-2 text-left font-medium text-gray-500 whitespace-nowrap">{h}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {loading ? (
                                    <tr><td colSpan="27" className="px-4 py-6 text-center">
                                        <div className="flex justify-center items-center"><div className="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600"></div><span className="ml-2 text-gray-500">Loading...</span></div>
                                    </td></tr>
                                ) : alerts.length === 0 ? (
                                    <tr><td colSpan="27" className="px-4 py-6 text-center text-gray-500">No reports found matching your criteria.</td></tr>
                                ) : (
                                    alerts.map((a, index) => (
                                        <tr key={a.id} className="hover:bg-gray-50">
                                            <td className="px-2 py-1.5 whitespace-nowrap text-gray-500 font-medium">{(pagination.from || 0) + index}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap font-medium">{a.Customer || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap"><span className="px-1 py-0.5 bg-gray-100 rounded">{a.id}</span></td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{a.site_zone || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{a.ATMID || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap max-w-[150px] truncate" title={a.SiteAddress}>{a.SiteAddress || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{a.City || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{a.State || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap"><span className="px-1 py-0.5 bg-cyan-100 text-cyan-800 rounded">{a.zone || '-'}</span></td>
                                            <td className="px-2 py-1.5 whitespace-nowrap"><span className="px-1 py-0.5 bg-yellow-100 text-yellow-800 rounded">{a.alarm || '-'}</span></td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{a.alerttype || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{isRestoral(a.alarm) ? <span className="text-green-600">{a.alerttype} Restoral</span> : (a.alerttype || '-')}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-gray-500">{formatDate(a.createtime)}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-gray-500">{formatDate(a.receivedtime)}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-gray-500">{formatDate(a.closedtime)}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap font-mono text-gray-600">{a.DVRIP || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{a.Panel_Make || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap font-mono">{a.panelid || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{a.Bank || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{isRestoral(a.alarm) ? <span className="px-1 py-0.5 bg-green-100 text-green-800 rounded">Non-Reactive</span> : <span className="px-1 py-0.5 bg-red-100 text-red-800 rounded">Reactive</span>}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{a.closedBy || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-gray-500">{formatDateOnly(a.closedtime)}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap"><span className={`px-1 py-0.5 rounded ${a.aging === 'NA' ? 'bg-gray-100 text-gray-600' : 'bg-blue-100 text-blue-800'}`}>{a.aging || 'NA'}</span></td>
                                            <td className="px-2 py-1.5 max-w-[200px]">
                                                <div className="truncate" title={a.comment}>
                                                    {a.comment ? a.comment.replace(/\n/g, ' ').replace(/\s+/g, ' ').trim() : '-'}
                                                </div>
                                            </td>
                                            <td className="px-2 py-1.5 whitespace-nowrap font-mono text-gray-600">{a.sendip || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{a.testing_by_service_team || '-'}</td>
                                            <td className="px-2 py-1.5 max-w-[200px]">
                                                <div className="truncate" title={a.testing_remark}>
                                                    {a.testing_remark ? a.testing_remark.replace(/\n/g, ' ').replace(/\s+/g, ' ').trim() : '-'}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {pagination && pagination.last_page > 1 && (
                        <div className="px-4 py-2 border-t border-gray-200 flex items-center justify-between">
                            <div className="text-xs text-gray-500">
                                Showing {pagination.from || 0} to {pagination.to || 0} of {pagination.total.toLocaleString()}
                            </div>
                            <nav className="flex gap-1">
                                <PgBtn onClick={() => fetchAlerts(1)} disabled={pagination.current_page === 1}><DblLeftIcon /></PgBtn>
                                <PgBtn onClick={() => fetchAlerts(pagination.current_page - 1)} disabled={pagination.current_page === 1}><LeftIcon /></PgBtn>
                                {(() => {
                                    const pages = [];
                                    let start = Math.max(1, pagination.current_page - 2);
                                    let end = Math.min(pagination.last_page, pagination.current_page + 2);
                                    if (end - start < 4) { if (start === 1) end = Math.min(pagination.last_page, start + 4); else start = Math.max(1, end - 4); }
                                    if (start > 1) { pages.push(<PgBtn key={1} onClick={() => fetchAlerts(1)}>1</PgBtn>); if (start > 2) pages.push(<span key="d1" className="px-1 text-gray-400">...</span>); }
                                    for (let i = start; i <= end; i++) pages.push(<PgBtn key={i} onClick={() => fetchAlerts(i)} active={i === pagination.current_page}>{i}</PgBtn>);
                                    if (end < pagination.last_page) { if (end < pagination.last_page - 1) pages.push(<span key="d2" className="px-1 text-gray-400">...</span>); pages.push(<PgBtn key={pagination.last_page} onClick={() => fetchAlerts(pagination.last_page)}>{pagination.last_page}</PgBtn>); }
                                    return pages;
                                })()}
                                <PgBtn onClick={() => fetchAlerts(pagination.current_page + 1)} disabled={pagination.current_page === pagination.last_page}><RightIcon /></PgBtn>
                                <PgBtn onClick={() => fetchAlerts(pagination.last_page)} disabled={pagination.current_page === pagination.last_page}><DblRightIcon /></PgBtn>
                            </nav>
                        </div>
                    )}
                </div>
            </div>
        </DashboardLayout>
    );
};

const PgBtn = ({ children, onClick, disabled, active }) => (
    <button onClick={onClick} disabled={disabled} className={`px-2 py-0.5 text-xs border rounded ${active ? 'bg-blue-600 text-white border-blue-600' : disabled ? 'bg-gray-100 text-gray-400 cursor-not-allowed' : 'bg-white text-gray-700 hover:bg-gray-50'}`}>{children}</button>
);

const DownloadIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" /></svg>;
const ExcelIcon = ({ className }) => <svg className={className} fill="currentColor" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 2l5 5h-5V4zM8.5 13.5l1.5 2-1.5 2h1.5l.75-1 .75 1h1.5l-1.5-2 1.5-2h-1.5l-.75 1-.75-1H8.5z"/></svg>;
const SpinnerIcon = ({ className }) => <svg className={className} fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>;
const FilterIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg>;
const SearchIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>;
const XIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" /></svg>;
const ListIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 10h16M4 14h16M4 18h16" /></svg>;
const DblLeftIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 19l-7-7 7-7m8 14l-7-7 7-7" /></svg>;
const LeftIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" /></svg>;
const RightIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>;
const DblRightIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 5l7 7-7 7M5 5l7 7-7 7" /></svg>;

export default VMAlertDashboard;
