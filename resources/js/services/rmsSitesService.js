import api from './api';

/**
 * Get paginated RMS sites with filters
 */
export const getRMSSites = async (params = {}) => {
    const response = await api.get('/rms-sites', { params });
    return response.data;
};

/**
 * Get filter options for dropdowns
 */
export const getRMSFilterOptions = async () => {
    const response = await api.get('/rms-sites/filter-options');
    return response.data;
};

/**
 * Export RMS sites to CSV - triggers download via API
 */
export const exportRMSCsv = async (params = {}) => {
    try {
        const response = await api.get('/rms-sites/export/csv', {
            params,
            responseType: 'blob',
        });
        
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        
        const contentDisposition = response.headers['content-disposition'];
        let filename = `rms_sites_${new Date().toISOString().split('T')[0]}.csv`;
        
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
        throw error;
    }
};
