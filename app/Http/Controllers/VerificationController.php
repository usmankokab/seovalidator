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

                // Run sequential validation with HTML analysis for JavaScript SPA detection
                // Caching is preserved across all URLs for consistent results
                if (!empty($validationItems)) {
                    foreach ($validationItems as $i => $item) {
                        // Use validate() which includes HTML analysis and caching
                        // Do NOT clear cache between requests - this ensures consistent results
                        $res = $this->urlValidator->validate(
                            $item['url'],
                            $item['ourUrl'],
                            $item['keyword']
                        );
                        
                        $rIdx = $rowIndexes[$i];
                        $uIdx = $urlIndexes[$i];
                        $filteredRows[$rIdx]['urls'][$uIdx]['status'] = $res['status'];
                        $filteredRows[$rIdx]['urls'][$uIdx]['status_code'] = $res['status_code'];
                        $filteredRows[$rIdx]['urls'][$uIdx]['final_url'] = $res['final_url'];
                        $filteredRows[$rIdx]['urls'][$uIdx]['error'] = $res['error'];
                        $filteredRows[$rIdx]['urls'][$uIdx]['cannot_verify'] = $res['cannot_verify'] ?? false;
                        $filteredRows[$rIdx]['urls'][$uIdx]['is_blank'] = $res['is_blank'] ?? false;
                    }
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