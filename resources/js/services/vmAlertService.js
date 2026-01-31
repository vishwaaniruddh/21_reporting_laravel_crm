import api from './api';
import axios from 'axios';

// Create a separate API instance for long-running operations like exports
const exportApi = axios.create({
    baseURL: '/api',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    timeout: 0, // No timeout for exports
});

// Add the same interceptors as the main API
exportApi.interceptors.request.use(
    (config) => {
        const authToken = localStorage.getItem('auth_token');
        if (authToken) {
            config.headers['Authorization'] = `Bearer ${authToken}`;
        }
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]');
        if (csrfToken) {
            config.headers['X-CSRF-TOKEN'] = csrfToken.getAttribute('content');
        }
        
        return config;
    },
    (error) => Promise.reject(error)
);

exportApi.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error.response?.status;
        
        if (status === 401) {
            localStorage.removeItem('auth_token');
            delete exportApi.defaults.headers.common['Authorization'];
            
            if (window.location.pathname !== '/login') {
                window.location.href = '/login';
            }
        }
        
        return Promise.reject(error);
    }
);

const API_BASE = '/vm-alerts';

/**
 * Fetch paginated VM alerts with filters
 */
export const getVMAlerts = async (params = {}) => {
    const response = await api.get(API_BASE, { params });
    return response.data;
};

/**
 * Fetch available filter options (customers and panel types)
 */
export const getVMFilterOptions = async () => {
    const response = await api.get(`${API_BASE}/filter-options`);
    return response.data;
};

/**
 * Export VM alerts to CSV - triggers download via API
 */
export const exportVMCsv = async (params = {}) => {
    try {
        const response = await exportApi.get(`${API_BASE}/export/csv`, {
            params,
            responseType: 'blob', // Important for file download
        });
        
        // Create blob link to download
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        
        // Get filename from response headers or use default
        const contentDisposition = response.headers['content-disposition'];
        let filename = `vm_alerts_report_${params.from_date || 'export'}.csv`;
        
        if (contentDisposition) {
            // Try to extract filename from content-disposition header
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
        throw error;
    }
};

/**
 * Check if pre-generated CSV report exists for a date
 */
export const checkVMCsvReport = async (date) => {
    const response = await api.get(`${API_BASE}/check-csv`, { params: { date } });
    return response.data;
};

/**
 * Check if Excel report exists for a date
 */
export const checkVMExcelReport = async (date, filters = {}) => {
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
