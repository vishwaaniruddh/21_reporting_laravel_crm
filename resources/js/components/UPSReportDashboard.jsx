import { useState, useEffect, useCallback } from 'react';
import DashboardLayout from './DashboardLayout';
import api from '../services/api';

const UPSReportDashboard = () => {
    const [data, setData] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [filterOptions, setFilterOptions] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
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
            const today = new Date();
            setServerDate(today.toISOString().split('T')[0]);
        }
    }, []);

    const [filters, setFilters] = useState({
        panelid: '',
        customer: '',
        atmid: '',
        from_date: '',
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

    const fetchData = useCallback(async (page = 1) => {
        setLoading(true);
        setError(null);
        try {
            const params = { ...filters, page };
            Object.keys(params).forEach(key => {
                if (params[key] === '' || params[key] === null) delete params[key];
            });
            
            const response = await api.get('/ups-reports', { params });
            
            if (response.data.success) {
                setData(response.data.data.reports || []);
                setPagination(response.data.data.pagination);
            } else {
                setError(response.data.error?.message || 'Failed to fetch UPS reports');
            }
        } catch (err) {
            setError(err.response?.data?.error?.message || err.message || 'Failed to connect to server');
        } finally {
            setLoading(false);
        }
    }, [filters]);

    const fetchFilterOptions = useCallback(async () => {
        try {
            const response = await api.get('/ups-reports/filter-options');
            if (response.data.success) setFilterOptions(response.data.data);
        } catch (err) {
            console.error('Failed to fetch filter options:', err);
        }
    }, []);

    useEffect(() => { fetchFilterOptions(); }, [fetchFilterOptions]);
    
    useEffect(() => { 
        if (filters.from_date) {
            fetchData(1); 
        }
    }, [fetchData, filters.from_date]);

    const handleFilterChange = (key, value) => setFilters(prev => ({ ...prev, [key]: value }));
    const handleSearch = (e) => { e.preventDefault(); fetchData(1); };
    const handleClearFilters = () => {
        setFilters({ panelid: '', customer: '', atmid: '', from_date: serverDate || '', per_page: 25 });
    };

    const handleExport = async () => {
        try {
            const params = { ...filters };
            Object.keys(params).forEach(key => {
                if (params[key] === '' || params[key] === null) delete params[key];
            });
            
            const queryString = new URLSearchParams(params).toString();
            window.open(`/api/ups-reports/export/csv?${queryString}`, '_blank');
        } catch (err) {
            console.error('Export failed:', err);
            setError('Failed to export CSV. Please try again.');
        }
    };

    const formatDate = (dateStr) => {
        if (!dateStr) return '-';
        return new Date(dateStr).toLocaleString('en-US', { month: 'short', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    };

    return (
        <DashboardLayout>
            <div className="space-y-3">
                {/* Filter Section */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-4 py-2 border-b border-gray-200">
                        <h2 className="text-sm font-medium text-gray-900 flex items-center">
                            <FilterIcon className="w-4 h-4 mr-2" />Filter UPS Reports
                        </h2>
                    </div>
                    <div className="p-3">
                        <form onSubmit={handleSearch} className="grid grid-cols-2 md:grid-cols-5 gap-2">
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">Panel ID</label>
                                <input type="text" value={filters.panelid} onChange={(e) => handleFilterChange('panelid', e.target.value)}
                                    placeholder="Enter Panel ID" className="w-full px-2 py-1 border border-gray-300 rounded text-sm" />
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
                                <label className="block text-xs text-gray-600 mb-1">ATM ID</label>
                                <input type="text" value={filters.atmid} onChange={(e) => handleFilterChange('atmid', e.target.value)}
                                    placeholder="Enter ATM ID" className="w-full px-2 py-1 border border-gray-300 rounded text-sm" />
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
                            <div className="col-span-2 md:col-span-5 flex gap-2 mt-1">
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
                                <ListIcon className="w-4 h-4 mr-2" />UPS Reports
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
                            
                            {/* Export CSV Button */}
                            <button 
                                onClick={handleExport} 
                                disabled={!filters.from_date}
                                className="px-3 py-1.5 bg-green-600 text-white rounded text-xs hover:bg-green-700 flex items-center gap-1.5 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                title="Export UPS report to CSV"
                            >
                                <DownloadIcon className="w-4 h-4" />
                                <span className="font-medium">Export CSV</span>
                            </button>
                        </div>
                    </div>

                    {error && <div className="p-3 bg-red-50 border-b border-red-200 text-red-700 text-sm">{error}</div>}

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-xs">
                            <thead className="bg-gray-50">
                                <tr>
                                    {['#','Client','Bank','Incident','Circle','Location','Address','ATMID','Full Address','DVRIP','Incident Time','EB Fail Date','EB Fail Time','UPS Avail Date','UPS Avail Time','UPS Fail Date','UPS Fail Time','UPS Restore Date','UPS Restore Time','EB Restore Date','EB Restore Time'].map(h => (
                                        <th key={h} className="px-2 py-2 text-left font-medium text-gray-500 whitespace-nowrap text-xs">{h}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {loading ? (
                                    <tr><td colSpan="21" className="px-4 py-6 text-center">
                                        <div className="flex justify-center items-center"><div className="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600"></div><span className="ml-2 text-gray-500">Loading...</span></div>
                                    </td></tr>
                                ) : data.length === 0 ? (
                                    <tr><td colSpan="21" className="px-4 py-6 text-center text-gray-500">No UPS reports found matching your criteria.</td></tr>
                                ) : (
                                    data.map((item, index) => (
                                        <tr key={item.id} className="hover:bg-gray-50">
                                            <td className="px-2 py-1.5 whitespace-nowrap text-gray-500 font-medium">{(pagination.from || 0) + index}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap font-medium">{item.Customer || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{item.Bank || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap"><span className="px-1 py-0.5 bg-gray-100 rounded">{item.id}</span></td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{item.site_zone || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{(item.City || '') + (item.State ? ', ' + item.State : '')}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{item.ATMShortName || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{item.ATMID || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap max-w-[150px] truncate" title={item.SiteAddress}>{item.SiteAddress || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap font-mono text-gray-600">{item.DVRIP || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-gray-500">{formatDate(item.createtime)}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-xs">{item.eb_fail_date || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-xs">{item.eb_fail_time || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-xs">{item.ups_available_date || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-xs">{item.ups_available_time || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-xs">{item.ups_fail_date || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-xs">{item.ups_fail_time || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-xs">{item.ups_restore_date || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-xs">{item.ups_restore_time || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-xs">{item.eb_restore_date || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-xs">{item.eb_restore_time || '-'}</td>
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
                                <PgBtn onClick={() => fetchData(1)} disabled={pagination.current_page === 1}><DblLeftIcon /></PgBtn>
                                <PgBtn onClick={() => fetchData(pagination.current_page - 1)} disabled={pagination.current_page === 1}><LeftIcon /></PgBtn>
                                {(() => {
                                    const pages = [];
                                    let start = Math.max(1, pagination.current_page - 2);
                                    let end = Math.min(pagination.last_page, pagination.current_page + 2);
                                    if (end - start < 4) { if (start === 1) end = Math.min(pagination.last_page, start + 4); else start = Math.max(1, end - 4); }
                                    if (start > 1) { pages.push(<PgBtn key={1} onClick={() => fetchData(1)}>1</PgBtn>); if (start > 2) pages.push(<span key="d1" className="px-1 text-gray-400">...</span>); }
                                    for (let i = start; i <= end; i++) pages.push(<PgBtn key={i} onClick={() => fetchData(i)} active={i === pagination.current_page}>{i}</PgBtn>);
                                    if (end < pagination.last_page) { if (end < pagination.last_page - 1) pages.push(<span key="d2" className="px-1 text-gray-400">...</span>); pages.push(<PgBtn key={pagination.last_page} onClick={() => fetchData(pagination.last_page)}>{pagination.last_page}</PgBtn>); }
                                    return pages;
                                })()}
                                <PgBtn onClick={() => fetchData(pagination.current_page + 1)} disabled={pagination.current_page === pagination.last_page}><RightIcon /></PgBtn>
                                <PgBtn onClick={() => fetchData(pagination.last_page)} disabled={pagination.current_page === pagination.last_page}><DblRightIcon /></PgBtn>
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
const FilterIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg>;
const SearchIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>;
const XIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" /></svg>;
const ListIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 10h16M4 14h16M4 18h16" /></svg>;
const DblLeftIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 19l-7-7 7-7m8 14l-7-7 7-7" /></svg>;
const LeftIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" /></svg>;
const RightIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>;
const DblRightIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 5l7 7-7 7M5 5l7 7-7 7" /></svg>;

export default UPSReportDashboard;
