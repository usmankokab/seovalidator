<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Illuminate\Support\Facades\Log;

/**
 * FR-12: Excel Export Service
 * Generates styled Excel verification reports.
 */
class ExcelExportService
{
    private string $outputPath;

    public function __construct()
    {
        $this->outputPath = storage_path('app/exports');
        if (!is_dir($this->outputPath)) {
            mkdir($this->outputPath, 0755, true);
        }
    }

    /**
     * Export verification report to Excel
     */
    public function export(
        array $summary,
        array $exceptions,
        array $coverage,
        string $fileName = 'Verification_Report.xlsx'
    ): string {
        $spreadsheet = new Spreadsheet();
        
        // Create sheets as per SRS Section 9.2
        $this->addExecutiveSummary($spreadsheet, $summary);
        $this->addWorksheetSummary($spreadsheet, $summary);
        $this->addPeriodCoverage($spreadsheet, $coverage);
        $this->addUrlChecks($spreadsheet, $exceptions['url_checks']);
        $this->addBrokenUrls($spreadsheet, $exceptions['broken_urls']);
        $this->addBlankPosts($spreadsheet, $exceptions['blank_posts']);
        $this->addLowContentPosts($spreadsheet, $exceptions['low_content_posts']);
        $this->addPostAnalysis($spreadsheet, $summary);

        // Save file
        $outputFile = $this->outputPath . '/' . $fileName;
        $writer = new Xlsx($spreadsheet);
        $writer->save($outputFile);

        Log::info("Excel report exported: {$outputFile}");
        return $outputFile;
    }

    /**
     * Add Executive Summary sheet
     */
    private function addExecutiveSummary(Spreadsheet $spreadsheet, array $summary): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Executive Summary');

        $sheet->setCellValue('A1', 'SEO Workbook Verification Report');
        $sheet->setCellValue('A2', 'Generated: ' . date('Y-m-d H:i:s'));
        $sheet->setCellValue('A3', 'Report Mode: ' . ucfirst($summary['mode']));

        // Overall metrics
        $sheet->setCellValue('A5', 'Overall Metrics');
        $sheet->setCellValue('A6', 'Total Rows Reviewed');
        $sheet->setCellValue('B6', $summary['overall']['total_rows']);
        $sheet->setCellValue('A7', 'Total URLs Checked');
        $sheet->setCellValue('B7', $summary['overall']['total_urls_checked']);
        $sheet->setCellValue('A8', 'Working URLs');
        $sheet->setCellValue('B8', $summary['overall']['working_urls']);
        $sheet->setCellValue('A9', 'Broken URLs');
        $sheet->setCellValue('B9', $summary['overall']['broken_urls']);
        $sheet->setCellValue('A10', 'Redirected URLs');
        $sheet->setCellValue('B10', $summary['overall']['redirected_urls']);
        $sheet->setCellValue('A11', 'Invalid URLs');
        $sheet->setCellValue('B11', $summary['overall']['invalid_urls']);
        $sheet->setCellValue('A12', 'Blank Posts');
        $sheet->setCellValue('B12', $summary['overall']['blank_posts']);
        $sheet->setCellValue('A13', 'Posts Under 50 Words');
        $sheet->setCellValue('B13', $summary['overall']['low_content_posts']);

