import api from './api';

export const analyticsService = {
    /**
     * Get all analytics data from PostgreSQL database
     */
    async getAnalytics() {
        try {
            const response = await api.get('/analytics');
            return response.data;
        } catch (error) {
            throw new Error(`Failed to fetch analytics: ${error.response?.data?.message || error.message}`);
        }
    },

    /**
     * Get analytics data with pagination
     */
    async getAnalyticsPaginated(page = 1, limit = 10) {
        try {
            const response = await api.get('/analytics', {
                params: { page, limit }
            });
            return response.data;
        } catch (error) {
            throw new Error(`Failed to fetch analytics: ${error.response?.data?.message || error.message}`);
        }
    },

    /**
     * Get analytics data by date range
     */
    async getAnalyticsByDateRange(startDate, endDate) {
        try {
            const response = await api.get('/analytics/date-range', {
                params: { start_date: startDate, end_date: endDate }
            });
            return response.data;
        } catch (error) {
            throw new Error(`Failed to fetch analytics by date range: ${error.response?.data?.message || error.message}`);
        }
    },

    /**
     * Create new analytics entry
     */
    async createAnalytics(analyticsData) {
        try {
            const response = await api.post('/analytics', analyticsData);
            return response.data;
        } catch (error) {
            throw new Error(`Failed to create analytics entry: ${error.response?.data?.message || error.message}`);
        }
    },

    /**
     * Get analytics summary/statistics
     */
    async getAnalyticsSummary() {
        try {
            const response = await api.get('/analytics/summary');
            return response.data;
        } catch (error) {
            throw new Error(`Failed to fetch analytics summary: ${error.response?.data?.message || error.message}`);
        }
    }
};