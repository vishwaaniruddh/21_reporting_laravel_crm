import { useState, useEffect, useCallback } from 'react';
import ReactPaginate from 'react-paginate';
import { getDownCommunication, getFilterOptions, exportCsv } from '../services/downCommunicationService';
import DashboardLayout from './DashboardLayout';

const DownCommunicationDashboard = () => {
    const [records, setRecords] = useState([]);
    const [pagination, setPagination] = useState(null);
    const [summary, setSummary] = useState(null);
    const [loading, setLoading] = useState(false);
    const [exporting, setExporting] = useState(false);
    const [error, setError] = useState(null);

    const [filters, setFilters] = useState({
        customer: '',
        bank: '',
        atmid: '',
        city: '',
        state: '',
        page: 1,
        per_page: 25,
    });

    const [filterOptions, setFilterOptions] = useState({
        customers: [],
        banks: [],
    });

    // Fetch filter options
    useEffect(() => {
        const fetchOptions = async () => {
            try {
                const response = await getFilterOptions();
                if (response.success) {
                    setFilterOptions(response.data);
                }
            } catch (err) {
                console.error('Failed to fetch filter options:', err);
            }
        };
        fetchOptions();
    }, []);

    // Fetch data
    const fetchData = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const params = { ...filters };
            Object.keys(params).forEach(key => {
                if (params[key] === '' || params[key] === null) delete params[key];
            });
            const response = await getDownCommunication(params);
            if (response.success) {
                setRecords(response.data.records);
                setPagination(response.data.pagination);
                setSummary(response.data.summary);
            }
        } catch (err) {
            setError(err.response?.data?.error?.message || 'Failed to fetch data');
        } finally {
            setLoading(false);
        }
    }, [filters]);

    useEffect(() => {
        fetchData();
    }, [fetchData]);

    const handleFilterChange = (key, value) => {
        setFilters(prev => ({ ...prev, [key]: value, page: 1 }));
    };

    const handlePageChange = (newPage) => {
        setFilters(prev => ({ ...prev, page: newPage }));
    };

    const handlePageClick = (event) => {
        handlePageChange(event.selected + 1); // react-paginate uses 0-based index
    };

    const handleExport = async () => {
        setExporting(true);
        try {
            const blob = await exportCsv();
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `down_communication_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);
        } catch (err) {
            setError('Failed to export CSV');
        } finally {
            setExporting(false);
        }
    };

    const clearFilters = () => {
        setFilters({
            customer: '',
            bank: '',
            atmid: '',
            city: '',
            state: '',
            page: 1,
            per_page: 25,
        });
    };

    return (
        <DashboardLayout>
            <div className="space-y-4">
                {/* Header */}
                <div className="flex justify-between items-center">
                    <h2 className="text-xl font-semibold text-gray-900">Down Communication Report</h2>
                    <button
                        onClick={handleExport}
                        disabled={exporting}
                        className="px-3 py-1.5 bg-green-600 text-white rounded text-xs hover:bg-green-700 disabled:opacity-50"
                    >
                        {exporting ? 'Exporting...' : 'Export CSV'}
                    </button>
                </div>

                {/* Summary Cards */}
                {summary && (
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div className="bg-blue-50 p-4 rounded-lg">
                            <p className="text-sm text-blue-600 font-medium">Total ATMs</p>
                            <p className="text-2xl font-bold text-blue-900">{summary.total_count}</p>
                        </div>
                        <div className="bg-green-50 p-4 rounded-lg">
                            <p className="text-sm text-green-600 font-medium">Working ATMs</p>
                            <p className="text-2xl font-bold text-green-900">{summary.working_count}</p>
                        </div>
                        <div className="bg-red-50 p-4 rounded-lg">
                            <p className="text-sm text-red-600 font-medium">Not Working ATMs</p>
                            <p className="text-2xl font-bold text-red-900">{summary.not_working_count}</p>
                        </div>
                    </div>
                )}

                {/* Filters */}
                <div className="bg-white p-4 rounded-lg shadow">
                    <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-3">
                        <select
                            value={filters.customer}
                            onChange={(e) => handleFilterChange('customer', e.target.value)}
                            className="px-3 py-1.5 border rounded text-xs"
                        >
                            <option value="">All Customers</option>
                            {filterOptions.customers.map(c => (
                                <option key={c} value={c}>{c}</option>
                            ))}
                        </select>

                        <select
                            value={filters.bank}
                            onChange={(e) => handleFilterChange('bank', e.target.value)}
                            className="px-3 py-1.5 border rounded text-xs"
                        >
                            <option value="">All Banks</option>
                            {filterOptions.banks.map(b => (
                                <option key={b} value={b}>{b}</option>
                            ))}
                        </select>

                        <input
                            type="text"
                            placeholder="ATM ID"
                            value={filters.atmid}
                            onChange={(e) => handleFilterChange('atmid', e.target.value)}
                            className="px-3 py-1.5 border rounded text-xs"
                        />

                        <input
                            type="text"
                            placeholder="City"
                            value={filters.city}
                            onChange={(e) => handleFilterChange('city', e.target.value)}
                            className="px-3 py-1.5 border rounded text-xs"
                        />

                        <input
                            type="text"
                            placeholder="State"
                            value={filters.state}
                            onChange={(e) => handleFilterChange('state', e.target.value)}
                            className="px-3 py-1.5 border rounded text-xs"
                        />
                    </div>
                    <div className="mt-3 flex gap-2">
                        <button
                            onClick={clearFilters}
                            className="px-3 py-1.5 bg-gray-200 text-gray-700 rounded text-xs hover:bg-gray-300"
                        >
                            Clear Filters
                        </button>
                    </div>
                </div>

                {/* Error */}
                {error && (
                    <div className="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded text-sm">
                        {error}
                    </div>
                )}

                {/* Loading Overlay */}
                {loading && (
                    <div className="bg-white rounded-lg shadow p-8 flex flex-col items-center justify-center">
                        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-4"></div>
                        <p className="text-gray-600 text-sm">Loading down communication data...</p>
                    </div>
                )}

                {/* Table */}
                {!loading && (
                <div className="bg-white rounded-lg shadow overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">#</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Customer</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Bank</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">ATM ID</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">ATM Name</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">City</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">State</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Panel Make</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Old Panel ID</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">New Panel ID</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">DVR IP</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">DVR Name</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Last Comm</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">BM Name</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">BM Number</th>
                                    <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Zone</th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {records.length === 0 ? (
                                    <tr>
                                        <td colSpan="16" className="px-3 py-4 text-center text-sm text-gray-500">
                                            No records found
                                        </td>
                                    </tr>
                                ) : (
                                    records.map((record, index) => (
                                        <tr key={index} className="hover:bg-gray-50">
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{pagination.from + index}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.Customer}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.Bank}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.ATMID}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.ATMShortName}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.City}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.State}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.Panel_Make}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.OldPanelID}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.NewPanelID}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.DVRIP}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.DVRName}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.dc_date}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.CSSBM}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.CSSBMNumber}</td>
                                            <td className="px-3 py-2 text-xs text-gray-900 whitespace-nowrap">{record.Zone}</td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {pagination && pagination.last_page > 1 && (
                        <div className="bg-gray-50 px-4 py-3 flex items-center justify-between border-t">
                            <div className="text-xs text-gray-700">
                                Showing {pagination.from} to {pagination.to} of {pagination.total} results
                            </div>
                            <ReactPaginate
                                breakLabel="..."
                                nextLabel="Next ›"
                                previousLabel="‹ Prev"
                                onPageChange={handlePageClick}
                                pageRangeDisplayed={3}
                                marginPagesDisplayed={2}
                                pageCount={pagination.last_page}
                                forcePage={pagination.current_page - 1}
                                renderOnZeroPageCount={null}
                                containerClassName="flex items-center gap-1"
                                pageClassName="page-item"
                                pageLinkClassName="px-3 py-1 text-xs border rounded hover:bg-gray-100 transition-colors"
                                previousClassName="page-item"
                                previousLinkClassName="px-3 py-1 text-xs border rounded hover:bg-gray-100 transition-colors"
                                nextClassName="page-item"
                                nextLinkClassName="px-3 py-1 text-xs border rounded hover:bg-gray-100 transition-colors"
                                breakClassName="page-item"
                                breakLinkClassName="px-3 py-1 text-xs"
                                activeClassName="active_page"
                                activeLinkClassName="bg-blue-600 text-white border-blue-600 hover:bg-blue-700"
                                disabledClassName="opacity-50 cursor-not-allowed"
                            />
                        </div>
                    )}
                </div>
                )}
            </div>
        </DashboardLayout>
    );
};

export default DownCommunicationDashboard;
