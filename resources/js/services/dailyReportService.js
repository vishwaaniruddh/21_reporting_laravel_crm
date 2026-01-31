import api from './api';

const API_BASE = '/reports/daily';

/**
 * Daily Report Service
 * Handles API calls for daily operational reports
 */
const dailyReportService = {
    /**
     * Get daily report data
     * @param {Object} params - { date, customer, zone, panel_type }
     * @returns {Promise}
     */
    getDailyReport: async (params) => {
        try {
            const response = await api.get(API_BASE, { params });
            return response.data;
        } catch (error) {
            console.error('Failed to fetch daily report:', error);
            throw error;
        }
    },

    /**
     * Get filter options for daily reports
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

export default dailyReportService;
