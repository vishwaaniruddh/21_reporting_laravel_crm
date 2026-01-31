import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';

/**
 * TopSitesBar Component
 * Displays top sites by alert volume as horizontal bar chart
 */
const TopSitesBar = ({ data }) => {
    return (
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-6">Top 10 Sites by Alert Volume</h3>
            
            <ResponsiveContainer width="100%" height={400}>
                <BarChart data={data} layout="vertical">
                    <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                    <XAxis 
                        type="number" 
                        stroke="#6b7280"
                        style={{ fontSize: '12px' }}
                    />
                    <YAxis 
                        type="category" 
                        dataKey="atmid" 
                        width={100}
                        stroke="#6b7280"
                        style={{ fontSize: '11px' }}
                    />
                    <Tooltip 
                        contentStyle={{ 
                            backgroundColor: '#fff', 
                            border: '1px solid #e5e7eb',
                            borderRadius: '8px',
                            fontSize: '12px'
                        }}
                        content={({ active, payload }) => {
                            if (active && payload && payload.length) {
                                const data = payload[0].payload;
                                return (
                                    <div className="bg-white p-3 border border-gray-200 rounded-lg shadow-lg">
                                        <p className="font-semibold text-gray-900">{data.atmid}</p>
                                        <p className="text-sm text-gray-600">{data.name}</p>
                                        <p className="text-sm text-gray-600">{data.customer}</p>
                                        <p className="text-sm text-gray-600">{data.city}</p>
                                        <p className="text-sm font-semibold text-blue-600 mt-1">
                                            Alerts: {data.alert_count}
                                        </p>
                                    </div>
                                );
                            }
                            return null;
                        }}
                    />
                    <Bar 
                        dataKey="alert_count" 
                        fill="#3b82f6"
                        radius={[0, 4, 4, 0]}
                    />
                </BarChart>
            </ResponsiveContainer>
        </div>
    );
};

export default TopSitesBar;
