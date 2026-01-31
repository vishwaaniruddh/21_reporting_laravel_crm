import api from './api';

/**
 * Get paginated DVR sites with filters
 */
export const getDVRSites = async (params = {}) => {
    const response = await api.get('/dvr-sites', { params });
    return response.data;
};

/**
 * Get filter options for dropdowns
 */
export const getDVRFilterOptions = async () => {
    const response = await api.get('/dvr-sites/filter-options');
    return response.data;
};

/**
 * Export DVR sites to CSV - triggers download via API
 */
export const exportDVRCsv = async (params = {}) => {
    try {
        const response = await api.get('/dvr-sites/export/csv', {
            params,
            responseType: 'blob',
        });
        
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        
        const contentDisposition = response.headers['content-disposition'];
        let filename = `dvr_sites_${new Date().toISOString().split('T')[0]}.csv`;
        
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
