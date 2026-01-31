import React, { useState, useEffect } from 'react';
import { useAuth } from '../contexts/AuthContext';
import api from '../services/api';

/**
 * AdminDashboard component
 * Displays Manager management options and limited statistics for Admin users.
 * 
 * Requirements: 6.2
 */
const AdminDashboard = () => {
    const { user } = useAuth();
    const [stats, setStats] = useState({
        totalManagers: 0,
        activeManagers: 0,
        inactiveManagers: 0,
        managersCreatedByMe: 0,
    });
    const [recentManagers, setRecentManagers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        fetchDashboardData();
    }, []);

    const fetchDashboardData = async () => {
        try {
            setLoading(true);
            setError(null);
            
            // Fetch users (Admin can only see Managers)
            const usersResponse = await api.get('/users');
            const users = usersResponse.data.data || usersResponse.data || [];

            // Filter to only managers (API should already filter, but double-check)
            const managers = users.filter(u => u.role === 'manager');
            const activeManagers = managers.filter(u => u.is_active);
            const managersCreatedByMe = managers.filter(u => u.created_by === user?.id);

            setStats({
                totalManagers: managers.length,
                activeManagers: activeManagers.length,
                inactiveManagers: managers.length - activeManagers.length,
                managersCreatedByMe: managersCreatedByMe.length,
            });

            // Get recent managers (last 5)
            const sortedManagers = [...managers].sort((a, b) => 
                new Date(b.created_at) - new Date(a.created_at)
            );
            setRecentManagers(sortedManagers.slice(0, 5));
        } catch (err) {
            console.error('Failed to fetch dashboard data:', err);
            setError('Failed to load dashboard data');
        } finally {
            setLoading(false);
        }
    };

    const statCards = [
        {
            title: 'Total Managers',
            value: stats.totalManagers,
            icon: UsersIcon,
            color: 'bg-blue-500',
        },
        {
            title: 'Active Managers',
            value: stats.activeManagers,
            icon: ActiveIcon,
            color: 'bg-green-500',
        },
        {
            title: 'Inactive Managers',
            value: stats.inactiveManagers,
            icon: InactiveIcon,
            color: 'bg-red-500',
        },
        {
            title: 'Created by Me',
            value: stats.managersCreatedByMe,
            icon: CreatedIcon,
            color: 'bg-indigo-500',
        },
    ];

    const quickActions = [
        {
            title: 'Manage Managers',
            description: 'View and manage Manager accounts',
            icon: UsersIcon,
            href: '/users',
            color: 'bg-blue-100 text-blue-600',
        },
        {
            title: 'Create Manager',
            description: 'Add a new Manager to the system',
            icon: AddUserIcon,
            href: '/users?action=create',
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
                        <button onClick={fetchDashboardData} className="ml-auto text-red-700 hover:text-red-900">
                            Retry
                        </button>
                    </div>
                </div>
            )}

            {/* Statistics Grid */}
            <div>
                <h3 className="text-sm font-medium text-gray-900 mb-3">Manager Statistics</h3>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
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
                        </div>
                    ))}
                </div>
            </div>

            {/* Quick Actions */}
            <div>
                <h3 className="text-sm font-medium text-gray-900 mb-3">Quick Actions</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
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

            {/* Recent Managers */}
            <div className="bg-white rounded-lg shadow">
                <div className="px-4 py-3 border-b border-gray-200">
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-medium text-gray-900">Recent Managers</h3>
                        <a href="/users" className="text-xs text-indigo-600 hover:text-indigo-800">
                            View all →
                        </a>
                    </div>
                </div>
                <div className="divide-y divide-gray-200">
                    {recentManagers.length === 0 ? (
                        <div className="px-4 py-6 text-center text-gray-500 text-sm">
                            No managers found. Create your first manager to get started.
                        </div>
                    ) : (
                        recentManagers.map((manager) => (
                            <div key={manager.id} className="px-4 py-3 flex items-center justify-between">
                                <div className="flex items-center gap-2">
                                    <div className="w-8 h-8 rounded-full bg-teal-100 flex items-center justify-center">
                                        <span className="text-teal-600 font-medium text-xs">
                                            {manager.name?.charAt(0).toUpperCase()}
                                        </span>
                                    </div>
                                    <div>
                                        <p className="text-xs font-medium text-gray-900">{manager.name}</p>
                                        <p className="text-[10px] text-gray-500">{manager.email}</p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className={`px-1.5 py-0.5 text-[10px] font-medium rounded-full ${
                                        manager.is_active 
                                            ? 'bg-green-100 text-green-800' 
                                            : 'bg-red-100 text-red-800'
                                    }`}>
                                        {manager.is_active ? 'Active' : 'Inactive'}
                                    </span>
                                    <span className="text-[10px] text-gray-400">
                                        {new Date(manager.created_at).toLocaleDateString()}
                                    </span>
                                </div>
                            </div>
                        ))
                    )}
                </div>
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

const CreatedIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
    </svg>
);

const AddUserIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
    </svg>
);

export default AdminDashboard;
