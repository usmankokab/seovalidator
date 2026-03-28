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

                // Get worksheet data
                $sheetData = $this->scannerService->getWorksheetData($sheet);

                // Map headers
                $headerMapping = $this->headerService->map($sheetData['headers']);

                // Filter to ONLY specific worksheet if requested
                $worksheetFilter = $request->input('worksheet', '');
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

                // Validate URLs with content analysis
                foreach ($filteredRows as $rowIndex => &$filteredRow) {
                    if (!$filteredRow['included_in_scope']) continue;

                    // Get Our Url and keyword from this row for content analysis
                    $ourUrl = null;
                    $keyword = null;
                    
                    // Find Our Url column value (if we haven't extracted already)
                    foreach ($filteredRow['urls'] as $urlInfo) {
                        $colName = strtolower($urlInfo['column_name'] ?? '');
                        if (str_contains($colName, 'our url')) {
                            $ourUrl = $urlInfo['original_url'];
                            break;
                        }
                    }
                    
                    // Get keyword from row (now stored by RowNormalizationService)
                    $keyword = $filteredRow['keyword'] ?? null;
                    
                    foreach ($filteredRow['urls'] as $urlIndex => &$urlData) {
                        // Pass Our Url and keyword for content analysis
                        $urlResult = $this->urlValidator->validate(
                            $urlData['original_url'],
                            $ourUrl,
                            $keyword
                        );
                        $filteredRows[$rowIndex]['urls'][$urlIndex]['status'] = $urlResult['status'];
                        $filteredRows[$rowIndex]['urls'][$urlIndex]['status_code'] = $urlResult['status_code'];
                        $filteredRows[$rowIndex]['urls'][$urlIndex]['final_url'] = $urlResult['final_url'];
                        $filteredRows[$rowIndex]['urls'][$urlIndex]['error'] = $urlResult['error'];
                        
                        // Add HTML analysis results
                        if (isset($urlResult['our_url_found'])) {
                            $filteredRows[$rowIndex]['urls'][$urlIndex]['our_url_found'] = $urlResult['our_url_found'];
                        }
                        if (isset($urlResult['keyword_found'])) {
                            $filteredRows[$rowIndex]['urls'][$urlIndex]['keyword_found'] = $urlResult['keyword_found'];
                        }
                    }
                }
                unset($filteredRow, $urlData);  // Break references

                // Analyze posts
                $this->postAnalyzer->analyzeMultiple($filteredRows);

                // Store processed data
                $processedData['by_worksheet'][$worksheetInfo['name']] = $filteredRows;
                $processedData['all_rows'] = array_merge(
                    $processedData['all_rows'],
                    $filteredRows
                );
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

            $excelFile = $this->excelExport->export($summary, $exceptions, $coverage);
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
}