<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Log;

/**
 * FR-12: Excel Export Service
 * Generates multi-sheet verification reports.
 */
class ExcelExportService
{
    private string $exportPath;

    public function __construct()
    {
        $this->exportPath = storage_path('app/exports');
        if (!is_dir($this->exportPath)) {
            mkdir($this->exportPath, 0755, true);
        }
    }

    /**
     * Generate Excel report with all tabs
     */
    public function export(array $summary, array $exceptions, array $coverage, string $fileName = 'Verification_Report.xlsx'): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Temp');
        $sheetIndex = 1;

        // Sheet 1: Dashboard
        $this->addDashboard($spreadsheet, $summary, $exceptions, $sheetIndex++);

        // Sheet 2: Executive Summary
        $this->addExecutiveSummary($spreadsheet, $summary, $sheetIndex++);

        // Sheet 3: Worksheet Summary
        $this->addWorksheetSummary($spreadsheet, $summary, $sheetIndex++);

        // Sheet 4: URL Health Analysis
        $this->addUrlHealthAnalysis($spreadsheet, $summary, $sheetIndex++);

        // Sheet 5: Content Quality
        $this->addContentQualityAnalysis($spreadsheet, $exceptions, $sheetIndex++);

        // Sheet 6: Period Coverage
        $this->addPeriodCoverage($spreadsheet, $coverage, $sheetIndex++);

        // Sheet 7: Recommendations
        $this->addRecommendations($spreadsheet, $summary, $exceptions, $sheetIndex++);

        // Sheet 8: Timeout URLs
        $this->addTimeoutUrls($spreadsheet, $exceptions, $sheetIndex++);

        // Sheet 9: URL Checks
        $this->addUrlChecks($spreadsheet, $exceptions, $sheetIndex++);

        // Sheet 10: Broken URLs
        $this->addBrokenUrls($spreadsheet, $exceptions, $sheetIndex++);

        // Sheet 11: Cannot Verify URLs
        $this->addCannotVerifyUrls($spreadsheet, $exceptions, $sheetIndex++);
        $this->addTimeoutUrls($spreadsheet, $exceptions, $sheetIndex++);

        // Sheet 8: Blank Posts
        $this->addBlankPosts($spreadsheet, $exceptions, $sheetIndex++);

        // Sheet 9: Low Content Posts
        $this->addLowContentPosts($spreadsheet, $exceptions, $sheetIndex++);

        // Only add detailed Post Analysis for Complete mode to avoid memory issues
        $isFilteredMode = (count($summary['worksheets'] ?? []) < 5) ||
                         isset($summary['filter']['week']) ||
                         isset($summary['filter']['start_date']);

        if (!$isFilteredMode) {
            // Sheet 10: Post Analysis (only for complete mode with many worksheets)
            $this->addPostAnalysis($spreadsheet, $exceptions, $sheetIndex++);
        }

        // Sheet 11/10: URL Validation Status with result column
        $this->addUrlValidationStatus($spreadsheet, $exceptions, $sheetIndex++);

        $fileName = $this->exportPath . '/' . $fileName;
        $writer = new Xlsx($spreadsheet);
        $writer->save($fileName);

