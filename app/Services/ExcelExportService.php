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
    public function export(array $summary, array $exceptions, array $coverage): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeActiveSheet();
        $sheetIndex = 0;

        // Sheet 1: Executive Summary
        $this->addExecutiveSummary($spreadsheet, $summary, $sheetIndex++);

        // Sheet 2: Worksheet Summary
        $this->addWorksheetSummary($spreadsheet, $summary, $sheetIndex++);

        // Sheet 3: Period Coverage
        $this->addPeriodCoverage($spreadsheet, $coverage, $sheetIndex++);

        // Sheet 4: URL Checks
        $this->addUrlChecks($spreadsheet, $exceptions, $sheetIndex++);

        // Sheet 5: Broken URLs
        $this->addBrokenUrls($spreadsheet, $exceptions, $sheetIndex++);

        // Sheet 6: Blank Posts
        $this->addBlankPosts($spreadsheet, $exceptions, $sheetIndex++);

        // Sheet 7: Low Content Posts
        $this->addLowContentPosts($spreadsheet, $exceptions, $sheetIndex++);
        
        // Sheet 8: Post Analysis
        $this->addPostAnalysis($spreadsheet, $exceptions, $sheetIndex++);
        
        // NEW - Sheet 9: URL Validation Status with result column
        $this->addUrlValidationStatus($spreadsheet, $exceptions, $sheetIndex++);

        $fileName = $this->exportPath . '/Verification_Report.xlsx';
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
    }

    private function addExecutiveSummary(Spreadsheet $spreadsheet, array $summary, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Executive Summary');
        $sheet->fromArray([
            ['Metric', 'Value'],
            ['Total Worksheets', $summary['total_worksheets'] ?? 0],
            ['Total Rows', $summary['total_rows'] ?? 0],
            ['Total URLs Checked', $summary['total_urls_checked'] ?? 0],
            ['Working URLs', $summary['working_urls'] ?? 0],
            ['Broken URLs', $summary['broken_urls'] ?? 0],
            ['Blank Posts', $summary['blank_posts'] ?? 0],
            ['Low Content Posts', $summary['low_content_posts'] ?? 0]
        ], null, 'A1');
    }

    private function addWorksheetSummary(Spreadsheet $spreadsheet, array $summary, int $sheetIndex): void
    {
        $sheet = $spreadsheet->createSheet($sheetIndex);
        $sheet->setTitle('Worksheet Summary');
        
        $data = [['Worksheet', 'Rows', 'URLs Checked', 'Working', 'Broken']];
        foreach ($summary['by_worksheet'] as $wsName => $wsSummary) {
            $data[] = [
                $wsName,
                $wsSummary['total_rows'] ?? 0,
                $wsSummary['total_urls'] ?? 0,
                $wsSummary['working'] ?? 0,
                $wsSummary['broken'] ?? 0
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
}