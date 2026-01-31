import api from './api';

/**
 * Get down communication report with pagination and filters
 */
export const getDownCommunication = async (params = {}) => {
    const response = await api.get('/down-communication', { params });
    return response.data;
};

/**
 * Get filter options (customers, banks)
 */
export const getFilterOptions = async () => {
    const response = await api.get('/down-communication/filter-options');
    return response.data;
};

/**
 * Export down communication report to CSV
 */
export const exportCsv = async () => {
    const response = await api.get('/down-communication/export/csv', {
        responseType: 'blob'
    });
    return response.data;
};
