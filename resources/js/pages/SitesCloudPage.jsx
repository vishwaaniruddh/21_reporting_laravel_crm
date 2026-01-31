import DashboardLayout from '../components/DashboardLayout';

const SitesCloudPage = () => {
    return (
        <DashboardLayout>
            <div className="space-y-4">
                <div className="bg-white rounded-lg shadow p-6">
                    <h1 className="text-2xl font-bold text-gray-900 mb-4">Cloud Sites</h1>
                    <p className="text-gray-600">Cloud-based sites management.</p>
                </div>
                
                <div className="bg-white rounded-lg shadow p-6">
                    <div className="text-center text-gray-500 py-12">
                        <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 15a4 4 0 004 4h9a5 5 0 10-.1-9.999 5.002 5.002 0 10-9.78 2.096A4.001 4.001 0 003 15z" />
                        </svg>
                        <h3 className="mt-2 text-sm font-medium text-gray-900">Cloud Sites</h3>
                        <p className="mt-1 text-sm text-gray-500">Content coming soon...</p>
                    </div>
                </div>
            </div>
        </DashboardLayout>
    );
};

export default SitesCloudPage;
