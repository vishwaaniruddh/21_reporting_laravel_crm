import api from './api';

/**
 * Pipeline Service
 * 
 * Provides API methods for pipeline monitoring and control.
 * 
 * Requirements: 2.2, 2.3
 */

/**
 * Get current pipeline status
 * @returns {Promise} Pipeline status data
 */
export const getPipelineStatus = async () => {
    const response = await api.get('/pipeline/status');
    return response.data;
};

/**
 * Get sync logs with pagination and filters
 * @param {Object} params - Query parameters
 * @param {number} params.per_page - Items per page
 * @param {string} params.operation - Filter by operation type
 * @param {string} params.status - Filter by status
 * @param {string} params.date_from - Start date filter
 * @param {string} params.date_to - End date filter
 * @returns {Promise} Sync logs data
 */
export const getSyncLogs = async (params = {}) => {
    const response = await api.get('/pipeline/sync-logs', { params });
    return response.data;
};

/**
 * Trigger a manual sync job
 * @param {Object} options - Sync options
 * @param {number} options.batch_size - Optional batch size override
 * @param {number} options.start_from_id - Optional starting ID
 * @returns {Promise} Trigger response
 */
export const triggerSync = async (options = {}) => {
    const response = await api.post('/pipeline/sync/trigger', options);
    return response.data;
};

/**
 * Get cleanup preview
 * @returns {Promise} Cleanup preview data
 */
export const getCleanupPreview = async () => {
    const response = await api.get('/pipeline/cleanup/preview');
    return response.data;
};

/**
 * Trigger a manual cleanup job
 * @param {Object} options - Cleanup options
 * @param {boolean} options.confirm - Confirmation flag (required)
 * @param {number} options.retention_days - Optional retention days override
 * @param {number[]} options.batch_ids - Optional specific batch IDs to clean
 * @returns {Promise} Trigger response
 */
export const triggerCleanup = async (options = {}) => {
    const response = await api.post('/pipeline/cleanup/trigger', options);
    return response.data;
};

export default {
    getPipelineStatus,
    getSyncLogs,
    triggerSync,
    getCleanupPreview,
    triggerCleanup,
};
