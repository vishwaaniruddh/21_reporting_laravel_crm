# Executive Dashboard - Quick Start Guide

## 🚀 Access the Dashboard

**URL**: `http://your-domain/dashboard/executive`

**Permission Required**: `dashboard.view`

**Sidebar Location**: Dashboard → Executive Dashboard

## 📊 What You'll See

### Top Section - KPI Cards
- **Total Sites**: Total number of sites in the system
- **Active Alerts**: Currently open/active alerts
- **Uptime**: System uptime percentage
- **SLA Compliance**: Percentage of tickets meeting SLA

### Health Score Section
- **Overall Health Score**: 0-100 score with status (Excellent/Good/Fair/Poor)
- **Component Breakdown**: Uptime, SLA, Response Time scores
- **Site Status Distribution**: Online/Offline/Maintenance sites (donut chart)

### Alert Trends
- **Line Chart**: Shows alert trends over selected date range
- **Priority Levels**: Critical (red), High (orange), Medium (blue), Low (green)

### Top Sites & Critical Issues
- **Top 10 Sites**: Sites with highest alert volume (bar chart)
- **Active Critical Issues**: List of current critical alerts with status

### Additional Metrics
- **Down Communication Summary**: Sites down, average downtime, critical sites
- **Month-over-Month Comparison**: Changes in alerts, tickets, SLA, uptime
- **Revenue & Cost Analysis**: Revenue, costs, profit, profit margin

## 🎛️ Controls

### Date Range Selector
- **Start Date**: Select beginning of date range
- **End Date**: Select end of date range
- **Default**: Last 30 days
- Changes automatically refresh the dashboard

### Refresh Button
- **Manual Refresh**: Click to force refresh data
- **Auto-Refresh**: Dashboard auto-refreshes every 5 minutes
- **Cache**: Data is cached for 5 minutes for performance

### Export Button
- **Export to Excel**: Download dashboard data as Excel file
- **Export to PDF**: Download dashboard as PDF report
- *(Note: Backend implementation needed)*

## 📈 Understanding the Metrics

### Health Score Calculation
```
Health Score = (Uptime × 40%) + (SLA Compliance × 40%) + (Response Time × 20%)
```

**Status Levels**:
- **Excellent**: 90-100
- **Good**: 75-89
- **Fair**: 60-74
- **Poor**: Below 60

### SLA Targets by Priority
- **Critical**: 15 minutes
- **High**: 30 minutes
- **Medium**: 60 minutes
- **Low**: 120 minutes

### Customer Health Score
```
Health Score = (Alert Score × 50%) + (SLA Compliance × 50%)
Alert Score = 100 - (Alerts per Site × 10)
```

**Status**:
- **Healthy**: 80-100
- **Warning**: 60-79
- **Critical**: Below 60

## 🔍 Data Sources

### PostgreSQL (Primary)
- Sites information
- Alerts and tickets
- User data
- Performance metrics

### MySQL (Legacy)
- Down communication records
- Site BM information

## ⚡ Performance

- **Initial Load**: < 2 seconds
- **Cached Data**: 5 minutes
- **Auto-Refresh**: Every 5 minutes
- **API Response**: < 1 second (cached)

## 🎨 Color Coding

### Status Colors
- 🟢 **Green**: Good/Online/Healthy
- 🔵 **Blue**: Normal/Medium
- 🟡 **Yellow**: Warning/Maintenance
- 🟠 **Orange**: High Priority
- 🔴 **Red**: Critical/Offline/Poor

### Chart Colors
- **Critical Alerts**: Red (#ef4444)
- **High Alerts**: Orange (#f59e0b)
- **Medium Alerts**: Blue (#3b82f6)
- **Low Alerts**: Green (#10b981)

## 📱 Responsive Design

The dashboard is fully responsive and works on:
- **Desktop**: Full layout with all charts
- **Tablet**: Stacked layout, scrollable
- **Mobile**: Single column, optimized for touch

## 🔧 Troubleshooting

### Dashboard Not Loading
1. Check user has `dashboard.view` permission
2. Verify database connections (PostgreSQL + MySQL)
3. Check browser console for errors
4. Clear cache and refresh

### Data Not Updating
1. Click manual refresh button
2. Check date range selection
3. Verify API endpoint is accessible
4. Check cache settings (5-minute cache)

### Charts Not Displaying
1. Ensure data exists for selected date range
2. Check browser console for errors
3. Verify Recharts library is loaded
4. Try different date range

### Slow Performance
1. Data is cached for 5 minutes
2. Reduce date range for faster queries
3. Check database performance
4. Verify Redis cache is working

## 💡 Tips

1. **Use Date Range**: Narrow date range for faster loading
2. **Monitor Auto-Refresh**: Dashboard updates every 5 minutes automatically
3. **Check Critical Issues**: Review active critical issues regularly
4. **Track Trends**: Use alert trends to identify patterns
5. **Customer Health**: Monitor customer health matrix for at-risk customers

## 🎯 Key Metrics to Watch

### Daily
- Active Critical Issues
- Down Communication Summary
- SLA Compliance

### Weekly
- Alert Trends
- Top Sites by Alerts
- Team Performance

### Monthly
- Month-over-Month Comparison
- Revenue & Cost Analysis
- Customer Health Matrix

## 📞 Support

If you encounter issues:
1. Check this guide first
2. Verify permissions and database connections
3. Review browser console for errors
4. Contact system administrator

## 🎉 You're Ready!

The Executive Dashboard provides a comprehensive view of your system's health and performance. Use it to make data-driven decisions and identify issues before they become critical.

**Happy Monitoring!** 📊
