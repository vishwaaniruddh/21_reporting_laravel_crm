import { useState, useEffect } from 'react';
import {
    createConfiguration,
    updateConfiguration,
    testConfiguration,
} from '../services/tableSyncService';

/**
 * TableSyncConfigurationForm Component
 * 
 * Form for creating/editing table sync configurations.
 * - Table name input with validation
 * - Column mapping editor
 * - Batch size and schedule inputs
 * 
 * ⚠️ NO DELETION FROM MYSQL: Form saves to PostgreSQL config table
 * 
 * Requirements: 8.4
 */
const TableSyncConfigurationForm = ({ configuration, onSave, onCancel }) => {
    const isEditing = !!configuration;
    
    const [formData, setFormData] = useState({
        name: '',
        source_table: '',
        target_table: '',
        primary_key_column: 'id',
        sync_marker_column: 'synced_at',
        column_mappings: {},
        excluded_columns: [],
        batch_size: 10000,
        schedule: '',
        is_enabled: true,
    });
    
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState(null);
    const [message, setMessage] = useState(null);
    const [showColumnMappingEditor, setShowColumnMappingEditor] = useState(false);
    const [newMapping, setNewMapping] = useState({ source: '', target: '' });
    const [newExcludedColumn, setNewExcludedColumn] = useState('');

    useEffect(() => {
        if (configuration) {
            setFormData({
                name: configuration.name || '',
                source_table: configuration.source_table || '',
                target_table: configuration.target_table || '',
                primary_key_column: configuration.primary_key_column || 'id',
                sync_marker_column: configuration.sync_marker_column || 'synced_at',
                column_mappings: configuration.column_mappings || {},
                excluded_columns: configuration.excluded_columns || [],
                batch_size: configuration.batch_size || 10000,
                schedule: configuration.schedule || '',
                is_enabled: configuration.is_enabled ?? true,
            });
        }
    }, [configuration]);

    const handleInputChange = (field, value) => {
        setFormData(prev => ({ ...prev, [field]: value }));
        setErrors(prev => ({ ...prev, [field]: null }));
        setMessage(null);
    };

    const handleAddMapping = () => {
        if (newMapping.source && newMapping.target) {
            setFormData(prev => ({
                ...prev,
                column_mappings: {
                    ...prev.column_mappings,
                    [newMapping.source]: newMapping.target,
                },
            }));
            setNewMapping({ source: '', target: '' });
        }
    };

    const handleRemoveMapping = (sourceColumn) => {
        setFormData(prev => {
            const newMappings = { ...prev.column_mappings };
            delete newMappings[sourceColumn];
            return { ...prev, column_mappings: newMappings };
        });
    };

    const handleAddExcludedColumn = () => {
        if (newExcludedColumn && !formData.excluded_columns.includes(newExcludedColumn)) {
            setFormData(prev => ({
                ...prev,
                excluded_columns: [...prev.excluded_columns, newExcludedColumn],
            }));
            setNewExcludedColumn('');
        }
    };

    const handleRemoveExcludedColumn = (column) => {
        setFormData(prev => ({
            ...prev,
            excluded_columns: prev.excluded_columns.filter(c => c !== column),
        }));
    };


    const validateForm = () => {
        const newErrors = {};
        
        if (!formData.name.trim()) {
            newErrors.name = 'Name is required';
        }
        
        if (!formData.source_table.trim()) {
            newErrors.source_table = 'Source table is required';
        }
        
        if (formData.batch_size < 100 || formData.batch_size > 100000) {
            newErrors.batch_size = 'Batch size must be between 100 and 100,000';
        }
        
        if (formData.schedule && !isValidCron(formData.schedule)) {
            newErrors.schedule = 'Invalid cron expression';
        }
        
        setErrors(newErrors);
        return Object.keys(newErrors).length === 0;
    };

    const isValidCron = (expression) => {
        if (!expression) return true;
        const parts = expression.trim().split(/\s+/);
        return parts.length === 5;
    };

    const handleTest = async () => {
        if (!formData.source_table.trim()) {
            setErrors({ source_table: 'Source table is required for testing' });
            return;
        }

        setTesting(true);
        setTestResult(null);
        setMessage(null);

        try {
            const response = await testConfiguration(formData);
            if (response.success) {
                setTestResult(response.data);
                setMessage({ type: 'success', text: 'Configuration test passed!' });
            } else {
                setMessage({ type: 'error', text: response.error?.message || 'Test failed' });
            }
        } catch (err) {
            const errorDetails = err.response?.data?.error?.details;
            if (errorDetails && typeof errorDetails === 'object') {
                setErrors(errorDetails);
            }
            setMessage({
                type: 'error',
                text: err.response?.data?.error?.message || 'Failed to test configuration',
            });
        } finally {
            setTesting(false);
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        
        if (!validateForm()) {
            return;
        }

        setSaving(true);
        setMessage(null);

        try {
            const dataToSend = {
                ...formData,
                target_table: formData.target_table || formData.source_table,
            };

            let response;
            if (isEditing) {
                response = await updateConfiguration(configuration.id, dataToSend);
            } else {
                response = await createConfiguration(dataToSend);
            }

            if (response.success) {
                setMessage({
                    type: 'success',
                    text: `Configuration ${isEditing ? 'updated' : 'created'} successfully`,
                });
                if (onSave) {
                    onSave(response.data.configuration);
                }
            } else {
                setMessage({ type: 'error', text: response.error?.message });
            }
        } catch (err) {
            const errorDetails = err.response?.data?.error?.details;
            if (errorDetails && typeof errorDetails === 'object') {
                setErrors(errorDetails);
            }
            setMessage({
                type: 'error',
                text: err.response?.data?.error?.message || 'Failed to save configuration',
            });
        } finally {
            setSaving(false);
        }
    };


    return (
        <div className="bg-white rounded-lg shadow">
            {/* Header */}
            <div className="px-6 py-4 border-b border-gray-200">
                <h2 className="text-lg font-semibold text-gray-900">
                    {isEditing ? 'Edit Configuration' : 'New Table Sync Configuration'}
                </h2>
                <p className="text-sm text-gray-500 mt-1">
                    Configure synchronization from MySQL to PostgreSQL
                </p>
            </div>

            {/* Messages */}
            {message && (
                <div className={`mx-6 mt-4 p-3 rounded-md ${
                    message.type === 'success' ? 'bg-green-50 border border-green-200 text-green-700' :
                    'bg-red-50 border border-red-200 text-red-700'
                }`}>
                    <p className="text-sm">{message.text}</p>
                </div>
            )}

            <form onSubmit={handleSubmit} className="p-6 space-y-6">
                {/* Basic Information */}
                <div>
                    <h3 className="text-sm font-medium text-gray-900 mb-3">Basic Information</h3>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Configuration Name *
                            </label>
                            <input
                                type="text"
                                value={formData.name}
                                onChange={(e) => handleInputChange('name', e.target.value)}
                                className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                                    errors.name ? 'border-red-300' : 'border-gray-300'
                                }`}
                                placeholder="e.g., Orders Sync"
                            />
                            {errors.name && (
                                <p className="text-xs text-red-600 mt-1">{errors.name}</p>
                            )}
                        </div>
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Source Table (MySQL) *
                            </label>
                            <input
                                type="text"
                                value={formData.source_table}
                                onChange={(e) => handleInputChange('source_table', e.target.value)}
                                disabled={isEditing}
                                className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono ${
                                    errors.source_table ? 'border-red-300' : 'border-gray-300'
                                } ${isEditing ? 'bg-gray-100' : ''}`}
                                placeholder="e.g., orders"
                            />
                            {errors.source_table && (
                                <p className="text-xs text-red-600 mt-1">{errors.source_table}</p>
                            )}
                            {isEditing && (
                                <p className="text-xs text-gray-500 mt-1">Source table cannot be changed</p>
                            )}
                        </div>
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Target Table (PostgreSQL)
                            </label>
                            <input
                                type="text"
                                value={formData.target_table}
                                onChange={(e) => handleInputChange('target_table', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
                                placeholder="Same as source if empty"
                            />
                            <p className="text-xs text-gray-500 mt-1">
                                Leave empty to use the same name as source
                            </p>
                        </div>
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Primary Key Column
                            </label>
                            <input
                                type="text"
                                value={formData.primary_key_column}
                                onChange={(e) => handleInputChange('primary_key_column', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
                                placeholder="id"
                            />
                        </div>
                    </div>
                </div>

                {/* Sync Settings */}
                <div>
                    <h3 className="text-sm font-medium text-gray-900 mb-3">Sync Settings</h3>
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Batch Size
                            </label>
                            <input
                                type="number"
                                min="100"
                                max="100000"
                                step="100"
                                value={formData.batch_size}
                                onChange={(e) => handleInputChange('batch_size', parseInt(e.target.value) || 10000)}
                                className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 ${
                                    errors.batch_size ? 'border-red-300' : 'border-gray-300'
                                }`}
                            />
                            {errors.batch_size && (
                                <p className="text-xs text-red-600 mt-1">{errors.batch_size}</p>
                            )}
                            <p className="text-xs text-gray-500 mt-1">100 - 100,000 records per batch</p>
                        </div>
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Schedule (Cron)
                            </label>
                            <input
                                type="text"
                                value={formData.schedule}
                                onChange={(e) => handleInputChange('schedule', e.target.value)}
                                className={`w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono ${
                                    errors.schedule ? 'border-red-300' : 'border-gray-300'
                                }`}
                                placeholder="*/15 * * * *"
                            />
                            {errors.schedule && (
                                <p className="text-xs text-red-600 mt-1">{errors.schedule}</p>
                            )}
                            <p className="text-xs text-gray-500 mt-1">Leave empty for manual sync only</p>
                        </div>
                        <div>
                            <label className="block text-sm text-gray-600 mb-1">
                                Sync Marker Column
                            </label>
                            <input
                                type="text"
                                value={formData.sync_marker_column}
                                onChange={(e) => handleInputChange('sync_marker_column', e.target.value)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 font-mono"
                                placeholder="synced_at"
                            />
                        </div>
                    </div>
                    <div className="mt-4">
                        <label className="flex items-center">
                            <input
                                type="checkbox"
                                checked={formData.is_enabled}
                                onChange={(e) => handleInputChange('is_enabled', e.target.checked)}
                                className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                            />
                            <span className="ml-2 text-sm text-gray-700">Enable sync</span>
                        </label>
                    </div>
                </div>


                {/* Column Mappings */}
                <div>
                    <div className="flex items-center justify-between mb-3">
                        <h3 className="text-sm font-medium text-gray-900">Column Mappings</h3>
                        <button
                            type="button"
                            onClick={() => setShowColumnMappingEditor(!showColumnMappingEditor)}
                            className="text-sm text-blue-600 hover:text-blue-800"
                        >
                            {showColumnMappingEditor ? 'Hide Editor' : 'Show Editor'}
                        </button>
                    </div>
                    
                    {showColumnMappingEditor && (
                        <div className="bg-gray-50 rounded-md p-4 space-y-4">
                            {/* Add New Mapping */}
                            <div className="flex items-end space-x-2">
                                <div className="flex-1">
                                    <label className="block text-xs text-gray-500 mb-1">Source Column</label>
                                    <input
                                        type="text"
                                        value={newMapping.source}
                                        onChange={(e) => setNewMapping(prev => ({ ...prev, source: e.target.value }))}
                                        className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono"
                                        placeholder="source_column"
                                    />
                                </div>
                                <div className="text-gray-400">→</div>
                                <div className="flex-1">
                                    <label className="block text-xs text-gray-500 mb-1">Target Column</label>
                                    <input
                                        type="text"
                                        value={newMapping.target}
                                        onChange={(e) => setNewMapping(prev => ({ ...prev, target: e.target.value }))}
                                        className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono"
                                        placeholder="target_column"
                                    />
                                </div>
                                <button
                                    type="button"
                                    onClick={handleAddMapping}
                                    disabled={!newMapping.source || !newMapping.target}
                                    className="px-3 py-2 bg-blue-600 text-white rounded-md text-sm hover:bg-blue-700 disabled:bg-gray-300"
                                >
                                    Add
                                </button>
                            </div>

                            {/* Current Mappings */}
                            {Object.keys(formData.column_mappings).length > 0 && (
                                <div className="space-y-2">
                                    <p className="text-xs text-gray-500">Current Mappings:</p>
                                    {Object.entries(formData.column_mappings).map(([source, target]) => (
                                        <div key={source} className="flex items-center justify-between bg-white px-3 py-2 rounded border">
                                            <span className="text-sm font-mono">
                                                {source} → {target}
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => handleRemoveMapping(source)}
                                                className="text-red-600 hover:text-red-800"
                                            >
                                                <XIcon className="w-4 h-4" />
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                    
                    {Object.keys(formData.column_mappings).length === 0 && !showColumnMappingEditor && (
                        <p className="text-sm text-gray-500">
                            No custom mappings. Columns will be synced with the same names.
                        </p>
                    )}
                </div>

                {/* Excluded Columns */}
                <div>
                    <h3 className="text-sm font-medium text-gray-900 mb-3">Excluded Columns</h3>
                    <div className="flex items-end space-x-2 mb-3">
                        <div className="flex-1">
                            <input
                                type="text"
                                value={newExcludedColumn}
                                onChange={(e) => setNewExcludedColumn(e.target.value)}
                                className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm font-mono"
                                placeholder="column_name"
                            />
                        </div>
                        <button
                            type="button"
                            onClick={handleAddExcludedColumn}
                            disabled={!newExcludedColumn}
                            className="px-3 py-2 bg-gray-600 text-white rounded-md text-sm hover:bg-gray-700 disabled:bg-gray-300"
                        >
                            Exclude
                        </button>
                    </div>
                    {formData.excluded_columns.length > 0 ? (
                        <div className="flex flex-wrap gap-2">
                            {formData.excluded_columns.map((column) => (
                                <span
                                    key={column}
                                    className="inline-flex items-center px-2 py-1 bg-gray-100 rounded text-sm font-mono"
                                >
                                    {column}
                                    <button
                                        type="button"
                                        onClick={() => handleRemoveExcludedColumn(column)}
                                        className="ml-1 text-gray-500 hover:text-red-600"
                                    >
                                        <XIcon className="w-3 h-3" />
                                    </button>
                                </span>
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-gray-500">No columns excluded. All columns will be synced.</p>
                    )}
                </div>


                {/* Test Result */}
                {testResult && (
                    <div className="bg-green-50 border border-green-200 rounded-md p-4">
                        <h4 className="text-sm font-medium text-green-800 mb-2">Test Result</h4>
                        <div className="text-sm text-green-700 space-y-1">
                            <p>✓ Source table exists: {testResult.source_table_exists ? 'Yes' : 'No'}</p>
                            {testResult.detected_columns && (
                                <p>✓ Detected columns: {testResult.detected_columns.length}</p>
                            )}
                            {testResult.primary_key && (
                                <p>✓ Primary key: {testResult.primary_key}</p>
                            )}
                        </div>
                    </div>
                )}

                {/* Actions */}
                <div className="flex justify-between pt-4 border-t border-gray-200">
                    <button
                        type="button"
                        onClick={handleTest}
                        disabled={testing || !formData.source_table}
                        className="px-4 py-2 text-sm text-blue-600 border border-blue-600 rounded-md hover:bg-blue-50 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        {testing ? 'Testing...' : 'Test Configuration'}
                    </button>
                    <div className="space-x-3">
                        <button
                            type="button"
                            onClick={onCancel}
                            disabled={saving}
                            className="px-4 py-2 text-sm text-gray-600 hover:text-gray-800 disabled:opacity-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={saving}
                            className="px-4 py-2 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50"
                        >
                            {saving ? 'Saving...' : isEditing ? 'Update Configuration' : 'Create Configuration'}
                        </button>
                    </div>
                </div>
            </form>
        </div>
    );
};

// Icon Components
const XIcon = ({ className }) => (
    <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
    </svg>
);

export default TableSyncConfigurationForm;
