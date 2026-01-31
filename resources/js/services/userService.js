import api from './api';

/**
 * User Service for API calls
 * Provides getUsers, createUser, updateUser, deleteUser methods
 * 
 * Requirements: 9.1-9.6
 */
export const userService = {
    /**
     * Get all users (filtered by role permissions on backend)
     * Superadmin sees all users, Admin sees only Managers
     * 
     * Requirements: 9.1, 2.4, 4.4
     * @param {Object} filters - Optional filters (role, status)
     * @returns {Promise<Object>} Users list response
     */
    async getUsers(filters = {}) {
        try {
            const params = new URLSearchParams();
            if (filters.role) params.append('role', filters.role);
            if (filters.status !== undefined) params.append('status', filters.status);
            
            const queryString = params.toString();
            const url = queryString ? `/users?${queryString}` : '/users';
            const response = await api.get(url);
            return response.data;
        } catch (error) {
            const errorData = error.response?.data;
            throw {
                message: errorData?.message || errorData?.error || 'Failed to fetch users',
                errors: errorData?.errors || {},
                status: error.response?.status
            };
        }
    },

    /**
     * Get a specific user by ID
     * 
     * Requirements: 9.1
     * @param {number} id - User ID
     * @returns {Promise<Object>} User data
     */
    async getUser(id) {
        try {
            const response = await api.get(`/users/${id}`);
            return response.data;
        } catch (error) {
            const errorData = error.response?.data;
            throw {
                message: errorData?.message || errorData?.error || 'Failed to fetch user',
                errors: errorData?.errors || {},
                status: error.response?.status
            };
        }
    },

    /**
     * Create a new user
     * 
     * Requirements: 9.2, 2.1, 4.1
     * @param {Object} userData - User data (name, email, password, role_id)
     * @returns {Promise<Object>} Created user data
     */
    async createUser(userData) {
        try {
            const response = await api.post('/users', userData);
            return response.data;
        } catch (error) {
            const errorData = error.response?.data;
            throw {
                message: errorData?.message || errorData?.error || 'Failed to create user',
                errors: errorData?.errors || {},
                status: error.response?.status
            };
        }
    },

    /**
     * Update an existing user
     * 
     * Requirements: 9.3
     * @param {number} id - User ID
     * @param {Object} userData - Updated user data
     * @returns {Promise<Object>} Updated user data
     */
    async updateUser(id, userData) {
        try {
            const response = await api.put(`/users/${id}`, userData);
            return response.data;
        } catch (error) {
            const errorData = error.response?.data;
            throw {
                message: errorData?.message || errorData?.error || 'Failed to update user',
                errors: errorData?.errors || {},
                status: error.response?.status
            };
        }
    },

    /**
     * Delete (deactivate) a user
     * 
     * Requirements: 9.4
     * @param {number} id - User ID
     * @returns {Promise<Object>} Deletion response
     */
    async deleteUser(id) {
        try {
            const response = await api.delete(`/users/${id}`);
            return response.data;
        } catch (error) {
            const errorData = error.response?.data;
            throw {
                message: errorData?.message || errorData?.error || 'Failed to delete user',
                errors: errorData?.errors || {},
                status: error.response?.status
            };
        }
    },

    /**
     * Get all available roles
     * 
     * Requirements: 9.5
     * @returns {Promise<Object>} Roles list response
     */
    async getRoles() {
        try {
            const response = await api.get('/roles');
            return response.data;
        } catch (error) {
            const errorData = error.response?.data;
            throw {
                message: errorData?.message || errorData?.error || 'Failed to fetch roles',
                errors: errorData?.errors || {},
                status: error.response?.status
            };
        }
    },

    /**
     * Get all available permissions
     * 
     * Requirements: 9.6
     * @returns {Promise<Object>} Permissions list response
     */
    async getPermissions() {
        try {
            const response = await api.get('/permissions');
            return response.data;
        } catch (error) {
            const errorData = error.response?.data;
            throw {
                message: errorData?.message || errorData?.error || 'Failed to fetch permissions',
                errors: errorData?.errors || {},
                status: error.response?.status
            };
        }
    }
};