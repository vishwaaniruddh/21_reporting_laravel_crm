import { AlertCircle, Clock } from 'lucide-react';

/**
 * CriticalAlertsList Component
 * Displays active critical issues in a list format
 */
const CriticalAlertsList = ({ data }) => {
    const getStatusColor = (status) => {
        switch (status) {
            case 'open': return 'bg-red-100 text-red-700';
            case 'in_progress': return 'bg-yellow-100 text-yellow-700';
            case 'pending': return 'bg-blue-100 text-blue-700';
            default: return 'bg-gray-100 text-gray-700';
        }
    };

    return (
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <div className="flex items-center justify-between mb-6">
                <h3 className="text-lg font-semibold text-gray-900">Active Critical Issues</h3>
                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">
                    {data.length} Active
                </span>
            </div>
            
            <div className="space-y-3 max-h-96 overflow-y-auto">
                {data.length === 0 ? (
                    <div className="text-center py-8 text-gray-500">
                        <AlertCircle className="w-12 h-12 mx-auto mb-2 text-gray-400" />
                        <p className="text-sm">No critical issues at the moment</p>
                    </div>
                ) : (
                    data.map((alert) => (
                        <div 
                            key={alert.id} 
                            className="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors"
                        >
                            <div className="flex items-start justify-between mb-2">
                                <div className="flex items-start gap-3 flex-1">
                                    <AlertCircle className="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" />
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-semibold text-gray-900 truncate">
                                            {alert.atmid} - {alert.site_name}
                                        </p>
                                        <p className="text-xs text-gray-600 mt-0.5">{alert.customer}</p>
                                    </div>
                                </div>
                                <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${getStatusColor(alert.status)}`}>
                                    {alert.status.replace('_', ' ')}
                                </span>
                            </div>
                            <p className="text-sm text-gray-700 mb-2 ml-8">{alert.message}</p>
                            <div className="flex items-center gap-4 ml-8 text-xs text-gray-500">
                                <div className="flex items-center gap-1">
                                    <Clock className="w-3 h-3" />
                                    <span>{alert.duration}</span>
                                </div>
                                <span className="text-gray-400">•</span>
                                <span className="font-medium text-red-600">{alert.alert_type}</span>
                            </div>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
};

export default CriticalAlertsList;
