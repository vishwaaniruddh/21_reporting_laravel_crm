import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { format, parseISO } from 'date-fns';

/**
 * AlertTrendChart Component
 * Displays Total Alerts vs VM Alerts daily comparison
 */
const AlertTrendChart = ({ data }) => {
    const formattedData = data.map(item => ({
        ...item,
        date: format(parseISO(item.date), 'MMM dd'),
    }));

    return (
        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-6">Alert Trends - Total vs VM Alerts</h3>
            
            <ResponsiveContainer width="100%" height={300}>
                <LineChart data={formattedData}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                    <XAxis 
                        dataKey="date" 
                        stroke="#6b7280"
                        style={{ fontSize: '12px' }}
                    />
                    <YAxis 
                        stroke="#6b7280"
                        style={{ fontSize: '12px' }}
                    />
                    <Tooltip 
                        contentStyle={{ 
                            backgroundColor: '#fff', 
                            border: '1px solid #e5e7eb',
                            borderRadius: '8px',
                            fontSize: '12px'
                        }}
                    />
                    <Legend 
                        wrapperStyle={{ fontSize: '12px' }}
                    />
                    <Line 
                        type="monotone" 
                        dataKey="total" 
                        stroke="#3b82f6" 
                        strokeWidth={2}
                        dot={{ fill: '#3b82f6', r: 4 }}
                        activeDot={{ r: 6 }}
                        name="Total Alerts"
                    />
                    <Line 
                        type="monotone" 
                        dataKey="vm_alerts" 
                        stroke="#f59e0b" 
                        strokeWidth={2}
                        dot={{ fill: '#f59e0b', r: 4 }}
                        activeDot={{ r: 6 }}
                        name="VM Alerts"
                    />
                </LineChart>
            </ResponsiveContainer>
        </div>
    );
};

export default AlertTrendChart;
