import api from './api';

const API_BASE = '/reports/monthly';

/**
 * Monthly Report Service
 * Handles API calls for monthly operational reports
 */
const monthlyReportService = {
    /**
     * Get monthly report data
     * @param {Object} params - { year, month, customer, zone }
     * @returns {Promise}
     */
    getMonthlyReport: async (params) => {
        try {
            const response = await api.get(API_BASE, { params });
            return response.data;
        } catch (error) {
            console.error('Failed to fetch monthly report:', error);
            throw error;
        }
    },

    /**
     * Get filter options for monthly reports
     * @returns {Promise}
     */
    getFilterOptions: async () => {
        try {
            const response = await api.get('/alerts-reports/filter-options');
            return response.data;
        } catch (error) {
            console.error('Failed to fetch filter options:', error);
            throw error;
        }
    },
};

export default monthlyReportService;
