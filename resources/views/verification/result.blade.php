@extends('layouts.app')

@section('title', 'Verification Results - SEO Workbook Verifier')

@section('styles')
<style>
    :root {
        --primary: #667eea;
        --danger: #cb0000;
        --success: #28a745;
        --warning: #ffc107;
        --info: #17a2b8;
    }

    body { background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); min-height: 100vh; }
    
    .results-wrapper { 
        max-width: 1200px; 
        margin: 40px auto;
        padding: 0 15px;
    }

    .header-section {
        text-align: center;
        margin-bottom: 40px;
        color: white;
        text-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .header-section h1 {
        font-size: 42px;
        font-weight: 700;
        margin: 0 0 10px 0;
        background: linear-gradient(135deg, var(--primary) 0%, #764ba2 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }

    .header-section p {
        font-size: 16px;
        color: #666;
        margin: 5px 0 0 0;
    }

    .summary-card { 
        background: white; 
        border-radius: 16px; 
        padding: 30px;
        margin-bottom: 25px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.08);
        border-top: 4px solid var(--primary);
        transition: all 0.3s ease;
    }

    .summary-card:hover {
        box-shadow: 0 15px 50px rgba(0,0,0,0.12);
    }

    .summary-card h5 {
        font-size: 20px;
        font-weight: 600;
        color: #333;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
    }

    .summary-card h5::before {
        content: '';
        width: 4px;
        height: 24px;
        background: linear-gradient(135deg, var(--primary), #764ba2);
        border-radius: 2px;
        margin-right: 12px;
    }

    .stat-card {
        text-align: center;
        padding: 25px 15px;
        background: linear-gradient(135deg, #f8f9ff 0%, #f1f3ff 100%);
        border-radius: 12px;
        border: 1px solid #e0e7ff;
        transition: all 0.3s ease;
    }

    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(102, 126, 234, 0.15);
    }

    .stat-value { 
        font-size: 32px; 
        font-weight: 700;
        color: var(--primary);
        line-height: 1;
        margin-bottom: 8px;
    }

    .stat-label { 
        color: #6c757d; 
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .download-btn {
        display: inline-block;
        width: 32%;
        padding: 16px 12px;
        margin: 8px 0.8%;
        margin-bottom: 12px;
        border-radius: 10px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s;
        text-align: center;
        font-size: 14px;
        border: none;
        cursor: pointer;
    }

    .download-btn:hover { 
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    }

    .download-btn:active {
        transform: translateY(-1px);
    }

    .btn-excel { 
        background: linear-gradient(135deg, #217346 0%, #2d9e3e 100%); 
        color: white; 
    }

    .btn-word { 
        background: linear-gradient(135deg, #2b579a 0%, #1f4788 100%); 
        color: white; 
    }

    .btn-pdf { 
        background: linear-gradient(135deg, #cb0000 0%, #a00000 100%); 
        color: white; 
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-present { 
        background: #d4edda; 
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .status-missing { 
        background: #f8d7da; 
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .status-cannot-verify { 
        background: #fff3cd; 
        color: #856404;
        border: 1px solid #ffeaa7;
    }

    .table-responsive {
        border-radius: 12px;
        overflow: hidden;
    }

    .table {
        margin-bottom: 0;
        font-size: 13px;
    }

    .table thead {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    .table thead th {
        border: none;
        padding: 16px 12px;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 12px;
        letter-spacing: 0.5px;
    }

    .table tbody tr {
        border-bottom: 1px solid #e9ecef;
        transition: background 0.2s ease;
    }

    .table tbody tr:hover {
        background: #f8f9ff;
    }

    .table tbody td {
        padding: 14px 12px;
        vertical-align: middle;
    }

    .action-buttons {
        text-align: center;
        margin-top: 40px;
        padding: 30px;
        background: white;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.08);
    }

    .btn-primary, .btn-secondary {
        padding: 12px 30px;
        font-weight: 600;
        border-radius: 10px;
        border: none;
        transition: all 0.3s ease;
        margin: 0 10px;
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary) 0%, #764ba2 100%);
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
        color: white;
        text-decoration: none;
    }

    .btn-secondary {
        background: #e9ecef;
        color: #495057;
    }

    .btn-secondary:hover {
        background: #dee2e6;
        color: #333;
        text-decoration: none;
    }

    @media (max-width: 1024px) {
        .download-btn {
            width: 48%;
            margin: 8px 1%;
        }
    }

    @media (max-width: 768px) {
        .results-wrapper { margin: 20px auto; }
        .summary-card { padding: 20px; }
        .header-section h1 { font-size: 28px; }
        .download-btn { width: 100%; margin: 8px 0; }
        .stat-card { padding: 15px; margin-bottom: 10px; }
        .stat-value { font-size: 24px; }
        .table { font-size: 12px; }
        .table thead th { padding: 12px 8px; }
        .table tbody td { padding: 10px 8px; }
    }
</style>
@endsection

@section('content')
<div class="results-wrapper">
    <div class="header-section">
        <h1>✅ Verification Complete!</h1>
        <p>Processed in <strong>{{ $elapsed }}s</strong></p>
    </div>

    <!-- Download Buttons -->
    <div class="summary-card">
        <h5>📥 Download Reports</h5>
        <a href="{{ route('verification.download', 'pdf') }}" class="download-btn btn-pdf">
            📄 PDF Report
        </a>
        <a href="{{ route('verification.download', 'excel') }}" class="download-btn btn-excel">
            📊 Excel Report
        </a>
        <a href="{{ route('verification.download', 'word') }}" class="download-btn btn-word">
            📝 Word Report
        </a>
    </div>

    <!-- Executive Summary -->
    <div class="summary-card">
        <h5>📈 Executive Summary</h5>
        <div class="row g-3">
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value">{{ $results['summary']['overall']['total_rows'] }}</div>
                        <div class="stat-label">Total Rows</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value">{{ $results['summary']['overall']['total_urls_checked'] }}</div>
                        <div class="stat-label">URLs Checked</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value">{{ $results['summary']['overall']['unique_domains'] ?? 0 }}</div>
                        <div class="stat-label">Unique Domains</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value text-success">{{ $results['summary']['overall']['working_urls'] }}</div>
                        <div class="stat-label">Working</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value text-primary">{{ $results['summary']['overall']['valid_urls'] }}</div>
                        <div class="stat-label">Valid URLs</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value text-danger">{{ $results['summary']['overall']['broken_urls'] }}</div>
                        <div class="stat-label">Broken</div>
                    </div>
                </div>
            </div>
            <div class="row mt-3">
                 <div class="col-md-2">
                     <div class="stat-card">
                         <div class="stat-value">{{ $results['summary']['overall']['cannot_verify_urls'] }}</div>
                         <div class="stat-label">Cannot Verify</div>
                         @if(($results['summary']['overall']['cannot_verify_breakdown']['forbidden'] ?? 0) > 0)
                             <div class="small text-muted">Including {{ $results['summary']['overall']['cannot_verify_breakdown']['forbidden'] }} Forbidden</div>
                         @endif
                     </div>
                 </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value">{{ $results['summary']['overall']['redirected_urls'] }}</div>
                        <div class="stat-label">Redirected</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value">{{ $results['summary']['overall']['timeout_urls'] }}</div>
                        <div class="stat-label">Timeout</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value">{{ $results['summary']['overall']['blank_posts'] }}</div>
                        <div class="stat-label">Blank Posts</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value text-warning">{{ $results['summary']['overall']['low_content_posts'] }}</div>
                        <div class="stat-label">Low Content</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value">{{ count($results['summary']['overall']['weeks_found']) }}</div>
                        <div class="stat-label">Weeks Found</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Worksheet Summary -->
        <div class="summary-card">
            <h5 class="mb-3">Worksheet Summary</h5>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Worksheet</th>
                            <th>Rows</th>
                            <th>Checked</th>
                            <th>Working</th>
                            <th>Cannot Verify</th>
                            <th>Valid</th>
                            <th>Broken</th>
                            <th>Blank</th>
                            <th>Low</th>
                            <th>Redirected</th>
                            <th>Timeout</th>
                            <th>Unique</th>
                            <th>Weeks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($results['summary']['worksheets'] as $name => $data)
                        <tr>
                            <td>{{ $name }}</td>
                            <td>{{ $data['total_rows'] }}</td>
                            <td>{{ $data['working_urls'] + $data['broken_urls'] + $data['cannot_verify_urls'] + $data['redirected_urls'] + $data['timeout_urls'] }}</td>
                            <td>{{ $data['working_urls'] }}</td>
                            <td>{{ $data['cannot_verify_urls'] }}</td>
                            <td>{{ $data['valid_urls'] }}</td>
                            <td>{{ $data['broken_urls'] }}</td>
                            <td>{{ $data['blank_posts'] }}</td>
                            <td>{{ $data['low_content_posts'] }}</td>
                            <td>{{ $data['redirected_urls'] }}</td>
                            <td>{{ $data['timeout_urls'] }}</td>
                            <td>{{ $data['unique_domains'] ?? 0 }}</td>
                            <td>{{ count($data['weeks']) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Period Coverage -->
        <div class="summary-card">
            <h5 class="mb-3">Period Coverage</h5>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Worksheet</th>
                            <th>Status</th>
                            <th>Date Range</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($results['coverage'] as $name => $data)
                        <tr>
                            <td>{{ $name }}</td>
                            <td>
                                @if($data['status'] === 'Present')
                                    <span class="status-badge status-present">Present</span>
                                @elseif($data['status'] === 'Missing')
                                    <span class="status-badge status-missing">Missing</span>
                                @else
                                    <span class="status-badge status-cannot-verify">Cannot Verify</span>
                                @endif
                            </td>
                            <td>{{ $data['start_date'] ?? '-' }} to {{ $data['end_date'] ?? '-' }}</td>
                            <td>{{ implode(', ', $data['notes']) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Exceptions Summary - REMOVED PER USER REQUEST -->

        <div class="action-buttons">
            <a href="{{ route('verification.index') }}" class="btn btn-primary">🔄 Run Another Verification</a>
            <a href="{{ route('verification.index') }}" class="btn btn-secondary">🏠 Go Home</a>
        </div>
    </div>
</div>
@endsection