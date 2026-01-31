import api from './api';

/**
 * Report Service
 * 
 * Provides API methods for report generation from PostgreSQL.
 * ⚠️ All reports query PostgreSQL ONLY - never MySQL alerts
 * 
 * Requirements: 5.2, 5.4
 */

/**
 * Get daily report
 * @param {string} date - Date in YYYY-MM-DD format (optional, defaults to today)
 * @returns {Promise} Daily report data
 */
export const getDailyReport = async (date = null) => {
    const params = date ? { date } : {};
    const response = await api.get('/reports/daily', { params });
    return response.data;
};

/**
 * Get summary report with filters
 * @param {Object} filters - Report filters
 * @param {string} filters.date_from - Start date
 * @param {string} filters.date_to - End date
 * @param {string} filters.alert_type - Alert type filter
 * @param {string} filters.priority - Priority filter
 * @param {string} filters.panel_id - Panel ID filter
 * @returns {Promise} Summary report data
 */
export const getSummaryReport = async (filters = {}) => {
    const response = await api.get('/reports/summary', { params: filters });
    return response.data;
};

/**
 * Get filtered alerts with pagination
 * @param {Object} params - Query parameters
 * @returns {Promise} Filtered alerts data
 */
export const getAlerts = async (params = {}) => {
    const response = await api.get('/reports/alerts', { params });
    return response.data;
};

/**
 * Get report statistics
 * @param {Object} filters - Statistics filters
 * @returns {Promise} Statistics data
 */
export const getStatistics = async (filters = {}) => {
    const response = await api.get('/reports/statistics', { params: filters });
    return response.data;
};

/**
 * Get available filter options
 * @returns {Promise} Filter options data
 */
export const getFilterOptions = async () => {
    const response = await api.get('/reports/filter-options');
    return response.data;
};

/**
 * Export report as CSV
 * @param {Object} filters - Export filters
 * @returns {Promise} CSV blob
 */
export const exportCsv = async (filters = {}) => {
    const response = await api.get('/reports/export/csv', {
        params: filters,
        responseType: 'blob',
    });
    return response.data;
};

/**
 * Export report as PDF
 * @param {Object} filters - Export filters
 * @returns {Promise} PDF blob
 */
export const exportPdf = async (filters = {}) => {
    const response = await api.get('/reports/export/pdf', {
        params: filters,
        responseType: 'blob',
    });
    return response.data;
};

/**
 * Download a blob as a file
 * @param {Blob} blob - The blob to download
 * @param {string} filename - The filename
 */
export const downloadBlob = (blob, filename) => {
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    link.remove();
    window.URL.revokeObjectURL(url);
};

export default {
    getDailyReport,
    getSummaryReport,
    getAlerts,
    getStatistics,
    getFilterOptions,
    exportCsv,
    exportPdf,
    downloadBlob,
};
