<?php

namespace App\Http\Controllers;

use App\Services\WorkbookUploadService;
use App\Services\WorksheetScannerService;
use App\Services\HeaderMappingService;
use App\Services\RowNormalizationService;
use App\Services\DateParserService;
use App\Services\ReportScopeFilterService;
use App\Services\UrlValidationService;
use App\Services\ContentExtractionService;
use App\Services\PostAnalysisService;
use App\Services\CoverageEvaluationService;
use App\Services\SummaryAggregationService;
use App\Services\ExcelExportService;
use App\Services\WordExportService;
use App\Services\PdfExportService;
use App\Jobs\ProcessWorkbookVerification;
use App\Models\VerificationJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    private WorkbookUploadService $uploadService;

    public function __construct()
    {
        $this->uploadService = new WorkbookUploadService();
    }

    /**
     * Show dashboard
     */
    public function index()
    {
        return view('verification.index');
    }

    /**
     * Run verification - Queue-based processing
     */
    public function run(Request $request)
    {
        // Validate input
        $request->validate([
            'workbook' => 'required|file|mimes:xlsx|max:51200',
            'mode' => 'required|in:complete,single_week,date_range,complete_worksheet',
            'worksheet' => 'required_if:mode,complete',
            'week' => 'nullable|required_if:mode,single_week',
            'start_date' => 'nullable|required_if:mode,date_range',
            'end_date' => 'nullable|required_if:mode,date_range'
        ], [
            'mode.required' => 'Please select a Report Mode before running verification.',
            'mode.in' => 'Please select a valid Report Mode.',
        ]);

        // Validate date range (max 30 days)
        if ($request->input('mode') === 'date_range') {
            $startDateStr = $request->input('start_date');
            $endDateStr = $request->input('end_date');

            if ($startDateStr && $endDateStr) {
                try {
                    $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $startDateStr);
                    $endDate = \Carbon\Carbon::createFromFormat('Y-m-d', $endDateStr);
                    $daysDifference = $startDate->diffInDays($endDate);

                    if ($daysDifference > 30) {
                        return back()->with('error', "Date range cannot exceed 30 days. Your selected range is {$daysDifference} days. Please reduce the date range.")->withInput();
                    }
                } catch (\Exception $e) {
                    return back()->with('error', 'Invalid date format received from browser. Please try selecting the dates again.')->withInput();
                }
            }
        }

        try {
            // CRITICAL: Release session lock IMMEDIATELY to unblock other sessions
            // This must happen before any heavy operations (file upload, etc)
            Session::save();
            session_write_close();
            
            // Upload workbook (session no longer locked - other requests can proceed)
            $uploadResult = $this->uploadService->store($request->file('workbook'));
            
            if (!$uploadResult['success']) {
                return back()->with('error', $uploadResult['error']);
            }

            $workbookPath = $uploadResult['file_path'];
            $fileName = $uploadResult['file_name'];

            // Create verification job record
            $jobId = 'job_' . Str::random(32);
            $filterValues = [
                'week' => $request->input('week'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date'),
                'worksheet' => $request->input('worksheet')
            ];

            $verificationJob = VerificationJob::create([
                'job_id' => $jobId,
                'status' => 'pending',
                'progress' => 0,
                'workbook_name' => $fileName,
                'file_path' => $workbookPath,
                'report_mode' => $request->input('mode'),
                'filter_values' => $filterValues,
                'error_message' => null
            ]);

            Log::info("Created verification job: {$jobId}");

            // Dispatch job to queue
            ProcessWorkbookVerification::dispatch($verificationJob, $workbookPath, $fileName);

            // Redirect to status page
            return redirect()->route('verification.status', ['job_id' => $jobId])
                ->with('success', 'Verification started! Your job has been queued for processing.');

        } catch (\Exception $e) {
            Log::error("Verification error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            return back()->with('error', 'Failed to start verification: ' . $e->getMessage())->withInput();
        }
    }

    /**
     * Check job status - for AJAX polling
     */
    public function status(Request $request)
    {
        $jobId = $request->input('job_id');
        $job = VerificationJob::where('job_id', $jobId)->first();

        if (!$job) {
            return response()->json([
                'success' => false,
                'error' => 'Job not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'status' => $job->status,
            'progress' => $job->progress,
            'display_status' => $job->getDisplayStatus(),
            'error_message' => $job->error_message,
            'is_complete' => $job->isComplete(),
            'is_successful' => $job->isSuccessful(),
            'has_failed' => $job->hasFailed(),
            'elapsed_time' => $job->elapsed_time
        ]);
    }

    /**
     * Display job status page (for user to view progress)
     */
    public function showStatus(Request $request)
    {
        $jobId = $request->route('job_id');
        $job = VerificationJob::where('job_id', $jobId)->first();

        if (!$job) {
            return redirect('/')->with('error', 'Job not found');
        }

        return view('verification.status', [
            'job' => $job
        ]);
    }

    /**
     * Retrieve results after job completion
     */
    public function results(Request $request)
    {
        $jobId = $request->input('job_id');
        $job = VerificationJob::where('job_id', $jobId)->first();

        if (!$job) {
            return redirect('/')->with('error', 'Job not found');
        }

        if ($job->status !== 'completed') {
            return back()->with('error', 'Job has not completed yet. ' . $job->getDisplayStatus());
        }

        // Store results in session for download
        $results = [
            'excel' => $job->excel_file,
            'word' => $job->word_file,
            'pdf' => $job->pdf_file,
            'summary' => $job->summary,
            'coverage' => $job->coverage,
            'exceptions' => $job->exceptions
        ];

        session(['verification_results' => $results]);

        return view('verification.result', [
            'results' => $results,
            'elapsed' => $job->elapsed_time
        ]);
    }

    /**
     * Download file
     */
    public function download(Request $request, string $format)
    {
        $results = session('verification_results', []);

        if (empty($results)) {
            return redirect('/')->with('error', 'No results found');
        }

        $fileName = $results[$format] ?? null;

        // Debug logging to check filenames
        Log::info("Download request for {$format}: filename = {$fileName}");

        if (!$fileName) {
            return back()->with('error', 'File not found');
        }

        $filePath = storage_path('app/exports/' . $fileName);
        
        if (!file_exists($filePath)) {
            return back()->with('error', 'File not found');
        }

        Log::info("Serving download for {$format}: {$fileName}");
        $response = response()->download($filePath, $fileName);
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        return $response;
    }

    /**
     * Clear persistent cache
     */
    public function clearCache(Request $request)
    {
        // Clear all Laravel caches
        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('view:clear');
        Artisan::call('route:clear');
        Artisan::call('optimize:clear');

        // Clear custom persistent cache
        $this->urlValidator->clearPersistentCache();

        // Clear session data
        Session::forget('verification_results');

        return response('All caches cleared successfully', 200);
    }

    /**
     * Clear worksheet cache (not currently used with queue)
     */
    public function clearWorksheetCache(Request $request)
    {
        // Legacy method - kept for compatibility
        // In queue-based system, just return success
        return response('Worksheet cache cleared', 200);
    }

    /**
     * Cancel a running job
     */
    public function cancelJob(Request $request)
    {
        $jobId = $request->input('job_id');
        $job = VerificationJob::where('job_id', $jobId)->first();

        if (!$job) {
            return response()->json([
                'success' => false,
                'error' => 'Job not found'
            ], 404);
        }

        // Mark job as cancelled
        $job->update([
            'status' => 'cancelled',
            'completed_at' => now(),
            'error_message' => 'Job was cancelled by user'
        ]);

        // Try to remove the job from the queue database table if it hasn't started yet
        try {
            DB::table('jobs')
                ->where('payload', 'LIKE', '%' . $jobId . '%')
                ->delete();
            
            Log::info("Removed job {$jobId} from queue table");
        } catch (\Exception $e) {
            Log::warning("Could not remove job from queue table: " . $e->getMessage());
        }

        Log::info("Job {$jobId} cancelled by user");

        return response()->json([
            'success' => true,
            'message' => 'Job cancelled successfully'
        ]);
    }
}