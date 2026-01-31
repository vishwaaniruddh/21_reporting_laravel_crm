import React, { useState, useEffect } from 'react';
import { databaseService } from '../services';

function DatabaseStatus() {
    const [status, setStatus] = useState({
        mysql: 'checking',
        postgresql: 'checking'
    });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        checkDatabaseStatus();
    }, []);

    const checkDatabaseStatus = async () => {
        try {
            setLoading(true);
            setError(null);
            const statusData = await databaseService.getDatabaseStatus();
            setStatus({
                mysql: statusData.mysql ? 'connected' : 'disconnected',
                postgresql: statusData.postgresql ? 'connected' : 'disconnected'
            });
        } catch (err) {
            console.error('Failed to fetch database status:', err);
            setError(err.message);
            // Set status to disconnected on error
            setStatus({
                mysql: 'disconnected',
                postgresql: 'disconnected'
            });
        } finally {
            setLoading(false);
        }
    };

    const getStatusColor = (status) => {
        switch (status) {
            case 'connected':
                return 'text-green-600 bg-green-100';
            case 'disconnected':
                return 'text-red-600 bg-red-100';
            case 'checking':
                return 'text-yellow-600 bg-yellow-100';
            default:
                return 'text-gray-600 bg-gray-100';
        }
    };

    const getStatusText = (status) => {
        if (loading) return 'checking...';
        return status;
    };

    return (
        <div className="bg-white rounded-lg shadow-md p-6 mb-6">
            <div className="flex items-center justify-between mb-4">
                <h2 className="text-xl font-semibold text-gray-900">
                    Database Connection Status
                </h2>
                <button
                    onClick={checkDatabaseStatus}
                    disabled={loading}
                    className="px-3 py-1 text-sm bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50"
                >
                    {loading ? 'Checking...' : 'Refresh'}
                </button>
            </div>
            
            {error && (
                <div className="mb-4 p-3 bg-red-100 border border-red-400 text-red-700 rounded">
                    Error: {error}
                </div>
            )}
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="flex items-center justify-between p-4 border rounded-lg">
                    <div>
                        <h3 className="font-medium text-gray-900">MySQL Database</h3>
                        <p className="text-sm text-gray-600">Primary database</p>
                    </div>
                    <span className={`px-3 py-1 rounded-full text-sm font-medium ${getStatusColor(status.mysql)}`}>
                        {getStatusText(status.mysql)}
                    </span>
                </div>
                <div className="flex items-center justify-between p-4 border rounded-lg">
                    <div>
                        <h3 className="font-medium text-gray-900">PostgreSQL Database</h3>
                        <p className="text-sm text-gray-600">Target database</p>
                    </div>
                    <span className={`px-3 py-1 rounded-full text-sm font-medium ${getStatusColor(status.postgresql)}`}>
                        {getStatusText(status.postgresql)}
                    </span>
                </div>
            </div>
        </div>
    );
}

export default DatabaseStatus;