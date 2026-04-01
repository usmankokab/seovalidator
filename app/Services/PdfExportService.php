<?php

namespace App\Services;

use Mpdf\Mpdf;
use Illuminate\Support\Facades\Log;

/**
 * FR-12: PDF Export Service
 * Generates PDF verification reports using mPDF.
 */
class PdfExportService
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
     * Export verification report to PDF
     */
    public function export(
        array $summary,
        array $exceptions,
        array $coverage,
        string $sourceFileName,
        array $metadata,
        string $fileName = 'Verification_Report.pdf'
    ): string {
        $mpdf = new Mpdf([
            'format' => 'A4',
            'margin_top' => 20,
            'margin_bottom' => 20,
            'margin_left' => 20,
            'margin_right' => 20
        ]);

        // Styles
        $styles = '
            <style>
                body { font-family: Arial, sans-serif; font-size: 11px; }
                h1 { font-size: 20px; text-align: center; margin-bottom: 20px; }
                h2 { font-size: 16px; margin-top: 20px; margin-bottom: 10px; border-bottom: 1px solid #333; }
                h3 { font-size: 14px; margin-top: 15px; margin-bottom: 8px; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
                th, td { border: 1px solid #999; padding: 6px; text-align: left; font-size: 10px; }
                th { background-color: #f0f0f0; font-weight: bold; }
                .meta { margin-bottom: 20px; }
                .meta p { margin: 3px 0; }
                .summary-box { background-color: #f9f9f9; padding: 10px; margin-bottom: 15px; }
                .warning { color: #cc0000; }
            </style>
        ';

        // Cover page
        $html = '<html><body>';
        $html .= '<h1>SEO Workbook Verification Report</h1>';
        $html .= '<div class="meta">';
        $html .= "<p><strong>Source Workbook:</strong> {$sourceFileName}</p>";
        $html .= "<p><strong>Generated:</strong> {$metadata['generated_at']}</p>";
        $html .= "<p><strong>Report Mode:</strong> " . ucfirst($metadata['mode']) . '</p>';
        
        if (!empty($metadata['filter'])) {
            $filterText = is_array($metadata['filter']) ? json_encode($metadata['filter']) : $metadata['filter'];
            $html .= "<p><strong>Filter:</strong> {$filterText}</p>";
        }
        $html .= '</div>';
        $html .= '</body></html>';
        
        $mpdf->WriteHTML($html);
        $mpdf->AddPage();

        // Executive Summary
        $html = '<html><body>';
        $html .= '<h2>Executive Summary</h2>';
        $html .= '<div class="summary-box">';
        $html .= "<p><strong>Total Rows Reviewed:</strong> {$summary['overall']['total_rows']}</p>";
        $html .= "<p><strong>Total URLs Checked:</strong> {$summary['overall']['total_urls_checked']}</p>";
        $html .= "<p><strong>Working URLs:</strong> {$summary['overall']['working_urls']}</p>";
        $html .= "<p><strong>Broken URLs:</strong> {$summary['overall']['broken_urls']}</p>";
        $html .= "<p><strong>Cannot Verify URLs:</strong> {$summary['overall']['cannot_verify_urls']}</p>";
        $html .= "<p><strong>Redirected URLs:</strong> {$summary['overall']['redirected_urls']}</p>";
        $html .= "<p><strong>Invalid URLs:</strong> {$summary['overall']['invalid_urls']}</p>";
        $html .= "<p><strong>Blank Posts:</strong> {$summary['overall']['blank_posts']}</p>";
        $html .= "<p><strong>Posts Under 50 Words:</strong> {$summary['overall']['low_content_posts']}</p>";
        $html .= '</div>';
        $html .= '</body></html>';
        
        $mpdf->WriteHTML($html);
        $mpdf->AddPage();

        // Worksheet Summary
        $html = '<html><body>';
        $html .= '<h2>Worksheet Summary</h2>';
        $html .= '<table>';
        $html .= '<tr><th>Worksheet</th><th>Rows</th><th>URLs</th><th>Working</th><th>Broken</th><th>Blank</th><th>Low</th></tr>';
        
        foreach ($summary['worksheets'] as $name => $data) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($name) . '</td>';
            $html .= '<td>' . $data['total_rows'] . '</td>';
            $html .= '<td>' . $data['total_urls_checked'] . '</td>';
            $html .= '<td>' . $data['working_urls'] . '</td>';
            $html .= '<td>' . $data['broken_urls'] . '</td>';
            $html .= '<td>' . $data['blank_posts'] . '</td>';
            $html .= '<td>' . $data['low_content_posts'] . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        $html .= '</body></html>';
        
        $mpdf->WriteHTML($html);
        $mpdf->AddPage();

        // Period Coverage
        $html = '<html><body>';
        $html .= '<h2>Period Coverage</h2>';
        $html .= '<table>';
        $html .= '<tr><th>Worksheet</th><th>Status</th><th>Date Range</th><th>Notes</th></tr>';
        
        foreach ($coverage as $worksheet => $data) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($worksheet) . '</td>';
            $html .= '<td>' . htmlspecialchars($data['status']) . '</td>';
            $html .= '<td>' . htmlspecialchars(($data['start_date'] ?? '') . ' - ' . ($data['end_date'] ?? '')) . '</td>';
            $html .= '<td>' . htmlspecialchars(implode(', ', $data['notes'])) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        $html .= '</body></html>';
        
        $mpdf->WriteHTML($html);

        // Broken URLs
        if (!empty($exceptions['broken_urls'])) {
            $mpdf->AddPage();
            $html = '<html><body>';
            $html .= '<h2>Broken URLs</h2>';
            $html .= '<table>';
            $html .= '<tr><th>Worksheet</th><th>Row</th><th>URL</th><th>Error</th></tr>';
            
            $count = 0;
            foreach ($exceptions['broken_urls'] as $url) {
                if ($count >= 50) break;
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($url['source_worksheet']) . '</td>';
                $html .= '<td>' . $url['original_row_number'] . '</td>';
                $html .= '<td>' . htmlspecialchars(mb_substr($url['original_url'], 0, 40)) . '</td>';
                $html .= '<td>' . htmlspecialchars($url['error']) . '</td>';
                $html .= '</tr>';
                $count++;
            }
            
            $html .= '</table>';
            $html .= '</body></html>';
            
            $mpdf->WriteHTML($html);
        }

        // Cannot Verify URLs
        if (!empty($exceptions['cannot_verify_urls'])) {
            $mpdf->AddPage();
            $html = '<html><body>';
            $html .= '<h2>Cannot Verify URLs</h2>';
            $html .= '<table>';
            $html .= '<tr><th>Worksheet</th><th>Row</th><th>URL</th><th>Reason</th></tr>';
            
            $count = 0;
            foreach ($exceptions['cannot_verify_urls'] as $url) {
                if ($count >= 50) break;
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($url['source_worksheet']) . '</td>';
                $html .= '<td>' . $url['original_row_number'] . '</td>';
                $html .= '<td>' . htmlspecialchars(mb_substr($url['original_url'], 0, 40)) . '</td>';
                $html .= '<td>' . htmlspecialchars($url['error']) . '</td>';
                $html .= '</tr>';
                $count++;
            }
            
            $html .= '</table>';
            $html .= '</body></html>';
            
            $mpdf->WriteHTML($html);
        }

        // Blank Posts
        if (!empty($exceptions['blank_posts'])) {
            $mpdf->AddPage();
            $html = '<html><body>';
            $html .= '<h2>Blank Posts</h2>';
            $html .= '<table>';
            $html .= '<tr><th>Worksheet</th><th>Row</th><th>Flag Reason</th></tr>';
            
            $count = 0;
            foreach ($exceptions['blank_posts'] as $post) {
                if ($count >= 50) break;
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($post['source_worksheet']) . '</td>';
                $html .= '<td>' . $post['original_row_number'] . '</td>';
                $html .= '<td>' . htmlspecialchars($post['flag_reason']) . '</td>';
                $html .= '</tr>';
                $count++;
            }
            
            $html .= '</table>';
            $html .= '</body></html>';
            
            $mpdf->WriteHTML($html);
        }

        // Low Content Posts
        if (!empty($exceptions['low_content_posts'])) {
            $mpdf->AddPage();
            $html = '<html><body>';
            $html .= '<h2>Posts Under 50 Words</h2>';
            $html .= '<table>';
            $html .= '<tr><th>Worksheet</th><th>Row</th><th>Words</th><th>Flag Reason</th></tr>';
            
            $count = 0;
            foreach ($exceptions['low_content_posts'] as $post) {
                if ($count >= 50) break;
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($post['source_worksheet']) . '</td>';
                $html .= '<td>' . $post['original_row_number'] . '</td>';
                $html .= '<td>' . $post['word_count'] . '</td>';
                $html .= '<td>' . htmlspecialchars($post['flag_reason']) . '</td>';
                $html .= '</tr>';
                $count++;
            }
            
            $html .= '</table>';
            $html .= '</body></html>';
            
            $mpdf->WriteHTML($html);
        }

        // Save file
        $outputFile = $this->outputPath . '/' . $fileName;
        $mpdf->Output($outputFile, 'F');

        Log::info("PDF report exported: {$outputFile}");
        return $outputFile;
    }
}