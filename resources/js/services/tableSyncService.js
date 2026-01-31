import api from './api';

/**
 * Table Sync Service
 * 
 * Provides API methods for table sync configuration and operations.
 * ⚠️ NO DELETION FROM MYSQL: API calls never trigger MySQL deletion
 * 
 * Requirements: 8.1, 8.2, 8.3
 */

// ============================================================================
// Configuration CRUD Operations
// ============================================================================

/**
 * Get all table sync configurations
 * @param {Object} params - Query parameters
 * @param {boolean} params.enabled_only - Filter to enabled only
 * @param {boolean} params.with_stats - Include statistics
 * @returns {Promise} Configurations data
 */
export const getConfigurations = async (params = {}) => {
    const response = await api.get('/table-sync/configurations', { params });
    return response.data;
};

/**
 * Get a single configuration by ID
 * @param {number} id - Configuration ID
 * @returns {Promise} Configuration data
 */
export const getConfiguration = async (id) => {
    const response = await api.get(`/table-sync/configurations/${id}`);
    return response.data;
};

/**
 * Create a new table sync configuration
 * @param {Object} data - Configuration data
 * @returns {Promise} Created configuration
 */
export const createConfiguration = async (data) => {
    const response = await api.post('/table-sync/configurations', data);
    return response.data;
};

/**
 * Update an existing configuration
 * @param {number} id - Configuration ID
 * @param {Object} data - Updated configuration data
 * @returns {Promise} Updated configuration
 */
export const updateConfiguration = async (id, data) => {
    const response = await api.put(`/table-sync/configurations/${id}`, data);
    return response.data;
};

/**
 * Delete a configuration (from PostgreSQL only)
 * @param {number} id - Configuration ID
 * @returns {Promise} Delete result
 */
export const deleteConfiguration = async (id) => {
    const response = await api.delete(`/table-sync/configurations/${id}`);
    return response.data;
};

/**
 * Enable a configuration
 * @param {number} id - Configuration ID
 * @returns {Promise} Updated configuration
 */
export const enableConfiguration = async (id) => {
    const response = await api.post(`/table-sync/configurations/${id}/enable`);
    return response.data;
};


/**
 * Disable a configuration
 * @param {number} id - Configuration ID
 * @returns {Promise} Updated configuration
 */
export const disableConfiguration = async (id) => {
    const response = await api.post(`/table-sync/configurations/${id}/disable`);
    return response.data;
};

/**
 * Test a configuration without creating it
 * @param {Object} data - Configuration data to test
 * @returns {Promise} Test result with schema info
 */
export const testConfiguration = async (data) => {
    const response = await api.post('/table-sync/configurations/test', data);
    return response.data;
};

/**
 * Duplicate an existing configuration
 * @param {number} id - Configuration ID to duplicate
 * @param {string} name - New configuration name
 * @param {string} sourceTable - New source table name
 * @returns {Promise} Duplicated configuration
 */
export const duplicateConfiguration = async (id, name, sourceTable) => {
    const response = await api.post(`/table-sync/configurations/${id}/duplicate`, {
        name,
        source_table: sourceTable,
    });
    return response.data;
};

// ============================================================================
// Sync Operations
// ============================================================================

/**
 * Trigger sync for a specific table
 * ⚠️ NO DELETION FROM MYSQL: Reads from MySQL, writes to PostgreSQL
 * @param {number} id - Configuration ID
 * @returns {Promise} Sync result
 */
export const triggerSync = async (id) => {
    const response = await api.post(`/table-sync/sync/${id}`);
    return response.data;
};

/**
 * Trigger sync for all enabled tables
 * ⚠️ NO DELETION FROM MYSQL: Reads from MySQL, writes to PostgreSQL
 * @returns {Promise} Sync results for all tables
 */
export const triggerSyncAll = async () => {
    const response = await api.post('/table-sync/sync-all');
    return response.data;
};

/**
 * Get sync status for a specific table
 * @param {number} id - Configuration ID
 * @returns {Promise} Sync status data
 */
