import { useState, useEffect } from 'react';
import DashboardLayout from '../components/DashboardLayout';
import api from '../services/api';

/**
 * PermissionsPage component
 * Displays all permissions grouped by module.
 */
const PermissionsPage = () => {
    const [permissions, setPermissions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        fetchPermissions();
    }, []);

    const fetchPermissions = async () => {
        try {
            setLoading(true);
            const response = await api.get('/permissions');
            setPermissions(response.data.data || []);
        } catch (err) {
            setError('Failed to load permissions');
            console.error('Error fetching permissions:', err);
        } finally {
            setLoading(false);
        }
    };

    // Group permissions by module
    const groupedPermissions = permissions.reduce((acc, perm) => {
        const module = perm.module || 'Other';
        if (!acc[module]) {
            acc[module] = [];
        }
        acc[module].push(perm);
        return acc;
    }, {});

    return (
        <DashboardLayout>
            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-lg font-semibold text-gray-900">Permissions</h1>
                    <span className="text-xs text-gray-500">
                        Total: {permissions.length} permissions
                    </span>
                </div>

                {error && (
                    <div className="p-3 bg-red-50 border border-red-200 rounded-md">
                        <p className="text-xs text-red-600">{error}</p>
                    </div>
                )}

                {loading ? (
                    <div className="flex items-center justify-center py-8">
                        <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600"></div>
                        <span className="ml-2 text-xs text-gray-600">Loading permissions...</span>
                    </div>
                ) : (
                    <div className="grid gap-4">
                        {Object.entries(groupedPermissions).map(([module, perms]) => (
                            <div key={module} className="bg-white shadow rounded-lg overflow-hidden">
                                <div className="px-4 py-2 bg-gray-50 border-b border-gray-200">
                                    <h3 className="text-sm font-medium text-gray-900 capitalize">
                                        {module} Module
                                    </h3>
                                    <p className="text-[10px] text-gray-500">
                                        {perms.length} permission{perms.length !== 1 ? 's' : ''}
                                    </p>
                                </div>
                                <div className="p-3">
                                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2">
                                        {perms.map((perm) => (
                                            <div
                                                key={perm.id}
                                                className="flex items-start p-2 bg-gray-50 rounded-md border border-gray-200"
                                            >
                                                <div className="flex-shrink-0">
                                                    <div className="h-6 w-6 flex items-center justify-center rounded-full bg-indigo-100">
                                                        <PermissionIcon className="h-3 w-3 text-indigo-600" />
                                                    </div>
                                                </div>
                                                <div className="ml-2 overflow-hidden">
                                                    <p className="text-xs font-medium text-gray-900 truncate">
                                                        {perm.display_name || perm.name}
                                                    </p>
                                                    <p className="text-[10px] text-gray-500 font-mono truncate">
                                                        {perm.name}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        ))}

                        {Object.keys(groupedPermissions).length === 0 && (
                            <div className="bg-white shadow rounded-lg p-8 text-center">
                                <p className="text-xs text-gray-500">No permissions found</p>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
};

const PermissionIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
    </svg>
);

export default PermissionsPage;
