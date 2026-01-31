@extends('layouts.app')

@section('content')
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1">All Reports</h4>
                <p class="text-muted mb-0">Filter and export incident reports</p>
            </div>
            <div class="d-flex gap-2">
                <button id="exportCsvBtn" onclick="exportToCsv()" class="btn btn-outline-primary btn-sm">
                    <span id="exportCsvText"><i class="fas fa-file-csv me-1"></i>Export CSV</span>
                    <span id="exportCsvSpinner" style="display: none;">
                        <i class="fas fa-spinner fa-spin me-1"></i>Processing...
                    </span>
                </button>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Reports</h6>
            </div>
            <div class="card-body">
                <form method="GET" action="{{ route('reports.viewalerts') }}" class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label">Panel ID</label>
                        <input type="text" name="panelid" class="form-control form-control-sm" 
                               value="{{ request('panelid') }}" placeholder="Enter Panel ID">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">DVR IP</label>
                        <input type="text" name="dvrip" class="form-control form-control-sm" 
                               value="{{ request('dvrip') }}" placeholder="Enter DVR IP">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Customer</label>
                        <select name="customer" class="form-select form-select-sm">
                            <option value="">All Customers</option>
                            @foreach($customers as $customer)
                                <option value="{{ $customer->id }}" {{ request('customer') == $customer->id ? 'selected' : '' }}>
                                    {{ $customer->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Panel Type</label>
                        <select name="panel" class="form-select form-select-sm">
                            <option value="">All Panels</option>
                            @foreach($panelMakes as $panel)
                                <option value="{{ $panel }}" {{ request('panel') == $panel ? 'selected' : '' }}>
                                    {{ $panel }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">ATM ID</label>
                        <input type="text" name="atmid" class="form-control form-control-sm" 
                               value="{{ request('atmid') }}" placeholder="Enter ATM ID">
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">From Date</label>
                        <input type="date" name="from_date" class="form-control form-control-sm" 
                               value="{{ request('from_date') }}">
                    </div>
                    
                    <!-- <div class="col-md-2">
                        <label class="form-label">To Date</label>
                        <input type="date" name="to_date" class="form-control form-control-sm" 
                               value="{{ request('to_date') }}">
                    </div> -->
                    
                    <div class="col-12">
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-search me-1"></i>Filter
                            </button>
                            <a href="{{ route('reports.viewalerts') }}" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times me-1"></i>Clear
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Results Section -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-list me-2"></i>Reports</h6>
                                        <div class="d-flex align-items-center gap-3">
                            <span class="badge bg-primary">{{ $totalRecords }} Total Records</span>
                            
                            <!-- Items per page selector -->
                            <div class="d-flex align-items-center gap-2">
                                <label class="form-label mb-0" style="font-size: 0.75rem;">Show:</label>
                                <select class="form-select form-select-sm" style="width: auto;" onchange="changePerPage(this.value)">
                                    <option value="10" {{ request('per_page', 10) == 10 ? 'selected' : '' }}>10</option>
                                    <option value="25" {{ request('per_page', 10) == 25 ? 'selected' : '' }}>25</option>
                                    <option value="50" {{ request('per_page', 10) == 50 ? 'selected' : '' }}>50</option>
                                    <option value="100" {{ request('per_page', 10) == 100 ? 'selected' : '' }}>100</option>
                                </select>
                            </div>
                        </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" style="overflow-x: auto;">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Client</th>
                                <th>Incident #</th>
                                <th>Region</th>
                                <th>ATM ID</th>
                                <th>Address</th>
                                <th>City</th>
                                <th>State</th>
                                <th>Zone</th>
                                <th>Alarm</th>
                                <th>Category</th>
                                <th>Message</th>
                                <th>Created</th>
                                <th>Received</th>
                                <th>Closed</th>
                                <th>DVR IP</th>
                                <th>Panel</th>
                                <th>Panel ID</th>
                                <th>Bank</th>
                                <th>Type</th>
                                <th>Closed By</th>
                                <th>Closed Date</th>
                                <th>Aging (hrs)</th>
                                <th>Remark</th>
                                <th>Send IP</th>
                                <th>Testing</th>
                                <th>Testing Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($reports as $report)
                                <tr>
                                    <td>
                                        <span class="fw-medium">{{ $report->Customer ?? '-' }}</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">{{ $report->id }}</span>
                                    </td>
                                    <td>{{ $report->site_zone ?? '-' }}</td>
                                    <td>{{ $report->ATMID ?? '-' }}</td>
                                    <td>
                                        <span class="text-truncate d-inline-block" style="max-width: 150px;" 
                                              title="{{ $report->SiteAddress ?? '' }}">
                                            {{ $report->SiteAddress ?? '-' }}
                                        </span>
                                    </td>
                                    <td>{{ $report->City ?? '-' }}</td>
                                    <td>{{ $report->State ?? '-' }}</td>
                                    <td>
                                        <span class="badge bg-info">{{ $report->zone }}</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning">{{ $report->alarm }}</span>
                                    </td>
                                    <td>{{ $report->alerttype }}</td>
                                    <td>
                                        @if(str_ends_with($report->alarm, 'R'))
                                            <span class="text-success">{{ $report->alerttype }} Restoral</span>
                                        @else
                                            {{ $report->alerttype }}
                                        @endif
                                    </td>
                                    <td>
                                        <small class="text-muted">{{ \Carbon\Carbon::parse($report->createtime)->format('M d, Y H:i') }}</small>
                                    </td>
                                    <td>
                                        <small class="text-muted">{{ \Carbon\Carbon::parse($report->receivedtime)->format('M d, Y H:i') }}</small>
                                    </td>
                                    <td>
                                        <small class="text-muted">{{ $report->closedtime ? \Carbon\Carbon::parse($report->closedtime)->format('M d, Y H:i') : '-' }}</small>
                                    </td>
                                    <td>
                                        <code class="small">{{ $report->DVRIP ?? '-' }}</code>
                                    </td>
                                    <td>{{ $report->Panel_Make ?? '-' }}</td>
                                    <td>
                                        <code class="small">{{ $report->panelid }}</code>
                                    </td>
                                    <td>{{ $report->Bank ?? '-' }}</td>
                                    <td>
                                        @if(str_ends_with($report->alarm, 'R'))
                                            <span class="badge bg-success">Non-Reactive</span>
                                        @else
                                            <span class="badge bg-danger">Reactive</span>
                                        @endif
                                    </td>
                                    <td>{{ $report->closedBy ?? '-' }}</td>
                                    <td>
                                        <small class="text-muted">{{ $report->closedtime ? \Carbon\Carbon::parse($report->closedtime)->format('M d, Y') : '-' }}</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">{{ number_format($report->aging, 2) }}h</span>
                                    </td>
                                    <td>
                                        <span class="text-truncate d-inline-block" style="max-width: 100px;" 
                                              title="{{ $report->comment ?? '' }}">
                                            {{ $report->comment ?? '-' }}
                                        </span>
                                    </td>
                                    <td>
                                        <code class="small">{{ $report->sendip ?? '-' }}</code>
                                    </td>
                                    <td>{{ $report->testing_by_service_team ?? '-' }}</td>
                                    <td>{{ $report->testing_remark ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="26" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="fas fa-inbox fa-2x mb-3"></i>
                                            <p class="mb-0">No reports found matching your criteria.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Pagination -->
            @if($reports->hasPages())
                <div class="card-footer bg-transparent border-top-0">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted small">
                            Showing {{ $reports->firstItem() ?? 0 }} to {{ $reports->lastItem() ?? 0 }} of {{ $reports->total() }} results
                        </div>
                        <nav aria-label="Reports pagination">
                            <ul class="pagination pagination-sm mb-0">
                                <!-- First Page -->
                                @if($reports->onFirstPage())
                                    <li class="page-item disabled">
                                        <span class="page-link"><i class="fas fa-angle-double-left"></i></span>
                                    </li>
                                @else
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $reports->url(1) }}" title="First Page">
                                            <i class="fas fa-angle-double-left"></i>
                                        </a>
                                    </li>
                                @endif
                                
                                <!-- Previous Page -->
                                @if($reports->onFirstPage())
                                    <li class="page-item disabled">
                                        <span class="page-link"><i class="fas fa-angle-left"></i></span>
                                    </li>
                                @else
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $reports->previousPageUrl() }}" title="Previous Page">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    </li>
                                @endif
                                
                                <!-- Page Numbers -->
                                @php
                                    $start = max(1, $reports->currentPage() - 2);
                                    $end = min($reports->lastPage(), $reports->currentPage() + 2);
                                    
                                    // Adjust start and end to show 5 pages when possible
                                    if ($end - $start < 4) {
                                        if ($start == 1) {
                                            $end = min($reports->lastPage(), $start + 4);
                                        } else {
                                            $start = max(1, $end - 4);
                                        }
                                    }
                                @endphp
                                
                                <!-- Show first page if not in range -->
                                @if($start > 1)
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $reports->url(1) }}">1</a>
                                    </li>
                                    @if($start > 2)
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    @endif
                                @endif
                                
                                <!-- Page numbers in range -->
                                @for($i = $start; $i <= $end; $i++)
                                    @if($i == $reports->currentPage())
                                        <li class="page-item active">
                                            <span class="page-link">{{ $i }}</span>
                                        </li>
                                    @else
                                        <li class="page-item">
                                            <a class="page-link" href="{{ $reports->url($i) }}">{{ $i }}</a>
                                        </li>
                                    @endif
                                @endfor
                                
                                <!-- Show last page if not in range -->
                                @if($end < $reports->lastPage())
                                    @if($end < $reports->lastPage() - 1)
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    @endif
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $reports->url($reports->lastPage()) }}">{{ $reports->lastPage() }}</a>
                                    </li>
                                @endif
                                
                                <!-- Next Page -->
                                @if($reports->hasMorePages())
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $reports->nextPageUrl() }}" title="Next Page">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                    </li>
                                @else
                                    <li class="page-item disabled">
                                        <span class="page-link"><i class="fas fa-angle-right"></i></span>
                                    </li>
                                @endif
                                
                                <!-- Last Page -->
                                @if($reports->hasMorePages())
                                    <li class="page-item">
                                        <a class="page-link" href="{{ $reports->url($reports->lastPage()) }}" title="Last Page">
                                            <i class="fas fa-angle-double-right"></i>
                                        </a>
                                    </li>
                                @else
                                    <li class="page-item disabled">
                                        <span class="page-link"><i class="fas fa-angle-double-right"></i></span>
                                    </li>
                                @endif
                            </ul>
                        </nav>
                    </div>
                </div>
            @endif
        </div>
    </div>

