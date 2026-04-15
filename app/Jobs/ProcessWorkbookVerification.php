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

class ProcessWorkbookVerification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 2700;
    public $tries = 1;

    public function __construct(
        private string $jobId,
        private string $workbookPath,
        private string $fileName
    ) {}

    public function handle(): void
    {
        set_time_limit(2700);
        ini_set('memory_limit', '2048M');

        $startTime = microtime(true);

        $job = VerificationJob::where('job_id', $this->jobId)->first();
        if (!$job) {
            Log::error("Verification job not found: {$this->jobId}");
            return;
        }

        try {
            if (!file_exists($this->workbookPath)) {
                throw new \Exception("Workbook file not found at: {$this->workbookPath}");
            }
            if (!is_readable($this->workbookPath)) {
                throw new \Exception("Workbook file is not readable: {$this->workbookPath}");
            }

            Log::info("Job {$job->job_id} started. File: {$this->workbookPath} (" . filesize($this->workbookPath) . " bytes)");

            $uploadService    = app(WorkbookUploadService::class);
            $scannerService   = app(WorksheetScannerService::class);
            $headerService    = app(HeaderMappingService::class);
            $rowNormalizer    = app(RowNormalizationService::class);
            $filterService    = app(ReportScopeFilterService::class);
            $urlValidator     = app(UrlValidationService::class);
            $postAnalyzer     = app(PostAnalysisService::class);
            $coverageService  = app(CoverageEvaluationService::class);
            $summaryService   = app(SummaryAggregationService::class);
            $excelExport      = app(ExcelExportService::class);
            $wordExport       = app(WordExportService::class);
            $pdfExport        = app(PdfExportService::class);

            $job->update(['status' => 'processing', 'started_at' => now(), 'progress' => 0]);

            $this->checkCancellation($job);
            $this->updateProgress($job, 5, 'Loading workbook...');
            $spreadsheet = $uploadService->load($this->workbookPath);
            if (!$spreadsheet) {
                throw new \Exception('Failed to load workbook');
            }

            $this->checkCancellation($job);
            $this->updateProgress($job, 10, 'Scanning worksheets...');
            $worksheets = $scannerService->scan($spreadsheet);
            Log::info("Found worksheets: " . implode(", ", array_column($worksheets, 'name')));

            $this->checkCancellation($job);
            $this->updateProgress($job, 15, 'Processing worksheets...');

            $reportMode   = $job->report_mode;
            $filterValues = $job->filter_values ?? [];
            if (is_string($filterValues)) {
                $filterValues = json_decode($filterValues, true) ?? [];
            }

            $processedData   = ['by_worksheet' => [], 'all_rows' => []];
            $worksheetCount  = count($worksheets);
            $worksheetIndex  = 0;

            foreach ($worksheets as $worksheetInfo) {
                $worksheetIndex++;
                $worksheetProgress = 15 + (($worksheetIndex / $worksheetCount) * 60);
                $this->updateProgress($job, (int)$worksheetProgress, "Processing worksheet: {$worksheetInfo['name']}");
                $this->checkCancellation($job);

                $sheet = $spreadsheet->getSheetByName($worksheetInfo['name']);
                if (!$sheet) continue;

                if (strtolower($worksheetInfo['name']) === 'meta tags') {
                    Log::info("Skipping Meta Tags worksheet");
                    continue;
                }

                $sheetData     = $scannerService->getWorksheetData($sheet);
                $headerMapping = $headerService->map($sheetData['headers']);

                $validationStatusColIdx = null;
                for ($colIdx = 1; $colIdx <= 50; $colIdx++) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                    $cellValue = $sheet->getCell($colLetter . '1')->getValue();
                    if ($cellValue !== null && strtolower(trim($cellValue)) === 'validation status') {
                        $validationStatusColIdx = $colIdx;
                        break;
                    }
                }

                if (empty($headerMapping['url_columns'])) {
                    Log::info("Skipping '{$worksheetInfo['name']}' - no Submission page column");
                    continue;
                }

                $worksheetFilter = $filterValues['worksheet'] ?? null;
                if ($reportMode !== 'complete_worksheet' && !empty($worksheetFilter) &&
                    strtolower($worksheetInfo['name']) !== strtolower($worksheetFilter)) {
                    Log::info("Skipping '{$worksheetInfo['name']}' - not matching filter");
                    continue;
                }

                $normalizedRows = [];
                foreach ($sheetData['rows'] as $row) {
                    $normalizedRows[] = $rowNormalizer->normalize(
                        $row['data'], $headerMapping, $worksheetInfo['name'], $row['row_number']
                    );
                }

                $filteredRows = $filterService->filter($normalizedRows, $reportMode, $filterValues);

                $this->updateProgress($job, (int)$worksheetProgress + 5, "Validating URLs in {$worksheetInfo['name']}...");
                $validationItems = [];

                foreach ($filteredRows as $rowIndex => &$filteredRow) {
                    if (!$filteredRow['included_in_scope']) continue;

                    $ourUrl  = null;
                    foreach ($filteredRow['urls'] as $urlInfo) {
                        if (str_contains(strtolower($urlInfo['column_name'] ?? ''), 'our url')) {
                            $ourUrl = $urlInfo['original_url'];
                            break;
                        }
                    }
                    $keyword = $filteredRow['keyword'] ?? null;

                    foreach ($filteredRow['urls'] as $urlIdx => $urlData) {
                        $validationItems[] = [
                            'url'      => $urlData['original_url'],
                            'ourUrl'   => $ourUrl,
                            'keyword'  => $keyword,
                            'rowIndex' => $rowIndex,
                            'urlIndex' => $urlIdx
                        ];
                    }
                }

                if (count($validationItems) > 1000) {
                    $sorted = [];
                    foreach (array_chunk($validationItems, 500) as $chunk) {
                        usort($chunk, fn($a, $b) => strcmp($a['url'], $b['url']));
                        $sorted = array_merge($sorted, $chunk);
                    }
                    usort($sorted, fn($a, $b) => strcmp($a['url'], $b['url']));
                    $validationItems = $sorted;
                } else {
                    usort($validationItems, fn($a, $b) => strcmp($a['url'], $b['url']));
                }

                if (!empty($validationItems)) {
                    $this->validateWithDomainBatching($validationItems, $filteredRows, $urlValidator, $job);
                    $urlValidator->savePersistentCache();
                }

                $postAnalyzer->analyzeMultiple($filteredRows, $reportMode);

                if (memory_get_usage(true) > 500 * 1024 * 1024) {
                    gc_collect_cycles();
                }

                if ($validationStatusColIdx) {
                    foreach ($filteredRows as $rowProc) {
                        $rowNum = $rowProc['row_number'] ?? null;
                        if (!$rowNum || empty($rowProc['urls'])) continue;
                        $firstUrl  = reset($rowProc['urls']);
                        $statusVal = $firstUrl['status'] ?? 'Unknown';
                        $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($validationStatusColIdx);
                        $sheet->setCellValue($colLetter . $rowNum, $statusVal);
                    }
                }

                if ($reportMode === 'complete' || $this->worksheetHasIncludedRows($filteredRows)) {
                    $processedData['by_worksheet'][$worksheetInfo['name']] = $filteredRows;
                    $processedData['all_rows'] = array_merge($processedData['all_rows'], $filteredRows);
                }
            }

            $this->updateProgress($job, 80, 'Generating summary...');
            if ($reportMode === 'complete' && empty($processedData['by_worksheet'])) {
                throw new \Exception("No worksheets processed. Please check your workbook.");
            }

            $this->checkCancellation($job);

            $summary = $summaryService->generateSummary($processedData, $reportMode, $filterValues);
            if (empty($summary['overall']['weeks_found'])) {
                throw new \Exception('No valid periods or weeks found in the Excel sheet.');
            }

            $exceptions = $summaryService->generateExceptions($processedData);

            $this->updateProgress($job, 85, 'Evaluating coverage...');
            $coverage = [];
            foreach ($processedData['by_worksheet'] as $wsName => $rows) {
                $coverage[$wsName] = $coverageService->evaluateWorksheetCoverage($rows, $reportMode, $filterValues);
            }

            $this->updateProgress($job, 90, 'Exporting reports...');
            $currentTime  = time() + (5.5 * 3600);
            $timestamp    = date('Y-m-d_H-i-s', $currentTime) . '_' . str_replace('.', '-', microtime(true));
            $baseName     = 'Verification_Report_' . $timestamp;

            $excelFile = $excelExport->export($summary, $exceptions, $coverage, $baseName . '.xlsx');
            $wordFile  = $wordExport->export($summary, $exceptions, $coverage, $this->fileName, [
                'generated_at' => date('Y-m-d H:i:s'), 'mode' => $reportMode,
                'filter' => $filterValues, 'timestamp' => $timestamp
            ], $baseName . '.docx');
            $pdfFile   = $pdfExport->export($summary, $exceptions, $coverage, $this->fileName, [
                'generated_at' => date('Y-m-d H:i:s'), 'mode' => $reportMode,
                'filter' => $filterValues, 'timestamp' => $timestamp
            ], $baseName . '.pdf');

            $inputExcelFile = storage_path('app/exports/Input_Excel_File_' . $timestamp . '.xlsx');
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($inputExcelFile);

            $uploadService->cleanup($this->workbookPath);

            $elapsed = round(microtime(true) - $startTime, 2);
            $job->update([
                'status'      => 'completed',
                'progress'    => 100,
                'completed_at'=> now(),
                'elapsed_time'=> $elapsed,
                'excel_file'  => basename($excelFile),
                'word_file'   => basename($wordFile),
                'pdf_file'    => basename($pdfFile),
                'summary'     => $summary,
                'coverage'    => $coverage,
                'exceptions'  => $exceptions
            ]);

            Log::info("Job {$job->job_id} completed in {$elapsed}s");

        } catch (\Exception $e) {
            Log::error("Job {$this->jobId} failed: " . $e->getMessage());
            $job->update([
                'status'       => strpos($e->getMessage(), 'cancelled') !== false ? 'cancelled' : 'failed',
                'completed_at' => now(),
                'elapsed_time' => round(microtime(true) - $startTime, 2),
                'error_message'=> $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function validateWithDomainBatching(array $validationItems, array &$filteredRows, UrlValidationService $urlValidator, VerificationJob $job): void
    {
        $batchSize    = 10;
        $batches      = [];
        $currentBatch = [];
        $usedDomains  = [];

        foreach ($validationItems as $i => $item) {
            $domain = parse_url($item['url'] ?? '', PHP_URL_HOST) ?? '';

            if (!empty($domain) && isset($usedDomains[$domain])) {
                $batches[]    = $currentBatch;
                $currentBatch = [];
                $usedDomains  = [];
            }

            $currentBatch[] = ['index' => $i, 'item' => $item];
            if (!empty($domain)) $usedDomains[$domain] = true;

            if (count($currentBatch) >= $batchSize) {
                $batches[]    = $currentBatch;
                $currentBatch = [];
                $usedDomains  = [];
            }
        }
        if (!empty($currentBatch)) $batches[] = $currentBatch;

        $totalProcessed      = 0;
        $totalUrlsToProcess  = count($validationItems);

        foreach ($batches as $batchNum => $batch) {
            $batchItems   = array_map(fn($b) => $b['item'], $batch);
            $batchResults = $urlValidator->batchValidateWithAnalysis($batchItems, 10);

            foreach ($batch as $batchIdx => $batchItem) {
                $res  = $batchResults[$batchIdx] ?? [];
                $rIdx = $batchItem['item']['rowIndex'];
                $uIdx = $batchItem['item']['urlIndex'];

                if (isset($filteredRows[$rIdx]['urls'][$uIdx])) {
                    $filteredRows[$rIdx]['urls'][$uIdx]['status']       = $res['status'] ?? 'Unknown';
                    $filteredRows[$rIdx]['urls'][$uIdx]['status_code']  = $res['status_code'] ?? 0;
                    $filteredRows[$rIdx]['urls'][$uIdx]['final_url']    = $res['final_url'] ?? '';
                    $filteredRows[$rIdx]['urls'][$uIdx]['error']        = $res['error'] ?? null;
                    $filteredRows[$rIdx]['urls'][$uIdx]['cannot_verify']= $res['cannot_verify'] ?? false;
                    $filteredRows[$rIdx]['urls'][$uIdx]['is_blank']     = $res['is_blank'] ?? false;
                }
            }

            $totalProcessed += count($batch);
            $this->updateProgress($job, (int)(75 + ($totalProcessed / $totalUrlsToProcess) * 15),
                "Validating URLs... ({$totalProcessed}/{$totalUrlsToProcess})");

            if (memory_get_usage(true) > 800 * 1024 * 1024) gc_collect_cycles();
            usleep(500000);
        }
    }

    private function updateProgress(VerificationJob $job, int $progress, string $message): void
    {
        $job->update(['progress' => min(100, $progress)]);
        Log::info("Job {$job->job_id}: {$message}");
    }

    private function checkCancellation(VerificationJob $job): void
    {
        $job->refresh();
        if ($job->status === 'cancelled') {
            Log::info("Job {$job->job_id} was cancelled");
            throw new \Exception('Job was cancelled by user');
        }
    }

    private function worksheetHasIncludedRows(array $filteredRows): bool
    {
        foreach ($filteredRows as $row) {
            if (!empty($row['included_in_scope'])) return true;
        }
        return false;
    }

    public function failed(\Throwable $exception): void
    {
        $job = VerificationJob::where('job_id', $this->jobId)->first();
        if (!$job) return;

        Log::error("Job {$this->jobId} FAILED: " . $exception->getMessage());

        $job->update([
            'status'        => strpos($exception->getMessage(), 'cancelled') !== false ? 'cancelled' : 'failed',
            'completed_at'  => now(),
            'error_message' => substr($exception->getMessage(), 0, 500),
            'progress'      => 0
        ]);
    }
}
