import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from '../contexts/AuthContext';
import ProtectedRoute from './ProtectedRoute';
import LoginPage from '../pages/LoginPage';
import Dashboard from './Dashboard';
import UserListPage from '../pages/UserListPage';
import RolesPage from '../pages/RolesPage';
import PermissionsPage from '../pages/PermissionsPage';
import TableSyncDashboard from './TableSyncDashboard';
import AlertsReportDashboard from './AlertsReportDashboard';
import VMAlertDashboard from './VMAlertDashboard';
import DownCommunicationDashboard from './DownCommunicationDashboard';
import PartitionManagementDashboard from './PartitionManagementDashboard';
import SitesRMSPage from '../pages/SitesRMSPage';
import SitesDVRPage from '../pages/SitesDVRPage';
import SitesCloudPage from '../pages/SitesCloudPage';
import SitesGPSPage from '../pages/SitesGPSPage';
import ServiceManagementPage from '../pages/ServiceManagementPage';
import RecentAlertsPage from '../pages/RecentAlertsPage';
import PostgresDashboardPage from '../pages/PostgresDashboardPage';
import ExecutiveDashboardPage from '../pages/ExecutiveDashboardPage';
import DailyReportPage from '../pages/DailyReportPage';
import WeeklyReportPage from '../pages/WeeklyReportPage';
import MonthlyReport from '../pages/MonthlyReport';
import DownloadsPage from '../pages/DownloadsPage';
import SystemHealthPage from '../pages/SystemHealthPage';
import ProfilePage from '../pages/ProfilePage';
import ChangePasswordPage from '../pages/ChangePasswordPage';

/**
 * Main App component with React Router configuration
 * 
 * Routes:
 * - /login - LoginPage (public)
 * - /dashboard - Dashboard (protected)
 * - /users - UserListPage (protected, requires users.read permission)
 * - /partitions - PartitionManagementDashboard (protected)
 * - / - Redirects to dashboard if authenticated, otherwise to login
 * 
 * Requirements: 6.1, 5.1, 9.4
 */
function App() {
    return (
        <BrowserRouter>
            <AuthProvider>
                <Routes>
                    {/* Public routes */}
                    <Route path="/login" element={<LoginPage />} />
                    
                    {/* Protected routes */}
                    <Route 
                        path="/dashboard" 
                        element={
                            <ProtectedRoute>
                                <Dashboard />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/dashboard/executive" 
                        element={
                            <ProtectedRoute requiredPermission="dashboard.view">
                                <ExecutiveDashboardPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/reports/daily" 
                        element={
                            <ProtectedRoute requiredPermission="reports.view">
                                <DailyReportPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/reports/weekly" 
                        element={
                            <ProtectedRoute requiredPermission="reports.view">
                                <WeeklyReportPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/reports/monthly" 
                        element={
                            <ProtectedRoute requiredPermission="reports.view">
                                <MonthlyReport />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/reports/downloads" 
                        element={
                            <ProtectedRoute requiredPermission="reports.view">
                                <DownloadsPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/dashboard/postgres" 
                        element={
                            <ProtectedRoute requiredPermission="dashboard.view">
                                <PostgresDashboardPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/users" 
                        element={
                            <ProtectedRoute requiredPermission="users.read">
                                <UserListPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/roles" 
                        element={
                            <ProtectedRoute requiredPermission="roles.read">
                                <RolesPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/permissions" 
                        element={
                            <ProtectedRoute requiredPermission="permissions.read">
                                <PermissionsPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/table-sync" 
                        element={
                            <ProtectedRoute>
                                <TableSyncDashboard />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/alerts-reports" 
                        element={
                            <ProtectedRoute requiredPermission="reports.view">
                                <AlertsReportDashboard />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/vm-alerts" 
                        element={
                            <ProtectedRoute requiredPermission="reports.view">
                                <VMAlertDashboard />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/recent-alerts" 
                        element={
                            <ProtectedRoute requiredPermission="reports.view">
                                <RecentAlertsPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/down-communication" 
                        element={
                            <ProtectedRoute requiredPermission="reports.view">
                                <DownCommunicationDashboard />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/partitions" 
                        element={
                            <ProtectedRoute requiredPermission="partitions.view">
                                <PartitionManagementDashboard />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/services" 
                        element={
                            <ProtectedRoute requiredPermission="services.manage">
                                <ServiceManagementPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/system/health" 
                        element={
                            <ProtectedRoute requiredPermission="system.view">
                                <SystemHealthPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    {/* Profile route - accessible to all authenticated users */}
                    <Route 
                        path="/profile" 
                        element={
                            <ProtectedRoute>
                                <ProfilePage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/change-password" 
                        element={
                            <ProtectedRoute>
                                <ChangePasswordPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    {/* Sites routes */}
                    <Route 
                        path="/sites/rms" 
                        element={
                            <ProtectedRoute requiredPermission="sites.rms">
                                <SitesRMSPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/sites/dvr" 
                        element={
                            <ProtectedRoute requiredPermission="sites.dvr">
                                <SitesDVRPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/sites/cloud" 
                        element={
                            <ProtectedRoute requiredPermission="sites.cloud">
                                <SitesCloudPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    <Route 
                        path="/sites/gps" 
                        element={
                            <ProtectedRoute requiredPermission="sites.gps">
                                <SitesGPSPage />
                            </ProtectedRoute>
                        } 
                    />
                    
                    {/* Default route - redirect to login */}
                    <Route path="/" element={<Navigate to="/login" replace />} />
                    
                    {/* Catch-all route - redirect to login */}
                    <Route path="*" element={<Navigate to="/login" replace />} />
                </Routes>
            </AuthProvider>
        </BrowserRouter>
    );
}

export default App;
