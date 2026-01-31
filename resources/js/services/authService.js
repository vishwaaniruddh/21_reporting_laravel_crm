import api, { setApiToken } from './api';

/**
 * Authentication service for API calls
 * Provides login, logout, and getCurrentUser methods
 * 
 * Requirements: 1.1, 1.3
 */
const authService = {
    /**
     * Login with email and password
     * @param {string} email - User email
     * @param {string} password - User password
     * @returns {Promise<Object>} Login response with user, token, and permissions
     */
    login: async (email, password) => {
        const response = await api.post('/auth/login', { email, password });
        
        // Store token and set it for future requests
        if (response.data.token) {
            localStorage.setItem('auth_token', response.data.token);
            setApiToken(response.data.token);
        }
        
        return response.data;
    },

    /**
     * Logout current user
     * @returns {Promise<Object>} Logout response
     */
    logout: async () => {
        try {
            const response = await api.post('/auth/logout');
            return response.data;
        } finally {
            // Always clear token on logout attempt
            localStorage.removeItem('auth_token');
            setApiToken(null);
        }
    },

    /**
     * Get current authenticated user
     * @returns {Promise<Object>} Current user data with permissions
     */
    getCurrentUser: async () => {
        const response = await api.get('/auth/me');
        return response.data;
    },

    /**
     * Check if user is authenticated (has token stored)
     * @returns {boolean} True if token exists
     */
    isAuthenticated: () => {
        return !!localStorage.getItem('auth_token');
    },

    /**
     * Get stored auth token
     * @returns {string|null} The stored token or null
     */
    getToken: () => {
        return localStorage.getItem('auth_token');
    },
};

export default authService;
