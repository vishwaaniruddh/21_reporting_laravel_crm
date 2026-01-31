import api from './api';

const API_BASE_URL = '/dashboard/executive';

/**
 * Executive Dashboard Service
 * Handles all API calls for executive dashboard data
 */
const executiveDashboardService = {
    /**
     * Get all executive dashboard metrics
     * @param {Object} params - Query parameters
     * @param {string} params.start_date - Start date (YYYY-MM-DD)
     * @param {string} params.end_date - End date (YYYY-MM-DD)
     * @param {boolean} params.refresh - Force refresh cache
     * @returns {Promise} Dashboard data
     */
    async getDashboardData(params = {}) {
        try {
            const response = await api.get(API_BASE_URL, { 
                params,
                timeout: 0 // No timeout for dashboard data loading
            });
            return response.data;
        } catch (error) {
            console.error('Failed to fetch executive dashboard data:', error);
            throw error;
        }
    },

    /**
     * Export dashboard to PDF
     * @param {Object} params - Export parameters
     * @returns {Promise} PDF blob
     */
    async exportToPDF(params = {}) {
        try {
            const response = await api.get(`${API_BASE_URL}/export/pdf`, {
                params,
                responseType: 'blob',
                timeout: 0 // No timeout for PDF export
            });
            return response.data;
        } catch (error) {
            console.error('Failed to export dashboard to PDF:', error);
            throw error;
        }
    },

    /**
     * Export dashboard to Excel
     * @param {Object} params - Export parameters
     * @returns {Promise} Excel blob
     */
    async exportToExcel(params = {}) {
        try {
            const response = await api.get(`${API_BASE_URL}/export/excel`, {
                params,
                responseType: 'blob',
                timeout: 0 // No timeout for Excel export
            });
            return response.data;
        } catch (error) {
            console.error('Failed to export dashboard to Excel:', error);
            throw error;
        }
    },
};

export default executiveDashboardService;