<script>
function exportToCsv() {
    const exportBtn = document.getElementById('exportCsvBtn');
    const exportText = document.getElementById('exportCsvText');
    const exportSpinner = document.getElementById('exportCsvSpinner');
    
    // Show loading state
    exportBtn.disabled = true;
    exportText.style.display = 'none';
    exportSpinner.style.display = 'inline';
    
    // Get current URL parameters
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('export', 'csv');
    
    // Create download link
    const downloadUrl = '{{ route("reports.viewalerts") }}?' + urlParams.toString();
    
    // Create a temporary link element
    const link = document.createElement('a');
    link.href = downloadUrl;
    link.style.display = 'none';
    document.body.appendChild(link);
    
    // Trigger download
    link.click();
    
    // Remove the temporary link
    document.body.removeChild(link);
    
    // Reset button state after a delay
    setTimeout(() => {
        exportBtn.disabled = false;
        exportText.style.display = 'inline';
        exportSpinner.style.display = 'none';
    }, 3000); // 3 seconds delay to allow for download to start
}

// Add error handling for failed downloads
window.addEventListener('error', function(e) {
    if (e.target.tagName === 'A' && e.target.href.includes('export')) {
        alert('Export failed. Please try again or contact support if the problem persists.');
    }
});

// Function to change items per page
function changePerPage(perPage) {
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('per_page', perPage);
    urlParams.delete('page'); // Reset to first page when changing per_page
    window.location.href = '{{ route("reports.viewalerts") }}?' + urlParams.toString();
}
</script>
@endsection