        // Format header
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    }

    /**
     * Add Worksheet Summary sheet
     */
    private function addWorksheetSummary(Spreadsheet $spreadsheet, array $summary): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Worksheet Summary');

        $headers = ['Worksheet', 'Total Rows', 'URLs Checked', 'Working', 'Broken', 'Redirected', 'Invalid', 'Blank Posts', 'Low Content'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($summary['worksheets'] as $worksheetName => $data) {
            $sheet->setCellValue("A{$row}", $worksheetName);
            $sheet->setCellValue("B{$row}", $data['total_rows']);
            $sheet->setCellValue("C{$row}", $data['total_urls_checked']);
            $sheet->setCellValue("D{$row}", $data['working_urls']);
            $sheet->setCellValue("E{$row}", $data['broken_urls']);
            $sheet->setCellValue("F{$row}", $data['redirected_urls']);
            $sheet->setCellValue("G{$row}", $data['invalid_urls']);
            $sheet->setCellValue("H{$row}", $data['blank_posts']);
            $sheet->setCellValue("I{$row}", $data['low_content_posts']);
            $row++;
        }

        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
    }

    /**
     * Add Period Coverage sheet
     */
    private function addPeriodCoverage(Spreadsheet $spreadsheet, array $coverage): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Period Coverage');

        $headers = ['Worksheet', 'Status', 'Week/Date Range', 'Notes'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($coverage as $worksheetName => $data) {
            $sheet->setCellValue("A{$row}", $worksheetName);
            $sheet->setCellValue("B{$row}", $data['status']);
            $sheet->setCellValue("C{$row}", $data['start_date'] . ' - ' . $data['end_date']);
            $sheet->setCellValue("D{$row}", implode(', ', $data['notes']));
            $row++;
        }

        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
    }

    /**
     * Add URL Checks sheet
     */
    private function addUrlChecks(Spreadsheet $spreadsheet, array $urlChecks): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('URL Checks');

        $headers = ['Worksheet', 'Row', 'Week', 'Date', 'URL Column', 'Original URL', 'Final URL', 'Status', 'Code', 'Error'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($urlChecks as $check) {
            $sheet->setCellValue("A{$row}", $check['source_worksheet']);
            $sheet->setCellValue("B{$row}", $check['original_row_number']);
            $sheet->setCellValue("C{$row}", $check['week_value']);
            $sheet->setCellValue("D{$row}", $check['detected_date']);
            $sheet->setCellValue("E{$row}", $check['url_column_name']);
            $sheet->setCellValue("F{$row}", $check['original_url']);
            $sheet->setCellValue("G{$row}", $check['final_url']);
            $sheet->setCellValue("H{$row}", $check['status']);
            $sheet->setCellValue("I{$row}", $check['status_code']);
            $sheet->setCellValue("J{$row}", $check['error']);
            $row++;
        }

        $sheet->getStyle('A1:J1')->getFont()->setBold(true);
    }

    /**
     * Add Broken URLs sheet
     */
    private function addBrokenUrls(Spreadsheet $spreadsheet, array $brokenUrls): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Broken URLs');

        $headers = ['Worksheet', 'Row', 'Week', 'Date', 'URL Column', 'Original URL', 'Error'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($brokenUrls as $url) {
            $sheet->setCellValue("A{$row}", $url['source_worksheet']);
            $sheet->setCellValue("B{$row}", $url['original_row_number']);
            $sheet->setCellValue("C{$row}", $url['week_value']);
            $sheet->setCellValue("D{$row}", $url['detected_date']);
            $sheet->setCellValue("E{$row}", $url['url_column_name']);
            $sheet->setCellValue("F{$row}", $url['original_url']);
            $sheet->setCellValue("G{$row}", $url['error']);
            $row++;
        }

        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
    }

    /**
     * Add Blank Posts sheet
     */
    private function addBlankPosts(Spreadsheet $spreadsheet, array $blankPosts): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Blank Posts');

        $headers = ['Worksheet', 'Row', 'Week', 'Date', 'Post Column', 'Word Count', 'Flag Reason'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($blankPosts as $post) {
            $sheet->setCellValue("A{$row}", $post['source_worksheet']);
            $sheet->setCellValue("B{$row}", $post['original_row_number']);
            $sheet->setCellValue("C{$row}", $post['week_value']);
            $sheet->setCellValue("D{$row}", $post['detected_date']);
            $sheet->setCellValue("E{$row}", $post['post_column_name']);
            $sheet->setCellValue("F{$row}", $post['word_count']);
            $sheet->setCellValue("G{$row}", $post['flag_reason']);
            $row++;
        }

        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
    }

    /**
     * Add Low Content Posts sheet
     */
    private function addLowContentPosts(Spreadsheet $spreadsheet, array $lowContentPosts): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Low Content Posts');

        $headers = ['Worksheet', 'Row', 'Week', 'Date', 'Post Column', 'Word Count', 'Flag Reason'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($lowContentPosts as $post) {
            $sheet->setCellValue("A{$row}", $post['source_worksheet']);
            $sheet->setCellValue("B{$row}", $post['original_row_number']);
            $sheet->setCellValue("C{$row}", $post['week_value']);
            $sheet->setCellValue("D{$row}", $post['detected_date']);
            $sheet->setCellValue("E{$row}", $post['post_column_name']);
            $sheet->setCellValue("F{$row}", $post['word_count']);
            $sheet->setCellValue("G{$row}", $post['flag_reason']);
            $row++;
        }

        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
    }

    /**
     * Add Post Analysis sheet
     */
    private function addPostAnalysis(Spreadsheet $spreadsheet, array $summary): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Post Analysis');

        $headers = ['Worksheet', 'Total Rows', 'Blank Posts', 'Low Content', 'Exceeds Threshold'];
        $sheet->fromArray($headers, null, 'A1');

        $row = 2;
        foreach ($summary['worksheets'] as $worksheetName => $data) {
            $exceeds = $data['total_rows'] - $data['blank_posts'] - $data['low_content_posts'];
            $sheet->setCellValue("A{$row}", $worksheetName);
            $sheet->setCellValue("B{$row}", $data['total_rows']);
            $sheet->setCellValue("C{$row}", $data['blank_posts']);
            $sheet->setCellValue("D{$row}", $data['low_content_posts']);
            $sheet->setCellValue("E{$row}", $exceeds);
            $row++;
        }

        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
    }
}