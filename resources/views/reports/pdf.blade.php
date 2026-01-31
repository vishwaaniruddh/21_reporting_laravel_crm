<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Alert Report' }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .header .subtitle {
            color: #666;
            font-size: 14px;
        }
        .section {
            margin-bottom: 20px;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            background-color: #f5f5f5;
            padding: 5px 10px;
        }
        .summary-box {
            background-color: #e8f4f8;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .summary-box .big-number {
            font-size: 32px;
            font-weight: bold;
            color: #2c5282;
        }
        .stats-grid {
            display: table;
            width: 100%;
        }
        .stats-row {
            display: table-row;
        }
        .stats-cell {
            display: table-cell;
            padding: 5px 10px;
            border-bottom: 1px solid #ddd;
        }
        .stats-label {
            font-weight: bold;
            width: 40%;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #4a5568;
            color: white;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .filters {
            background-color: #fff3cd;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .filters strong {
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title ?? 'Alert Report' }}</h1>
        <div class="subtitle">Generated: {{ $generated_at ?? now()->toDateTimeString() }}</div>
    </div>

    @if(!empty($filters))
    <div class="filters">
        <strong>Applied Filters:</strong>
        @foreach($filters as $key => $value)
            @if($value)
                {{ ucfirst(str_replace('_', ' ', $key)) }}: {{ $value }}@if(!$loop->last), @endif
            @endif
        @endforeach
    </div>
    @endif

    <div class="section">
        <div class="section-title">Summary</div>
        <div class="summary-box">
            <div class="big-number">{{ number_format($summary['total_alerts'] ?? 0) }}</div>
            <div>Total Alerts</div>
        </div>
    </div>

    @if(!empty($statistics))
    <div class="section">
        <div class="section-title">Statistics by Type</div>
        @if(!empty($statistics['by_type']))
        <div class="stats-grid">
            @foreach($statistics['by_type'] as $type => $count)
            <div class="stats-row">
                <div class="stats-cell stats-label">{{ $type }}</div>
                <div class="stats-cell">{{ number_format($count) }}</div>
            </div>
            @endforeach
        </div>
        @else
        <p>No data available</p>
        @endif
    </div>

    <div class="section">
        <div class="section-title">Statistics by Priority</div>
        @if(!empty($statistics['by_priority']))
        <div class="stats-grid">
            @foreach($statistics['by_priority'] as $priority => $count)
            <div class="stats-row">
                <div class="stats-cell stats-label">{{ $priority }}</div>
                <div class="stats-cell">{{ number_format($count) }}</div>
            </div>
            @endforeach
        </div>
        @else
        <p>No data available</p>
        @endif
    </div>

    <div class="section">
        <div class="section-title">Statistics by Status</div>
        @if(!empty($statistics['by_status']))
        <div class="stats-grid">
            @foreach($statistics['by_status'] as $status => $count)
            <div class="stats-row">
                <div class="stats-cell stats-label">{{ $status }}</div>
                <div class="stats-cell">{{ number_format($count) }}</div>
            </div>
            @endforeach
        </div>
        @else
        <p>No data available</p>
        @endif
    </div>
    @endif

    @if(!empty($alerts) && count($alerts) > 0)
    <div class="section">
        <div class="section-title">Alert Details ({{ count($alerts) }} of {{ number_format($total_records ?? count($alerts)) }})</div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Panel</th>
                    <th>Type</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                @foreach($alerts as $alert)
                <tr>
                    <td>{{ $alert->id ?? $alert['id'] ?? '-' }}</td>
                    <td>{{ $alert->panelid ?? $alert['panelid'] ?? '-' }}</td>
                    <td>{{ $alert->alerttype ?? $alert['alerttype'] ?? '-' }}</td>
                    <td>{{ $alert->priority ?? $alert['priority'] ?? '-' }}</td>
                    <td>{{ $alert->status ?? $alert['status'] ?? '-' }}</td>
                    <td>{{ isset($alert->createtime) ? $alert->createtime->format('Y-m-d H:i') : ($alert['createtime'] ?? '-') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    <div class="footer">
        <p>This report was generated from PostgreSQL reporting database.</p>
        <p>⚠️ Data is synced from production MySQL - may have slight delay.</p>
    </div>
</body>
</html>
