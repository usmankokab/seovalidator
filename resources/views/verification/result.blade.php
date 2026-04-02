<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Results - SEO Workbook Verification</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .container { max-width: 1000px; margin-top: 30px; }
        .summary-card { 
            background: white; 
            border-radius: 12px; 
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-card {
            text-align: center;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .stat-value { font-size: 28px; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 14px; }
        .download-btn {
            display: block;
            width: 100%;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s;
        }
        .download-btn:hover { transform: translateY(-2px); }
        .btn-excel { background: #217346; color: white; }
        .btn-word { background: #2b579a; color: white; }
        .btn-pdf { background: #cb0000; color: white; }
        .status-badge {
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .status-present { background: #d4edda; color: #155724; }
        .status-missing { background: #f8d7da; color: #721c24; }
        .status-cannot-verify { background: #fff3cd; color: #856404; }
    </style>
</head>
<body>
    <div class="container">
        <div class="text-center mb-4">
            <h1>Verification Complete</h1>
            <p class="text-muted">Processed in {{ $elapsed }}s</p>
        </div>

        <!-- Download Buttons -->
        <div class="summary-card">
            <h5 class="mb-3">Download Reports</h5>
            <a href="{{ route('verification.download', 'excel') }}" class="download-btn btn-excel">
                📊 Download Excel Report
            </a>
            <a href="{{ route('verification.download', 'word') }}" class="download-btn btn-word">
                📝 Download Word Report
            </a>
            <a href="{{ route('verification.download', 'pdf') }}" class="download-btn btn-pdf">
                📄 Download PDF Report
            </a>
        </div>

        <!-- Executive Summary -->
        <div class="summary-card">
            <h5 class="mb-3">Executive Summary</h5>
            <div class="row">
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
                        <div class="stat-value text-success">{{ $results['summary']['overall']['working_urls'] }}</div>
                        <div class="stat-label">Working</div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value">{{ $results['summary']['overall']['cannot_verify_urls'] }}</div>
                        <div class="stat-label">Cannot Verify</div>
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
                <div class="col-md-2">
                    <div class="stat-card">
                        <div class="stat-value">{{ $results['summary']['overall']['unique_domains'] ?? 0 }}</div>
                        <div class="stat-label">Unique Domains</div>
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
                            <th>Working</th>
                            <th>Cannot Verify</th>
                            <th>Valid</th>
                            <th>Broken</th>
                            <th>Blank</th>
                            <th>Low</th>
                            <th>Redirected</th>
                            <th>Timeout</th>
                            <th>Weeks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($results['summary']['worksheets'] as $name => $data)
                        <tr>
                            <td>{{ $name }}</td>
                            <td>{{ $data['total_rows'] }}</td>
                            <td>{{ $data['working_urls'] }}</td>
                            <td>{{ $data['cannot_verify_urls'] }}</td>
                            <td>{{ $data['valid_urls'] }}</td>
                            <td>{{ $data['broken_urls'] }}</td>
                            <td>{{ $data['blank_posts'] }}</td>
                            <td>{{ $data['low_content_posts'] }}</td>
                            <td>{{ $data['redirected_urls'] }}</td>
                            <td>{{ $data['timeout_urls'] }}</td>
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

        <div class="text-center mt-4 mb-4">
            <a href="{{ route('verification.index') }}" class="btn btn-primary">Run Another Verification</a>
        </div>
    </div>
</body>
</html>