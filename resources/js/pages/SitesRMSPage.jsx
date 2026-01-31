import { useState, useEffect, useCallback } from 'react';
import { getRMSSites, getRMSFilterOptions, exportRMSCsv } from '../services/rmsSitesService';
import DashboardLayout from '../components/DashboardLayout';

const SitesRMSPage = () => {
    const [sites, setSites] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [filterOptions, setFilterOptions] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [exporting, setExporting] = useState(false);
    
    const [filters, setFilters] = useState({
        atmid: '',
        customer: '',
        bank: '',
        panel_make: '',
        dvrname: '',
        dvrip: '',
        live: '',
        per_page: 25,
    });

    const fetchSites = useCallback(async (page = 1) => {
        setLoading(true);
        setError(null);
        try {
            const params = { ...filters, page };
            Object.keys(params).forEach(key => {
                if (params[key] === '' || params[key] === null) delete params[key];
            });
            const response = await getRMSSites(params);
            if (response.success) {
                setSites(response.data.sites);
                setPagination(response.data.pagination);
            } else {
                setError(response.error?.message || 'Failed to fetch sites');
            }
        } catch (err) {
            setError(err.response?.data?.error?.message || err.message || 'Failed to connect to server');
        } finally {
            setLoading(false);
        }
    }, [filters]);

    const fetchFilterOptions = useCallback(async () => {
        try {
            const response = await getRMSFilterOptions();
            if (response.success) setFilterOptions(response.data);
        } catch (err) {
            console.error('Failed to fetch filter options:', err);
        }
    }, []);

    useEffect(() => { fetchFilterOptions(); }, [fetchFilterOptions]);
    useEffect(() => { fetchSites(1); }, [fetchSites]);

    const handleFilterChange = (key, value) => setFilters(prev => ({ ...prev, [key]: value }));
    const handleSearch = (e) => { e.preventDefault(); fetchSites(1); };
    const handleClearFilters = () => {
        setFilters({ atmid: '', customer: '', bank: '', panel_make: '', dvrname: '', dvrip: '', live: '', per_page: 25 });
    };
    const handleExport = async () => {
        setExporting(true);
        try {
            const params = { ...filters };
            Object.keys(params).forEach(key => {
                if (params[key] === '' || params[key] === null || key === 'per_page') delete params[key];
            });
            await exportRMSCsv(params);
        } catch (err) {
            console.error('Export failed:', err);
            setError('Failed to export CSV. Please try again.');
        } finally {
            setExporting(false);
        }
    };

    return (
        <DashboardLayout>
            <div className="space-y-3">
                {/* Header */}
                <div className="bg-white rounded-lg shadow p-4">
                    <h1 className="text-xl font-bold text-gray-900">RMS Sites</h1>
                    <p className="text-sm text-gray-600">Remote Monitoring System sites management</p>
                </div>

                {/* Filter Section */}
                <div className="bg-white rounded-lg shadow">
                    <div className="px-4 py-2 border-b border-gray-200">
                        <h2 className="text-sm font-medium text-gray-900 flex items-center">
                            <FilterIcon className="w-4 h-4 mr-2" />Filter Sites
                        </h2>
                    </div>
                    <div className="p-3">
                        <form onSubmit={handleSearch} className="grid grid-cols-2 md:grid-cols-4 gap-2">
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">ATM ID</label>
                                <input type="text" value={filters.atmid} onChange={(e) => handleFilterChange('atmid', e.target.value)}
                                    placeholder="Enter ATM ID" className="w-full px-2 py-1 border border-gray-300 rounded text-sm" />
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
                                <label className="block text-xs text-gray-600 mb-1">Bank</label>
                                <select value={filters.bank} onChange={(e) => handleFilterChange('bank', e.target.value)}
                                    className="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                    <option value="">All Banks</option>
                                    {filterOptions?.banks?.map(b => <option key={b} value={b}>{b}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">Panel Make</label>
                                <select value={filters.panel_make} onChange={(e) => handleFilterChange('panel_make', e.target.value)}
                                    className="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                    <option value="">All Panel Makes</option>
                                    {filterOptions?.panel_makes?.map(p => <option key={p} value={p}>{p}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">DVR Name</label>
                                <select value={filters.dvrname} onChange={(e) => handleFilterChange('dvrname', e.target.value)}
                                    className="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                    <option value="">All DVR Names</option>
                                    {filterOptions?.dvr_names?.map(d => <option key={d} value={d}>{d}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">DVR IP</label>
                                <input type="text" value={filters.dvrip} onChange={(e) => handleFilterChange('dvrip', e.target.value)}
                                    placeholder="Enter DVR IP" className="w-full px-2 py-1 border border-gray-300 rounded text-sm" />
                            </div>
                            <div>
                                <label className="block text-xs text-gray-600 mb-1">Live Status</label>
                                <select value={filters.live} onChange={(e) => handleFilterChange('live', e.target.value)}
                                    className="w-full px-2 py-1 border border-gray-300 rounded text-sm">
                                    <option value="">All Status</option>
                                    {filterOptions?.live_statuses?.map(l => <option key={l} value={l}>{l}</option>)}
                                </select>
                            </div>
                            <div className="flex items-end">
                                <button type="submit" className="px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 flex items-center mr-2">
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
                                <ListIcon className="w-4 h-4 mr-2" />RMS Sites
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
                            
                            <button 
                                onClick={handleExport} 
                                disabled={exporting}
                                className="px-3 py-1.5 bg-green-600 text-white rounded text-xs hover:bg-green-700 flex items-center gap-1.5 transition-colors disabled:opacity-50"
                            >
                                {exporting ? (
                                    <><SpinnerIcon className="w-4 h-4 animate-spin" /><span>Exporting...</span></>
                                ) : (
                                    <><DownloadIcon className="w-4 h-4" /><span>Export CSV</span></>
                                )}
                            </button>
                        </div>
                    </div>

                    {error && <div className="p-3 bg-red-50 border-b border-red-200 text-red-700 text-sm">{error}</div>}

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200 text-xs">
                            <thead className="bg-gray-50">
                                <tr>
                                    {['SN','Customer','Bank','ATM ID','Address','City','State','Zone','Panel ID (New)','Panel ID (Old)','Panel Make','DVR Name','DVR IP','Port','Live','Installation Date'].map(h => (
                                        <th key={h} className="px-2 py-2 text-left font-medium text-gray-500 whitespace-nowrap">{h}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {loading ? (
                                    <tr><td colSpan="16" className="px-4 py-6 text-center">
                                        <div className="flex justify-center items-center"><div className="animate-spin rounded-full h-5 w-5 border-b-2 border-blue-600"></div><span className="ml-2 text-gray-500">Loading...</span></div>
                                    </td></tr>
                                ) : sites.length === 0 ? (
                                    <tr><td colSpan="16" className="px-4 py-6 text-center text-gray-500">No sites found matching your criteria.</td></tr>
                                ) : (
                                    sites.map((site) => (
                                        <tr key={site.SN} className="hover:bg-gray-50">
                                            <td className="px-2 py-1.5 whitespace-nowrap text-gray-500 font-medium">{site.SN}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap font-medium">{site.Customer || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{site.Bank || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap"><span className="px-1 py-0.5 bg-blue-100 text-blue-800 rounded">{site.ATMID || '-'}</span></td>
                                            <td className="px-2 py-1.5 whitespace-nowrap max-w-[200px] truncate" title={site.SiteAddress}>{site.SiteAddress || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{site.City || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{site.State || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap"><span className="px-1 py-0.5 bg-cyan-100 text-cyan-800 rounded">{site.Zone || '-'}</span></td>
                                            <td className="px-2 py-1.5 whitespace-nowrap font-mono text-gray-600">{site.NewPanelID || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap font-mono text-gray-600">{site.OldPanelID || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{site.Panel_Make || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{site.DVRName || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap font-mono text-gray-600">{site.DVRIP || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">{site.Port || '-'}</td>
                                            <td className="px-2 py-1.5 whitespace-nowrap">
                                                <span className={`px-1 py-0.5 rounded text-xs ${site.live === 'Y' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
                                                    {site.live || '-'}
                                                </span>
                                            </td>
                                            <td className="px-2 py-1.5 whitespace-nowrap text-gray-500">{site.installationDate || '-'}</td>
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
                                <PgBtn onClick={() => fetchSites(1)} disabled={pagination.current_page === 1}><DblLeftIcon /></PgBtn>
                                <PgBtn onClick={() => fetchSites(pagination.current_page - 1)} disabled={pagination.current_page === 1}><LeftIcon /></PgBtn>
                                {(() => {
                                    const pages = [];
                                    let start = Math.max(1, pagination.current_page - 2);
                                    let end = Math.min(pagination.last_page, pagination.current_page + 2);
                                    if (end - start < 4) { if (start === 1) end = Math.min(pagination.last_page, start + 4); else start = Math.max(1, end - 4); }
                                    if (start > 1) { pages.push(<PgBtn key={1} onClick={() => fetchSites(1)}>1</PgBtn>); if (start > 2) pages.push(<span key="d1" className="px-1 text-gray-400">...</span>); }
                                    for (let i = start; i <= end; i++) pages.push(<PgBtn key={i} onClick={() => fetchSites(i)} active={i === pagination.current_page}>{i}</PgBtn>);
                                    if (end < pagination.last_page) { if (end < pagination.last_page - 1) pages.push(<span key="d2" className="px-1 text-gray-400">...</span>); pages.push(<PgBtn key={pagination.last_page} onClick={() => fetchSites(pagination.last_page)}>{pagination.last_page}</PgBtn>); }
                                    return pages;
                                })()}
                                <PgBtn onClick={() => fetchSites(pagination.current_page + 1)} disabled={pagination.current_page === pagination.last_page}><RightIcon /></PgBtn>
                                <PgBtn onClick={() => fetchSites(pagination.last_page)} disabled={pagination.current_page === pagination.last_page}><DblRightIcon /></PgBtn>
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
const SpinnerIcon = ({ className }) => <svg className={className} fill="none" viewBox="0 0 24 24"><circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle><path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>;
const FilterIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" /></svg>;
const SearchIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>;
const XIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" /></svg>;
const ListIcon = ({ className }) => <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 10h16M4 14h16M4 18h16" /></svg>;
const DblLeftIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 19l-7-7 7-7m8 14l-7-7 7-7" /></svg>;
const LeftIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" /></svg>;
const RightIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" /></svg>;
const DblRightIcon = () => <svg className="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 5l7 7-7 7M5 5l7 7-7 7" /></svg>;

export default SitesRMSPage;