        Log::info("Excel report exported: $fileName");
        return $fileName;
    }
    
    /**
     * Add sheet with URLs and validation status for each submission URL
     */
    private function addUrlValidationStatus(Spreadsheet $spreadsheet, array $exceptions, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('URL Validation Status');

        // Headers
        $headers = ['Worksheet', 'Row', 'URL', 'Status', 'Status Code', 'Error'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($exceptions['broken_urls'] as $url) {
            $sheet->setCellValue('A' . $row, $url['worksheet'] ?? '');
            $sheet->setCellValue('B' . $row, $url['row_number'] ?? '');
            $sheet->setCellValue('C' . $row, $url['url'] ?? '');
            $sheet->setCellValue('D' . $row, $url['status'] ?? '');
            $sheet->setCellValue('E' . $row, $url['status_code'] ?? '');
            $sheet->setCellValue('F' . $row, $url['error'] ?? '');
            $row++;
        }
        
        // Also include Working URLs
        foreach ($exceptions['working_urls'] ?? [] as $url) {
            $sheet->setCellValue('A' . $row, $url['worksheet'] ?? '');
            $sheet->setCellValue('B' . $row, $url['row_number'] ?? '');
            $sheet->setCellValue('C' . $row, $url['url'] ?? '');
            $sheet->setCellValue('D' . $row, 'Working');
            $sheet->setCellValue('E' . $row, $url['status_code'] ?? 200);
            $row++;
        }
        
        // Also Redirected
        foreach ($exceptions['redirected_urls'] ?? [] as $url) {
            $sheet->setCellValue('A' . $row, $url['worksheet'] ?? '');
            $sheet->setCellValue('B' . $row, $url['row_number'] ?? '');
            $sheet->setCellValue('C' . $row, $url['url'] ?? '');
            $sheet->setCellValue('D' . $row, 'Redirected');
            $sheet->setCellValue('E' . $row, $url['status_code'] ?? '');
            $row++;
        }
        
        // Also Cannot Verify
        foreach ($exceptions['cannot_verify_urls'] ?? [] as $url) {
            $sheet->setCellValue('A' . $row, $url['source_worksheet'] ?? '');
            $sheet->setCellValue('B' . $row, $url['original_row_number'] ?? '');
            $sheet->setCellValue('C' . $row, $url['original_url'] ?? '');
            $sheet->setCellValue('D' . $row, 'Cannot Verify');
            $sheet->setCellValue('E' . $row, $url['status_code'] ?? '');
            $sheet->setCellValue('F' . $row, $url['error'] ?? '');
            $row++;
        }
    }

    private function addExecutiveSummary(Spreadsheet $spreadsheet, array $summary, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Executive Summary');
        $overall = $summary['overall'] ?? [];
        $sheet->fromArray([
            ['Metric', 'Value'],
            ['Total Worksheets', count($summary['worksheets'] ?? [])],
            ['Rows', $overall['total_rows'] ?? 0],
            ['Checked', $overall['total_urls_checked'] ?? 0],
            ['Working', $overall['working_urls'] ?? 0],
            ['Cannot Verify', $overall['cannot_verify_urls'] ?? 0],
            ['Valid', $overall['valid_urls'] ?? 0],
            ['Broken', $overall['broken_urls'] ?? 0],
            ['Blank', $overall['blank_posts'] ?? 0],
            ['Low', $overall['low_content_posts'] ?? 0],
            ['Redirected', $overall['redirected_urls'] ?? 0],
            ['Timeout', $overall['timeout_urls'] ?? 0],
            ['Unique', $overall['unique_domains'] ?? 0],
            ['Weeks', count($overall['weeks_found'] ?? [])]
        ], null, 'A1');
    }

    private function addWorksheetSummary(Spreadsheet $spreadsheet, array $summary, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Worksheet Summary');
        
        $data = [['Worksheet', 'Rows', 'Checked', 'Working', 'Cannot Verify', 'Valid', 'Broken', 'Blank', 'Low', 'Redirected', 'Timeout', 'Unique', 'Weeks']];
        foreach ($summary['worksheets'] ?? [] as $wsName => $wsSummary) {
            $data[] = [
                $wsName,
                $wsSummary['total_rows'] ?? 0,
                $wsSummary['total_urls_checked'] ?? 0,
                $wsSummary['working_urls'] ?? 0,
                $wsSummary['cannot_verify_urls'] ?? 0,
                $wsSummary['valid_urls'] ?? 0,
                $wsSummary['broken_urls'] ?? 0,
                $wsSummary['blank_posts'] ?? 0,
                $wsSummary['low_content_posts'] ?? 0,
                $wsSummary['redirected_urls'] ?? 0,
                $wsSummary['timeout_urls'] ?? 0,
                $wsSummary['unique_domains'] ?? 0,
                count($wsSummary['weeks'] ?? [])
            ];
        }
        $sheet->fromArray($data, null, 'A1');
    }

    private function addPeriodCoverage(Spreadsheet $spreadsheet, array $coverage, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Period Coverage');
        
        $data = [['Worksheet', 'Period Status', 'Week/Date Range']];
        foreach ($coverage as $wsName => $periodInfo) {
            $data[] = [
                $wsName,
                $periodInfo['status'] ?? 'Missing',
                $periodInfo['period'] ?? ''
            ];
        }
        $sheet->fromArray($data, null, 'A1');
    }

    private function addUrlChecks(Spreadsheet $spreadsheet, array $exceptions, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('URL Checks');
        
        $data = [['Worksheet', 'Row', 'URL', 'Status', 'Error']];
        
        foreach ($exceptions['broken_urls'] as $url) {
            $data[] = [
                $url['worksheet'] ?? '',
                $url['row_number'] ?? '',
                $url['url'] ?? '',
                $url['status'] ?? 'Broken',
                $url['error'] ?? ''
            ];
        }
        $sheet->fromArray($data, null, 'A1');
    }

    private function addBrokenUrls(Spreadsheet $spreadsheet, array $exceptions, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Broken URLs');
        
        $data = [['Worksheet', 'Row', 'URL', 'Error']];
        foreach ($exceptions['broken_urls'] as $url) {
            $data[] = [
                $url['worksheet'] ?? '',
                $url['row_number'] ?? '',
                $url['url'] ?? '',
                $url['error'] ?? ''
            ];
        }
        $sheet->fromArray($data, null, 'A1');
    }

    private function addCannotVerifyUrls(Spreadsheet $spreadsheet, array $exceptions, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Cannot Verify URLs');

        $data = [['Worksheet', 'Row', 'URL', 'Error']];
        foreach ($exceptions['cannot_verify_urls'] ?? [] as $url) {
            $data[] = [
                $url['source_worksheet'] ?? '',
                $url['original_row_number'] ?? '',
                $url['original_url'] ?? '',
                $url['error'] ?? ''
            ];
        }
        $sheet->fromArray($data, null, 'A1');
    }

    private function addTimeoutUrls(Spreadsheet $spreadsheet, array $exceptions, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Timeout URLs');

        $data = [['Worksheet', 'Row', 'URL', 'Error']];
        foreach ($exceptions['timeout_urls'] ?? [] as $url) {
            $data[] = [
                $url['source_worksheet'] ?? '',
                $url['original_row_number'] ?? '',
                $url['original_url'] ?? '',
                $url['error'] ?? ''
            ];
        }
        $sheet->fromArray($data, null, 'A1');
    }

    private function addBlankPosts(Spreadsheet $spreadsheet, array $exceptions, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Blank Posts');
        
        $data = [['Worksheet', 'Row', 'URL']];
        foreach ($exceptions['blank_posts'] ?? [] as $post) {
            $data[] = [
                $post['worksheet'] ?? '',
                $post['row_number'] ?? '',
                $post['url'] ?? ''
            ];
        }
        $sheet->fromArray($data, null, 'A1');
    }

    private function addLowContentPosts(Spreadsheet $spreadsheet, array $exceptions, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Low Content Posts');
        
        $data = [['Worksheet', 'Row', 'URL', 'Word Count']];
        foreach ($exceptions['low_content_posts'] ?? [] as $post) {
            $data[] = [
                $post['worksheet'] ?? '',
                $post['row_number'] ?? '',
                $post['url'] ?? '',
                $post['word_count'] ?? 0
            ];
        }
        $sheet->fromArray($data, null, 'A1');
    }
    
    private function addPostAnalysis(Spreadsheet $spreadsheet, array $exceptions, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Post Analysis');
        
        $data = [['Worksheet', 'Row', 'URL', 'Post Content', 'Word Count']];
        foreach ($exceptions['all_posts'] ?? [] as $post) {
            $data[] = [
                $post['worksheet'] ?? '',
                $post['row_number'] ?? '',
                $post['url'] ?? '',
                $post['post_text'] ?? '',
                $post['word_count'] ?? 0
            ];
        }
        $sheet->fromArray($data, null, 'A1');
    }

    /**
     * Add Dashboard sheet with key metrics and charts
     */
    private function addDashboard(Spreadsheet $spreadsheet, array $summary, array $exceptions, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Dashboard');

        $overall = $summary['overall'];

        // Title
        $sheet->setCellValue('A1', 'SEO Workbook Verification Dashboard');
        $sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
        $sheet->mergeCells('A1:E1');

        // Key Metrics
        $sheet->setCellValue('A3', 'Key Metrics');
        $sheet->getStyle('A3')->getFont()->setBold(true);

        $metrics = [
            ['URL Health Score', ($overall['total_urls_checked'] > 0 ? round(($overall['working_urls'] / $overall['total_urls_checked']) * 100, 1) : 0) . '%'],
            ['Total URLs Checked', $overall['total_urls_checked']],
            ['Working URLs', $overall['working_urls']],
            ['Broken URLs', $overall['broken_urls']],
            ['Cannot Verify URLs', $overall['cannot_verify_urls']],
            ['Content Quality Issues', $overall['blank_posts'] + $overall['low_content_posts']],
            ['Unique Domains', $overall['unique_domains']],
            ['Weeks Covered', count($overall['weeks_found'])]
        ];

        $row = 4;
        foreach ($metrics as $metric) {
            $sheet->setCellValue('A' . $row, $metric[0]);
            $sheet->setCellValue('B' . $row, $metric[1]);
            $row++;
        }

        // Conditional formatting for status
        $healthScore = $overall['total_urls_checked'] > 0 ? ($overall['working_urls'] / $overall['total_urls_checked']) * 100 : 0;
        if ($healthScore >= 80) {
            $sheet->getStyle('B4')->getFont()->getColor()->setRGB('27AE60');
        } elseif ($healthScore >= 60) {
            $sheet->getStyle('B4')->getFont()->getColor()->setRGB('F39C12');
        } else {
            $sheet->getStyle('B4')->getFont()->getColor()->setRGB('E74C3C');
        }

        // Auto-size columns
        foreach (range('A', 'B') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Add URL Health Analysis sheet
     */
    private function addUrlHealthAnalysis(Spreadsheet $spreadsheet, array $summary, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('URL Health Analysis');

        $overall = $summary['overall'];

        // Title
        $sheet->setCellValue('A1', 'URL Health Analysis');
        $sheet->getStyle('A1')->getFont()->setSize(14)->setBold(true);

        // Headers
        $sheet->setCellValue('A3', 'Status');
        $sheet->setCellValue('B3', 'Count');
        $sheet->setCellValue('C3', 'Description');
        $sheet->setCellValue('D3', 'Priority');
        $sheet->getStyle('A3:D3')->getFont()->setBold(true);

        // Data
        $urlStats = [
            ['Working URLs', $overall['working_urls'], 'Accessible and responding properly', 'Low'],
            ['Broken URLs', $overall['broken_urls'], 'Not accessible (404, 5xx errors, DNS failures)', 'High'],
            ['Cannot Verify', $overall['cannot_verify_urls'], 'HTTP 403 Forbidden (' . ($overall['cannot_verify_breakdown']['forbidden'] ?? 0) . ') or protected by anti-bot systems/authentication', 'Medium'],
            ['Redirected', $overall['redirected_urls'], 'HTTP redirects (may be normal)', 'Low'],
            ['Timeout', $overall['timeout_urls'], 'Request timed out (>10 seconds)', 'Medium']
        ];

        $row = 4;
        foreach ($urlStats as $stat) {
            $sheet->setCellValue('A' . $row, $stat[0]);
            $sheet->setCellValue('B' . $row, $stat[1]);
            $sheet->setCellValue('C' . $row, $stat[2]);
            $sheet->setCellValue('D' . $row, $stat[3]);
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Add Content Quality Analysis sheet
     */
    private function addContentQualityAnalysis(Spreadsheet $spreadsheet, array $exceptions, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Content Quality');

        // Title
        $sheet->setCellValue('A1', 'Content Quality Analysis');
        $sheet->getStyle('A1')->getFont()->setSize(14)->setBold(true);

        // Headers
        $sheet->setCellValue('A3', 'Issue Type');
        $sheet->setCellValue('B3', 'Count');
        $sheet->setCellValue('C3', 'Description');
        $sheet->setCellValue('D3', 'SEO Impact');
        $sheet->getStyle('A3:D3')->getFont()->setBold(true);

        // Data
        $contentStats = [
            ['Blank Posts', count($exceptions['blank_posts']), 'Posts with no content (0 words)', 'High'],
            ['Low Content Posts', count($exceptions['low_content_posts']), 'Posts with insufficient content (<50 words)', 'Medium']
        ];

        $row = 4;
        foreach ($contentStats as $stat) {
            $sheet->setCellValue('A' . $row, $stat[0]);
            $sheet->setCellValue('B' . $row, $stat[1]);
            $sheet->setCellValue('C' . $row, $stat[2]);
            $sheet->setCellValue('D' . $row, $stat[3]);
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Add Recommendations sheet
     */
    private function addRecommendations(Spreadsheet $spreadsheet, array $summary, array $exceptions, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Recommendations');

        $overall = $summary['overall'];

        // Title
        $sheet->setCellValue('A1', 'Recommendations & Action Items');
        $sheet->getStyle('A1')->getFont()->setSize(14)->setBold(true);

        $recommendations = [];
        $row = 3;

        if ($overall['broken_urls'] > 0) {
            $sheet->setCellValue('A' . $row, 'HIGH PRIORITY: Fix Broken URLs');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->getColor()->setRGB('E74C3C');
            $row++;

            $sheet->setCellValue('A' . $row, "Address {$overall['broken_urls']} broken URLs that are hurting your SEO and user experience.");
            $row++;

            $actions = ['Check for typos in URLs', 'Update moved or deleted content', 'Set up proper 301 redirects'];
            foreach ($actions as $action) {
                $sheet->setCellValue('B' . $row, '• ' . $action);
                $row++;
            }
            $row++; // Empty row
        }

        if ($overall['blank_posts'] > 0) {
            $sheet->setCellValue('A' . $row, 'HIGH PRIORITY: Add Content to Blank Posts');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->getColor()->setRGB('E74C3C');
            $row++;

            $sheet->setCellValue('A' . $row, "{$overall['blank_posts']} posts have no content and may affect SEO performance.");
            $row++;

            $actions = ['Write meaningful content for each blank post', 'Ensure each post has at least 300 words', 'Add relevant images and formatting'];
            foreach ($actions as $action) {
                $sheet->setCellValue('B' . $row, '• ' . $action);
                $row++;
            }
            $row++;
        }

        if ($overall['cannot_verify_urls'] > 0) {
            $sheet->setCellValue('A' . $row, 'MEDIUM PRIORITY: Review Protected URLs');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->getColor()->setRGB('F39C12');
            $row++;

            $sheet->setCellValue('A' . $row, "{$overall['cannot_verify_urls']} URLs are protected by anti-bot systems or require authentication.");
            $row++;

            $actions = ['Verify if these URLs require authentication', 'Check if these are internal/admin URLs', 'Consider using different user agents'];
            foreach ($actions as $action) {
                $sheet->setCellValue('B' . $row, '• ' . $action);
                $row++;
            }
            $row++;
        }

        if (empty($recommendations) && empty(array_filter($overall, fn($v) => is_numeric($v) && $v > 0))) {
            $sheet->setCellValue('A' . $row, '✅ EXCELLENT! No major issues found.');
            $sheet->getStyle('A' . $row)->getFont()->getColor()->setRGB('27AE60');
            $row++;

            $sheet->setCellValue('A' . $row, 'Your content appears to be in good health. Continue monitoring for optimal SEO performance.');
        }

        // Auto-size columns
        foreach (range('A', 'B') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}