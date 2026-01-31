import { useState, useEffect } from 'react';
import DashboardLayout from '../components/DashboardLayout';
import api from '../services/api';

/**
 * RolesPage component
 * Displays all roles with their associated permissions and allows editing.
 */
const RolesPage = () => {
    const [roles, setRoles] = useState([]);
    const [permissions, setPermissions] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [expandedRole, setExpandedRole] = useState(null);
    const [editingRole, setEditingRole] = useState(null);
    const [selectedPermissions, setSelectedPermissions] = useState([]);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        fetchData();
    }, []);

    const fetchData = async () => {
        try {
            setLoading(true);
            const [rolesResponse, permissionsResponse] = await Promise.all([
                api.get('/roles'),
                api.get('/permissions')
            ]);
            setRoles(rolesResponse.data.data || []);
            setPermissions(permissionsResponse.data.data || []);
        } catch (err) {
            setError('Failed to load data');
            console.error('Error fetching data:', err);
        } finally {
            setLoading(false);
        }
    };

    const toggleExpand = (roleId) => {
        setExpandedRole(expandedRole === roleId ? null : roleId);
    };

    const startEditing = (role) => {
        setEditingRole(role.id);
        setSelectedPermissions(role.permissions?.map(p => p.id) || []);
        setExpandedRole(role.id);
    };

    const cancelEditing = () => {
        setEditingRole(null);
        setSelectedPermissions([]);
    };

    const togglePermission = (permissionId) => {
        setSelectedPermissions(prev => {
            if (prev.includes(permissionId)) {
                return prev.filter(id => id !== permissionId);
            } else {
                return [...prev, permissionId];
            }
        });
    };

    const savePermissions = async () => {
        try {
            setSaving(true);
            await api.post(`/roles/${editingRole}/permissions`, {
                permission_ids: selectedPermissions
            });
            
            // Refresh roles data
            await fetchData();
            setEditingRole(null);
            setSelectedPermissions([]);
        } catch (err) {
            setError('Failed to save permissions');
            console.error('Error saving permissions:', err);
        } finally {
            setSaving(false);
        }
    };

    return (
        <DashboardLayout>
            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-lg font-semibold text-gray-900">Roles Management</h1>
                </div>

                {error && (
                    <div className="p-3 bg-red-50 border border-red-200 rounded-md">
                        <p className="text-xs text-red-600">{error}</p>
                    </div>
                )}

                {loading ? (
                    <div className="flex items-center justify-center py-8">
                        <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-indigo-600"></div>
                        <span className="ml-2 text-xs text-gray-600">Loading roles...</span>
                    </div>
                ) : (
                    <div className="bg-white shadow overflow-hidden rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-4 py-2 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">
                                        Role
                                    </th>
                                    <th className="px-4 py-2 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">
                                        Description
                                    </th>
                                    <th className="px-4 py-2 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">
                                        Permissions
                                    </th>
                                    <th className="px-4 py-2 text-left text-[10px] font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {roles.map((role) => (
                                    <tr key={role.id} className="hover:bg-gray-50">
                                        <td className="px-4 py-2 whitespace-nowrap">
                                            <div className="flex items-center">
                                                <div className="flex-shrink-0 h-7 w-7 flex items-center justify-center rounded-full bg-indigo-100">
                                                    <span className="text-indigo-600 font-medium text-[10px]">
                                                        {role.display_name?.charAt(0) || role.name.charAt(0).toUpperCase()}
                                                    </span>
                                                </div>
                                                <div className="ml-3">
                                                    <div className="text-xs font-medium text-gray-900">
                                                        {role.display_name || role.name}
                                                    </div>
                                                    <div className="text-[10px] text-gray-500">{role.name}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-2">
                                            <div className="text-xs text-gray-900">
                                                {role.description || 'No description'}
                                            </div>
                                        </td>
                                        <td className="px-4 py-2">
                                            <span className="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-green-100 text-green-800">
                                                {role.permissions?.length || 0} permissions
                                            </span>
                                        </td>
                                        <td className="px-4 py-2 whitespace-nowrap text-xs">
                                            {editingRole === role.id ? (
                                                <div className="flex gap-2">
                                                    <button
                                                        onClick={savePermissions}
                                                        disabled={saving}
                                                        className="text-green-600 hover:text-green-900 text-xs font-medium disabled:opacity-50"
                                                    >
                                                        {saving ? 'Saving...' : 'Save'}
                                                    </button>
                                                    <button
                                                        onClick={cancelEditing}
                                                        disabled={saving}
                                                        className="text-gray-600 hover:text-gray-900 text-xs disabled:opacity-50"
                                                    >
                                                        Cancel
                                                    </button>
                                                </div>
                                            ) : (
                                                <div className="flex gap-2">
                                                    <button
                                                        onClick={() => startEditing(role)}
                                                        className="text-indigo-600 hover:text-indigo-900 text-xs font-medium"
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        onClick={() => toggleExpand(role.id)}
                                                        className="text-gray-600 hover:text-gray-900 text-xs"
                                                    >
                                                        {expandedRole === role.id ? 'Hide' : 'View'}
                                                    </button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {roles.length === 0 && (
                                    <tr>
                                        <td colSpan="4" className="px-4 py-8 text-center text-xs text-gray-500">
                                            No roles found
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>

                        {/* Expanded permissions panel */}
                        {expandedRole && (
                            <div className="border-t border-gray-200 bg-gray-50 px-4 py-3">
                                <h4 className="text-xs font-medium text-gray-900 mb-2">
                                    {editingRole === expandedRole ? 'Edit Permissions for' : 'Permissions for'} {roles.find(r => r.id === expandedRole)?.display_name}
                                </h4>
                                
                                {editingRole === expandedRole ? (
                                    /* Edit mode - checkboxes */
                                    <div className="space-y-2">
                                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                                            {permissions.map((perm) => (
                                                <label
                                                    key={perm.id}
                                                    className="flex items-center space-x-2 p-2 rounded hover:bg-gray-100 cursor-pointer"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedPermissions.includes(perm.id)}
                                                        onChange={() => togglePermission(perm.id)}
                                                        className="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded"
                                                    />
                                                    <span className="text-xs text-gray-700">
                                                        {perm.display_name || perm.name}
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                        <div className="mt-3 pt-3 border-t border-gray-200">
                                            <p className="text-xs text-gray-500">
                                                Selected: {selectedPermissions.length} permission(s)
                                            </p>
                                        </div>
                                    </div>
                                ) : (
                                    /* View mode - badges */
                                    <div className="flex flex-wrap gap-1.5">
                                        {roles.find(r => r.id === expandedRole)?.permissions?.map((perm) => (
                                            <span
                                                key={perm.id}
                                                className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-medium bg-indigo-100 text-indigo-800"
                                            >
                                                {perm.display_name || perm.name}
                                            </span>
                                        ))}
                                        {(!roles.find(r => r.id === expandedRole)?.permissions?.length) && (
                                            <span className="text-xs text-gray-500">No permissions assigned</span>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </DashboardLayout>
    );
};

export default RolesPage;
