import api from './api';

export const databaseService = {
    /**
     * Check the status of both database connections
     */
    async getDatabaseStatus() {
        try {
            const response = await api.get('/database/status');
            return response.data;
        } catch (error) {
            throw new Error(`Failed to fetch database status: ${error.response?.data?.message || error.message}`);
        }
    },

    /**
     * Get detailed health check for all databases
     */
    async getHealthCheck() {
        try {
            const response = await api.get('/database/health');
            return response.data;
        } catch (error) {
            // Return a structured error response for health check failures
            return {
                healthy: false,
                timestamp: new Date().toISOString(),
                databases: {
                    mysql: { healthy: false, status: 'error', message: 'Health check failed' },
                    postgresql: { healthy: false, status: 'error', message: 'Health check failed' }
                },
                error: error.response?.data?.message || error.message
            };
        }
    },

    /**
     * Check MySQL database connection specifically
     */
    async getMySQLStatus() {
        try {
            const response = await api.get('/database/status/mysql');
            return response.data;
        } catch (error) {
            throw new Error(`Failed to fetch MySQL status: ${error.response?.data?.message || error.message}`);
        }
    },

    /**
     * Check PostgreSQL database connection specifically
     */
    async getPostgreSQLStatus() {
        try {
            const response = await api.get('/database/status/postgresql');
            return response.data;
        } catch (error) {
            throw new Error(`Failed to fetch PostgreSQL status: ${error.response?.data?.message || error.message}`);
        }
    },

    /**
     * Test database connections
     */
    async testConnections() {
        try {
            const response = await api.post('/database/test-connections');
            return response.data;
        } catch (error) {
            throw new Error(`Failed to test database connections: ${error.response?.data?.message || error.message}`);
        }
    }
};