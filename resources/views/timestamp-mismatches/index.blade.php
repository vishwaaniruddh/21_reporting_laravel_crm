<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Timestamp Mismatch Checker</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 30px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        input[type="date"] {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            width: 250px;
        }
        
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
        }
        
        button:hover {
            background: #0056b3;
        }
        
        button:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .loading {
            display: none;
            margin-top: 20px;
            padding: 15px;
            background: #e3f2fd;
            border-left: 4px solid #2196f3;
            border-radius: 4px;
            color: #1976d2;
        }
        
        .loading.show {
            display: block;
        }
        
        .results {
            display: none;
            margin-top: 30px;
        }
        
        .results.show {
            display: block;
        }
        
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            padding: 20px;
            border-radius: 6px;
            background: #f8f9fa;
            border-left: 4px solid #007bff;
        }
        
        .summary-card.success {
            background: #d4edda;
            border-left-color: #28a745;
        }
        
        .summary-card.warning {
            background: #fff3cd;
            border-left-color: #ffc107;
        }
        
        .summary-card.danger {
            background: #f8d7da;
            border-left-color: #dc3545;
        }
        
        .summary-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .summary-value {
            font-size: 28px;
            font-weight: bold;
            color: #333;
        }
        
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-danger {
            background: #dc3545;
            color: white;
        }
        
        .badge-warning {
            background: #ffc107;
            color: #333;
        }
        
        .badge-success {
            background: #28a745;
            color: white;
        }
        
        .mismatch-indicator {
            color: #dc3545;
            font-weight: bold;
        }
        
        .match-indicator {
            color: #28a745;
        }
        
        .diff-hours {
            color: #dc3545;
            font-weight: 600;
        }
        
        .error-message {
            display: none;
            margin-top: 20px;
            padding: 15px;
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            border-radius: 4px;
            color: #721c24;
        }
        
        .error-message.show {
            display: block;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .fix-buttons button {
            margin-right: 10px;
        }
        
        .fix-buttons button:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Timestamp Mismatch Checker</h1>
        <p class="subtitle">Compare timestamps between MySQL and PostgreSQL partition tables</p>
        
        <div class="form-group">
            <label for="checkDate">Select Date:</label>
            <input type="date" id="checkDate" value="{{ date('Y-m-d') }}">
            
            <label for="checkMode" style="margin-left: 20px;">Mode:</label>
            <select id="checkMode" style="padding: 10px 15px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px;">
                <option value="quick">Quick Check (Sample 100 records)</option>
                <option value="full">Full Check (All records)</option>
            </select>
            
            <button onclick="checkMismatches()" id="checkBtn">Check Mismatches</button>
        </div>
        
        <div class="fix-buttons" id="fixButtons" style="display: none; margin-top: 20px; padding: 20px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
            <h3 style="margin-bottom: 15px; color: #856404;">⚠️ Fix Timestamp Mismatches</h3>
            <p style="margin-bottom: 15px; color: #856404;">This will add 5 hours 30 minutes to all timestamps in PostgreSQL to match MySQL (IST timezone).</p>
            
            <button onclick="fixAllMismatches()" id="fixAllBtn" style="background: #dc3545; margin-right: 10px;">
                Fix All Mismatches in Partition
            </button>
            
            <button onclick="fixVisibleRecords()" id="fixVisibleBtn" style="background: #fd7e14;">
                Fix Visible Records Only
            </button>
            
            <div id="fixLoading" style="display: none; margin-top: 15px; padding: 10px; background: white; border-radius: 4px; color: #856404;">
                <strong>⏳ Fixing timestamps...</strong> Please wait...
            </div>
            
            <div id="fixSuccess" style="display: none; margin-top: 15px; padding: 10px; background: #d4edda; border-left: 4px solid #28a745; border-radius: 4px; color: #155724;">
                <!-- Success message will be displayed here -->
            </div>
            
            <div id="fixError" style="display: none; margin-top: 15px; padding: 10px; background: #f8d7da; border-left: 4px solid #dc3545; border-radius: 4px; color: #721c24;">
                <!-- Error message will be displayed here -->
            </div>
        </div>
        
        <div class="info-box" style="margin-bottom: 20px; padding: 15px; background: #e3f2fd; border-left: 4px solid #2196f3; border-radius: 4px;">
            <strong>💡 Tip:</strong> Use <strong>Quick Check</strong> for fast results (2-5 seconds). It samples 100 random records and estimates total mismatches. Use <strong>Full Check</strong> for exact count (may take longer for large datasets).
        </div>
        
        <div class="loading" id="loading">
            <strong>⏳ Checking...</strong> Comparing timestamps between MySQL and PostgreSQL...
        </div>
        
        <div class="error-message" id="errorMessage"></div>
        
        <div class="results" id="results">
            <h2 style="margin-bottom: 20px;">Results for <span id="resultDate"></span></h2>
            
            <div class="summary">
                <div class="summary-card">
                    <div class="summary-label">MySQL Alerts</div>
                    <div class="summary-value" id="totalMysql">0</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">PostgreSQL Alerts</div>
                    <div class="summary-value" id="totalPostgres">0</div>
                </div>
                <div class="summary-card success">
                    <div class="summary-label" id="matchedLabel">Matched</div>
                    <div class="summary-value" id="totalMatched">0</div>
                </div>
                <div class="summary-card danger">
                    <div class="summary-label" id="mismatchedLabel">Mismatched</div>
                    <div class="summary-value" id="totalMismatched">0</div>
                </div>
            </div>
            
            <div id="modeInfo" style="display: none; margin-bottom: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; border-radius: 4px;">
                <!-- Mode-specific information will be displayed here -->
            </div>
            
            <div id="mismatchesContainer"></div>
        </div>
    </div>
    
    <script>
        async function checkMismatches() {
            const date = document.getElementById('checkDate').value;
            const mode = document.getElementById('checkMode').value;
            const loading = document.getElementById('loading');
            const results = document.getElementById('results');
            const errorMessage = document.getElementById('errorMessage');
            const checkBtn = document.getElementById('checkBtn');
            
            // Reset
            loading.classList.add('show');
            results.classList.remove('show');
            errorMessage.classList.remove('show');
            checkBtn.disabled = true;
            
            try {
                const response = await fetch('/api/timestamp-mismatches/check', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({ date, mode })
                });
                
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.message || 'Failed to check mismatches');
                }
                
                displayResults(data);
                
            } catch (error) {
                errorMessage.textContent = '❌ Error: ' + error.message;
                errorMessage.classList.add('show');
            } finally {
                loading.classList.remove('show');
                checkBtn.disabled = false;
            }
        }
        
        function displayResults(data) {
            const results = document.getElementById('results');
            const resultDate = document.getElementById('resultDate');
            const totalMysql = document.getElementById('totalMysql');
            const totalPostgres = document.getElementById('totalPostgres');
            const totalMatched = document.getElementById('totalMatched');
            const totalMismatched = document.getElementById('totalMismatched');
            const matchedLabel = document.getElementById('matchedLabel');
            const mismatchedLabel = document.getElementById('mismatchedLabel');
            const modeInfo = document.getElementById('modeInfo');
            const mismatchesContainer = document.getElementById('mismatchesContainer');
            const fixButtons = document.getElementById('fixButtons');
            
            // Store current data for fixing
            currentMismatches = data.mismatches;
            currentDate = data.date;
            
            // Show/hide fix buttons based on mismatches
            if (data.mismatches.length > 0) {
                fixButtons.style.display = 'block';
            } else {
                fixButtons.style.display = 'none';
            }
            
            // Update summary
            resultDate.textContent = data.date;
            totalMysql.textContent = data.summary.total_mysql.toLocaleString();
            totalPostgres.textContent = data.summary.total_postgres.toLocaleString();
            
            // Display mode-specific information
            if (data.mode === 'quick') {
                matchedLabel.textContent = 'Sample Matched';
                mismatchedLabel.textContent = 'Sample Mismatched';
                totalMatched.textContent = data.summary.sample_matched;
                totalMismatched.textContent = data.summary.sample_mismatched;
                
                modeInfo.style.display = 'block';
                modeInfo.innerHTML = `
                    <strong>📊 Quick Check Results (Sample: ${data.summary.sample_size} records)</strong><br>
                    Match Rate: ${data.summary.match_percentage}%<br>
                    Estimated Total Mismatches: ~${data.summary.estimated_total_mismatches.toLocaleString()} records<br>
                    <em>For exact count, use Full Check mode.</em>
                `;
            } else {
                matchedLabel.textContent = 'Matched';
                mismatchedLabel.textContent = 'Mismatched';
                totalMatched.textContent = data.summary.matched.toLocaleString();
                totalMismatched.textContent = data.summary.mismatched.toLocaleString();
                
                modeInfo.style.display = 'block';
                modeInfo.innerHTML = `
                    <strong>✅ Full Check Complete</strong><br>
                    All ${data.summary.total_mysql.toLocaleString()} records have been checked.
                `;
            }
            
            // Display mismatches
            if (data.mismatches.length === 0) {
                mismatchesContainer.innerHTML = '<div class="no-data">✅ No mismatches found! All timestamps match perfectly.</div>';
            } else {
                let html = '<div class="table-container"><table>';
                html += '<thead><tr>';
                html += '<th>Alert ID</th>';
                html += '<th>Issue</th>';
                html += '<th>Panel ID</th>';
                html += '<th>Zone</th>';
                html += '<th>MySQL createtime</th>';
                html += '<th>PG createtime</th>';
                html += '<th>MySQL receivedtime</th>';
                html += '<th>PG receivedtime</th>';
                html += '<th>MySQL closedtime</th>';
                html += '<th>PG closedtime</th>';
                html += '<th>Diff (hours)</th>';
                html += '</tr></thead><tbody>';
                
                data.mismatches.forEach(mismatch => {
                    html += '<tr>';
                    html += `<td>${mismatch.id}</td>`;
                    html += `<td><span class="badge badge-${mismatch.issue === 'timestamp_mismatch' ? 'danger' : 'warning'}">${mismatch.issue.replace('_', ' ')}</span></td>`;
                    html += `<td>${mismatch.panelid || '-'}</td>`;
                    html += `<td>${mismatch.zone || '-'}</td>`;
                    html += `<td>${mismatch.mysql_createtime || 'NULL'}</td>`;
                    html += `<td class="${mismatch.createtime_mismatch ? 'mismatch-indicator' : ''}">${mismatch.pg_createtime || 'NULL'}</td>`;
                    html += `<td>${mismatch.mysql_receivedtime || 'NULL'}</td>`;
                    html += `<td class="${mismatch.receivedtime_mismatch ? 'mismatch-indicator' : ''}">${mismatch.pg_receivedtime || 'NULL'}</td>`;
                    html += `<td>${mismatch.mysql_closedtime || 'NULL'}</td>`;
                    html += `<td class="${mismatch.closedtime_mismatch ? 'mismatch-indicator' : ''}">${mismatch.pg_closedtime || 'NULL'}</td>`;
                    html += `<td class="diff-hours">${mismatch.receivedtime_diff_hours || '-'}</td>`;
                    html += '</tr>';
                });
                
                html += '</tbody></table></div>';
                
                if (data.mode === 'quick' && data.mismatches.length > 0) {
                    html += '<div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 4px;">';
                    html += '<strong>Note:</strong> Showing sample mismatches. Switch to Full Check mode to see all mismatches.';
                    html += '</div>';
                }
                
                mismatchesContainer.innerHTML = html;
            }
            
            results.classList.add('show');
        }
        
        // Store current mismatches for fixing
        let currentMismatches = [];
        let currentDate = '';
        
        async function fixAllMismatches() {
            if (!confirm('⚠️ WARNING: This will fix ALL records in the partition table by adding 5 hours 30 minutes to all timestamps.\n\nThis action cannot be undone!\n\nAre you sure you want to proceed?')) {
                return;
            }
            
            if (!confirm('⚠️ FINAL CONFIRMATION: You are about to modify ALL timestamps in the partition table.\n\nType OK in the next prompt to confirm.')) {
                return;
            }
            
            const userConfirm = prompt('Type "FIX ALL" to confirm:');
            if (userConfirm !== 'FIX ALL') {
                alert('Fix cancelled. You must type "FIX ALL" exactly.');
                return;
            }
            
            await performFix(true, []);
        }
        
        async function fixVisibleRecords() {
            if (currentMismatches.length === 0) {
                alert('No mismatches to fix.');
                return;
            }
            
            const alertIds = currentMismatches.map(m => m.id);
            
            if (!confirm(`⚠️ WARNING: This will fix ${alertIds.length} visible records by adding 5 hours 30 minutes to their timestamps.\n\nThis action cannot be undone!\n\nAre you sure you want to proceed?`)) {
                return;
            }
            
            await performFix(false, alertIds);
        }
        
        async function performFix(fixAll, alertIds) {
            const fixLoading = document.getElementById('fixLoading');
            const fixSuccess = document.getElementById('fixSuccess');
            const fixError = document.getElementById('fixError');
            const fixAllBtn = document.getElementById('fixAllBtn');
            const fixVisibleBtn = document.getElementById('fixVisibleBtn');
            
            // Reset messages
            fixLoading.style.display = 'block';
            fixSuccess.style.display = 'none';
            fixError.style.display = 'none';
            fixAllBtn.disabled = true;
            fixVisibleBtn.disabled = true;
            
            try {
                const response = await fetch('/api/timestamp-mismatches/fix', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        date: currentDate,
                        fix_all: fixAll,
                        alert_ids: alertIds
                    })
                });
                
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.message || 'Failed to fix timestamps');
                }
                
                fixSuccess.innerHTML = `<strong>✅ Success!</strong> ${data.message}<br><em>Re-checking timestamps...</em>`;
                fixSuccess.style.display = 'block';
                
                // Re-check after 2 seconds
                setTimeout(() => {
                    checkMismatches();
                }, 2000);
                
            } catch (error) {
                fixError.innerHTML = `<strong>❌ Error:</strong> ${error.message}`;
                fixError.style.display = 'block';
            } finally {
                fixLoading.style.display = 'none';
                fixAllBtn.disabled = false;
                fixVisibleBtn.disabled = false;
            }
        }
        
        // Auto-check on page load
        window.addEventListener('load', () => {
            checkMismatches();
        });
    </script>
</body>
</html>
