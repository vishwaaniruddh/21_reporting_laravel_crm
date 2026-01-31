import api from './api';

const API_BASE = '/alerts-reports';

/**
 * Fetch paginated alerts with filters
 */
export const getAlerts = async (params = {}) => {
    const response = await api.get(API_BASE, { params });
    return response.data;
};

/**
 * Fetch available filter options (customers and panel types)
 */
export const getFilterOptions = async () => {
    const response = await api.get(`${API_BASE}/filter-options`);
    return response.data;
};

/**
 * Export alerts to CSV - triggers download via API
 */
export const exportCsv = async (params = {}) => {
    try {
        const response = await api.get(`${API_BASE}/export/csv`, {
            params,
            responseType: 'blob',
            timeout: 300000, // 5 minutes for large exports
        });
        
        // Check if response is actually an error (JSON) instead of CSV
        if (response.data.type === 'application/json') {
            const text = await response.data.text();
            const errorData = JSON.parse(text);
            throw new Error(errorData.error?.message || errorData.message || 'Export failed');
        }
        
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        
        const contentDisposition = response.headers['content-disposition'];
        let filename = `alerts_report_${params.from_date || new Date().toISOString().split('T')[0]}.csv`;
        
        if (contentDisposition) {
            const filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
            const matches = filenameRegex.exec(contentDisposition);
            if (matches != null && matches[1]) {
                filename = matches[1].replace(/['"]/g, '');
            }
        }
        
        link.setAttribute('download', filename);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
        
        return { success: true };
    } catch (error) {
        console.error('Export failed:', error);
        console.error('Error details:', {
            message: error.message,
            response: error.response?.data,
            status: error.response?.status,
            statusText: error.response?.statusText
        });
        throw error;
    }
};

/**
 * Check if pre-generated CSV report exists for a date
 */
export const checkCsvReport = async (date) => {
    const response = await api.get(`${API_BASE}/check-csv`, { params: { date } });
    return response.data;
};

/**
 * Check if Excel report exists for a date
 */
export const checkExcelReport = async (date, filters = {}) => {
    const params = { date, ...filters };
    const response = await api.get(`${API_BASE}/excel-check`, { params });
    return response.data;
};

/**
 * Get Excel report download URL
 */
export const getExcelDownloadUrl = (url) => {
    // URL is already complete from the API
    return url;
};
