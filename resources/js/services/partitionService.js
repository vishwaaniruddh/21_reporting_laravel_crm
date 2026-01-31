import api from './api';

/**
 * Partition Service
 * 
 * Provides API methods for partition management:
 * - Trigger partition sync
 * - List all partitions
 * - Get partition info
 * - Query across partitions
 * 
 * Requirements: 9.4
 */

/**
 * Trigger a date-partitioned sync job
 * @param {Object} options - Sync options
 * @param {number} options.batch_size - Batch size for sync
 * @param {number} options.start_from_id - Starting ID for sync
 * @returns {Promise} API response
 */
export const triggerPartitionedSync = async (options = {}) => {
    try {
        const response = await api.post('/sync/partitioned/trigger', options);
        return response.data;
    } catch (error) {
        console.error('Failed to trigger partitioned sync:', error);
        throw error;
    }
};

/**
 * List all partition tables with metadata
 * @param {Object} params - Query parameters
 * @param {number} params.per_page - Results per page
 * @param {string} params.date_from - Start date filter (YYYY-MM-DD)
 * @param {string} params.date_to - End date filter (YYYY-MM-DD)
 * @param {string} params.order_by - Sort field (partition_date, record_count, last_synced_at)
 * @param {string} params.order_direction - Sort direction (asc, desc)
 * @returns {Promise} API response with partitions list
 */
export const listPartitions = async (params = {}) => {
    try {
        const response = await api.get('/sync/partitions', { params });
        return response.data;
    } catch (error) {
        console.error('Failed to list partitions:', error);
        throw error;
    }
};

/**
 * Get detailed info for a specific partition by date
 * @param {string} date - Partition date (YYYY-MM-DD)
 * @returns {Promise} API response with partition details
 */
export const getPartitionInfo = async (date) => {
    try {
        const response = await api.get(`/sync/partitions/${date}`);
        return response.data;
    } catch (error) {
        console.error('Failed to get partition info:', error);
        throw error;
    }
};

/**
 * Query across date partitions with filters
 * @param {Object} params - Query parameters
 * @param {string} params.date_from - Start date (YYYY-MM-DD) - required
 * @param {string} params.date_to - End date (YYYY-MM-DD) - required
 * @param {string} params.alert_type - Filter by alert type
 * @param {string} params.severity - Filter by severity
 * @param {string} params.terminal_id - Filter by terminal ID
 * @param {string} params.status - Filter by status
 * @param {number} params.per_page - Results per page
 * @param {number} params.page - Page number
 * @param {string} params.order_by - Sort field
 * @param {string} params.order_direction - Sort direction
 * @returns {Promise} API response with query results
 */
export const queryPartitions = async (params) => {
    try {
        const response = await api.get('/reports/partitioned/query', { params });
        return response.data;
    } catch (error) {
        console.error('Failed to query partitions:', error);
        throw error;
    }
};
