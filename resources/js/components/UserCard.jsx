import React from 'react';

/**
 * UserCard component
 * Displays user info with role badge and status indicator.
 * 
 * Requirements: 2.4
 */
const UserCard = ({ user, onEdit, onDeactivate, canEdit = true, canDeactivate = true }) => {
    const getRoleBadgeColor = (role) => {
        switch (role) {
            case 'superadmin':
                return 'bg-purple-100 text-purple-800';
            case 'admin':
                return 'bg-blue-100 text-blue-800';
            case 'manager':
                return 'bg-green-100 text-green-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    };

    const getStatusBadge = (isActive) => {
        return isActive
            ? 'bg-green-100 text-green-800'
            : 'bg-red-100 text-red-800';
    };

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
            case 'superadmin':
                return 'bg-purple-500';
            case 'admin':
                return 'bg-blue-500';
            case 'manager':
                return 'bg-green-500';
            default:
                return 'bg-gray-500';
        }
    };

    return (
        <div className="bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow">
            <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                    {/* Avatar */}
                    <div className={`w-12 h-12 rounded-full ${getAvatarColor(user.role)} flex items-center justify-center`}>
                        <span className="text-white font-medium text-lg">
                            {getInitials(user.name)}
                        </span>
                    </div>
                    
                    {/* User Info */}
                    <div>
                        <h3 className="text-sm font-medium text-gray-900">{user.name}</h3>
                        <p className="text-xs text-gray-500">{user.email}</p>
                    </div>
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2">
                    {canEdit && onEdit && (
                        <button
                            onClick={() => onEdit(user)}
                            className="p-1 text-gray-400 hover:text-indigo-600 transition-colors"
                            title="Edit user"
                        >
                            <EditIcon className="w-4 h-4" />
                        </button>
                    )}
                    {canDeactivate && onDeactivate && user.is_active && (
                        <button
                            onClick={() => onDeactivate(user)}
                            className="p-1 text-gray-400 hover:text-red-600 transition-colors"
                            title="Deactivate user"
                        >
                            <DeactivateIcon className="w-4 h-4" />
                        </button>
                    )}
                </div>
            </div>

            {/* Badges */}
            <div className="mt-3 flex items-center gap-2">
                <span className={`px-2 py-1 text-xs font-medium rounded-full capitalize ${getRoleBadgeColor(user.role)}`}>
                    {user.role}
                </span>
                <span className={`px-2 py-1 text-xs font-medium rounded-full ${getStatusBadge(user.is_active)}`}>
                    {user.is_active ? 'Active' : 'Inactive'}
                </span>
            </div>

            {/* Additional Info */}
            {user.created_at && (
                <p className="mt-2 text-xs text-gray-400">
                    Created: {new Date(user.created_at).toLocaleDateString()}
                </p>
            )}
        </div>
    );
};

// Icon components
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

export default UserCard;
