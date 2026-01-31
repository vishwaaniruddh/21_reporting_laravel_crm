import React from 'react';
import { useAuth } from '../contexts/AuthContext';

/**
 * RoleGuard component for permission-based rendering
 * Renders children only if the user has the required permission(s).
 * 
 * @param {Object} props
 * @param {string} [props.requiredPermission] - Single permission required to render children
 * @param {string[]} [props.requiredPermissions] - Array of permissions (user must have ALL)
 * @param {string[]} [props.anyPermissions] - Array of permissions (user must have ANY)
 * @param {string} [props.requiredRole] - Role required to render children
 * @param {React.ReactNode} props.children - Content to render if authorized
 * @param {React.ReactNode} [props.fallback] - Optional content to render if unauthorized
 * 
 * Requirements: 3.4
 */
const RoleGuard = ({ 
    requiredPermission,
    requiredPermissions,
    anyPermissions,
    requiredRole,
    children, 
    fallback = null 
}) => {
    const { hasPermission, hasAllPermissions, hasAnyPermission, hasRole, isAuthenticated } = useAuth();

    // If not authenticated, don't render anything
    if (!isAuthenticated) {
        return fallback;
    }

    // Check single permission
    if (requiredPermission && !hasPermission(requiredPermission)) {
        return fallback;
    }

    // Check all permissions (AND logic)
    if (requiredPermissions && requiredPermissions.length > 0) {
        if (!hasAllPermissions(requiredPermissions)) {
            return fallback;
        }
    }

    // Check any permissions (OR logic)
    if (anyPermissions && anyPermissions.length > 0) {
        if (!hasAnyPermission(anyPermissions)) {
            return fallback;
        }
    }

    // Check role
    if (requiredRole && !hasRole(requiredRole)) {
        return fallback;
    }

    // User has required permission(s)/role
    return children;
};

/**
 * Higher-Order Component version of RoleGuard
 * Wraps a component to only render if user has required permission.
 * 
 * @param {React.ComponentType} WrappedComponent - Component to wrap
 * @param {string} requiredPermission - Permission required to render
 * @param {React.ComponentType} [FallbackComponent] - Optional fallback component
 */
export const withPermission = (WrappedComponent, requiredPermission, FallbackComponent = null) => {
    return function PermissionGuardedComponent(props) {
        return (
            <RoleGuard 
                requiredPermission={requiredPermission} 
                fallback={FallbackComponent ? <FallbackComponent {...props} /> : null}
            >
                <WrappedComponent {...props} />
            </RoleGuard>
        );
    };
};

/**
 * Higher-Order Component version of RoleGuard for role-based access
 * Wraps a component to only render if user has required role.
 * 
 * @param {React.ComponentType} WrappedComponent - Component to wrap
 * @param {string} requiredRole - Role required to render
 * @param {React.ComponentType} [FallbackComponent] - Optional fallback component
 */
export const withRole = (WrappedComponent, requiredRole, FallbackComponent = null) => {
    return function RoleGuardedComponent(props) {
        return (
            <RoleGuard 
                requiredRole={requiredRole} 
                fallback={FallbackComponent ? <FallbackComponent {...props} /> : null}
            >
                <WrappedComponent {...props} />
            </RoleGuard>
        );
    };
};

export default RoleGuard;
