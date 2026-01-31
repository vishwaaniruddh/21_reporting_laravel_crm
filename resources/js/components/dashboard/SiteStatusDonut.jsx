import { PieChart, Pie, Cell, ResponsiveContainer, Legend, Tooltip } from 'recharts';

/**
 * SiteStatusDonut Component
 * Displays site status distribution as a donut chart
 */
const SiteStatusDonut = ({ data }) => {
    const chartData = [
        { name: 'Online', value: data.online, color: '#10b981' },
        { name: 'Offline', value: data.offline, color: '#ef4444' },
        { name: 'Maintenance', value: data.maintenance, color: '#f59e0b' },
    ];

    const COLORS = chartData.map(item => item.color);

    const renderCustomLabel = ({ cx, cy }) => {
        return (
            <text x={cx} y={cy} textAnchor="middle" dominantBaseline="middle">
                <tspan x={cx} dy="-0.5em" fontSize="24" fontWeight="bold" fill="#111827">
                    {data.total}
                </tspan>
                <tspan x={cx} dy="1.5em" fontSize="12" fill="#6b7280">
                    Total Sites
                </tspan>
            </text>
        );
    };

    return (
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-6">Site Status Distribution</h3>
            
            <ResponsiveContainer width="100%" height={300}>
                <PieChart>
                    <Pie
                        data={chartData}
                        cx="50%"
                        cy="50%"
                        innerRadius={60}
                        outerRadius={100}
                        paddingAngle={2}
                        dataKey="value"
                        label={renderCustomLabel}
                        labelLine={false}
                    >
                        {chartData.map((entry, index) => (
                            <Cell key={`cell-${index}`} fill={COLORS[index]} />
                        ))}
                    </Pie>
                    <Tooltip 
                        contentStyle={{ 
                            backgroundColor: '#fff', 
                            border: '1px solid #e5e7eb',
                            borderRadius: '8px',
                            fontSize: '12px'
                        }}
                    />
                    <Legend 
                        verticalAlign="bottom" 
                        height={36}
                        wrapperStyle={{ fontSize: '12px' }}
                    />
                </PieChart>
            </ResponsiveContainer>

            <div className="grid grid-cols-3 gap-4 mt-4">
                <div className="text-center">
                    <div className="flex items-center justify-center gap-2 mb-1">
                        <div className="w-3 h-3 rounded-full bg-green-500"></div>
                        <span className="text-xs text-gray-600">Online</span>
                    </div>
                    <p className="text-lg font-bold text-gray-900">{data.online}</p>
                </div>
                <div className="text-center">
                    <div className="flex items-center justify-center gap-2 mb-1">
                        <div className="w-3 h-3 rounded-full bg-red-500"></div>
                        <span className="text-xs text-gray-600">Offline</span>
                    </div>
                    <p className="text-lg font-bold text-gray-900">{data.offline}</p>
                </div>
                <div className="text-center">
                    <div className="flex items-center justify-center gap-2 mb-1">
                        <div className="w-3 h-3 rounded-full bg-yellow-500"></div>
                        <span className="text-xs text-gray-600">Maintenance</span>
                    </div>
                    <p className="text-lg font-bold text-gray-900">{data.maintenance}</p>
                </div>
            </div>
        </div>
    );
};

export default SiteStatusDonut;
