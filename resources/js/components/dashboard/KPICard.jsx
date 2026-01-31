import { TrendingUp, TrendingDown, Minus } from 'lucide-react';

/**
 * KPICard Component
 * Displays a key performance indicator with icon, value, and trend
 */
const KPICard = ({ title, value, icon: Icon, trend, trendValue, color = 'blue', suffix = '' }) => {
    const colorClasses = {
        blue: 'bg-blue-50 text-blue-600',
        green: 'bg-green-50 text-green-600',
        yellow: 'bg-yellow-50 text-yellow-600',
        red: 'bg-red-50 text-red-600',
        purple: 'bg-purple-50 text-purple-600',
    };

    const getTrendIcon = () => {
        if (trend === 'up') return <TrendingUp className="w-3 h-3" />;
        if (trend === 'down') return <TrendingDown className="w-3 h-3" />;
        return <Minus className="w-3 h-3" />;
    };

    const getTrendColor = () => {
        if (trend === 'up') return 'text-green-600';
        if (trend === 'down') return 'text-red-600';
        return 'text-gray-600';
    };

    return (
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 hover:shadow-md transition-shadow">
            <div className="flex items-center justify-between mb-3">
                <div className={`w-10 h-10 rounded-lg ${colorClasses[color]} flex items-center justify-center`}>
                    <Icon className="w-5 h-5" />
                </div>
                {trendValue && (
                    <div className={`flex items-center gap-1 ${getTrendColor()}`}>
                        {getTrendIcon()}
                        <span className="text-xs font-semibold">{trendValue}%</span>
                    </div>
                )}
            </div>
            <h3 className="text-xs font-medium text-gray-600 mb-1">{title}</h3>
            <p className="text-xl font-bold text-gray-900">
                {value}{suffix}
            </p>
        </div>
    );
};

export default KPICard;
