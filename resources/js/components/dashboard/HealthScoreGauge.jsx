/**
 * HealthScoreGauge Component
 * Displays overall health score as a circular gauge
 */
const HealthScoreGauge = ({ score, status, components }) => {
    const getStatusColor = () => {
        if (status === 'excellent') return 'text-green-600';
        if (status === 'good') return 'text-blue-600';
        if (status === 'fair') return 'text-yellow-600';
        return 'text-red-600';
    };

    const getStatusBgColor = () => {
        if (status === 'excellent') return 'bg-green-100';
        if (status === 'good') return 'bg-blue-100';
        if (status === 'fair') return 'bg-yellow-100';
        return 'bg-red-100';
    };

    const circumference = 2 * Math.PI * 70;
    const strokeDashoffset = circumference - (score / 100) * circumference;

    return (
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-6">Overall Health Score</h3>
            
            <div className="flex items-center justify-center mb-6 overflow-visible">
                <div className="relative" style={{ width: '192px', height: '192px' }}>
                    <svg 
                        className="transform -rotate-90" 
                        style={{ width: '192px', height: '192px' }}
                        viewBox="0 0 192 192"
                    >
                        <circle
                            cx="96"
                            cy="96"
                            r="70"
                            stroke="currentColor"
                            strokeWidth="12"
                            fill="transparent"
                            className="text-gray-200"
                        />
                        <circle
                            cx="96"
                            cy="96"
                            r="70"
                            stroke="currentColor"
                            strokeWidth="12"
                            fill="transparent"
                            strokeDasharray={circumference}
                            strokeDashoffset={strokeDashoffset}
                            className={getStatusColor()}
                            strokeLinecap="round"
                        />
                    </svg>
                    <div className="absolute inset-0 flex flex-col items-center justify-center">
                        <span className={`text-4xl font-bold ${getStatusColor()}`}>{score}</span>
                        <span className="text-sm text-gray-600 mt-1">out of 100</span>
                    </div>
                </div>
            </div>

            <div className="flex justify-center mb-6">
                <div className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold ${getStatusBgColor()} ${getStatusColor()}`}>
                    {status.charAt(0).toUpperCase() + status.slice(1)}
                </div>
            </div>

            {components && (
                <div className="space-y-3 mt-4">
                    <div className="flex items-center justify-between">
                        <span className="text-sm text-gray-600">Uptime</span>
                        <span className="text-sm font-semibold text-gray-900">{components.uptime}%</span>
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-sm text-gray-600">SLA Compliance</span>
                        <span className="text-sm font-semibold text-gray-900">{components.sla}%</span>
                    </div>
                    <div className="flex items-center justify-between">
                        <span className="text-sm text-gray-600">Response Time</span>
                        <span className="text-sm font-semibold text-gray-900">{components.response_time}%</span>
                    </div>
                </div>
            )}
        </div>
    );
};

export default HealthScoreGauge;
