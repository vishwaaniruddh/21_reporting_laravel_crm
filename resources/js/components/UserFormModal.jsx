import { useState, useEffect } from 'react';
import { useAuth } from '../contexts/AuthContext';
import { userService } from '../services/userService';

/**
 * UserFormModal component
 * Create/edit user form with role selection filtered by current user's role.
 * 
 * Requirements: 2.1, 4.1
 */
const UserFormModal = ({ isOpen, onClose, user = null, onSuccess }) => {
    const { hasRole } = useAuth();
    const isEditing = !!user;
    
    const [formData, setFormData] = useState({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role_id: '',
    });
    const [roles, setRoles] = useState([]);
    const [errors, setErrors] = useState({});
    const [loading, setLoading] = useState(false);
    const [rolesLoading, setRolesLoading] = useState(true);

    // Fetch available roles on mount
    useEffect(() => {
        if (isOpen) {
            fetchRoles();
        }
    }, [isOpen]);

    // Populate form when editing - need to find role_id from role name after roles are loaded
    useEffect(() => {
        if (user) {
            // Find the role_id from the user's role name
            let roleId = user.role_id || '';
            if (!roleId && user.role && roles.length > 0) {
                const matchingRole = roles.find(r => r.name === user.role);
                if (matchingRole) {
                    roleId = matchingRole.id;
                }
            }
            
            setFormData({
                name: user.name || '',
                email: user.email || '',
                password: '',
                password_confirmation: '',
                role_id: roleId,
            });
        } else {
            setFormData({
                name: '',
                email: '',
                password: '',
                password_confirmation: '',
                role_id: '',
            });
        }
        setErrors({});
    }, [user, isOpen, roles]);

    const fetchRoles = async () => {
        try {
            setRolesLoading(true);
            const response = await userService.getRoles();
            let availableRoles = response.data || response || [];
            
            // Filter roles based on current user's role
            // Admin can only assign Manager role
            // Superadmin can assign any role
            if (hasRole('admin')) {
                availableRoles = availableRoles.filter(role => role.name === 'manager');
            }
            
            setRoles(availableRoles);
            
            // Set default role if creating new user
            if (!user && availableRoles.length > 0) {
                const managerRole = availableRoles.find(r => r.name === 'manager');
                if (managerRole) {
                    setFormData(prev => ({ ...prev, role_id: managerRole.id }));
                } else {
                    setFormData(prev => ({ ...prev, role_id: availableRoles[0].id }));
                }
            }
        } catch (error) {
            console.error('Failed to fetch roles:', error);
            setErrors({ general: 'Failed to load roles. Please try again.' });
        } finally {
            setRolesLoading(false);
        }
    };

    const handleChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({ ...prev, [name]: value }));
        if (errors[name]) {
            setErrors(prev => ({ ...prev, [name]: null }));
        }
    };

    const validateForm = () => {
        const newErrors = {};
        
        if (!formData.name.trim()) {
            newErrors.name = 'Name is required';
        }
        
        if (!formData.email.trim()) {
            newErrors.email = 'Email is required';
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) {
            newErrors.email = 'Please enter a valid email address';
        }
        
        if (!isEditing) {
            if (!formData.password) {
                newErrors.password = 'Password is required';
            } else if (formData.password.length < 8) {
                newErrors.password = 'Password must be at least 8 characters';
            }
            
            if (formData.password !== formData.password_confirmation) {
                newErrors.password_confirmation = 'Passwords do not match';
            }
        } else if (formData.password) {
            if (formData.password.length < 8) {
                newErrors.password = 'Password must be at least 8 characters';
            }
            if (formData.password !== formData.password_confirmation) {
                newErrors.password_confirmation = 'Passwords do not match';
            }
        }
        
        if (!formData.role_id) {
            newErrors.role_id = 'Role is required';
        }
        
        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        
        if (!validateForm()) {
            return;
        }
        
        setLoading(true);
        setErrors({});
        
        try {
            // Find the selected role name from the roles array
            const selectedRole = roles.find(r => r.id.toString() === formData.role_id.toString());
            
            const submitData = {
                name: formData.name,
                email: formData.email,
                role: selectedRole?.name, // API expects role name, not role_id
            };
            
            if (formData.password) {
                submitData.password = formData.password;
                submitData.password_confirmation = formData.password_confirmation;
            }
            
            if (isEditing) {
                await userService.updateUser(user.id, submitData);
            } else {
                await userService.createUser(submitData);
            }
            
            onSuccess?.();
        } catch (error) {
            if (error.errors) {
                setErrors(error.errors);
            } else {
                setErrors({ general: error.message || 'An error occurred. Please try again.' });
            }
        } finally {
            setLoading(false);
        }
    };

    const handleClose = () => {
        setFormData({
            name: '',
            email: '',
            password: '',
            password_confirmation: '',
            role_id: '',
        });
        setErrors({});
        onClose?.();
    };

    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                <div className="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" onClick={handleClose} />
                
                <div className="relative inline-block w-full max-w-md p-6 my-8 overflow-hidden text-left align-middle transition-all transform bg-white shadow-xl rounded-lg">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="text-lg font-medium leading-6 text-gray-900">
                            {isEditing ? 'Edit User' : 'Create New User'}
                        </h3>
                        <button onClick={handleClose} className="text-gray-400 hover:text-gray-500">
                            <CloseIcon className="w-5 h-5" />
                        </button>
                    </div>

                    {errors.general && (
                        <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-md">
                            <p className="text-sm text-red-600">{errors.general}</p>
                        </div>
                    )}

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div>
                            <label htmlFor="name" className="block text-sm font-medium text-gray-700">
                                Name <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                value={formData.name}
                                onChange={handleChange}
                                className={`mt-1 block w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm ${errors.name ? 'border-red-300' : 'border-gray-300'}`}
                                placeholder="Enter full name"
                            />
                            {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name}</p>}
                        </div>

                        <div>
                            <label htmlFor="email" className="block text-sm font-medium text-gray-700">
                                Email <span className="text-red-500">*</span>
                            </label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                value={formData.email}
                                onChange={handleChange}
                                className={`mt-1 block w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm ${errors.email ? 'border-red-300' : 'border-gray-300'}`}
                                placeholder="Enter email address"
                            />
                            {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
                        </div>

                        <div>
                            <label htmlFor="role_id" className="block text-sm font-medium text-gray-700">
                                Role <span className="text-red-500">*</span>
                            </label>
                            {rolesLoading ? (
                                <div className="mt-1 flex items-center text-sm text-gray-500">
                                    <LoadingSpinner className="w-4 h-4 mr-2" />
                                    Loading roles...
                                </div>
                            ) : (
                                <select
                                    id="role_id"
                                    name="role_id"
                                    value={formData.role_id}
                                    onChange={handleChange}
                                    className={`mt-1 block w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm ${errors.role_id ? 'border-red-300' : 'border-gray-300'}`}
                                >
                                    <option value="">Select a role</option>
                                    {roles.map(role => (
                                        <option key={role.id} value={role.id}>
                                            {role.display_name || role.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            {errors.role_id && <p className="mt-1 text-sm text-red-600">{errors.role_id}</p>}
                        </div>

                        <div>
                            <label htmlFor="password" className="block text-sm font-medium text-gray-700">
                                Password {!isEditing && <span className="text-red-500">*</span>}
                            </label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                value={formData.password}
                                onChange={handleChange}
                                className={`mt-1 block w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm ${errors.password ? 'border-red-300' : 'border-gray-300'}`}
                                placeholder={isEditing ? 'Leave blank to keep current password' : 'Enter password'}
                            />
                            {errors.password && <p className="mt-1 text-sm text-red-600">{errors.password}</p>}
                            {!isEditing && <p className="mt-1 text-xs text-gray-500">Minimum 8 characters</p>}
                        </div>

                        <div>
                            <label htmlFor="password_confirmation" className="block text-sm font-medium text-gray-700">
                                Confirm Password {!isEditing && <span className="text-red-500">*</span>}
                            </label>
                            <input
                                type="password"
                                id="password_confirmation"
                                name="password_confirmation"
                                value={formData.password_confirmation}
                                onChange={handleChange}
                                className={`mt-1 block w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm ${errors.password_confirmation ? 'border-red-300' : 'border-gray-300'}`}
                                placeholder="Confirm password"
                            />
                            {errors.password_confirmation && <p className="mt-1 text-sm text-red-600">{errors.password_confirmation}</p>}
                        </div>

                        <div className="flex items-center justify-end gap-3 pt-4 border-t border-gray-200">
                            <button
                                type="button"
                                onClick={handleClose}
                                className="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={loading || rolesLoading}
                                className="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md shadow-sm hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {loading ? (
                                    <>
                                        <LoadingSpinner className="w-4 h-4 mr-2" />
                                        {isEditing ? 'Updating...' : 'Creating...'}
                                    </>
                                ) : (
                                    isEditing ? 'Update User' : 'Create User'
                                )}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    );
};

const CloseIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
    </svg>
);

const LoadingSpinner = ({ className }) => (
    <svg className={`animate-spin ${className}`} fill="none" viewBox="0 0 24 24">
        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
    </svg>
);

export default UserFormModal;
