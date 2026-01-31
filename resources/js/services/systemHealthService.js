import api from './api';

const API_BASE = '/system';

/**
 * System Health Service
 * Handles API calls for system health monitoring
 */
const systemHealthService = {
    /**
     * Get comprehensive system health metrics
     * @returns {Promise}
     */
    getSystemHealth: async () => {
        try {
            const response = await api.get(`${API_BASE}/health`);
            return response.data;
        } catch (error) {
            console.error('Failed to fetch system health:', error);
            throw error;
        }
    },

    /**
     * Read a specific log file
     * @param {string} filename - Log file name
     * @param {number} lines - Number of lines to read (default 500)
     * @returns {Promise}
     */
    readLog: async (filename, lines = 500) => {
        try {
            const response = await api.get(`${API_BASE}/logs/${filename}`, {
                params: { lines }
            });
            return response.data;
        } catch (error) {
            console.error('Failed to read log file:', error);
            throw error;
        }
    },

    /**
     * Clear a specific log file
     * @param {string} filename - Log file name
     * @returns {Promise}
     */
    clearLog: async (filename) => {
        try {
            const response = await api.delete(`${API_BASE}/logs/${filename}`);
            return response.data;
        } catch (error) {
            console.error('Failed to clear log file:', error);
            throw error;
        }
    },

    /**
     * Get paginated API requests for a specific user
     * @param {number} userId - User ID
     * @param {number} page - Page number (default 1)
     * @param {number} perPage - Items per page (default 50)
     * @returns {Promise}
     */
    getUserRequests: async (userId, page = 1, perPage = 50) => {
        try {
            const response = await api.get(`${API_BASE}/health/user-requests/${userId}`, {
                params: { page, per_page: perPage }
            });
            return response.data;
        } catch (error) {
            console.error('Failed to fetch user requests:', error);
            throw error;
        }
    },
};

export default systemHealthService;
