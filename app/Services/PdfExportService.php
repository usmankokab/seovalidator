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

        // Enhanced styles with better formatting
        $styles = '
            <style>
                body { font-family: Arial, sans-serif; font-size: 11px; line-height: 1.4; color: #333; }
                h1 { font-size: 24px; text-align: center; margin-bottom: 20px; color: #2c3e50; border-bottom: 3px solid #3498db; padding-bottom: 10px; }
                h2 { font-size: 18px; margin-top: 25px; margin-bottom: 12px; color: #34495e; border-bottom: 2px solid #bdc3c7; padding-bottom: 5px; }
                h3 { font-size: 14px; margin-top: 18px; margin-bottom: 8px; color: #7f8c8d; }
                .insights { background: #ecf0f1; padding: 15px; border-left: 4px solid #3498db; margin: 15px 0; }
                .metric-card { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 10px; margin: 5px; display: inline-block; min-width: 120px; text-align: center; }
                .metric-value { font-size: 18px; font-weight: bold; color: #2c3e50; }
                .metric-label { font-size: 10px; color: #7f8c8d; text-transform: uppercase; }
                .status-excellent { color: #27ae60; font-weight: bold; }
                .status-good { color: #f39c12; font-weight: bold; }
                .status-attention { color: #e74c3c; font-weight: bold; }
                table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10px; }
                th { background: #34495e; color: white; padding: 8px; text-align: left; }
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
        $html .= "<p><strong>Unique Report ID:</strong> {$metadata['timestamp']}</p>";
        
        if (!empty($metadata['filter'])) {
            $filterText = is_array($metadata['filter']) ? json_encode($metadata['filter']) : $metadata['filter'];
            $html .= "<p><strong>Filter:</strong> {$filterText}</p>";
        }
        $html .= '</div>';
        $html .= '</body></html>';
        
        $mpdf->WriteHTML($html);
        $mpdf->AddPage();

        // Log PDF generation with key metrics
        Log::info("PDF Report Generated", [
            'total_rows' => $summary['overall']['total_rows'] ?? 0,
            'working_urls' => $summary['overall']['working_urls'] ?? 0,
            'generated_at' => $metadata['generated_at'] ?? 'unknown'
        ]);

        // Executive Insights
        $html = '<html><body>';
        $html .= $this->generateExecutiveInsights($summary, $exceptions);
        $mpdf->WriteHTML($html);
        $mpdf->AddPage();

        // Key Metrics Dashboard
        $html = '<html><body>';
        $html .= $this->generateKeyMetricsDashboard($summary);
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
            $this->addUrlExceptionsSection($mpdf, 'Broken URLs', $exceptions['broken_urls']);
        }

        // Timeout URLs
        if (!empty($exceptions['timeout_urls'])) {
            $mpdf->AddPage();
            $this->addUrlExceptionsSection($mpdf, 'Timeout URLs', $exceptions['timeout_urls']);
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

    /**
     * Add URL exceptions section
     */
    private function addUrlExceptionsSection(Mpdf $mpdf, string $title, array $urls): void
    {
        $html = '<html><body>';
        $html .= "<h2>{$title}</h2>";
        $html .= '<table>';
        $html .= '<tr><th>Worksheet</th><th>Row</th><th>URL</th><th>Error</th></tr>';

        $count = 0;
        foreach ($urls as $url) {
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

    /**
     * Generate Executive Insights section
     */
    private function generateExecutiveInsights(array $summary, array $exceptions): string
    {
        $html = '<h2>Executive Insights</h2>';
        $html .= '<div class="insights">';

        $overall = $summary['overall'];
        $totalUrls = $overall['total_urls_checked'];
        $workingUrls = $overall['working_urls'];
        $brokenUrls = $overall['broken_urls'];

        $healthScore = $totalUrls > 0 ? round(($workingUrls / $totalUrls) * 100, 1) : 0;

        $html .= '<h3>🔍 Key Findings:</h3>';
        $html .= '<ul>';

        if ($healthScore >= 80) {
            $html .= "<li class='status-excellent'>✅ Excellent URL Health: {$healthScore}% of URLs are working properly.</li>";
        } elseif ($healthScore >= 60) {
            $html .= "<li class='status-good'>⚠️ Good URL Health: {$healthScore}% of URLs are working, but {$brokenUrls} URLs need attention.</li>";
        } else {
            $html .= "<li class='status-attention'>❌ Poor URL Health: Only {$healthScore}% of URLs are working. {$brokenUrls} URLs require immediate fixing.</li>";
        }

        if ($overall['cannot_verify_urls'] > 0) {
            $html .= "<li>🔒 {$overall['cannot_verify_urls']} URLs are protected by anti-bot systems or require authentication.</li>";
        }

        if ($overall['blank_posts'] > 0) {
            $html .= "<li>📝 {$overall['blank_posts']} posts have no content and may affect SEO performance.</li>";
        }

        if ($overall['low_content_posts'] > 0) {
            $html .= "<li>📄 {$overall['low_content_posts']} posts have insufficient content (<50 words) for optimal SEO.</li>";
        }

        if (count($overall['weeks_found']) > 1) {
            $html .= "<li>📅 Content spans " . count($overall['weeks_found']) . " weeks, showing good content consistency.</li>";
        }

        $html .= '</ul>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Generate Key Metrics Dashboard
     */
    private function generateKeyMetricsDashboard(array $summary): string
    {
        $html = '<h2>Key Metrics Dashboard</h2>';

        $overall = $summary['overall'];

        $html .= '<div style="text-align: center; margin: 20px 0;">';

        // URL Health
        $totalUrls = $overall['total_urls_checked'];
        $workingUrls = $overall['working_urls'];
        $healthPercent = $totalUrls > 0 ? round(($workingUrls / $totalUrls) * 100, 1) : 0;

        $html .= '<div class="metric-card">';
        $html .= '<div class="metric-value">' . $healthPercent . '%</div>';
        $html .= '<div class="metric-label">URL Health Score</div>';
        $html .= '</div>';

        // Content Quality
        $contentIssues = $overall['blank_posts'] + $overall['low_content_posts'];

        $html .= '<div class="metric-card">';
        $html .= '<div class="metric-value">' . $contentIssues . '</div>';
        $html .= '<div class="metric-label">Content Issues</div>';
        $html .= '</div>';

        // Domain Diversity
        $html .= '<div class="metric-card">';
        $html .= '<div class="metric-value">' . $overall['unique_domains'] . '</div>';
        $html .= '<div class="metric-label">Unique Domains</div>';
        $html .= '</div>';

        // Coverage
        $html .= '<div class="metric-card">';
        $html .= '<div class="metric-value">' . count($overall['weeks_found']) . '</div>';
        $html .= '<div class="metric-label">Weeks Covered</div>';
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Generate URL Health Analysis
     */
    private function generateUrlHealthAnalysis(array $summary, array $exceptions): string
    {
        $html = '<h2>URL Health Analysis</h2>';

        $overall = $summary['overall'];

        $html .= '<table>';
        $html .= '<tr><th>Status</th><th>Count</th><th>Description</th><th>Priority</th></tr>';

        $urlStats = [
            ['Working URLs', $overall['working_urls'], 'Accessible and responding properly', 'Low'],
            ['Broken URLs', $overall['broken_urls'], 'Not accessible (404, 5xx errors, DNS failures)', 'High'],
            ['Cannot Verify', $overall['cannot_verify_urls'], 'Protected by anti-bot systems or authentication', 'Medium'],
            ['Redirected', $overall['redirected_urls'], 'HTTP redirects (may be normal)', 'Low'],
            ['Timeout', $overall['timeout_urls'], 'Request timed out (>10 seconds)', 'Medium']
        ];

        foreach ($urlStats as $stat) {
            $html .= '<tr>';
            $html .= '<td>' . $stat[0] . '</td>';
            $html .= '<td>' . $stat[1] . '</td>';
            $html .= '<td>' . $stat[2] . '</td>';
            $html .= '<td>' . $stat[3] . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';

        return $html;
    }

    /**
     * Generate Content Quality Analysis
     */
    private function generateContentQualityAnalysis(array $exceptions): string
    {
        $html = '<h2>Content Quality Analysis</h2>';

        $html .= '<table>';
        $html .= '<tr><th>Issue Type</th><th>Count</th><th>Description</th><th>SEO Impact</th></tr>';

        $contentStats = [
            ['Blank Posts', count($exceptions['blank_posts']), 'Posts with no content (0 words)', 'High'],
            ['Low Content Posts', count($exceptions['low_content_posts']), 'Posts with insufficient content (<50 words)', 'Medium']
        ];

        foreach ($contentStats as $stat) {
            $html .= '<tr>';
            $html .= '<td>' . $stat[0] . '</td>';
            $html .= '<td>' . $stat[1] . '</td>';
            $html .= '<td>' . $stat[2] . '</td>';
            $html .= '<td>' . $stat[3] . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';

        return $html;
    }

    /**
     * Generate Recommendations section
     */
    private function generateRecommendations(array $summary, array $exceptions): string
    {
        $html = '<h2>Recommendations & Action Items</h2>';

        $overall = $summary['overall'];
        $recommendations = [];

        if ($overall['broken_urls'] > 0) {
            $recommendations[] = [
                'priority' => 'High',
                'title' => 'Fix Broken URLs',
                'description' => "Address {$overall['broken_urls']} broken URLs that are hurting your SEO and user experience.",
                'actions' => ['Check for typos', 'Update moved content', 'Set up 301 redirects']
            ];
        }

        if ($overall['cannot_verify_urls'] > 0) {
            $recommendations[] = [
                'priority' => 'Medium',
                'title' => 'Review Protected URLs',
                'description' => "{$overall['cannot_verify_urls']} URLs require authentication or are blocked by anti-bot systems.",
                'actions' => ['Verify authentication requirements', 'Check if internal URLs', 'Consider different crawling approach']
            ];
        }

        if ($overall['blank_posts'] > 0) {
            $recommendations[] = [
                'priority' => 'High',
                'title' => 'Add Content to Blank Posts',
                'description' => "{$overall['blank_posts']} posts have no content, providing no value to users or search engines.",
                'actions' => ['Write meaningful content', 'Ensure 300+ words per post', 'Add images and formatting']
            ];
        }

        if ($overall['low_content_posts'] > 0) {
            $recommendations[] = [
                'priority' => 'Medium',
                'title' => 'Expand Low-Content Posts',
                'description' => "{$overall['low_content_posts']} posts have insufficient content for good SEO ranking.",
                'actions' => ['Add more details', 'Include examples', 'Add subheadings and lists']
            ];
        }

        foreach ($recommendations as $rec) {
            $html .= "<h3>{$rec['priority']} Priority: {$rec['title']}</h3>";
            $html .= "<p>{$rec['description']}</p>";
            $html .= "<p><strong>Recommended Actions:</strong></p>";
            $html .= "<ul>";
            foreach ($rec['actions'] as $action) {
                $html .= "<li>{$action}</li>";
            }
            $html .= "</ul>";
        }

        if (empty($recommendations)) {
            $html .= '<div class="insights">';
            $html .= '<p class="status-excellent">✅ Excellent! No major issues found. Your content is in good health.</p>';
            $html .= '<p>Continue monitoring to maintain optimal SEO performance.</p>';
            $html .= '</div>';
        }

        return $html;
    }
}