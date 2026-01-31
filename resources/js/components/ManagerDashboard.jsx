import React from 'react';
import { useAuth } from '../contexts/AuthContext';
import RoleGuard from './RoleGuard';

/**
 * ManagerDashboard component
 * Displays personal statistics and permitted features only for Manager users.
 * 
 * Requirements: 6.3
 */
const ManagerDashboard = () => {
    const { user, permissions } = useAuth();

    // Get the date the user was created
    const memberSince = user?.created_at 
        ? new Date(user.created_at).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        })
        : 'N/A';

    // Personal info cards
    const personalInfo = [
        {
            label: 'Name',
            value: user?.name || 'N/A',
            icon: UserIcon,
        },
        {
            label: 'Email',
            value: user?.email || 'N/A',
            icon: EmailIcon,
        },
        {
            label: 'Role',
            value: 'Manager',
            icon: RoleIcon,
        },
        {
            label: 'Member Since',
            value: memberSince,
            icon: CalendarIcon,
        },
    ];

    return (
        <div className="space-y-4">
            {/* Personal Information */}
            <div>
                <h3 className="text-sm font-medium text-gray-900 mb-3">Personal Information</h3>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    {personalInfo.map((info, index) => (
                        <div
                            key={index}
                            className="bg-white rounded-lg shadow p-3"
                        >
                            <div className="flex items-center gap-3">
                                <div className="p-2 rounded-full bg-teal-100">
                                    <info.icon className="h-4 w-4 text-teal-600" />
                                </div>
                                <div>
                                    <p className="text-xs font-medium text-gray-500">{info.label}</p>
                                    <p className="text-sm font-semibold text-gray-900 truncate">{info.value}</p>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            {/* Permissions Overview */}
            <div className="bg-white rounded-lg shadow">
                <div className="px-4 py-3 border-b border-gray-200">
                    <h3 className="text-sm font-medium text-gray-900">Your Permissions</h3>
                    <p className="text-xs text-gray-500 mt-0.5">
                        Actions you're authorized to perform.
                    </p>
                </div>
                <div className="p-4">
                    {permissions.length === 0 ? (
                        <div className="text-center text-gray-500 py-3">
                            <LockIcon className="h-8 w-8 mx-auto text-gray-300 mb-2" />
                            <p className="text-xs">No specific permissions assigned yet.</p>
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
                            {permissions.map((permission, index) => (
                                <div
                                    key={index}
                                    className="flex items-center gap-1.5 p-2 bg-green-50 rounded-md"
                                >
                                    <CheckIcon className="h-3 w-3 text-green-500 flex-shrink-0" />
                                    <span className="text-xs text-gray-700 capitalize truncate">
                                        {permission.replace('.', ' - ').replace(/_/g, ' ')}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {/* Available Features */}
            <div>
                <h3 className="text-sm font-medium text-gray-900 mb-3">Available Features</h3>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                    {/* Dashboard View - Always available for managers */}
                    <RoleGuard requiredPermission="dashboard.view">
                        <div className="bg-white rounded-lg shadow p-3">
                            <div className="flex items-center gap-3">
                                <div className="p-2 rounded-lg bg-teal-100 text-teal-600">
                                    <DashboardIcon className="h-4 w-4" />
                                </div>
                                <div>
                                    <h4 className="text-sm font-medium text-gray-900">Dashboard Access</h4>
                                    <p className="text-xs text-gray-500">
                                        View your personal dashboard
                                    </p>
                                </div>
                            </div>
                        </div>
                    </RoleGuard>

                    {/* Users Read - If permission granted */}
                    <RoleGuard requiredPermission="users.read">
                        <a
                            href="/users"
                            className="bg-white rounded-lg shadow p-3 hover:shadow-md transition-shadow group"
                        >
                            <div className="flex items-center gap-3">
                                <div className="p-2 rounded-lg bg-blue-100 text-blue-600">
                                    <UsersIcon className="h-4 w-4" />
                                </div>
                                <div>
                                    <h4 className="text-sm font-medium text-gray-900 group-hover:text-indigo-600">
                                        View Users
                                    </h4>
                                    <p className="text-xs text-gray-500">
                                        Browse user directory
                                    </p>
                                </div>
                            </div>
                        </a>
                    </RoleGuard>
                </div>
            </div>

            {/* Account Status */}
            <div className="bg-white rounded-lg shadow p-4">
                <h3 className="text-sm font-medium text-gray-900 mb-3">Account Status</h3>
                <div className="flex items-center gap-3">
                    <div className={`p-2 rounded-full ${user?.is_active ? 'bg-green-100' : 'bg-red-100'}`}>
                        {user?.is_active ? (
                            <ActiveIcon className="h-4 w-4 text-green-600" />
                        ) : (
                            <InactiveIcon className="h-4 w-4 text-red-600" />
                        )}
                    </div>
                    <div>
                        <p className="text-sm font-medium text-gray-900">
                            {user?.is_active ? 'Active Account' : 'Inactive Account'}
                        </p>
                        <p className="text-xs text-gray-500">
                            {user?.is_active 
                                ? 'Your account is active and in good standing.' 
                                : 'Your account has been deactivated.'}
                        </p>
                    </div>
                </div>
            </div>

            {/* Help Section */}
            <div className="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <div className="flex items-start gap-3">
                    <div className="p-1.5 rounded-lg bg-gray-200">
                        <HelpIcon className="h-4 w-4 text-gray-600" />
                    </div>
                    <div>
                        <h4 className="text-sm font-medium text-gray-900">Need Help?</h4>
                        <p className="text-xs text-gray-600 mt-0.5">
                            Contact your administrator for additional permissions or questions.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
};

// Icon components
const UserIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
    </svg>
);

const EmailIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
    </svg>
);

const RoleIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
    </svg>
);

const CalendarIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
    </svg>
);

const DashboardIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
    </svg>
);

const UsersIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
    </svg>
);

const CheckIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
    </svg>
);

const LockIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
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

const HelpIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

export default ManagerDashboard;
