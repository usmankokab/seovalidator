<?php

namespace App\Jobs;

use App\Models\VerificationJob;
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
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessWorkbookVerification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 2700; // 45 minutes
    public $tries = 1;      // Try once, then fail (to avoid "attempted too many times")
    public $backoff = 60;   // Wait 60 seconds before retry

    public function __construct(
        private VerificationJob $verificationJob,
        private string $workbookPath,
        private string $fileName
    ) {
    }

    /**
     * Determine if the job should be retried
     */
    public function shouldRetry(\Throwable $exception): bool
    {
        // Don't retry on timeout or serialization errors
        if (strpos($exception->getMessage(), 'timeout') !== false ||
            strpos($exception->getMessage(), 'serialization') !== false) {
            return false;
        }
        return true;
    }

    /**
     * Handle job failure before marking it failed
     */
    public function retryUntil()
    {
        // Don't retry after 2 hours
        return now()->addHours(2);
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        set_time_limit(2700);
        ini_set('memory_limit', '2048M');

        $startTime = microtime(true);

        try {
            // Verify workbook file exists and is readable
            if (!file_exists($this->workbookPath)) {
                throw new \Exception("Workbook file not found at: {$this->workbookPath}");
            }
            if (!is_readable($this->workbookPath)) {
                throw new \Exception("Workbook file is not readable: {$this->workbookPath}");
            }
            
            Log::info("Job {$this->verificationJob->job_id} started. File: {$this->workbookPath} (" . filesize($this->workbookPath) . " bytes)");

            // Resolve services from container
            $uploadService = app(WorkbookUploadService::class);
            $scannerService = app(WorksheetScannerService::class);
            $headerService = app(HeaderMappingService::class);
            $dateParser = app(DateParserService::class);
            $rowNormalizer = app(RowNormalizationService::class);
            $filterService = app(ReportScopeFilterService::class);
            $urlValidator = app(UrlValidationService::class);
            $contentExtractor = app(ContentExtractionService::class);
            $postAnalyzer = app(PostAnalysisService::class);
            $coverageService = app(CoverageEvaluationService::class);
            $summaryService = app(SummaryAggregationService::class);
            $excelExport = app(ExcelExportService::class);
            $wordExport = app(WordExportService::class);
            $pdfExport = app(PdfExportService::class);

            // Update job status
            $this->verificationJob->update([
                'status' => 'processing',
                'started_at' => now(),
                'progress' => 0
            ]);

            Log::info("Starting verification job {$this->verificationJob->job_id}");

            // Load workbook
            $this->checkCancellation();
            $this->updateProgress(5, 'Loading workbook...');
            $spreadsheet = $uploadService->load($this->workbookPath);
            if (!$spreadsheet) {
                throw new \Exception('Failed to load workbook');
            }

            // Scan worksheets
            $this->checkCancellation();
            $this->updateProgress(10, 'Scanning worksheets...');
            $worksheets = $scannerService->scan($spreadsheet);
            Log::info("Found worksheets: " . implode(", ", array_column($worksheets, 'name')));

            // Process each worksheet
            $this->checkCancellation();
            $this->updateProgress(15, 'Processing worksheets...');
            $processedData = [
                'by_worksheet' => [],
                'all_rows' => []
            ];

            $reportMode = $this->verificationJob->report_mode;
            $filterValues = $this->verificationJob->filter_values ?? [];

            $worksheetCount = count($worksheets);
            $worksheetIndex = 0;

            foreach ($worksheets as $worksheetInfo) {
                $worksheetIndex++;
                $worksheetProgress = 15 + (($worksheetIndex / $worksheetCount) * 60); // 15-75% for worksheets
                $this->updateProgress((int)$worksheetProgress, "Processing worksheet: {$worksheetInfo['name']}");

                $this->checkCancellation();

                $sheet = $spreadsheet->getSheetByName($worksheetInfo['name']);
                if (!$sheet) continue;

                // Skip Meta Tags worksheet
                if (strtolower($worksheetInfo['name']) === 'meta tags') {
                    Log::info("Skipping Meta Tags worksheet");
                    continue;
                }

                // Get worksheet data
                $sheetData = $scannerService->getWorksheetData($sheet);
                $headerMapping = $headerService->map($sheetData['headers']);

                // Detect Validation Status column
                $validationStatusColIdx = null;
                for ($colIdx = 1; $colIdx <= 50; $colIdx++) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                    $cellValue = $sheet->getCell($colLetter . '1')->getValue();
                    if ($cellValue !== null && strtolower(trim($cellValue)) === 'validation status') {
                        $validationStatusColIdx = $colIdx;
                        break;
                    }
                }

                // Skip if no URL columns
                if (empty($headerMapping['url_columns'])) {
                    Log::info("Skipping '" . $worksheetInfo['name'] . "' - no Submission page column");
                    continue;
                }

                // Filter worksheet if needed
                $worksheetFilter = $this->verificationJob->filter_values['worksheet'] ?? null;
                if ($reportMode !== 'complete_worksheet' && !empty($worksheetFilter) && 
                    strtolower($worksheetInfo['name']) !== strtolower($worksheetFilter)) {
                    Log::info("Skipping '" . $worksheetInfo['name'] . "' - not matching filter");
                    continue;
                }

                // Normalize rows
                $normalizedRows = [];
                foreach ($sheetData['rows'] as $row) {
                    $normalized = $rowNormalizer->normalize(
                        $row['data'],
                        $headerMapping,
                        $worksheetInfo['name'],
                        $row['row_number']
                    );
                    $normalizedRows[] = $normalized;
                }

                // Filter by mode
                $filteredRows = $filterService->filter(
                    $normalizedRows,
                    $reportMode,
                    $filterValues
                );

                // Validate URLs
                $this->updateProgress((int)$worksheetProgress + 5, "Validating URLs in {$worksheetInfo['name']}...");
                $validationItems = [];
                $rowIndexes = [];
                $urlIndexes = [];

                foreach ($filteredRows as $rowIndex => &$filteredRow) {
                    if (!$filteredRow['included_in_scope']) continue;

                    $ourUrl = null;
                    foreach ($filteredRow['urls'] as $urlInfo) {
                        $colName = strtolower($urlInfo['column_name'] ?? '');
                        if (str_contains($colName, 'our url')) {
                            $ourUrl = $urlInfo['original_url'];
                            break;
                        }
                    }
                    $keyword = $filteredRow['keyword'] ?? null;

                    foreach ($filteredRow['urls'] as $urlIdx => $urlData) {
                        $validationItems[] = [
                            'url' => $urlData['original_url'],
                            'ourUrl' => $ourUrl,
                            'keyword' => $keyword,
                            'rowIndex' => $rowIndex,
                            'urlIndex' => $urlIdx
                        ];
                        $rowIndexes[] = $rowIndex;
                        $urlIndexes[] = $urlIdx;
                    }
                }

                // Sort validation items deterministically
                if (count($validationItems) > 1000) {
                    $chunkSize = 500;
                    $sortedItems = [];
                    foreach (array_chunk($validationItems, $chunkSize) as $chunk) {
                        usort($chunk, fn($a, $b) => strcmp($a['url'], $b['url']));
                        $sortedItems = array_merge($sortedItems, $chunk);
                    }
                    usort($sortedItems, fn($a, $b) => strcmp($a['url'], $b['url']));
                    $validationItems = $sortedItems;
                } else {
                    usort($validationItems, fn($a, $b) => strcmp($a['url'], $b['url']));
                }

                Log::info("Processing " . count($validationItems) . " URLs");

                // Batch validate
                if (!empty($validationItems)) {
                    $this->validateWithDomainBatching($validationItems, $rowIndexes, $urlIndexes, $filteredRows, $urlValidator);
                    $urlValidator->savePersistentCache();
                }

                // Analyze posts
                $postAnalyzer->analyzeMultiple($filteredRows, $reportMode);

                // Memory cleanup
                if (memory_get_usage(true) > 500 * 1024 * 1024) {
                    gc_collect_cycles();
                }

                // Write validation status
                if ($validationStatusColIdx) {
                    foreach ($filteredRows as $rowIdx => $rowProc) {
                        $rowNum = $rowProc['row_number'] ?? null;
                        if (!$rowNum) continue;
                        $statusVal = null;
                        if (!empty($rowProc['urls'])) {
                            $firstUrl = reset($rowProc['urls']);
                            $statusVal = $firstUrl['status'] ?? 'Unknown';
                        }
                        if ($statusVal) {
                            $sheet->setCellValueByColumnAndRow($validationStatusColIdx, $rowNum, $statusVal);
                        }
                    }
                }

                // Add to processed data
                if ($reportMode === 'complete' || 
                    ($reportMode !== 'complete' && $this->worksheetHasIncludedRows($filteredRows))) {
                    $processedData['by_worksheet'][$worksheetInfo['name']] = $filteredRows;
                    $processedData['all_rows'] = array_merge(
                        $processedData['all_rows'],
                        $filteredRows
                    );
                }
            }

            // Verify we have data
            $this->updateProgress(80, 'Generating summary...');
            if ($reportMode === 'complete' && empty($processedData['by_worksheet'])) {
                throw new \Exception("No worksheets processed. Please check your workbook.");
            }

            $this->checkCancellation();

            // Generate summary
            $summary = $summaryService->generateSummary(
                $processedData,
                $reportMode,
                $filterValues
            );

            if (empty($summary['overall']['weeks_found'])) {
                throw new \Exception('No valid periods or weeks found in the Excel sheet.');
            }

            // Generate exceptions
            $exceptions = $summaryService->generateExceptions($processedData);

            // Evaluate coverage
            $this->updateProgress(85, 'Evaluating coverage...');
            $coverage = [];
            foreach ($processedData['by_worksheet'] as $wsName => $rows) {
                $coverage[$wsName] = $coverageService->evaluateWorksheetCoverage(
                    $rows,
                    $reportMode,
                    $filterValues
                );
            }

            // Export to all formats
            $this->updateProgress(90, 'Exporting to Excel, Word, PDF...');
            $currentTime = time() + (5.5 * 3600);
            $timestamp = date('Y-m-d_H-i-s', $currentTime) . '_' . str_replace('.', '-', microtime(true));
            $baseFileName = 'Verification_Report_' . $timestamp;

            $excelFileName = $baseFileName . '.xlsx';
            $wordFileName = $baseFileName . '.docx';
            $pdfFileName = $baseFileName . '.pdf';

            $excelFile = $excelExport->export($summary, $exceptions, $coverage, $excelFileName);
            $wordFile = $wordExport->export($summary, $exceptions, $coverage, $this->fileName, [
                'generated_at' => date('Y-m-d H:i:s'),
                'mode' => $reportMode,
                'filter' => $filterValues,
                'timestamp' => $timestamp
            ], $wordFileName);
            $pdfFile = $pdfExport->export($summary, $exceptions, $coverage, $this->fileName, [
                'generated_at' => date('Y-m-d H:i:s'),
                'mode' => $reportMode,
                'filter' => $filterValues,
                'timestamp' => $timestamp
            ], $pdfFileName);

            // Save spread with validation status
            $inputExcelFile = storage_path('app/exports/Input_Excel_File_' . $timestamp . '.xlsx');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($inputExcelFile);

            // Cleanup
            $uploadService->cleanup($this->workbookPath);

            // Mark as completed
            $elapsed = round(microtime(true) - $startTime, 2);
            $this->verificationJob->update([
                'status' => 'completed',
                'progress' => 100,
                'completed_at' => now(),
                'elapsed_time' => $elapsed,
                'excel_file' => basename($excelFile),
                'word_file' => basename($wordFile),
                'pdf_file' => basename($pdfFile),
                'summary' => $summary,
                'coverage' => $coverage,
                'exceptions' => $exceptions
            ]);

            Log::info("Verification job {$this->verificationJob->job_id} completed in {$elapsed}s");

        } catch (\Exception $e) {
            Log::error("Verification job {$this->verificationJob->job_id} failed: " . $e->getMessage());
            $this->verificationJob->update([
                'status' => 'failed',
                'completed_at' => now(),
                'elapsed_time' => round(microtime(true) - $startTime, 2),
                'error_message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate URLs with domain-aware batching
     */
    private function validateWithDomainBatching(array $validationItems, array $rowIndexes, array $urlIndexes, array &$filteredRows, UrlValidationService $urlValidator): void
    {
        $batchSize = 10;
        $batches = [];
        $currentBatch = [];
        $usedDomains = [];

        foreach ($validationItems as $i => $item) {
            $url = $item['url'] ?? '';
            $domain = $this->extractDomain($url);

            if (!empty($domain)) {
                if (!isset($usedDomains[$domain])) {
                    $currentBatch[] = ['index' => $i, 'item' => $item, 'domain' => $domain];
                    $usedDomains[$domain] = true;

                    if (count($currentBatch) >= $batchSize) {
                        $batches[] = $currentBatch;
                        $currentBatch = [];
                        $usedDomains = [];
                    }
                } else {
                    if (!empty($currentBatch)) {
                        $batches[] = $currentBatch;
                        $currentBatch = [];
                        $usedDomains = [];
                    }
                    $currentBatch[] = ['index' => $i, 'item' => $item, 'domain' => $domain];
                    $usedDomains[$domain] = true;
                }
            } else {
                if (count($currentBatch) > 0 && count($currentBatch) < $batchSize) {
                    $currentBatch[] = ['index' => $i, 'item' => $item, 'domain' => ''];
                } else {
                    if (!empty($currentBatch)) {
                        $batches[] = $currentBatch;
                        $currentBatch = [];
                        $usedDomains = [];
                    }
                    $currentBatch[] = ['index' => $i, 'item' => $item, 'domain' => ''];
                }
            }
        }

        if (!empty($currentBatch)) {
            $batches[] = $currentBatch;
        }

        Log::info("Processing URLs in " . count($batches) . " batches");

        $totalProcessed = 0;
        $totalUrlsToProcess = count($validationItems);

        foreach ($batches as $batchNum => $batch) {
            Log::info("Batch " . ($batchNum + 1) . "/" . count($batches));
            
            $batchItems = array_map(fn($b) => $b['item'], $batch);
            $batchResults = $urlValidator->batchValidateWithAnalysis($batchItems, 10);

            foreach ($batch as $batchIdx => $batchItem) {
                $res = $batchResults[$batchIdx] ?? [];
                $rIdx = $batchItem['item']['rowIndex'];
                $uIdx = $batchItem['item']['urlIndex'];

                if (isset($filteredRows[$rIdx]['urls'][$uIdx])) {
                    $filteredRows[$rIdx]['urls'][$uIdx]['status'] = $res['status'] ?? 'Unknown';
                    $filteredRows[$rIdx]['urls'][$uIdx]['status_code'] = $res['status_code'] ?? 0;
                    $filteredRows[$rIdx]['urls'][$uIdx]['final_url'] = $res['final_url'] ?? '';
                    $filteredRows[$rIdx]['urls'][$uIdx]['error'] = $res['error'] ?? null;
                    $filteredRows[$rIdx]['urls'][$uIdx]['cannot_verify'] = $res['cannot_verify'] ?? false;
                    $filteredRows[$rIdx]['urls'][$uIdx]['is_blank'] = $res['is_blank'] ?? false;
                }
            }

            $totalProcessed += count($batch);
            $progressPercent = 75 + (($totalProcessed / $totalUrlsToProcess) * 15); // 75-90% for URL validation
            $this->updateProgress((int)$progressPercent, "Validating URLs... ({$totalProcessed}/{$totalUrlsToProcess})");

            if (memory_get_usage(true) > 800 * 1024 * 1024) {
                gc_collect_cycles();
            }

            usleep(500000); // 0.5 second delay
        }
    }

    /**
     * Update job progress
     */
    private function updateProgress(int $progress, string $message = ''): void
    {
        $this->verificationJob->update([
            'progress' => min(100, $progress)
        ]);
        if (!empty($message)) {
            Log::info("Job {$this->verificationJob->job_id}: {$message}");
        }
    }

    /**
     * Extract domain from URL
     */
    private function extractDomain(string $url): string
    {
        try {
            $parsed = parse_url($url);
            return $parsed['host'] ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Check if worksheet has included rows
     */
    private function worksheetHasIncludedRows(array $filteredRows): bool
    {
        foreach ($filteredRows as $row) {
            if (isset($row['included_in_scope']) && $row['included_in_scope']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if job was cancelled by user
     */
    private function checkCancellation(): void
    {
        // Refresh job record from database
        $this->verificationJob->refresh();
        
        if ($this->verificationJob->status === 'cancelled') {
            Log::info("Job {$this->verificationJob->job_id} was cancelled - stopping processing");
            throw new \Exception('Job was cancelled by user');
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        $errorMsg = $exception->getMessage();
        $errorTrace = $exception->getTraceAsString();
        
        Log::error(
            "❌ Job {$this->verificationJob->job_id} FAILED\n" .
            "File: {$this->workbookPath}\n" .
            "Attempt: {$this->attempts}/{$this->tries}\n" .
            "Error: {$errorMsg}\n" .
            "Trace: {$errorTrace}"
        );
        
        // Check if this was a cancellation
        if (strpos($errorMsg, 'cancelled') !== false) {
            $this->verificationJob->update([
                'status' => 'cancelled',
                'completed_at' => now(),
                'error_message' => 'Job was cancelled by user'
            ]);
        } else {
            // Store detailed error message (truncated to avoid DB overflow)
            $detailedError = "Error: " . substr($errorMsg, 0, 500);
            if (strlen($errorMsg) > 500) {
                $detailedError .= "\n[See Laravel logs for full trace]";
            }
            
            $this->verificationJob->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $detailedError,
                'progress' => 0
            ]);
            
            // Also log to file for diagnostics
            \Storage::disk('local')->put(
                "logs/job_errors/{$this->verificationJob->job_id}.log",
                "Job Failed At: " . now() . "\n\n" .
                "Exception Message:\n{$errorMsg}\n\n" .
                "Stack Trace:\n{$errorTrace}"
            );
        }
    }
}
