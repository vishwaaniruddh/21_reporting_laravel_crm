import React, { useState, useEffect } from 'react';
import { useAuth } from '../contexts/AuthContext';
import api from '../services/api';

/**
 * SuperadminDashboard component
 * Displays system-wide statistics, user management, and all administrative functions.
 * 
 * Requirements: 6.1
 */
const SuperadminDashboard = () => {
    const { user } = useAuth();
    const [stats, setStats] = useState({
        totalUsers: 0,
        activeUsers: 0,
        inactiveUsers: 0,
        superadmins: 0,
        admins: 0,
        managers: 0,
        totalRoles: 0,
        totalPermissions: 0,
    });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        fetchDashboardStats();
    }, []);

    const fetchDashboardStats = async () => {
        try {
            setLoading(true);
            setError(null);
            
            // Fetch users, roles, and permissions to calculate stats
            const [usersResponse, rolesResponse, permissionsResponse] = await Promise.all([
                api.get('/users'),
                api.get('/roles'),
                api.get('/permissions'),
            ]);

            const users = usersResponse.data.data || usersResponse.data || [];
            const roles = rolesResponse.data.data || rolesResponse.data || [];
            const permissions = permissionsResponse.data.data || permissionsResponse.data || [];

            // Calculate statistics
            const activeUsers = users.filter(u => u.is_active).length;
            const superadmins = users.filter(u => u.role === 'superadmin').length;
            const admins = users.filter(u => u.role === 'admin').length;
            const managers = users.filter(u => u.role === 'manager').length;

            setStats({
                totalUsers: users.length,
                activeUsers,
                inactiveUsers: users.length - activeUsers,
                superadmins,
                admins,
                managers,
                totalRoles: roles.length,
                totalPermissions: permissions.length,
            });
        } catch (err) {
            console.error('Failed to fetch dashboard stats:', err);
            setError('Failed to load dashboard statistics');
        } finally {
            setLoading(false);
        }
    };

    const statCards = [
        {
            title: 'Total Users',
            value: stats.totalUsers,
            icon: UsersIcon,
            color: 'bg-blue-500',
            link: '/users',
        },
        {
            title: 'Active Users',
            value: stats.activeUsers,
            icon: ActiveIcon,
            color: 'bg-green-500',
        },
        {
            title: 'Inactive Users',
            value: stats.inactiveUsers,
            icon: InactiveIcon,
            color: 'bg-red-500',
        },
        {
            title: 'Superadmins',
            value: stats.superadmins,
            icon: ShieldIcon,
            color: 'bg-purple-500',
        },
        {
            title: 'Admins',
            value: stats.admins,
            icon: AdminIcon,
            color: 'bg-indigo-500',
        },
        {
            title: 'Managers',
            value: stats.managers,
            icon: ManagerIcon,
            color: 'bg-teal-500',
        },
        {
            title: 'Total Roles',
            value: stats.totalRoles,
            icon: RolesIcon,
            color: 'bg-orange-500',
            link: '/roles',
        },
        {
            title: 'Total Permissions',
            value: stats.totalPermissions,
            icon: PermissionsIcon,
            color: 'bg-pink-500',
            link: '/permissions',
        },
    ];

    const quickActions = [
        {
            title: 'Manage Users',
            description: 'Create, edit, and manage all system users',
            icon: UsersIcon,
            href: '/users',
            color: 'bg-blue-100 text-blue-600',
        },
        {
            title: 'View Roles',
            description: 'View and manage role definitions',
            icon: RolesIcon,
            href: '/roles',
            color: 'bg-purple-100 text-purple-600',
        },
        {
            title: 'View Permissions',
            description: 'View all available permissions',
            icon: PermissionsIcon,
            href: '/permissions',
            color: 'bg-green-100 text-green-600',
        },
    ];

    if (loading) {
        return (
            <div className="flex items-center justify-center h-64">
                <div className="flex items-center gap-2 text-gray-600">
                    <svg className="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Loading dashboard...
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {/* Error Alert */}
            {error && (
                <div className="bg-red-50 border-l-4 border-red-400 p-4 rounded-r-md">
                    <div className="flex">
                        <div className="flex-shrink-0">
                            <svg className="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                            </svg>
                        </div>
                        <div className="ml-3">
                            <p className="text-sm text-red-700">{error}</p>
                        </div>
                        <button onClick={fetchDashboardStats} className="ml-auto text-red-700 hover:text-red-900">
                            Retry
                        </button>
                    </div>
                </div>
            )}

            {/* Statistics Grid */}
            <div>
                <h3 className="text-sm font-medium text-gray-900 mb-3">System Statistics</h3>
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    {statCards.map((stat, index) => (
                        <div
                            key={index}
                            className="bg-white rounded-lg shadow p-3 hover:shadow-md transition-shadow"
                        >
                            <div className="flex items-center">
                                <div className={`p-2 rounded-full ${stat.color}`}>
                                    <stat.icon className="h-4 w-4 text-white" />
                                </div>
                                <div className="ml-3">
                                    <p className="text-xs font-medium text-gray-500">{stat.title}</p>
                                    <p className="text-lg font-semibold text-gray-900">{stat.value}</p>
                                </div>
                            </div>
                            {stat.link && (
                                <a
                                    href={stat.link}
                                    className="mt-2 block text-xs text-indigo-600 hover:text-indigo-800"
                                >
                                    View details →
                                </a>
                            )}
                        </div>
                    ))}
                </div>
            </div>

            {/* Quick Actions */}
            <div>
                <h3 className="text-sm font-medium text-gray-900 mb-3">Quick Actions</h3>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                    {quickActions.map((action, index) => (
                        <a
                            key={index}
                            href={action.href}
                            className="bg-white rounded-lg shadow p-3 hover:shadow-md transition-shadow group"
                        >
                            <div className="flex items-center gap-3">
                                <div className={`p-2 rounded-lg ${action.color}`}>
                                    <action.icon className="h-4 w-4" />
                                </div>
                                <div>
                                    <h4 className="text-sm font-medium text-gray-900 group-hover:text-indigo-600">
                                        {action.title}
                                    </h4>
                                    <p className="text-xs text-gray-500">{action.description}</p>
                                </div>
                            </div>
                        </a>
                    ))}
                </div>
            </div>

            {/* Role Distribution */}
            <div className="bg-white rounded-lg shadow p-4">
                <h3 className="text-sm font-medium text-gray-900 mb-3">User Distribution by Role</h3>
                <div className="space-y-4">
                    <RoleBar label="Superadmins" count={stats.superadmins} total={stats.totalUsers} color="bg-purple-500" />
                    <RoleBar label="Admins" count={stats.admins} total={stats.totalUsers} color="bg-indigo-500" />
                    <RoleBar label="Managers" count={stats.managers} total={stats.totalUsers} color="bg-teal-500" />
                </div>
            </div>
        </div>
    );
};

// Role distribution bar component
const RoleBar = ({ label, count, total, color }) => {
    const percentage = total > 0 ? (count / total) * 100 : 0;
    
    return (
        <div>
            <div className="flex justify-between text-sm mb-1">
                <span className="text-gray-600">{label}</span>
                <span className="text-gray-900 font-medium">{count} ({percentage.toFixed(1)}%)</span>
            </div>
            <div className="w-full bg-gray-200 rounded-full h-2">
                <div
                    className={`${color} h-2 rounded-full transition-all duration-300`}
                    style={{ width: `${percentage}%` }}
                />
            </div>
        </div>
    );
};

// Icon components
const UsersIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
    </svg>
);

const ActiveIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const InactiveIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const ShieldIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
    </svg>
);

const AdminIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const ManagerIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
    </svg>
);

const RolesIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
    </svg>
);

const PermissionsIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
    </svg>
);

export default SuperadminDashboard;
