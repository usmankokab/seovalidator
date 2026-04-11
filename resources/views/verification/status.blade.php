@extends('layouts.app')

@section('title', 'Verification in Progress - SEO Workbook Verifier')

@section('styles')
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .container { max-width: 600px; }
    .progress-card { 
        background: white; 
        border-radius: 12px; 
        padding: 30px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        text-align: center;
    }
    .progress { height: 30px; border-radius: 15px; }
    .progress-bar { animation: progress-animation 1.5s ease-in-out infinite; }
    @keyframes progress-animation {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }
    .status-message { 
        font-size: 16px; 
        color: #6c757d; 
        margin-top: 20px;
        min-height: 24px;
    }
    .elapsed-time {
        font-size: 14px;
        color: #999;
        margin-top: 10px;
    }
    .spinner {
        display: inline-block;
        width: 20px;
        height: 20px;
        margin-right: 10px;
        border: 3px solid #f3f3f3;
        border-top: 3px solid #0d6efd;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    .error-message {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
        padding: 15px;
        border-radius: 8px;
        margin-top: 20px;
    }
    .success-message {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
        padding: 15px;
        border-radius: 8px;
        margin-top: 20px;
    }
    .download-btn {
        margin: 5px;
        font-size: 13px;
    }
    .cancelled-message {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        color: #856404;
        padding: 15px;
        border-radius: 8px;
        margin-top: 20px;
    }
</style>
@endsection

@section('content')
<div class="container">
    <div class="progress-card" id="statusCard">
        <!-- Processing State -->
        <div id="processingState">
            <h3 id="processingTitle">Verification in Progress</h3>
            <p class="text-muted mb-4">Your workbook is being processed...</p>
            
            <div class="d-flex align-items-center justify-content-center mb-3">
                <span class="spinner"></span>
                <span id="progressPercent">0%</span>
            </div>

            <div class="progress mb-3" id="progressBar">
                <div class="progress-bar bg-primary" role="progressbar" style="width: 0%" id="progressFill"></div>
            </div>

            <div class="status-message" id="statusText">
                Initializing...
            </div>

            <div class="elapsed-time" id="elapsedTime">
                Time elapsed: 0s
            </div>

            <button type="button" class="btn btn-danger btn-sm mt-3" id="cancelBtn" onclick="cancelJob()">
                ⏹️ Stop & Go Back Home
            </button>
        </div>

        <!-- Cancelled State -->
        <div id="cancelledState" style="display: none;">
            <h3 style="color: #f39c12;">Job Cancelled</h3>
            <div class="cancelled-message">
                Your verification job has been cancelled and removed from the queue.
            </div>
            <a href="/" class="btn btn-primary btn-sm mt-3">
                🏠 Go Back Home
            </a>
        </div>

        <!-- Error State -->
        <div id="errorState" style="display: none;">
            <h3 style="color: #721c24;">❌ Verification Failed</h3>
            <div class="error-message" id="errorMessage">
                An error occurred during verification. Please try again.
            </div>
            <a href="/" class="btn btn-primary btn-sm mt-3">
                🔄 Try Again
            </a>
        </div>

        <!-- Success State -->
        <div id="successState" style="display: none;">
            <h3 style="color: #155724;">✅ Verification Complete!</h3>
            <div class="success-message">
                Your workbook has been successfully verified. Redirecting to results...
            </div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const jobId = '{{ $job->job_id }}';
    let startTime = Date.now();
    let pollInterval;

    function formatTime(seconds) {
        if (seconds < 60) return seconds + 's';
        if (seconds < 3600) return Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
        return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
    }

    function updateElapsedTime() {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        document.getElementById('elapsedTime').textContent = 'Time elapsed: ' + formatTime(elapsed);
    }

    function pollStatus() {
        fetch('{{ route("verification.status.api") }}?job_id=' + jobId)
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    clearInterval(pollInterval);
                    return;
                }

                // Update progress bar
                document.getElementById('progressFill').style.width = data.progress + '%';
                document.getElementById('progressPercent').textContent = data.progress + '%';

                // Update status message
                document.getElementById('statusText').textContent = data.display_status || 'Processing...';

                // Handle different states
                if (data.status === 'cancelled') {
                    clearInterval(pollInterval);
                    document.getElementById('processingState').style.display = 'none';
                    document.getElementById('cancelledState').style.display = 'block';
                } else if (data.status === 'failed' || data.has_failed) {
                    clearInterval(pollInterval);
                    document.getElementById('processingState').style.display = 'none';
                    document.getElementById('errorState').style.display = 'block';
                    document.getElementById('errorMessage').textContent = data.error_message || 'An unknown error occurred. Please try again.';
                } else if (data.is_complete && data.is_successful) {
                    clearInterval(pollInterval);
                    document.getElementById('processingState').style.display = 'none';
                    document.getElementById('successState').style.display = 'block';
                    
                    // Redirect to detailed results page after 2 seconds
                    setTimeout(() => {
                        window.location.href = '{{ route("verification.results") }}?job_id=' + jobId;
                    }, 2000);
                }
            })
            .catch(error => console.error('Status poll error:', error));
    }

    function cancelJob() {
        if (!confirm('Are you sure you want to cancel this verification job?')) {
            return;
        }

        fetch('{{ route("verification.cancel") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ job_id: jobId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                clearInterval(pollInterval);
                document.getElementById('processingState').style.display = 'none';
                document.getElementById('cancelledState').style.display = 'block';
            } else {
                alert('Failed to cancel job: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Cancel error:', error);
            alert('Error cancelling job');
        });
    }

    // Poll status every 1 second
    pollInterval = setInterval(() => {
        pollStatus();
        updateElapsedTime();
    }, 1000);

    // Initial status check
    pollStatus();
    updateElapsedTime();
</script>
@endsection
