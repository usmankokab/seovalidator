@extends('layouts.app')

@section('title', 'SEO Workbook Verification')

@section('styles')
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .container { max-width: 800px; }
    .card { border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    .upload-zone { 
        border: 2px dashed #dee2e6; 
        border-radius: 8px; 
        padding: 40px; 
        text-align: center;
        transition: all 0.3s;
        cursor: pointer;
    }
    .upload-zone:hover { border-color: #0d6efd; background-color: #f8f9ff; }
    .upload-zone.dragover { border-color: #0d6efd; background-color: #f8f9ff; }
    .mode-option {
        padding: 15px;
        border: 2px solid #dee2e6;
        border-radius: 8px;
        margin-bottom: 20px;
        cursor: pointer;
        transition: all 0.3s;
    }
    .mode-option:hover { border-color: #0d6efd; }
    .mode-option.selected { border-color: #0d6efd; background-color: #e7f1ff; }
    .mode-option input { display: none; }
</style>
@endsection

@section('content')
<div class="container">
    <div class="card">
        <div class="card-body p-4">
            <h1 class="text-center mb-4">SEO Workbook Verification</h1>
            <p class="text-center text-muted mb-4">Upload your SEO report workbook to validate URLs and content quality</p>

            <form method="POST" action="{{ route('verification.run') }}" enctype="multipart/form-data">
                @csrf

                <!-- Upload Zone -->
                <div class="mb-4">
                    <label class="upload-zone" id="uploadZone">
                        <input type="file" name="workbook" id="workbook" accept=".xlsx" required>
                        <div id="uploadText">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="currentColor" class="text-muted mb-3">
                                <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 .5-.5h12a.5.5 0 0 1 .5.5v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5H12a.5.5 0 0 1 .5.5v2.5a.5.5 0 0 1-.5.5H12a.5.5 0 0 1-.5-.5v-2.5a.5.5 0 0 1 .5-.5H.5z"/>
                                <path d="M1.214 11.513V9.25c-.85-.84-2.153-.834-3 .003-.848.838-1.318 1.599-.972 2.513.346.913 1.15 1.464 2.13 1.464h11.196c.98 0 1.784-.551 2.13-1.464.346-.914-.124-1.675-.972-2.513-.847-.837-2.15-.843-3-.003v2.263a2 2 0 0 1-1.995 1.995H1.214z"/>
                            </svg>
                            <p class="mb-1"><strong>Click to upload</strong> or drag and drop</p>
                            <p class="text-muted small">XLSX files only (max 50MB)</p>
                        </div>
                        <div id="fileName" style="display:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="success" class="mb-3 text-success">
                                <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a7.001 7.001 0 0 0-9.49-9.49L7.53.03a1.001 1.001 0 0 1 1.414 0l3.317 3.317a.997.997 0 0 1 0 1.414l-1.414 1.414a7.001 7.001 0 0 0 9.49 9.49l1.414-1.414a1 1 0 0 1 1.414 0l3.317-3.317a1 1 0 0 1 0-1.414l1.414-1.414A7.001 7.001 0 0 0 12.03 4.97z"/>
                            </svg>
                            <p class="mb-1"><strong>File selected</strong></p>
                            <p class="text-muted small" id="selectedFileName"></p>
                        </div>
                    </label>
                    @error('workbook')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Report Mode -->
                <div class="mb-4">
                    <label class="form-label"><strong>Report Mode</strong> (Select any one of the modes to proceed)</label>
                    
                    <div class="mode-option" onclick="selectMode(this, 'single_week')">
                        <input type="radio" name="mode" value="single_week">
                        <strong>Single Week</strong>
                        <p class="text-muted small mb-0">Filter by specific week</p>
                    </div>
                    
                    <div class="mode-option" onclick="selectMode(this, 'date_range')">
                        <input type="radio" name="mode" value="date_range">
                        <strong>Date Range</strong>
                        <p class="text-muted small mb-0">Filter by start and end date</p>
                    </div>

                    <div class="mode-option" onclick="selectMode(this, 'complete')">
                        <input type="radio" name="mode" value="complete" checked>
                        <strong>Complete Worksheet</strong>
                        <p class="text-muted small mb-0">Analyze all worksheets and rows</p>
                    </div>

                    @error('mode')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <!-- Submit -->
                <button type="submit" class="btn btn-primary btn-lg w-100">
                    🚀 Run Verification
                </button>
            </form>
        </div>
    </div>

    <p class="text-center text-muted mt-4">
        <small>Supported: Excel .xlsx files | Validates: URLs, Posts, Week/Date Coverage</small>
    </p>
</div>
@endsection

@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // File upload handling
    const fileInput = document.getElementById('workbook');
    const uploadZone = document.getElementById('uploadZone');
    const uploadText = document.getElementById('uploadText');
    const fileNameDisplay = document.getElementById('fileName');
    const selectedFileName = document.getElementById('selectedFileName');

    fileInput.addEventListener('change', function(e) {
        if (this.files.length > 0) {
            uploadText.style.display = 'none';
            fileNameDisplay.style.display = 'block';
            selectedFileName.textContent = this.files[0].name;
        }
    });

    uploadZone.addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('dragover');
    });

    uploadZone.addEventListener('dragleave', function(e) {
        this.classList.remove('dragover');
    });

    uploadZone.addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
        }
    });

    // Mode selection
    function selectMode(element, mode) {
        document.querySelectorAll('.mode-option').forEach(function(el) {
            el.classList.remove('selected');
        });
        
        document.querySelectorAll('.dynamic-filter').forEach(function(filter) {
            filter.remove();
        });
        
        element.classList.add('selected');
        
        if (mode === 'complete') {
            var filterContainer = document.createElement('div');
            filterContainer.className = 'mb-3 dynamic-filter';
            filterContainer.innerHTML = '<input type="text" name="worksheet" class="form-control form-control-sm" placeholder="Enter worksheet name (required)" required>';
            element.parentNode.insertBefore(filterContainer, element.nextSibling);
        } else if (mode === 'single_week') {
            var filterContainer = document.createElement('div');
            filterContainer.className = 'mb-3 dynamic-filter';
            filterContainer.innerHTML = '<input type="number" name="week" class="form-control form-control-sm" min="1" placeholder="Enter week number (e.g., 4 = 4th week)">';
            element.parentNode.insertBefore(filterContainer, element.nextSibling);
        } else if (mode === 'date_range') {
            var filterContainer = document.createElement('div');
            filterContainer.className = 'mb-3 dynamic-filter';
            filterContainer.innerHTML = '<div class="row g-2"><div class="col-6"><input type="date" name="start_date" class="form-control form-control-sm" placeholder="Start Date"></div><div class="col-6"><input type="date" name="end_date" class="form-control form-control-sm" placeholder="End Date"></div></div>';
            element.parentNode.insertBefore(filterContainer, element.nextSibling);
        }
        
        var radio = element.querySelector('input[type="radio"]');
        if (radio) radio.checked = true;
    }

    // Initialize
    selectMode(document.querySelector('.mode-option:nth-child(3)'), 'complete');
</script>
@endsection