export const getSyncStatus = async (id) => {
    const response = await api.get(`/table-sync/status/${id}`);
    return response.data;
};

/**
 * Resume a paused sync
 * @param {number} id - Configuration ID
 * @returns {Promise} Resume result
 */
export const resumeSync = async (id) => {
    const response = await api.post(`/table-sync/${id}/resume`);
    return response.data;
};

/**
 * Force unlock a stuck sync
 * @param {number} id - Configuration ID
 * @returns {Promise} Unlock result
 */
export const forceUnlock = async (id) => {
    const response = await api.post(`/table-sync/${id}/force-unlock`);
    return response.data;
};

/**
 * Force unlock all stuck syncs
 * @returns {Promise} Unlock results
 */
export const forceUnlockAll = async () => {
    const response = await api.post('/table-sync/force-unlock-all');
    return response.data;
};

/**
 * Get overview of all table syncs
 * @returns {Promise} Overview data with all table statuses
 */
export const getOverview = async () => {
    const response = await api.get('/table-sync/overview');
    return response.data;
};

// ============================================================================
// Logs Operations
// ============================================================================

/**
 * Get sync logs with filtering and pagination
 * @param {Object} params - Query parameters
 * @param {number} params.configuration_id - Filter by configuration
 * @param {string} params.source_table - Filter by source table
 * @param {string} params.status - Filter by status (running, completed, failed, partial)
 * @param {string} params.date_from - Start date filter
 * @param {string} params.date_to - End date filter
 * @param {number} params.per_page - Items per page
 * @returns {Promise} Logs data with pagination
 */
export const getLogs = async (params = {}) => {
    const response = await api.get('/table-sync/logs', { params });
    return response.data;
};


// ============================================================================
// Error Queue Operations
// ============================================================================

/**
 * Get error queue with filtering and pagination
 * @param {Object} params - Query parameters
 * @param {number} params.configuration_id - Filter by configuration
 * @param {string} params.source_table - Filter by source table
 * @param {boolean} params.resolved - Filter by resolved status
 * @param {boolean} params.retryable - Filter by retryable status
 * @param {boolean} params.exceeded_max_retries - Filter by exceeded max retries
 * @param {number} params.per_page - Items per page
 * @returns {Promise} Errors data with pagination
 */
export const getErrors = async (params = {}) => {
    const response = await api.get('/table-sync/errors', { params });
    return response.data;
};

/**
 * Retry a specific error
 * ⚠️ NO DELETION FROM MYSQL: Retry re-reads from MySQL, writes to PostgreSQL
 * @param {number} id - Error ID
 * @returns {Promise} Retry result
 */
export const retryError = async (id) => {
    const response = await api.post(`/table-sync/errors/${id}/retry`);
    return response.data;
};

/**
 * Mark an error as resolved without retrying
 * @param {number} id - Error ID
 * @returns {Promise} Resolve result
 */
export const resolveError = async (id) => {
    const response = await api.post(`/table-sync/errors/${id}/resolve`);
    return response.data;
};

/**
 * Retry all eligible errors
 * @param {number} configurationId - Optional configuration ID to filter
 * @returns {Promise} Retry results
 */
export const retryAllErrors = async (configurationId = null) => {
    const data = configurationId ? { configuration_id: configurationId } : {};
    const response = await api.post('/table-sync/errors/retry-all', data);
    return response.data;
};

// ============================================================================
// Default Export
// ============================================================================

export default {
    // Configuration CRUD
    getConfigurations,
    getConfiguration,
    createConfiguration,
    updateConfiguration,
    deleteConfiguration,
    enableConfiguration,
    disableConfiguration,
    testConfiguration,
    duplicateConfiguration,
    // Sync Operations
    triggerSync,
    triggerSyncAll,
    getSyncStatus,
    resumeSync,
    forceUnlock,
    forceUnlockAll,
    getOverview,
    // Logs
    getLogs,
    // Error Queue
    getErrors,
    retryError,
    resolveError,
    retryAllErrors,
};
