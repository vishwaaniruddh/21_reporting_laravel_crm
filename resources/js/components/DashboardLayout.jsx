import { useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../contexts/AuthContext';
import RoleGuard from './RoleGuard';
import {
    LayoutDashboard,
    Users,
    Shield,
    Key,
    RefreshCw,
    Database,
    Settings,
    FileText,
    Monitor,
    Clock,
    TrendingDown,
    Building2,
    Video,
    Cloud,
    MapPin,
    Menu,
    X,
    LogOut,
    ChevronRight,
    ChevronDown,
    Loader2,
    AlertTriangle,
    BarChart3,
    Calendar,
    CalendarDays,
    CalendarRange,
    Download,
    Activity,
    Target,
    Wrench,
    Building,
    HeartPulse,
    Package,
    List,
    Globe,
    Contact,
    Ticket,
    FolderOpen,
    UserX,
    AlertCircle,
    CheckCircle,
    TrendingUp,
    LineChart,
    Sparkles,
    Brain,
    Layout,
    Briefcase,
    Award,
    BookOpen,
    Zap,
    Server,
    ShieldCheck,
    Bell,
    CreditCard,
    FileSearch,
    HelpCircle,
    Code,
    MessageCircle,
    User
} from 'lucide-react';

/**
 * DashboardLayout component
 * Provides the main layout structure with sidebar navigation, header, and content area.
 * 
 * Requirements: 6.1, 6.2, 6.3
 */
const DashboardLayout = ({ children }) => {
    const { user, logout, loading } = useAuth();
    const location = useLocation();
    const navigate = useNavigate();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [isLoggingOut, setIsLoggingOut] = useState(false);
    const [showUserMenu, setShowUserMenu] = useState(false);
    
    // Helper function to check if a menu contains the active route
    const isMenuActive = (menuKey) => {
        const path = location.pathname;
        
        if (menuKey === 'alerts') {
            return path.startsWith('/alerts') || path.startsWith('/tickets');
        }
        if (menuKey === 'reports') {
            return path.startsWith('/reports');
        }
        if (menuKey === 'sites') {
            return path.startsWith('/sites') || path.startsWith('/assets') || path.startsWith('/maintenance');
        }
        if (menuKey === 'customers') {
            return path.startsWith('/customers');
        }
        if (menuKey === 'ticketing') {
            return path.startsWith('/tickets');
        }
        if (menuKey === 'analytics') {
            return path.startsWith('/analytics');
        }
        if (menuKey === 'operations') {
            return path.startsWith('/operations');
        }
        if (menuKey === 'system') {
            return path.startsWith('/system') || path.startsWith('/table-sync') || path.startsWith('/partitions') || path.startsWith('/services');
        }
        if (menuKey === 'administration') {
            return path.startsWith('/admin') || path.startsWith('/users') || path.startsWith('/roles') || path.startsWith('/permissions');
        }
        if (menuKey === 'help') {
            return path.startsWith('/help');
        }
        return false;
    };
    
    // Initialize openMenus based on active route
    const getInitialOpenMenus = () => {
        return {
            'dashboard': isMenuActive('dashboard'),
            'alerts': isMenuActive('alerts'),
            'reports': isMenuActive('reports'),
            'sites': isMenuActive('sites'),
            'customers': isMenuActive('customers'),
            'ticketing': isMenuActive('ticketing'),
            'analytics': isMenuActive('analytics'),
            'operations': isMenuActive('operations'),
            'system': isMenuActive('system'),
            'administration': isMenuActive('administration'),
            'user-management': isMenuActive('administration'), // Auto-open if on user/role/permission pages
            'help': isMenuActive('help')
        };
    };
    
    const [openMenus, setOpenMenus] = useState(getInitialOpenMenus());

    const handleLogout = async () => {
        setIsLoggingOut(true);
        await logout();
        setIsLoggingOut(false);
        navigate('/login');
    };

    const toggleMenu = (menuKey) => {
        setOpenMenus(prev => ({
            ...prev,
            [menuKey]: !prev[menuKey]
        }));
    };

    const navigationItems = [
        // DASHBOARD
        {
            name: 'Dashboard',
            icon: LayoutDashboard,
            key: 'dashboard',
            isParent: true,
            permission: 'dashboard.view',
            children: [
                { name: 'Main', href: '/dashboard', icon: LayoutDashboard, permission: 'dashboard.view', key: 'dashboard-main' },
                { name: 'Executive', href: '/dashboard/executive', icon: TrendingUp, permission: 'dashboard.view', key: 'executive-dashboard' },
                { name: 'Shift Overview', href: '/dashboard/postgres', icon: Database, permission: 'dashboard.view', key: 'postgres-dashboard' },
            ]
        },
        
        // ALERTS & INCIDENTS
        {
            name: 'Alerts & Incidents',
            icon: AlertTriangle,
            key: 'alerts',
            isParent: true,
            permission: 'alerts.view',
            children: [
                { name: 'Active Tickets', href: '/tickets/active', icon: Ticket, permission: 'tickets.view', key: 'active-tickets' },
                { name: 'My Assignments', href: '/tickets/my', icon: User, permission: 'tickets.view', key: 'my-tickets' },
            ]
        },
        
        // REPORTS
        {
            name: 'Reports',
            icon: BarChart3,
            key: 'reports',
            isParent: true,
            permission: 'reports.view',
            children: [
                { name: 'All Alerts', href: '/alerts-reports', icon: FileText, permission: 'reports.view', key: 'alerts-reports' },
                { name: 'VM Alerts', href: '/vm-alerts', icon: Monitor, permission: 'reports.view', key: 'vm-alerts' },
                { name: 'Recent (15 Min)', href: '/recent-alerts', icon: Clock, permission: 'reports.view', key: 'recent-alerts' },
                { name: 'UPS', href: '/ups-reports', icon: Zap, permission: 'reports.view', key: 'ups-reports' },
                { name: 'Daily Report', href: '/reports/daily', icon: Calendar, permission: 'reports.view', key: 'daily-report' },
                { name: 'Weekly Summary', href: '/reports/weekly', icon: CalendarDays, permission: 'reports.view', key: 'weekly-report' },
                { name: 'Monthly Report', href: '/reports/monthly', icon: CalendarRange, permission: 'reports.view', key: 'monthly-report' },
                { name: 'Downloads', href: '/reports/downloads', icon: Download, permission: 'reports.view', key: 'downloads' },
                { name: 'Down Communication', href: '/down-communication', icon: TrendingDown, permission: 'reports.view', key: 'down-communication' },
            ]
        },
        
        // SITES & ASSETS
        {
            name: 'Sites & Assets',
            icon: Building2,
            key: 'sites',
            isParent: true,
            permission: 'sites.view',
            children: [
                { name: 'RMS Sites', href: '/sites/rms', icon: Building, permission: 'sites.rms', key: 'sites-rms' },
                { name: 'DVR Sites', href: '/sites/dvr', icon: Video, permission: 'sites.dvr', key: 'sites-dvr' },
                { name: 'Cloud Sites', href: '/sites/cloud', icon: Cloud, permission: 'sites.cloud', key: 'sites-cloud' },
                { name: 'GPS Sites', href: '/sites/gps', icon: MapPin, permission: 'sites.gps', key: 'sites-gps' },
                { name: 'Site Health', href: '/sites/health', icon: HeartPulse, permission: 'sites.view', key: 'site-health' },
                { name: 'Asset Management', href: '/assets', icon: Package, permission: 'assets.view', key: 'assets' },
                { name: 'Maintenance', href: '/maintenance', icon: Wrench, permission: 'maintenance.view', key: 'maintenance' },
            ]
        },
        
        // CUSTOMERS
        {
            name: 'Customers',
            icon: Users,
            key: 'customers',
            isParent: true,
            permission: 'customers.view',
            children: [
                { name: 'Customer List', href: '/customers', icon: List, permission: 'customers.view', key: 'customer-list' },
                { name: 'Portal Access', href: '/customers/portal', icon: Globe, permission: 'customers.manage', key: 'customer-portal' },
                { name: 'Contracts & SLAs', href: '/customers/contracts', icon: FileText, permission: 'contracts.view', key: 'contracts' },
                { name: 'Contacts', href: '/customers/contacts', icon: Contact, permission: 'customers.view', key: 'contacts' },
            ]
        },
        
        // TICKETING
        {
            name: 'Ticketing',
            icon: Ticket,
            key: 'ticketing',
            isParent: true,
            permission: 'tickets.view',
            children: [
                { name: 'Open Tickets', href: '/tickets/open', icon: FolderOpen, permission: 'tickets.view', key: 'open-tickets' },
                { name: 'My Tickets', href: '/tickets/my', icon: User, permission: 'tickets.view', key: 'my-tickets-2' },
                { name: 'Unassigned', href: '/tickets/unassigned', icon: UserX, permission: 'tickets.view', key: 'unassigned-tickets' },
                { name: 'Escalated', href: '/tickets/escalated', icon: AlertCircle, permission: 'tickets.view', key: 'escalated-tickets' },
                { name: 'Closed', href: '/tickets/closed', icon: CheckCircle, permission: 'tickets.view', key: 'closed-tickets' },
            ]
        },
        
        // ANALYTICS
        {
            name: 'Analytics',
            icon: LineChart,
            key: 'analytics',
            isParent: true,
            permission: 'analytics.view',
            children: [
                { name: 'PostgreSQL Dashboard', href: '/dashboard/postgres', icon: Database, permission: 'dashboard.view', key: 'postgres-dashboard' },
                { name: 'Trend Analysis', href: '/analytics/trends', icon: TrendingUp, permission: 'analytics.view', key: 'trends' },
                { name: 'Pattern Detection', href: '/analytics/patterns', icon: Sparkles, permission: 'analytics.view', key: 'patterns' },
                { name: 'Predictive Insights', href: '/analytics/predictive', icon: Brain, permission: 'analytics.view', key: 'predictive' },
                { name: 'Custom Dashboards', href: '/analytics/custom', icon: Layout, permission: 'analytics.create', key: 'custom-dashboards' },
            ]
        },
        
        // OPERATIONS
        {
            name: 'Operations',
            icon: Briefcase,
            key: 'operations',
            isParent: true,
            permission: 'operations.view',
            children: [
                { name: 'Shift Management', href: '/operations/shifts', icon: Clock, permission: 'operations.shifts', key: 'shifts' },
                { name: 'Team Performance', href: '/operations/performance', icon: Award, permission: 'operations.view', key: 'performance' },
                { name: 'Knowledge Base', href: '/operations/kb', icon: BookOpen, permission: 'operations.view', key: 'knowledge-base' },
                { name: 'Workflow Automation', href: '/operations/workflows', icon: Zap, permission: 'operations.manage', key: 'workflows' },
            ]
        },
        
        // SYSTEM (Data Sync & Services)
        {
            name: 'System',
            icon: Server,
            key: 'system',
            isParent: true,
            anyPermissions: ['table-sync.view', 'services.manage'],
            children: [
                { name: 'Table Sync', href: '/table-sync', icon: RefreshCw, permission: 'table-sync.view', key: 'table-sync' },
                { name: 'Partitions', href: '/partitions', icon: Database, permission: 'partitions.view', key: 'partitions' },
                { name: 'Services', href: '/services', icon: Settings, permission: 'services.manage', key: 'services' },
                { name: 'System Health', href: '/system/health', icon: Activity, permission: 'system.view', key: 'system-health' },
            ]
        },
        
        // ADMINISTRATION
        {
            name: 'Administration',
            icon: Shield,
            key: 'administration',
            isParent: true,
            permission: 'admin.access',
            children: [
                { 
                    name: 'User Management',
                    icon: Users,
                    key: 'user-management',
                    isParent: true,
                    permission: 'admin.access',
                    children: [
                        { name: 'Users', href: '/users', icon: Users, permission: 'users.read', key: 'users' },
                        { name: 'Roles', href: '/roles', icon: ShieldCheck, permission: 'roles.read', key: 'roles' },
                        { name: 'Permissions', href: '/permissions', icon: Key, permission: 'permissions.read', key: 'permissions' },
                    ]
                },
                { name: 'General Settings', href: '/admin/settings', icon: Settings, permission: 'settings.manage', key: 'settings' },
                { name: 'Notifications', href: '/admin/notifications', icon: Bell, permission: 'settings.manage', key: 'notification-settings' },
                { name: 'SLA Configuration', href: '/admin/sla', icon: Target, permission: 'sla.manage', key: 'sla-config' },
                { name: 'Billing', href: '/admin/billing', icon: CreditCard, permission: 'billing.view', key: 'billing' },
                { name: 'Audit Logs', href: '/admin/audit', icon: FileSearch, permission: 'audit.view', key: 'audit-logs' },
            ]
        },
        
        // HELP & SUPPORT
        {
            name: 'Help & Support',
            icon: HelpCircle,
            key: 'help',
            isParent: true,
            children: [
                { name: 'Documentation', href: '/help/docs', icon: BookOpen, key: 'docs' },
                { name: 'API Docs', href: '/help/api', icon: Code, key: 'api-docs' },
                { name: 'Support', href: '/help/support', icon: MessageCircle, key: 'support' },
            ]
        },
    ];

    const getCurrentPage = () => {
        const path = location.pathname;
        
        // Dashboard
        if (path === '/dashboard') return 'dashboard-main';
        if (path === '/dashboard/executive') return 'executive-dashboard';
        if (path === '/dashboard/postgres') return 'postgres-dashboard';
        
        // Alerts & Incidents
        if (path === '/alerts-reports') return 'alerts-reports';
        if (path === '/recent-alerts') return 'recent-alerts';
        if (path === '/vm-alerts') return 'vm-alerts';
        if (path === '/tickets/active') return 'active-tickets';
        if (path === '/tickets/my') return 'my-tickets';
        
        // Reports
        if (path === '/reports/daily') return 'daily-report';
        if (path === '/reports/weekly') return 'weekly-report';
        if (path === '/reports/monthly') return 'monthly-report';
        if (path === '/reports/downloads') return 'downloads';
        if (path === '/reports/uptime') return 'uptime-report';
        if (path === '/reports/sla') return 'sla-report';
        if (path === '/ups-reports') return 'ups-reports';
        if (path === '/down-communication') return 'down-communication';
        if (path === '/reports/builder') return 'report-builder';
        
        // Sites & Assets
        if (path === '/sites/rms') return 'sites-rms';
        if (path === '/sites/dvr') return 'sites-dvr';
        if (path === '/sites/cloud') return 'sites-cloud';
        if (path === '/sites/gps') return 'sites-gps';
        if (path === '/sites/health') return 'site-health';
        if (path === '/assets') return 'assets';
        if (path === '/maintenance') return 'maintenance';
        
        // Customers
        if (path === '/customers') return 'customer-list';
        if (path === '/customers/portal') return 'customer-portal';
        if (path === '/customers/contracts') return 'contracts';
        if (path === '/customers/contacts') return 'contacts';
        
        // Ticketing
        if (path === '/tickets/open') return 'open-tickets';
        if (path === '/tickets/my') return 'my-tickets-2';
        if (path === '/tickets/unassigned') return 'unassigned-tickets';
        if (path === '/tickets/escalated') return 'escalated-tickets';
        if (path === '/tickets/closed') return 'closed-tickets';
        
        // Analytics
        if (path === '/analytics/trends') return 'trends';
        if (path === '/analytics/patterns') return 'patterns';
        if (path === '/analytics/predictive') return 'predictive';
        if (path === '/analytics/custom') return 'custom-dashboards';
        
        // Operations
        if (path === '/operations/shifts') return 'shifts';
        if (path === '/operations/performance') return 'performance';
        if (path === '/operations/kb') return 'knowledge-base';
        if (path === '/operations/workflows') return 'workflows';
        
        // System
        if (path === '/table-sync') return 'table-sync';
        if (path === '/partitions') return 'partitions';
        if (path === '/services') return 'services';
        if (path === '/system/health') return 'system-health';
        
        // Administration
        if (path === '/users') return 'users';
        if (path === '/roles') return 'roles';
        if (path === '/permissions') return 'permissions';
        if (path === '/admin/settings') return 'settings';
        if (path === '/admin/notifications') return 'notification-settings';
        if (path === '/admin/sla') return 'sla-config';
        if (path === '/admin/billing') return 'billing';
        if (path === '/admin/audit') return 'audit-logs';
        
        // Help & Support
        if (path === '/help/docs') return 'docs';
        if (path === '/help/api') return 'api-docs';
        if (path === '/help/support') return 'support';
        
        return 'dashboard';
    };

    const currentPage = getCurrentPage();

    const getRoleBadgeColor = (role) => {
        switch (role) {
            case 'superadmin': return 'bg-purple-100 text-purple-700 border border-purple-200';
            case 'admin': return 'bg-blue-100 text-blue-700 border border-blue-200';
            case 'manager': return 'bg-green-100 text-green-700 border border-green-200';
            default: return 'bg-gray-100 text-gray-700 border border-gray-200';
        }
    };

    if (loading) {
        return (
            <div className="min-h-screen flex items-center justify-center bg-gray-100">
                <div className="text-gray-600">Loading...</div>
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-gray-50">
            {/* Mobile sidebar overlay */}
            {sidebarOpen && (
                <div 
                    className="fixed inset-0 z-40 bg-gray-900 bg-opacity-75 lg:hidden transition-opacity"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Mobile sidebar */}
            <div className={`fixed inset-y-0 left-0 z-50 w-64 bg-gradient-to-b from-slate-900 to-slate-800 transform transition-transform duration-300 ease-in-out lg:hidden ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <div className="flex items-center justify-between h-14 px-4 bg-slate-950 border-b border-slate-700">
                    <Link to="/dashboard" className="flex items-center gap-2 hover:opacity-80 transition-opacity" onClick={() => setSidebarOpen(false)}>
                        <div className="w-7 h-7 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg flex items-center justify-center">
                            <LayoutDashboard className="w-4 h-4 text-white" />
                        </div>
                        <span className="text-base font-bold text-white">Sar Reporting</span>
                    </Link>
                    <button onClick={() => setSidebarOpen(false)} className="text-gray-400 hover:text-white transition-colors">
                        <X className="w-5 h-5" />
                    </button>
                </div>
                <nav className="mt-3 px-3 space-y-0.5 overflow-y-auto h-[calc(100vh-3.5rem)]">
                    {navigationItems.map((item) => (
                        item.isParent ? (
                            <RoleGuard 
                                key={item.key} 
                                requiredPermission={item.permission}
                                anyPermissions={item.anyPermissions}
                            >
                                <div>
                                    <button
                                        onClick={() => toggleMenu(item.key)}
                                        className="w-full group flex items-center justify-between px-3 py-2 text-xs font-medium rounded-lg text-gray-300 hover:bg-slate-700/50 hover:text-white transition-all duration-200"
                                    >
                                        <div className="flex items-center gap-2.5">
                                            <item.icon className="w-4 h-4 text-blue-400 group-hover:text-blue-300" />
                                            <span>{item.name}</span>
                                        </div>
                                        <ChevronRight className={`w-3.5 h-3.5 text-gray-400 group-hover:text-white transition-transform duration-200 ${openMenus[item.key] ? 'rotate-90' : ''}`} />
                                    </button>
                                    {openMenus[item.key] && (
                                        <div className="ml-3 mt-0.5 space-y-0.5 border-l-2 border-slate-700 pl-3">
                                            {item.children.map((child) => (
                                                child.isParent ? (
                                                    <RoleGuard key={child.key} requiredPermission={child.permission}>
                                                        <div>
                                                            <button
                                                                onClick={() => toggleMenu(child.key)}
                                                                className="w-full group flex items-center justify-between px-2.5 py-1.5 text-xs font-medium rounded-lg text-gray-400 hover:bg-slate-700/50 hover:text-white transition-all duration-200"
                                                            >
                                                                <div className="flex items-center gap-2">
                                                                    <child.icon className="w-3.5 h-3.5 text-gray-500 group-hover:text-blue-400" />
                                                                    <span>{child.name}</span>
                                                                </div>
                                                                <ChevronRight className={`w-3 h-3 text-gray-400 group-hover:text-white transition-transform duration-200 ${openMenus[child.key] ? 'rotate-90' : ''}`} />
                                                            </button>
                                                            {openMenus[child.key] && (
                                                                <div className="ml-3 mt-0.5 space-y-0.5 border-l-2 border-slate-700 pl-2">
                                                                    {child.children.map((subChild) => (
                                                                        <RoleGuard key={subChild.key} requiredPermission={subChild.permission}>
                                                                            <Link
                                                                                to={subChild.href}
                                                                                onClick={() => setSidebarOpen(false)}
                                                                                className={`group flex items-center gap-2 px-2 py-1.5 text-xs font-medium rounded-lg transition-all duration-200 ${
                                                                                    currentPage === subChild.key
                                                                                        ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg shadow-blue-500/20'
                                                                                        : 'text-gray-400 hover:bg-slate-700/50 hover:text-white'
                                                                                }`}
                                                                            >
                                                                                <subChild.icon className={`w-3 h-3 ${currentPage === subChild.key ? 'text-white' : 'text-gray-500 group-hover:text-blue-400'}`} />
                                                                                <span>{subChild.name}</span>
                                                                            </Link>
                                                                        </RoleGuard>
                                                                    ))}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </RoleGuard>
                                                ) : (
                                                    <RoleGuard key={child.key} requiredPermission={child.permission}>
                                                        <Link
                                                            to={child.href}
                                                            onClick={() => setSidebarOpen(false)}
                                                            className={`group flex items-center gap-2.5 px-2.5 py-1.5 text-xs font-medium rounded-lg transition-all duration-200 ${
                                                                currentPage === child.key
                                                                    ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg shadow-blue-500/20'
                                                                    : 'text-gray-400 hover:bg-slate-700/50 hover:text-white'
                                                            }`}
                                                        >
                                                            <child.icon className={`w-3.5 h-3.5 ${currentPage === child.key ? 'text-white' : 'text-gray-500 group-hover:text-blue-400'}`} />
                                                            <span>{child.name}</span>
                                                        </Link>
                                                    </RoleGuard>
                                                )
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </RoleGuard>
                        ) : (
                            <RoleGuard key={item.key} requiredPermission={item.permission}>
                                <Link
                                    to={item.href}
                                    onClick={() => setSidebarOpen(false)}
                                    className={`group flex items-center gap-2.5 px-3 py-2 text-xs font-medium rounded-lg transition-all duration-200 ${
                                        currentPage === item.key
                                            ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg shadow-blue-500/20'
                                            : 'text-gray-300 hover:bg-slate-700/50 hover:text-white'
                                    }`}
                                >
                                    <item.icon className={`w-4 h-4 ${currentPage === item.key ? 'text-white' : 'text-blue-400 group-hover:text-blue-300'}`} />
                                    <span>{item.name}</span>
                                </Link>
                            </RoleGuard>
                        )
                    ))}
                </nav>
            </div>

            {/* Desktop sidebar - Fixed position */}
            <div className="hidden lg:fixed lg:inset-y-0 lg:flex lg:w-64 lg:flex-col">
                <div className="flex flex-col flex-grow bg-gradient-to-b from-slate-900 to-slate-800 overflow-y-auto border-r border-slate-700">
                    {/* Logo */}
                    <Link to="/dashboard" className="flex items-center gap-2.5 h-14 flex-shrink-0 px-4 bg-slate-950 border-b border-slate-700 hover:opacity-80 transition-opacity">
                        <div className="w-8 h-8 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg flex items-center justify-center shadow-lg">
                            <LayoutDashboard className="w-4 h-4 text-white" />
                        </div>
                        <span className="text-base font-bold text-white">Sar Reporting</span>
                    </Link>
                    {/* Navigation */}
                    <nav className="mt-3 flex-1 px-3 space-y-0.5">
                        {navigationItems.map((item) => (
                            item.isParent ? (
                                <RoleGuard 
                                    key={item.key} 
                                    requiredPermission={item.permission}
                                    anyPermissions={item.anyPermissions}
                                >
                                    <div>
                                        <button
                                            onClick={() => toggleMenu(item.key)}
                                            className="w-full group flex items-center justify-between px-3 py-2 text-xs font-medium rounded-lg text-gray-300 hover:bg-slate-700/50 hover:text-white transition-all duration-200"
                                        >
                                            <div className="flex items-center gap-2.5">
                                                <item.icon className="w-4 h-4 text-blue-400 group-hover:text-blue-300" />
                                                <span>{item.name}</span>
                                            </div>
                                            <ChevronRight className={`w-3.5 h-3.5 text-gray-400 group-hover:text-white transition-transform duration-200 ${openMenus[item.key] ? 'rotate-90' : ''}`} />
                                        </button>
                                        {openMenus[item.key] && (
                                            <div className="ml-3 mt-0.5 space-y-0.5 border-l-2 border-slate-700 pl-3">
                                                {item.children.map((child) => (
                                                    child.isParent ? (
                                                        <RoleGuard key={child.key} requiredPermission={child.permission}>
                                                            <div>
                                                                <button
                                                                    onClick={() => toggleMenu(child.key)}
                                                                    className="w-full group flex items-center justify-between px-2.5 py-1.5 text-xs font-medium rounded-lg text-gray-400 hover:bg-slate-700/50 hover:text-white transition-all duration-200"
                                                                >
                                                                    <div className="flex items-center gap-2">
                                                                        <child.icon className="w-3.5 h-3.5 text-gray-500 group-hover:text-blue-400" />
                                                                        <span>{child.name}</span>
                                                                    </div>
                                                                    <ChevronRight className={`w-3 h-3 text-gray-400 group-hover:text-white transition-transform duration-200 ${openMenus[child.key] ? 'rotate-90' : ''}`} />
                                                                </button>
                                                                {openMenus[child.key] && (
                                                                    <div className="ml-3 mt-0.5 space-y-0.5 border-l-2 border-slate-700 pl-2">
                                                                        {child.children.map((subChild) => (
                                                                            <RoleGuard key={subChild.key} requiredPermission={subChild.permission}>
                                                                                <Link
                                                                                    to={subChild.href}
                                                                                    className={`group flex items-center gap-2 px-2 py-1.5 text-xs font-medium rounded-lg transition-all duration-200 ${
                                                                                        currentPage === subChild.key
                                                                                            ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg shadow-blue-500/20'
                                                                                            : 'text-gray-400 hover:bg-slate-700/50 hover:text-white'
                                                                                    }`}
                                                                                >
                                                                                    <subChild.icon className={`w-3 h-3 ${currentPage === subChild.key ? 'text-white' : 'text-gray-500 group-hover:text-blue-400'}`} />
                                                                                    <span>{subChild.name}</span>
                                                                                </Link>
                                                                            </RoleGuard>
                                                                        ))}
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </RoleGuard>
                                                    ) : (
                                                        <RoleGuard key={child.key} requiredPermission={child.permission}>
                                                            <Link
                                                                to={child.href}
                                                                className={`group flex items-center gap-2.5 px-2.5 py-1.5 text-xs font-medium rounded-lg transition-all duration-200 ${
                                                                    currentPage === child.key
                                                                        ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg shadow-blue-500/20'
                                                                        : 'text-gray-400 hover:bg-slate-700/50 hover:text-white'
                                                                }`}
                                                            >
                                                                <child.icon className={`w-3.5 h-3.5 ${currentPage === child.key ? 'text-white' : 'text-gray-500 group-hover:text-blue-400'}`} />
                                                                <span>{child.name}</span>
                                                            </Link>
                                                        </RoleGuard>
                                                    )
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                </RoleGuard>
                            ) : (
                                <RoleGuard key={item.key} requiredPermission={item.permission}>
                                    <Link
                                        to={item.href}
                                        className={`group flex items-center gap-2.5 px-3 py-2 text-xs font-medium rounded-lg transition-all duration-200 ${
                                            currentPage === item.key
                                                ? 'bg-gradient-to-r from-blue-600 to-blue-500 text-white shadow-lg shadow-blue-500/20'
                                                : 'text-gray-300 hover:bg-slate-700/50 hover:text-white'
                                        }`}
                                    >
                                        <item.icon className={`w-4 h-4 ${currentPage === item.key ? 'text-white' : 'text-blue-400 group-hover:text-blue-300'}`} />
                                        <span>{item.name}</span>
                                    </Link>
                                </RoleGuard>
                            )
                        ))}
                    </nav>
                    {/* User info at bottom of sidebar */}
                    <div className="flex-shrink-0 p-3 border-t border-slate-700 bg-slate-950">
                        <div className="flex items-center gap-2.5">
                            <div className="w-9 h-9 rounded-lg bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center shadow-lg">
                                <span className="text-white font-semibold text-sm">
                                    {user?.name?.charAt(0)?.toUpperCase() || 'U'}
                                </span>
                            </div>
                            <div className="flex-1 overflow-hidden">
                                <p className="text-xs font-medium text-white truncate">{user?.name}</p>
                                <p className="text-[10px] text-gray-400 truncate">{user?.email}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* Main content area - offset by sidebar width on desktop */}
            <div className="lg:pl-64 flex flex-col min-h-screen">
                {/* Top header */}
                <header className="bg-white shadow-sm sticky top-0 z-10 border-b border-gray-200">
                    <div className="flex items-center justify-between h-14 px-4 sm:px-6 lg:px-8">
                        {/* Mobile menu button */}
                        <button onClick={() => setSidebarOpen(true)} className="lg:hidden text-gray-600 hover:text-gray-900 focus:outline-none">
                            <Menu className="w-5 h-5" />
                        </button>

                        {/* Page title */}
                        <h1 className="text-base font-semibold text-gray-900 capitalize hidden lg:block">
                            {currentPage.replace(/-/g, ' ')}
                        </h1>
                        <h1 className="text-sm font-semibold text-gray-900 lg:hidden">Sar Reporting</h1>

                        {/* User dropdown menu */}
                        <div className="relative">
                            <button
                                onClick={() => setShowUserMenu(!showUserMenu)}
                                className="flex items-center gap-2 px-3 py-1.5 rounded-lg hover:bg-gray-100 transition-colors"
                            >
                                <div className="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                                    <span className="text-white font-semibold text-sm">
                                        {user?.name?.charAt(0)?.toUpperCase() || 'U'}
                                    </span>
                                </div>
                                <div className="hidden sm:block text-left">
                                    <p className="text-sm font-medium text-gray-800">{user?.name}</p>
                                    <p className="text-xs text-gray-500 capitalize">{user?.role}</p>
                                </div>
                                <ChevronDown className={`w-4 h-4 text-gray-500 transition-transform ${showUserMenu ? 'rotate-180' : ''}`} />
                            </button>

                            {/* Dropdown menu */}
                            {showUserMenu && (
                                <>
                                    {/* Backdrop to close menu */}
                                    <div 
                                        className="fixed inset-0 z-40" 
                                        onClick={() => setShowUserMenu(false)}
                                    />
                                    <div className="absolute right-0 mt-2 w-56 bg-white rounded-lg shadow-lg border border-gray-200 py-2 z-50">
                                        {/* User info header */}
                                        <div className="px-4 py-3 border-b border-gray-100">
                                            <p className="text-sm font-semibold text-gray-800">{user?.name}</p>
                                            <p className="text-xs text-gray-500">{user?.email}</p>
                                        </div>

                                        {/* Menu items */}
                                        <div className="py-1">
                                            <button
                                                onClick={() => {
                                                    setShowUserMenu(false);
                                                    navigate('/profile');
                                                }}
                                                className="w-full flex items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                            >
                                                <User className="w-4 h-4 text-gray-500" />
                                                My Profile
                                            </button>
                                            <button
                                                onClick={() => {
                                                    setShowUserMenu(false);
                                                    navigate('/change-password');
                                                }}
                                                className="w-full flex items-center gap-3 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                            >
                                                <Key className="w-4 h-4 text-gray-500" />
                                                Change Password
                                            </button>
                                        </div>

                                        {/* Sign out */}
                                        <div className="border-t border-gray-100 pt-1">
                                            <button
                                                onClick={() => {
                                                    setShowUserMenu(false);
                                                    handleLogout();
                                                }}
                                                disabled={isLoggingOut}
                                                className="w-full flex items-center gap-3 px-4 py-2 text-sm text-red-600 hover:bg-red-50 disabled:opacity-50"
                                            >
                                                <LogOut className="w-4 h-4" />
                                                {isLoggingOut ? 'Signing out...' : 'Sign Out'}
                                            </button>
                                        </div>
                                    </div>
                                </>
                            )}
                        </div>
                    </div>
                </header>

                {/* Main content */}
                <main className="flex-1 p-4 sm:p-6 lg:p-8 bg-gray-50">
                    {children}
                </main>

                {/* Footer */}
                <footer className="bg-white border-t border-gray-200 py-2 px-3 sm:px-4 lg:px-6">
                    <p className="text-center text-xs text-gray-500">
                        © 2026 Sar Reporting Management System
                    </p>
                </footer>
            </div>
        </div>
    );
};


export default DashboardLayout;
