import api from './api';

/**
 * Configuration Service
 * 
 * Provides API methods for pipeline configuration management.
 * 
 * Requirements: 6.5
 */

/**
 * Get current pipeline configuration
 * @returns {Promise} Configuration data
 */
export const getConfiguration = async () => {
    const response = await api.get('/config/pipeline');
    return response.data;
};

/**
 * Update pipeline configuration
 * @param {Object} config - Configuration updates
 * @param {boolean} confirmCleanupEnable - Confirmation for enabling cleanup
 * @returns {Promise} Updated configuration
 */
export const updateConfiguration = async (config, confirmCleanupEnable = false) => {
    const payload = { ...config };
    if (confirmCleanupEnable) {
        payload.confirm_cleanup_enable = true;
    }
    const response = await api.put('/config/pipeline', payload);
    return response.data;
};

/**
 * Reset configuration to defaults
 * @param {string[]} keys - Optional specific keys to reset
 * @returns {Promise} Reset configuration
 */
export const resetConfiguration = async (keys = null) => {
    const payload = keys ? { keys } : {};
    const response = await api.post('/config/pipeline/reset', payload);
    return response.data;
};

/**
 * Get schedule configuration
 * @returns {Promise} Schedule configuration
 */
export const getSchedules = async () => {
    const response = await api.get('/config/pipeline/schedules');
    return response.data;
};

/**
 * Get alert threshold configuration
 * @returns {Promise} Alert configuration
 */
export const getAlertConfig = async () => {
    const response = await api.get('/config/pipeline/alerts');
    return response.data;
};

export default {
    getConfiguration,
    updateConfiguration,
    resetConfiguration,
    getSchedules,
    getAlertConfig,
};
