import DashboardLayout from '../components/DashboardLayout';

const SitesGPSPage = () => {
    return (
        <DashboardLayout>
            <div className="space-y-4">
                <div className="bg-white rounded-lg shadow p-6">
                    <h1 className="text-2xl font-bold text-gray-900 mb-4">GPS Sites</h1>
                    <p className="text-gray-600">GPS tracking sites management.</p>
                </div>
                
                <div className="bg-white rounded-lg shadow p-6">
                    <div className="text-center text-gray-500 py-12">
                        <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <h3 className="mt-2 text-sm font-medium text-gray-900">GPS Sites</h3>
                        <p className="mt-1 text-sm text-gray-500">Content coming soon...</p>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
};

export default SitesGPSPage;
