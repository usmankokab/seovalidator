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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class VerificationController extends Controller
{
    private WorkbookUploadService $uploadService;
    private WorksheetScannerService $scannerService;
    private HeaderMappingService $headerService;
    private DateParserService $dateParser;
    private RowNormalizationService $rowNormalizer;
    private ReportScopeFilterService $filterService;
    private UrlValidationService $urlValidator;
    private ContentExtractionService $contentExtractor;
    private PostAnalysisService $postAnalyzer;
    private CoverageEvaluationService $coverageService;
    private SummaryAggregationService $summaryService;
    private ExcelExportService $excelExport;
    private WordExportService $wordExport;
    private PdfExportService $pdfExport;

    public function __construct()
    {
        $this->uploadService = new WorkbookUploadService();
        $this->scannerService = new WorksheetScannerService();
        $this->headerService = new HeaderMappingService();
        $this->dateParser = new DateParserService();
        $this->rowNormalizer = new RowNormalizationService($this->dateParser);
        $this->filterService = new ReportScopeFilterService($this->dateParser);
        $this->urlValidator = new UrlValidationService();
        $this->contentExtractor = new ContentExtractionService();
        $this->postAnalyzer = new PostAnalysisService($this->contentExtractor);
        $this->coverageService = new CoverageEvaluationService($this->dateParser);
        $this->summaryService = new SummaryAggregationService($this->urlValidator);
        $this->excelExport = new ExcelExportService();
        $this->wordExport = new WordExportService();
        $this->pdfExport = new PdfExportService();
    }

    /**
     * Show dashboard
     */
    public function index()
    {
        return view('verification.index');
    }

    /**
     * Run verification
     */
    public function run(Request $request)
    {
        // Increase execution time for large workbooks
        set_time_limit(600);
        
        $startTime = microtime(true);

        // Validate input
        $request->validate([
            'workbook' => 'required|file|mimes:xlsx|max:51200',
            'mode' => 'required|in:complete,single_week,date_range',
            'week' => 'nullable|required_if:mode,single_week',
            'start_date' => 'nullable|required_if:mode,date_range',
            'end_date' => 'nullable|required_if:mode,date_range'
        ]);

        try {
            // 1. Upload workbook
            $uploadResult = $this->uploadService->store($request->file('workbook'));
            
            if (!$uploadResult['success']) {
                return back()->with('error', $uploadResult['error']);
            }

            $workbookPath = $uploadResult['file_path'];
            $fileName = $uploadResult['file_name'];

            // Calculate workbook hash for persistent caching
            $workbookHash = md5_file($workbookPath);
            $this->urlValidator->setWorkbookHash($workbookHash);
            Log::info("Workbook hash: $workbookHash - will use persistent caching");

            // Check if we have cached summary stats - but only for complete mode without filters
            $reportMode = $request->input('mode');
            $hasFilters = ($reportMode !== 'complete') ||
                         !empty($request->input('worksheet')) ||
                         !empty($request->input('url_column'));

            $cachedSummary = null;
            if (!$hasFilters) {
                $cachedSummary = $this->urlValidator->getCachedSummary($workbookHash);
            }

            if ($cachedSummary !== null) {
                Log::info("Using cached summary stats - skipping URL validation");

                // Ensure cached summary has proper structure for view
                if (!isset($cachedSummary['overall'])) {
                    Log::info("Cached summary missing 'overall' key - adding default structure");
                    $cachedSummary['overall'] = [
                        'total_rows' => 0,
                        'total_urls_checked' => 0,
                        'working_urls' => 0,
                        'valid_urls' => 0,
                        'broken_urls' => 0,
                        'redirected_urls' => 0,
                        'invalid_urls' => 0,
                        'cannot_verify_urls' => 0,
                        'timeout_urls' => 0,
                        'blank_posts' => 0,
                        'low_content_posts' => 0,
                        'valid_posts' => 0,
                        'weeks_found' => [],
                        'unique_domains' => 0,
                        'date_range' => [
                            'start' => null,
                            'end' => null
                        ]
                    ];
                }

                if (!isset($cachedSummary['worksheets'])) {
                    Log::info("Cached summary missing 'worksheets' key - adding default structure");
                    $cachedSummary['worksheets'] = [];
                }

                if (!isset($cachedSummary['mode'])) {
                    $cachedSummary['mode'] = 'complete';
                }

                if (!isset($cachedSummary['filter'])) {
                    $cachedSummary['filter'] = [];
                }

                // Restore results from cached summary
                $results = [
                    'excel' => $cachedSummary['excel_file'] ?? 'cached_excel.xlsx',
                    'word' => $cachedSummary['word_file'] ?? 'cached_word.docx',
                    'pdf' => $cachedSummary['pdf_file'] ?? 'cached_pdf.pdf',
                    'summary' => $cachedSummary,
                    'coverage' => $cachedSummary['coverage'] ?? [],
                    'exceptions' => $cachedSummary['exceptions'] ?? []
                ];

                return view('verification.result', [
                    'results' => $results,
                    'elapsed' => 0
                ]);
            }

            // 2. Load workbook
            $spreadsheet = $this->uploadService->load($workbookPath);
            if (!$spreadsheet) {
                throw new \Exception('Failed to load workbook');
            }

            // 3. Scan worksheets
            $worksheets = $this->scannerService->scan($spreadsheet);

            // 4. Process each worksheet
            $processedData = [
                'by_worksheet' => [],
                'all_rows' => []
            ];

            $filterValues = [
                'week' => $request->input('week'),
                'start_date' => $request->input('start_date'),
                'end_date' => $request->input('end_date')
            ];

            $reportMode = $request->input('mode');

            foreach ($worksheets as $worksheetInfo) {
                $sheet = $spreadsheet->getSheetByName($worksheetInfo['name']);
                if (!$sheet) continue;

                // Skip Meta Tags worksheet
                if (strtolower($worksheetInfo['name']) === 'meta tags') {
                    Log::info("Skipping Meta Tags worksheet");
                    continue;
                }

                // Get worksheet data
                $sheetData = $this->scannerService->getWorksheetData($sheet);

                // Map headers
                $headerMapping = $this->headerService->map($sheetData['headers']);

                // Detect Validation Status column if present
                $validationStatusColIdx = null;
                for ($colIdx = 1; $colIdx <= 50; $colIdx++) {
                    $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
                    $cellValue = $sheet->getCell($colLetter . '1')->getValue();
                    if ($cellValue !== null && strtolower(trim($cellValue)) === 'validation status') {
                        $validationStatusColIdx = $colIdx;
                        break;
                    }
                }

                // Skip if no URL columns found (i.e., no Submission page column)
                if (empty($headerMapping['url_columns'])) {
                    Log::info("Skipping '" . $worksheetInfo['name'] . "' - no Submission page column");
                    continue;
                }

                // Default to only High Quality Submission worksheet
                $worksheetFilter = $request->input('worksheet', 'High Quality Submission');
                if (!empty($worksheetFilter) && $worksheetInfo['name'] !== $worksheetFilter) {
                    // Skip other worksheets when filter is set
                    Log::info("Skipping '" . $worksheetInfo['name'] . "' - not matching filter '$worksheetFilter'");
                    continue;
                }
                
                // Default to ONLY Submission page column if no filter specified
                $urlColumnFilter = $request->input('url_column', 'submission page');
                if (!empty($urlColumnFilter) && $urlColumnFilter !== 'submission page') {
                    // Only keep the requested URL column
                    $keptColumns = [];
                    foreach ($headerMapping['url_columns'] as $col) {
                        $colNameLower = strtolower($col['name'] ?? '');
                        $filterLower = strtolower($urlColumnFilter);
                        // Match if exact, partial, or special logic for Submission Page
                        if ($colNameLower === $filterLower || 
                            str_contains($colNameLower, $filterLower) ||
                            ($filterLower === 'submission page' && str_contains($colNameLower, 'submission'))) {
                            $keptColumns[] = $col;
                        }
                    }
                    $headerMapping['url_columns'] = $keptColumns;
                    Log::info("Filtered URL columns: " . implode(", ", array_column($keptColumns, 'name')));
                } else {
                    // Log detected URL columns when no filter
                    Log::info("Found URL columns: " . implode(", ", array_column($headerMapping['url_columns'], 'name')));
                }

                // Normalize rows
                $normalizedRows = [];
                foreach ($sheetData['rows'] as $row) {
                    $normalized = $this->rowNormalizer->normalize(
                        $row['data'],
                        $headerMapping,
                        $worksheetInfo['name'],
                        $row['row_number']
                    );
                    
                    // For now, don't filter by Status at all in complete mode - process all rows
                    // Status filtering can be added back when basic functionality works
                    // (Skipping status check to debug why 0 results)
                    
                    $normalizedRows[] = $normalized;
                }

                // Filter by mode
                $filteredRows = $this->filterService->filter(
                    $normalizedRows,
                    $reportMode,
                    $filterValues
                );

                // Validate URLs concurrently
                $validationItems = [];
                $rowIndexes = [];
                $urlIndexes = [];

                foreach ($filteredRows as $rowIndex => &$filteredRow) {
                    if (!$filteredRow['included_in_scope']) continue;

                    // Find Our Url for this row (still needed for HTML analysis)
                    $ourUrl = null;
                    foreach ($filteredRow['urls'] as $urlInfo) {
                        $colName = strtolower($urlInfo['column_name'] ?? '');
                        if (str_contains($colName, 'our url')) {
                            $ourUrl = $urlInfo['original_url'];
                            break;
                        }
                    }
                    $keyword = $filteredRow['keyword'] ?? null;

                    // Add each URL to batch
                    foreach ($filteredRow['urls'] as $urlIdx => $urlData) {
                        $validationItems[] = [
                            'url' => $urlData['original_url'],
                            'ourUrl' => $ourUrl,
                            'keyword' => $keyword
                        ];
                        $rowIndexes[] = $rowIndex;
                        $urlIndexes[] = $urlIdx;
                    }
                }

                // Run sequential validation with domain-aware parallel execution
                // Group URLs by domain to avoid rate limiting, process in batches
                // Caching is preserved across all URLs for consistent results
                if (!empty($validationItems)) {
                    $this->validateWithDomainBatching($validationItems, $rowIndexes, $urlIndexes, $filteredRows);
                    
                    // Save persistent cache after all validations
                    $this->urlValidator->savePersistentCache();
                }

                // Analyze posts
                $this->postAnalyzer->analyzeMultiple($filteredRows);

                // Write validation status to column if the column exists
                if ($validationStatusColIdx) {
                    foreach ($filteredRows as $rowIdx => $rowProc) {
                        $rowNum = $rowProc['row_number'] ?? null;
                        if (!$rowNum) continue;
                        $statusVal = null;
                        // Get status from first URL validated (submission page)
                        if (!empty($rowProc['urls'])) {
                            $firstUrl = reset($rowProc['urls']);
                            $statusVal = $firstUrl['status'] ?? 'Unknown';
                        }
                        if ($statusVal) {
                            $sheet->setCellValueByColumnAndRow($validationStatusColIdx, $rowNum, $statusVal);
                        }
                    }
                }

                // Store processed data
                $processedData['by_worksheet'][$worksheetInfo['name']] = $filteredRows;
                $processedData['all_rows'] = array_merge(
                    $processedData['all_rows'],
                    $filteredRows
                );
            }

            // Check if any rows are included in scope (for filtered modes)
            if ($reportMode === 'single_week') {
                $hasIncludedRows = false;
                foreach ($processedData['all_rows'] as $row) {
                    if (isset($row['included_in_scope']) && $row['included_in_scope']) {
                        $hasIncludedRows = true;
                        break;
                    }
                }

                if (!$hasIncludedRows) {
                    $requestedWeek = $request->input('week');
                    return back()->with('error', "No data found for week {$requestedWeek}. Please try a different week number.");
                }
            }

            // 5. Generate summary
            $summary = $this->summaryService->generateSummary(
                $processedData,
                $reportMode,
                $filterValues
            );

            // 6. Generate exceptions
            $exceptions = $this->summaryService->generateExceptions($processedData);

            // 7. Evaluate coverage per worksheet
            $coverage = [];
            foreach ($processedData['by_worksheet'] as $wsName => $rows) {
                $coverage[$wsName] = $this->coverageService->evaluateWorksheetCoverage(
                    $rows,
                    $reportMode,
                    $filterValues
                );
            }

            // 8. Export to all formats
            $metadata = [
                'generated_at' => date('Y-m-d H:i:s'),
                'mode' => $reportMode,
                'filter' => $filterValues
            ];

            // Generate the original Excel report (multiple sheets)
            $excelFile = $this->excelExport->export($summary, $exceptions, $coverage);

            // Also save a copy of the input workbook with added validation column
            $inputExcelFile = storage_path('app/exports/Input_Excel_File.xlsx');
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($inputExcelFile);
            $wordFile = $this->wordExport->export($summary, $exceptions, $coverage, $fileName, $metadata);
            $pdfFile = $this->pdfExport->export($summary, $exceptions, $coverage, $fileName, $metadata);

            // 9. Clean up uploaded workbook
            $this->uploadService->cleanup($workbookPath);

            // Store results in session for download
            $results = [
                'excel' => basename($excelFile),
                'word' => basename($wordFile),
                'pdf' => basename($pdfFile),
                'summary' => $summary,
                'coverage' => $coverage,
                'exceptions' => $exceptions
            ];

            // Save summary cache for future re-runs
            $cacheData = [
                'excel_file' => basename($excelFile),
                'word_file' => basename($wordFile),
                'pdf_file' => basename($pdfFile),
                'summary' => $summary,
                'coverage' => $coverage,
                'exceptions' => $exceptions,
                'cached_at' => date('Y-m-d H:i:s')
            ];
            $this->urlValidator->saveSummaryCache($workbookHash, $cacheData);

            session(['verification_results' => $results]);

            $elapsed = round(microtime(true) - $startTime, 2);
            Log::info("Verification completed in {$elapsed}s");

            return view('verification.result', [
                'results' => $results,
                'elapsed' => $elapsed
            ]);

        } catch (\Exception $e) {
            Log::error("Verification error: " . $e->getMessage());
            return back()->with('error', 'Verification failed: ' . $e->getMessage());
        }
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
        
        if (!$fileName) {
            return back()->with('error', 'File not found');
        }

        $filePath = storage_path('app/exports/' . $fileName);
        
        if (!file_exists($filePath)) {
            return back()->with('error', 'File not found');
        }

        return response()->download($filePath);
    }

    /**
     * Clear persistent cache
     */
    public function clearCache(Request $request)
    {
        $this->urlValidator->clearPersistentCache();
        return back()->with('success', 'Cache cleared successfully');
    }

    /**
     * Validate URLs with domain-aware parallel batching
     * Groups URLs by domain and processes in batches to avoid rate limiting
     * Each batch of 10 URLs contains unique domains only
     */
    private function validateWithDomainBatching(array $validationItems, array $rowIndexes, array $urlIndexes, array &$filteredRows): void
    {
        $batchSize = 10; // Process 10 URLs at a time
        $batches = [];
        $currentBatch = [];
        $usedDomains = [];
        
        foreach ($validationItems as $i => $item) {
            $url = $item['url'] ?? '';
            $domain = $this->extractDomain($url);
            
            if (!empty($domain)) {
                // Check if we can add to current batch (domain not used in current batch)
                if (!isset($usedDomains[$domain])) {
                    $currentBatch[] = ['index' => $i, 'item' => $item, 'domain' => $domain];
                    $usedDomains[$domain] = true;
                    
                    if (count($currentBatch) >= $batchSize) {
                        $batches[] = $currentBatch;
                        $currentBatch = [];
                        $usedDomains = [];
                    }
                } else {
                    // Domain already in current batch, save current batch and start new one
                    if (!empty($currentBatch)) {
                        $batches[] = $currentBatch;
                        $currentBatch = [];
                        $usedDomains = [];
                    }
                    // Add to new batch
                    $currentBatch[] = ['index' => $i, 'item' => $item, 'domain' => $domain];
                    $usedDomains[$domain] = true;
                }
            } else {
                // Invalid URL (no domain) - add to current batch or start new one
                // These will be marked as 'Invalid' by the validator
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
        
        // Add remaining items
        if (!empty($currentBatch)) {
            $batches[] = $currentBatch;
        }
        
        Log::info("Processing " . count($validationItems) . " URLs in " . count($batches) . " batches with domain-aware parallel execution");
        
        // Process each batch
        foreach ($batches as $batchNum => $batch) {
            Log::info("Processing batch " . ($batchNum + 1) . "/" . count($batches) . " with " . count($batch) . " URLs");
            
            // Use batchValidateWithAnalysis for parallel execution within batch
            $batchItems = array_map(fn($b) => $b['item'], $batch);
            $batchResults = $this->urlValidator->batchValidateWithAnalysis($batchItems, 10);
            
            // Map results back to filteredRows - use batch array index, not original index
            foreach ($batch as $batchIdx => $batchItem) {
                $i = $batchItem['index'];
                $res = $batchResults[$batchIdx] ?? [];
                
                $rIdx = $rowIndexes[$i];
                $uIdx = $urlIndexes[$i];
                
                if (isset($filteredRows[$rIdx]['urls'][$uIdx])) {
                    $filteredRows[$rIdx]['urls'][$uIdx]['status'] = $res['status'] ?? 'Unknown';
                    $filteredRows[$rIdx]['urls'][$uIdx]['status_code'] = $res['status_code'] ?? 0;
                    $filteredRows[$rIdx]['urls'][$uIdx]['final_url'] = $res['final_url'] ?? '';
                    $filteredRows[$rIdx]['urls'][$uIdx]['error'] = $res['error'] ?? null;
                    $filteredRows[$rIdx]['urls'][$uIdx]['cannot_verify'] = $res['cannot_verify'] ?? false;
                    $filteredRows[$rIdx]['urls'][$uIdx]['is_blank'] = $res['is_blank'] ?? false;
                }
            }
            
            // Small delay between batches to be polite
            usleep(500000); // 0.5 second delay
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
}