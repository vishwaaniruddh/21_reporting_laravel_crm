import { useState, useEffect, useCallback } from 'react';
import { useAuth } from '../contexts/AuthContext';
import DashboardLayout from '../components/DashboardLayout';
import UserCard from '../components/UserCard';
import UserFormModal from '../components/UserFormModal';
import { userService } from '../services/userService';

/**
 * UserListPage component
 * Displays users in table format with filtering and actions.
 * Superadmin sees all users, Admin sees only Managers.
 * 
 * Requirements: 2.4, 4.4
 */
const UserListPage = () => {
    const { user: currentUser, hasRole, hasPermission } = useAuth();
    
    // State
    const [users, setUsers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [roleFilter, setRoleFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [searchQuery, setSearchQuery] = useState('');
    const [viewMode, setViewMode] = useState('table'); // 'table' or 'cards'
    
    // Modal state
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedUser, setSelectedUser] = useState(null);
    
    // Confirmation dialog state
    const [confirmDialog, setConfirmDialog] = useState({ open: false, user: null });

    // Fetch users
    const fetchUsers = useCallback(async () => {
        try {
            setLoading(true);
            setError(null);
            
            const filters = {};
            if (roleFilter) filters.role = roleFilter;
            if (statusFilter !== '') filters.status = statusFilter;
            
            const response = await userService.getUsers(filters);
            setUsers(response.data || response || []);
        } catch (err) {
            setError(err.message || 'Failed to fetch users');
            console.error('Error fetching users:', err);
        } finally {
            setLoading(false);
        }
    }, [roleFilter, statusFilter]);

    useEffect(() => {
        fetchUsers();
    }, [fetchUsers]);

    // Filter users by search query (client-side)
    const filteredUsers = users.filter(user => {
        if (!searchQuery) return true;
        const query = searchQuery.toLowerCase();
        return (
            user.name?.toLowerCase().includes(query) ||
            user.email?.toLowerCase().includes(query)
        );
    });

    // Handlers
    const handleCreateUser = () => {
        setSelectedUser(null);
        setIsModalOpen(true);
    };

    const handleEditUser = (user) => {
        setSelectedUser(user);
        setIsModalOpen(true);
    };

    const handleDeactivateUser = (user) => {
        setConfirmDialog({ open: true, user });
    };

    const confirmDeactivate = async () => {
        if (!confirmDialog.user) return;
        
        try {
            await userService.deleteUser(confirmDialog.user.id);
            setConfirmDialog({ open: false, user: null });
            fetchUsers(); // Refresh the list
        } catch (err) {
            setError(err.message || 'Failed to deactivate user');
        }
    };

    const handleModalClose = () => {
        setIsModalOpen(false);
        setSelectedUser(null);
    };

    const handleModalSuccess = () => {
        setIsModalOpen(false);
        setSelectedUser(null);
        fetchUsers(); // Refresh the list
    };

    // Role badge colors
    const getRoleBadgeColor = (role) => {
        switch (role) {
            case 'superadmin': return 'bg-purple-100 text-purple-800';
            case 'admin': return 'bg-blue-100 text-blue-800';
            case 'manager': return 'bg-green-100 text-green-800';
            default: return 'bg-gray-100 text-gray-800';
        }
    };

    // Status badge colors
    const getStatusBadge = (isActive) => {
        return isActive
            ? 'bg-green-100 text-green-800'
            : 'bg-red-100 text-red-800';
    };

    // Check permissions
    const canCreate = hasPermission('users.create');
    const canEdit = hasPermission('users.update');
    const canDelete = hasPermission('users.delete');
    const isSuperadmin = hasRole('superadmin');

    return (
        <DashboardLayout>
            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-bold text-gray-900">User Management</h1>
                        <p className="text-xs text-gray-500">
                            {isSuperadmin 
                                ? 'Manage all users in the system' 
                                : 'Manage managers in your organization'}
                        </p>
                    </div>
                    {canCreate && (
                        <button
                            onClick={handleCreateUser}
                            className="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            <PlusIcon className="w-4 h-4 mr-1.5" />
                            Add User
                        </button>
                    )}
                </div>

                {/* Filters and Search */}
                <div className="bg-white rounded-lg shadow p-3">
                    <div className="flex flex-col sm:flex-row gap-3">
                        {/* Search */}
                        <div className="flex-1">
                            <label htmlFor="search" className="sr-only">Search users</label>
                            <div className="relative">
                                <div className="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none">
                                    <SearchIcon className="h-4 w-4 text-gray-400" />
                                </div>
                                <input
                                    type="text"
                                    id="search"
                                    placeholder="Search by name or email..."
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    className="block w-full pl-8 pr-3 py-1.5 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 text-xs"
                                />
                            </div>
                        </div>

                        {/* Role Filter - Only for Superadmin */}
                        {isSuperadmin && (
                            <div className="sm:w-36">
                                <label htmlFor="roleFilter" className="sr-only">Filter by role</label>
                                <select
                                    id="roleFilter"
                                    value={roleFilter}
                                    onChange={(e) => setRoleFilter(e.target.value)}
                                    className="block w-full pl-2.5 pr-8 py-1.5 text-xs border border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 rounded-md"
                                >
                                    <option value="">All Roles</option>
                                    <option value="superadmin">Superadmin</option>
                                    <option value="admin">Admin</option>
                                    <option value="manager">Manager</option>
                                </select>
                            </div>
                        )}

                        {/* Status Filter */}
                        <div className="sm:w-32">
                            <label htmlFor="statusFilter" className="sr-only">Filter by status</label>
                            <select
                                id="statusFilter"
                                value={statusFilter}
                                onChange={(e) => setStatusFilter(e.target.value)}
                                className="block w-full pl-2.5 pr-8 py-1.5 text-xs border border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 rounded-md"
                            >
                                <option value="">All Status</option>
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>

                        {/* View Mode Toggle */}
                        <div className="flex items-center gap-1">
                            <button
                                onClick={() => setViewMode('table')}
                                className={`p-1.5 rounded-md ${viewMode === 'table' ? 'bg-indigo-100 text-indigo-600' : 'text-gray-400 hover:text-gray-600'}`}
                                title="Table view"
                            >
                                <TableIcon className="w-4 h-4" />
                            </button>
                            <button
                                onClick={() => setViewMode('cards')}
                                className={`p-1.5 rounded-md ${viewMode === 'cards' ? 'bg-indigo-100 text-indigo-600' : 'text-gray-400 hover:text-gray-600'}`}
                                title="Card view"
                            >
                                <GridIcon className="w-4 h-4" />
                            </button>
                        </div>
                    </div>
                </div>

                {/* Error Message */}
                {error && (
                    <div className="bg-red-50 border border-red-200 rounded-md p-3">
                        <div className="flex">
                            <div className="flex-shrink-0">
                                <ErrorIcon className="h-4 w-4 text-red-400" />
                            </div>
                            <div className="ml-2">
                                <p className="text-xs text-red-700">{error}</p>
                            </div>
                            <div className="ml-auto pl-2">
                                <button
                                    onClick={() => setError(null)}
                                    className="text-red-400 hover:text-red-600"
                                >
                                    <CloseIcon className="h-4 w-4" />
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {/* Loading State */}
                {loading ? (
                    <div className="bg-white rounded-lg shadow p-6">
                        <div className="flex items-center justify-center">
                            <LoadingSpinner />
                            <span className="ml-2 text-xs text-gray-600">Loading users...</span>
                        </div>
                    </div>
                ) : filteredUsers.length === 0 ? (
                    /* Empty State */
                    <div className="bg-white rounded-lg shadow p-6 text-center">
                        <UsersIcon className="mx-auto h-10 w-10 text-gray-400" />
                        <h3 className="mt-2 text-xs font-medium text-gray-900">No users found</h3>
                        <p className="mt-1 text-xs text-gray-500">
                            {searchQuery || roleFilter || statusFilter
                                ? 'Try adjusting your filters'
                                : 'Get started by creating a new user'}
                        </p>
                        {canCreate && !searchQuery && !roleFilter && !statusFilter && (
                            <div className="mt-4">
                                <button
                                    onClick={handleCreateUser}
                                    className="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700"
                                >
                                    <PlusIcon className="w-4 h-4 mr-1.5" />
                                    Add User
                                </button>
                            </div>
                        )}
                    </div>
                ) : viewMode === 'table' ? (
                    /* Table View */
                    <div className="bg-white rounded-lg shadow overflow-hidden">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200">
                                <thead className="bg-gray-50">
                                    <tr>
                                        <th scope="col" className="px-4 py-2 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">
                                            #
                                        </th>
                                        <th scope="col" className="px-4 py-2 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">
                                            User
                                        </th>
                                        <th scope="col" className="px-4 py-2 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">
                                            Contact
                                        </th>
                                        <th scope="col" className="px-4 py-2 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">
                                            Role
                                        </th>
                                        <th scope="col" className="px-4 py-2 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">
                                            Status
                                        </th>
                                        <th scope="col" className="px-4 py-2 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">
                                            Created
                                        </th>
                                        <th scope="col" className="relative px-4 py-2">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="bg-white divide-y divide-gray-200">
                                    {filteredUsers.map((user, index) => (
                                        <tr key={user.id} className="hover:bg-gray-50">
                                            <td className="px-4 py-2 whitespace-nowrap text-xs text-gray-500 font-medium">
                                                {index + 1}
                                            </td>
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                <div className="flex items-center">
                                                    <div className={`w-7 h-7 rounded-full flex items-center justify-center ${getAvatarColor(user.role)}`}>
                                                        <span className="text-white font-medium text-[10px]">
                                                            {getInitials(user.name)}
                                                        </span>
                                                    </div>
                                                    <div className="ml-3">
                                                        <div className="flex items-center gap-1.5">
                                                            <UserIconSmall className="w-3 h-3 text-gray-400" />
                                                            <span className="text-xs font-medium text-gray-900">{user.name}</span>
                                                        </div>
                                                        <div className="flex items-center gap-1.5 mt-0.5">
                                                            <MailIconSmall className="w-3 h-3 text-gray-400" />
                                                            <span className="text-[10px] text-gray-500">{user.email}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                {user.contact ? (
                                                    <div className="flex items-center gap-1.5">
                                                        <PhoneIconSmall className="w-3 h-3 text-gray-400" />
                                                        <span className="text-xs text-gray-700">{user.contact}</span>
                                                    </div>
                                                ) : (
                                                    <span className="text-xs text-gray-400">-</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                <span className={`px-1.5 py-0.5 text-[10px] font-medium rounded-full capitalize ${getRoleBadgeColor(user.role)}`}>
                                                    {user.role}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                <span className={`px-1.5 py-0.5 text-[10px] font-medium rounded-full ${getStatusBadge(user.is_active)}`}>
                                                    {user.is_active ? 'Active' : 'Inactive'}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                <div className="flex items-center gap-1.5">
                                                    <CalendarIconSmall className="w-3 h-3 text-gray-400" />
                                                    <span className="text-[10px] text-gray-500">
                                                        {user.created_at ? new Date(user.created_at).toLocaleDateString() : '-'}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-2 whitespace-nowrap text-right text-xs font-medium">
                                                <div className="flex items-center justify-end gap-1.5">
                                                    {canEdit && canEditUser(user) && (
                                                        <button
                                                            onClick={() => handleEditUser(user)}
                                                            className="text-indigo-600 hover:text-indigo-900"
                                                            title="Edit user"
                                                        >
                                                            <EditIcon className="w-3.5 h-3.5" />
                                                        </button>
                                                    )}
                                                    {canDelete && canEditUser(user) && user.is_active && (
                                                        <button
                                                            onClick={() => handleDeactivateUser(user)}
                                                            className="text-red-600 hover:text-red-900"
                                                            title="Deactivate user"
                                                        >
                                                            <DeactivateIcon className="w-3.5 h-3.5" />
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                ) : (
                    /* Card View */
                    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                        {filteredUsers.map((user) => (
                            <UserCard
                                key={user.id}
                                user={user}
                                onEdit={canEdit && canEditUser(user) ? handleEditUser : null}
                                onDeactivate={canDelete && canEditUser(user) ? handleDeactivateUser : null}
                                canEdit={canEdit && canEditUser(user)}
                                canDeactivate={canDelete && canEditUser(user)}
                            />
                        ))}
                    </div>
                )}

                {/* Results count */}
                {!loading && filteredUsers.length > 0 && (
                    <div className="text-sm text-gray-500">
                        Showing {filteredUsers.length} of {users.length} users
                    </div>
                )}
            </div>

            {/* User Form Modal */}
            <UserFormModal
                isOpen={isModalOpen}
                onClose={handleModalClose}
                user={selectedUser}
                onSuccess={handleModalSuccess}
            />

            {/* Confirmation Dialog */}
            {confirmDialog.open && (
                <div className="fixed inset-0 z-50 overflow-y-auto">
                    <div className="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                        <div className="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" onClick={() => setConfirmDialog({ open: false, user: null })} />
                        
                        <div className="relative inline-block px-4 pt-5 pb-4 overflow-hidden text-left align-bottom transition-all transform bg-white rounded-lg shadow-xl sm:my-8 sm:align-middle sm:max-w-lg sm:w-full sm:p-6">
                            <div className="sm:flex sm:items-start">
                                <div className="flex items-center justify-center flex-shrink-0 w-12 h-12 mx-auto bg-red-100 rounded-full sm:mx-0 sm:h-10 sm:w-10">
                                    <WarningIcon className="w-6 h-6 text-red-600" />
                                </div>
                                <div className="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                    <h3 className="text-lg font-medium leading-6 text-gray-900">
                                        Deactivate User
                                    </h3>
                                    <div className="mt-2">
                                        <p className="text-sm text-gray-500">
                                            Are you sure you want to deactivate <strong>{confirmDialog.user?.name}</strong>? 
                                            They will no longer be able to log in to the system.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div className="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                                <button
                                    type="button"
                                    onClick={confirmDeactivate}
                                    className="inline-flex justify-center w-full px-4 py-2 text-base font-medium text-white bg-red-600 border border-transparent rounded-md shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:ml-3 sm:w-auto sm:text-sm"
                                >
                                    Deactivate
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setConfirmDialog({ open: false, user: null })}
                                    className="inline-flex justify-center w-full px-4 py-2 mt-3 text-base font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:w-auto sm:text-sm"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}
        </DashboardLayout>
    );

    // Helper function to check if current user can edit a specific user
    function canEditUser(targetUser) {
        // Superadmin can edit anyone except themselves (for safety)
        if (isSuperadmin) {
            return targetUser.id !== currentUser?.id;
        }
        // Admin can only edit managers
        if (hasRole('admin')) {
            return targetUser.role === 'manager';
        }
        return false;
    }
};


// Helper functions
const getInitials = (name) => {
    if (!name) return '?';
    return name
        .split(' ')
        .map(part => part.charAt(0).toUpperCase())
        .slice(0, 2)
        .join('');
};

const getAvatarColor = (role) => {
    switch (role) {
        case 'superadmin': return 'bg-purple-500';
        case 'admin': return 'bg-blue-500';
        case 'manager': return 'bg-green-500';
        default: return 'bg-gray-500';
    }
};

// Icon components
const PlusIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
    </svg>
);

const SearchIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
    </svg>
);

const TableIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
    </svg>
);

const GridIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
    </svg>
);

const UsersIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
    </svg>
);

const EditIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
    </svg>
);

const DeactivateIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
    </svg>
);

const ErrorIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
    </svg>
);

const CloseIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
    </svg>
);

const WarningIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
    </svg>
);

const LoadingSpinner = () => (
    <svg className="animate-spin h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
    </svg>
);

const UserIconSmall = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
    </svg>
);

const MailIconSmall = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
    </svg>
);

const PhoneIconSmall = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
    </svg>
);

const CalendarIconSmall = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
    </svg>
);

export default UserListPage;
