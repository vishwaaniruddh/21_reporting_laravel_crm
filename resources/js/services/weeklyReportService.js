import api from './api';

const API_BASE = '/reports/weekly';

/**
 * Weekly Report Service
 * Handles API calls for weekly operational reports
 */
const weeklyReportService = {
    /**
     * Get weekly report data
     * @param {Object} params - { week_start, customer, zone }
     * @returns {Promise}
     */
    getWeeklyReport: async (params) => {
        try {
            const response = await api.get(API_BASE, { params });
            return response.data;
        } catch (error) {
            console.error('Failed to fetch weekly report:', error);
            throw error;
        }
    },

    /**
     * Get filter options for weekly reports
     * @returns {Promise}
     */
    getFilterOptions: async () => {
        try {
            // Reuse alerts-reports filter options
            const response = await api.get('/alerts-reports/filter-options');
            return response.data;
        } catch (error) {
            console.error('Failed to fetch filter options:', error);
            throw error;
        }
    },
};

export default weeklyReportService;
