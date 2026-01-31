import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import api, { setApiToken } from '../services/api';

// Create the AuthContext
const AuthContext = createContext(null);

// Custom hook to use the auth context
export const useAuth = () => {
    const context = useContext(AuthContext);
    if (!context) {
        throw new Error('useAuth must be used within an AuthProvider');
    }
    return context;
};

// AuthProvider component
export const AuthProvider = ({ children }) => {
    const [user, setUser] = useState(null);
    const [token, setToken] = useState(localStorage.getItem('auth_token'));
    const [permissions, setPermissions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    // Check if user is authenticated
    const isAuthenticated = !!token && !!user;

    // Set auth token in axios headers and localStorage
    const setAuthToken = useCallback((newToken) => {
        if (newToken) {
            localStorage.setItem('auth_token', newToken);
            setApiToken(newToken);
        } else {
            localStorage.removeItem('auth_token');
            setApiToken(null);
        }
        setToken(newToken);
    }, []);

    // Fetch current user data
    const fetchUser = useCallback(async () => {
        if (!token) {
            setLoading(false);
            return;
        }

        try {
            setApiToken(token);
            const response = await api.get('/auth/me');
            setUser(response.data.user);
            setPermissions(response.data.permissions || []);
            setError(null);
        } catch (err) {
            console.error('Failed to fetch user:', err);
            // Token is invalid, clear it
            setAuthToken(null);
            setUser(null);
            setPermissions([]);
        } finally {
            setLoading(false);
        }
    }, [token, setAuthToken]);

    // Login function
    const login = async (email, password) => {
        setError(null);
        try {
            const response = await api.post('/auth/login', { email, password });
            const { user: userData, token: authToken, permissions: userPermissions } = response.data;
            
            setAuthToken(authToken);
            setUser(userData);
            setPermissions(userPermissions || []);
            
            return { success: true };
        } catch (err) {
            const errorMessage = err.response?.data?.error || 'Login failed. Please try again.';
            setError(errorMessage);
            return { success: false, error: errorMessage };
        }
    };

    // Logout function
    const logout = async () => {
        try {
            await api.post('/auth/logout');
        } catch (err) {
            console.error('Logout error:', err);
        } finally {
            setAuthToken(null);
            setUser(null);
            setPermissions([]);
            setError(null);
        }
    };

    // Check if user has a specific permission
    const hasPermission = (permission) => {
        return permissions.includes(permission);
    };

    // Check if user has any of the specified permissions
    const hasAnyPermission = (permissionList) => {
        return permissionList.some(permission => permissions.includes(permission));
    };

    // Check if user has all of the specified permissions
    const hasAllPermissions = (permissionList) => {
        return permissionList.every(permission => permissions.includes(permission));
    };

    // Check if user has a specific role
    const hasRole = (roleName) => {
        return user?.role === roleName;
    };

    // Initialize auth state on mount
    useEffect(() => {
        fetchUser();
    }, [fetchUser]);

    const value = {
        user,
        token,
        permissions,
        loading,
        error,
        isAuthenticated,
        login,
        logout,
        hasPermission,
        hasAnyPermission,
        hasAllPermissions,
        hasRole,
        setError,
    };

    return (
        <AuthContext.Provider value={value}>
            {children}
        </AuthContext.Provider>
    );
};

export default AuthContext;
