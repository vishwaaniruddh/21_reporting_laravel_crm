import axios from 'axios';

/**
 * API Service with axios configuration
 * 
 * Features:
 * - Base URL configuration for port 9000
 * - Token interceptor for authentication
 * - Error handling interceptor
 * 
 * Requirements: 8.3
 */

// Create axios instance with base configuration
const api = axios.create({
    baseURL: '/api',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
    timeout: 30000, // 30 second timeout
});

/**
 * Request interceptor
 * - Adds authentication token from localStorage
 * - Adds CSRF token if available
 */
api.interceptors.request.use(
    (config) => {
        // Add authentication token if available
        const authToken = localStorage.getItem('auth_token');
        if (authToken) {
            config.headers['Authorization'] = `Bearer ${authToken}`;
        }
        
        // Add CSRF token if available
        const csrfToken = document.querySelector('meta[name="csrf-token"]');
        if (csrfToken) {
            config.headers['X-CSRF-TOKEN'] = csrfToken.getAttribute('content');
        }
        
        return config;
    },
    (error) => {
        console.error('API Request Error:', error);
        return Promise.reject(error);
    }
);

/**
 * Response interceptor
 * - Handles 401 Unauthorized by clearing auth state and redirecting to login
 * - Handles 403 Forbidden errors
 * - Handles server errors (5xx)
 */
api.interceptors.response.use(
    (response) => {
        return response;
    },
    (error) => {
        const status = error.response?.status;
        
        if (status === 401) {
            // Clear auth token on unauthorized response
            localStorage.removeItem('auth_token');
            delete api.defaults.headers.common['Authorization'];
            
            // Redirect to login if not already there
            if (window.location.pathname !== '/login') {
                window.location.href = '/login';
            }
        } else if (status === 403) {
            console.warn('Forbidden: You do not have permission to perform this action');
        } else if (status >= 500) {
            console.error('Server error occurred');
        }
        
        return Promise.reject(error);
    }
);

/**
 * Set the authentication token for API requests
 * @param {string|null} token - The auth token or null to clear
 */
export const setApiToken = (token) => {
    if (token) {
        api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    } else {
        delete api.defaults.headers.common['Authorization'];
    }
};

export default api;