<?php

namespace App\Services;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Paragraph;
use Illuminate\Support\Facades\Log;

/**
 * FR-12: Word Export Service
 * Generates styled Word verification reports.
 */
class WordExportService
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
     * Export verification report to Word
     */
    public function export(
        array $summary,
        array $exceptions,
        array $coverage,
        string $sourceFileName,
        array $metadata,
        string $fileName = 'Verification_Report.docx'
    ): string {
        $phpWord = new PhpWord();

        // Set default font
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(11);

        // Add sections with enhanced styling and insights
        $this->addCoverPage($phpWord, $sourceFileName, $metadata);
        $this->addExecutiveInsightsSection($phpWord, $summary, $exceptions);
        $this->addExecutiveSummarySection($phpWord, $summary);
        $this->addKeyMetricsDashboard($phpWord, $summary);
        $this->addWorksheetSummarySection($phpWord, $summary);

        // Temporarily removed exception sections for testing

        // Save file with error handling
        $outputFile = $this->outputPath . '/' . $fileName;

        // Try complex export first
        try {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($outputFile);
            Log::info("Complex Word report exported: {$outputFile}");
            return $outputFile;
        } catch (\Exception $e) {
            Log::error("Complex Word export failed: " . $e->getMessage());
            Log::info("Falling back to simple Word export due to: " . $e->getMessage());
            // Fallback to simpler Word export
            return $this->createSimpleWordExport($summary, $exceptions, $sourceFileName, $metadata, $fileName);
        }
    }

    /**
     * Create a simple Word export as fallback
     */
    private function createSimpleWordExport(array $summary, array $exceptions, string $sourceFileName, array $metadata, string $fileName): string
    {
        Log::info("Creating simple Word export as fallback");

        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(11);

        $section = $phpWord->addSection();

        // Simple title
        $section->addText('SEO Workbook Verification Report', ['bold' => true, 'size' => 16], ['alignment' => 'center', 'spaceAfter' => 400]);

        // Basic info
        $section->addText("Source: {$sourceFileName}", ['size' => 12], ['spaceAfter' => 200]);
        $section->addText("Generated: {$metadata['generated_at']}", ['size' => 12], ['spaceAfter' => 200]);
        $section->addText("Mode: " . ucfirst($metadata['mode']), ['size' => 12], ['spaceAfter' => 400]);

        // Simple summary
        $overall = $summary['overall'];
        $section->addText('Summary:', ['bold' => true, 'size' => 14], ['spaceAfter' => 200]);
        $section->addText("Total Rows: {$overall['total_rows']}", ['size' => 11], ['spaceAfter' => 100]);
        $section->addText("URLs Checked: {$overall['total_urls_checked']}", ['size' => 11], ['spaceAfter' => 100]);
        $section->addText("Working URLs: {$overall['working_urls']}", ['size' => 11], ['spaceAfter' => 100]);
        $section->addText("Broken URLs: {$overall['broken_urls']}", ['size' => 11], ['spaceAfter' => 100]);

        // Save simple version
        $outputFile = $this->outputPath . '/' . $fileName;
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($outputFile);

        Log::info("Simple Word report exported: {$outputFile}");
        return $outputFile;
    }

    /**
     * Add cover page
     */
    private function addCoverPage(PhpWord $phpWord, string $sourceFileName, array $metadata): void
    {
        $section = $phpWord->addSection();
        
        // Title
        $section->addText(
            'SEO Workbook Verification Report',
            ['bold' => true, 'size' => 24],
            ['alignment' => 'center', 'spaceAfter' => 400]
        );

        // Metadata
        $section->addText("Source Workbook: {$sourceFileName}", ['size' => 12], ['spaceAfter' => 100]);
        $section->addText("Generated: {$metadata['generated_at']}", ['size' => 12], ['spaceAfter' => 100]);
        $section->addText("Report Mode: " . ucfirst($metadata['mode']), ['size' => 12], ['spaceAfter' => 100]);

        if (!empty($metadata['filter'])) {
            $filterText = is_array($metadata['filter']) 
                ? json_encode($metadata['filter']) 
                : $metadata['filter'];
            $section->addText("Filter: {$filterText}", ['size' => 12], ['spaceAfter' => 100]);
        }

        $section->addPageBreak();
    }

    /**
     * Add Executive Insights section with key findings
     */
    private function addExecutiveInsightsSection(PhpWord $phpWord, array $summary, array $exceptions): void
    {
        $section = $phpWord->addSection();

        $section->addText('Executive Insights', ['bold' => true, 'size' => 16], ['spaceAfter' => 200]);

        // Key findings
        $overall = $summary['overall'] ?? [];
        $totalUrls = $overall['total_urls_checked'] ?? 0;
        $workingUrls = $overall['working_urls'] ?? 0;
        $brokenUrls = $overall['broken_urls'] ?? 0;

        // Calculate health score
        $healthScore = $totalUrls > 0 ? round(($workingUrls / $totalUrls) * 100, 1) : 0;

        $section->addText("🔍 Key Findings:", ['bold' => true, 'size' => 14], ['spaceAfter' => 200]);

        $insights = [];

        if ($healthScore >= 80) {
            $insights[] = "✅ Excellent URL Health: {$healthScore}% of URLs are working properly.";
        } elseif ($healthScore >= 60) {
            $insights[] = "⚠️ Good URL Health: {$healthScore}% of URLs are working, but {$brokenUrls} URLs need attention.";
        } else {
            $insights[] = "❌ Poor URL Health: Only {$healthScore}% of URLs are working. {$brokenUrls} URLs require immediate fixing.";
        }

        if ($overall['cannot_verify_urls'] > 0) {
            $insights[] = "🔒 {$overall['cannot_verify_urls']} URLs are protected by anti-bot systems or require authentication.";
        }

        if ($overall['blank_posts'] > 0) {
            $insights[] = "📝 {$overall['blank_posts']} posts have no content and may affect SEO performance.";
        }

        if ($overall['low_content_posts'] > 0) {
            $insights[] = "📄 {$overall['low_content_posts']} posts have insufficient content (<50 words) for optimal SEO.";
        }

        if (isset($overall['weeks_found']) && count($overall['weeks_found']) > 1) {
            $insights[] = "📅 Content spans " . count($overall['weeks_found']) . " weeks, showing good content consistency.";
        }

        foreach ($insights as $insight) {
            $section->addText("• " . $insight, ['size' => 11], ['spaceAfter' => 100]);
        }

        $section->addPageBreak();
    }

    /**
     * Add Key Metrics Dashboard
     */
    private function addKeyMetricsDashboard(PhpWord $phpWord, array $summary): void
    {
        $section = $phpWord->addSection();

        $section->addText('Key Metrics Dashboard', ['bold' => true, 'size' => 16], ['spaceAfter' => 200]);

        $overall = $summary['overall'] ?? [];

        // Create a visual dashboard with metrics
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '006699', 'cellMargin' => 80]);

        // Header row
        $table->addRow();
        $table->addCell(2000)->addText('Metric', ['bold' => true, 'size' => 12]);
        $table->addCell(1500)->addText('Value', ['bold' => true, 'size' => 12]);
        $table->addCell(2500)->addText('Status', ['bold' => true, 'size' => 12]);

        // URL Health
        $totalUrls = $overall['total_urls_checked'] ?? 0;
        $workingUrls = $overall['working_urls'] ?? 0;
        $healthPercent = $totalUrls > 0 ? round(($workingUrls / $totalUrls) * 100, 1) : 0;

        $table->addRow();
        $table->addCell(2000)->addText('URL Health Score', ['size' => 11]);
        $table->addCell(1500)->addText("{$healthPercent}%", ['size' => 11, 'bold' => true]);
        $status = $healthPercent >= 80 ? 'Excellent' : ($healthPercent >= 60 ? 'Good' : 'Needs Attention');
        $table->addCell(2500)->addText($status, ['size' => 11, 'color' => $healthPercent >= 80 ? '006600' : ($healthPercent >= 60 ? 'FF6600' : 'CC0000')]);

        // Content Quality
        $blankPosts = $overall['blank_posts'] ?? 0;
        $lowContent = $overall['low_content_posts'] ?? 0;
        $contentIssues = $blankPosts + $lowContent;

        $table->addRow();
        $table->addCell(2000)->addText('Content Quality Issues', ['size' => 11]);
        $table->addCell(1500)->addText($contentIssues, ['size' => 11, 'bold' => true]);
        $status = $contentIssues === 0 ? 'Perfect' : ($contentIssues < 10 ? 'Minor Issues' : 'Needs Attention');
        $table->addCell(2500)->addText($status, ['size' => 11, 'color' => $contentIssues === 0 ? '006600' : ($contentIssues < 10 ? 'FF6600' : 'CC0000')]);

        // Domain Diversity
        $uniqueDomains = $overall['unique_domains'] ?? 0;

        $table->addRow();
        $table->addCell(2000)->addText('Domain Diversity', ['size' => 11]);
        $table->addCell(1500)->addText($uniqueDomains, ['size' => 11, 'bold' => true]);
        $status = $uniqueDomains > 10 ? 'Excellent' : ($uniqueDomains > 5 ? 'Good' : 'Limited');
        $table->addCell(2500)->addText($status, ['size' => 11, 'color' => $uniqueDomains > 10 ? '006600' : ($uniqueDomains > 5 ? 'FF6600' : 'CC0000')]);

        // Coverage
        $weeksCount = isset($overall['weeks_found']) ? count($overall['weeks_found']) : 0;

        $table->addRow();
        $table->addCell(2000)->addText('Time Coverage', ['size' => 11]);
        $table->addCell(1500)->addText("{$weeksCount} weeks", ['size' => 11, 'bold' => true]);
        $status = $weeksCount > 4 ? 'Comprehensive' : ($weeksCount > 1 ? 'Good' : 'Limited');
        $table->addCell(2500)->addText($status, ['size' => 11, 'color' => $weeksCount > 4 ? '006600' : ($weeksCount > 1 ? 'FF6600' : 'CC0000')]);

        $section->addPageBreak();
    }

    /**
     * Add URL Health Analysis
     */
    private function addUrlHealthAnalysis(PhpWord $phpWord, array $summary, array $exceptions): void
    {
        $section = $phpWord->addSection();

        $section->addText('URL Health Analysis', ['bold' => true, 'size' => 16], ['spaceAfter' => 200]);

        $overall = $summary['overall'] ?? [];

        $section->addText("📊 URL Status Breakdown:", ['bold' => true, 'size' => 14], ['spaceAfter' => 200]);

        $urlStats = [
            ['Working URLs', $overall['working_urls'] ?? 0, 'Accessible and responding properly', '006600'],
            ['Broken URLs', $overall['broken_urls'] ?? 0, 'Not accessible (404, 5xx errors, DNS failures)', 'CC0000'],
            ['Cannot Verify', $overall['cannot_verify_urls'] ?? 0, 'HTTP 403 Forbidden (' . ($overall['cannot_verify_breakdown']['forbidden'] ?? 0) . ') or protected by anti-bot systems/authentication', 'FF6600'],
            ['Redirected', $overall['redirected_urls'] ?? 0, 'HTTP redirects (may be normal)', 'FF9900'],
            ['Timeout', $overall['timeout_urls'] ?? 0, 'Request timed out (>10 seconds)', '996633']
        ];

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'CCCCCC', 'cellMargin' => 80]);
        $table->addRow();
        $table->addCell(2000)->addText('Status', ['bold' => true, 'size' => 12]);
        $table->addCell(1500)->addText('Count', ['bold' => true, 'size' => 12]);
        $table->addCell(3500)->addText('Description', ['bold' => true, 'size' => 12]);
        $table->addCell(1500)->addText('Action Priority', ['bold' => true, 'size' => 12]);

        foreach ($urlStats as $stat) {
            $table->addRow();
            $table->addCell(2000)->addText($stat[0], ['size' => 11, 'color' => $stat[3]]);
            $table->addCell(1500)->addText($stat[1], ['size' => 11, 'bold' => true]);
            $table->addCell(3500)->addText($stat[2], ['size' => 11]);
            $priority = $stat[0] === 'Broken URLs' ? 'High' : ($stat[0] === 'Cannot Verify' ? 'Medium' : 'Low');
            $table->addCell(1500)->addText($priority, ['size' => 11, 'bold' => true]);
        }

        $section->addPageBreak();
    }

    /**
     * Add Content Quality Analysis
     */
    private function addContentQualityAnalysis(PhpWord $phpWord, array $exceptions): void
    {
        $section = $phpWord->addSection();

        $section->addText('Content Quality Analysis', ['bold' => true, 'size' => 16], ['spaceAfter' => 200]);

        $section->addText("📝 Content Quality Assessment:", ['bold' => true, 'size' => 14], ['spaceAfter' => 200]);

        $contentStats = [
            ['Blank Posts', count($exceptions['blank_posts']), 'Posts with no content (0 words)', 'CC0000', 'High'],
            ['Low Content Posts', count($exceptions['low_content_posts']), 'Posts with insufficient content (<50 words)', 'FF6600', 'Medium']
        ];

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'CCCCCC', 'cellMargin' => 80]);
        $table->addRow();
        $table->addCell(2000)->addText('Issue Type', ['bold' => true, 'size' => 12]);
        $table->addCell(1500)->addText('Count', ['bold' => true, 'size' => 12]);
        $table->addCell(3500)->addText('Description', ['bold' => true, 'size' => 12]);
        $table->addCell(1500)->addText('SEO Impact', ['bold' => true, 'size' => 12]);

        foreach ($contentStats as $stat) {
            $table->addRow();
            $table->addCell(2000)->addText($stat[0], ['size' => 11, 'color' => $stat[3]]);
            $table->addCell(1500)->addText($stat[1], ['size' => 11, 'bold' => true]);
            $table->addCell(3500)->addText($stat[2], ['size' => 11]);
            $table->addCell(1500)->addText($stat[4], ['size' => 11, 'bold' => true]);
        }

        $section->addPageBreak();
    }

    /**
     * Add Recommendations section
     */
    private function addRecommendationsSection(PhpWord $phpWord, array $summary, array $exceptions): void
    {
        $section = $phpWord->addSection();

        $section->addText('Recommendations & Action Items', ['bold' => true, 'size' => 16], ['spaceAfter' => 200]);

        $overall = $summary['overall'] ?? [];
        $recommendations = [];

        // URL Health Recommendations
        if (($overall['broken_urls'] ?? 0) > 0) {
            $recommendations[] = [
                'priority' => 'High',
                'category' => 'URL Health',
                'recommendation' => "Fix {$overall['broken_urls']} broken URLs immediately. Broken links hurt user experience and SEO rankings.",
                'actions' => [
                    'Check for typos in URLs',
                    'Update moved or deleted content',
                    'Set up proper 301 redirects for moved pages',
                    'Remove links to discontinued services'
                ]
            ];
        }

        if (($overall['blank_posts'] ?? 0) > 0) {
            $recommendations[] = [
                'priority' => 'High',
                'category' => 'Content Quality',
                'recommendation' => "Add content to {$overall['blank_posts']} blank posts. Empty pages provide no value to users or search engines.",
                'actions' => [
                    'Write meaningful content for each blank post',
                    'Ensure each post has at least 300 words of quality content',
                    'Add relevant images and formatting'
                ]
            ];
        }

        if (($overall['low_content_posts'] ?? 0) > 0) {
            $recommendations[] = [
                'priority' => 'Medium',
                'category' => 'Content Quality',
                'recommendation' => "Expand {$overall['low_content_posts']} low-content posts. Posts under 50 words may not rank well in search results.",
                'actions' => [
                    'Add more detailed information and examples',
                    'Include related links and references',
                    'Break up content with subheadings and lists'
                ]
            ];
        }

        // Domain Diversity Recommendations
        if ($overall['unique_domains'] < 5) {
            $recommendations[] = [
                'priority' => 'Low',
                'category' => 'Link Diversity',
                'recommendation' => "Improve domain diversity. Currently using only {$overall['unique_domains']} unique domains.",
                'actions' => [
                    'Include links from different authoritative sources',
                    'Diversify content sources across multiple domains',
                    'Avoid over-reliance on single domain for backlinks'
                ]
            ];
        }

        // Display recommendations
        foreach ($recommendations as $rec) {
            $section->addText("{$rec['priority']} Priority - {$rec['category']}", ['bold' => true, 'size' => 13, 'color' => $rec['priority'] === 'High' ? 'CC0000' : ($rec['priority'] === 'Medium' ? 'FF6600' : '006600')], ['spaceAfter' => 100]);
            $section->addText($rec['recommendation'], ['size' => 11, 'italic' => true], ['spaceAfter' => 150]);

            $section->addText("Recommended Actions:", ['bold' => true, 'size' => 11], ['spaceAfter' => 100]);
            foreach ($rec['actions'] as $action) {
                $section->addText("• " . $action, ['size' => 11], ['spaceAfter' => 50]);
            }

            $section->addTextBreak(1);
        }

        if (empty($recommendations)) {
            $section->addText("✅ Excellent! No major issues found. Your content appears to be in good health.", ['bold' => true, 'size' => 12, 'color' => '006600'], ['spaceAfter' => 200]);
            $section->addText("Continue monitoring and maintaining your content quality to ensure optimal SEO performance.", ['size' => 11], ['spaceAfter' => 100]);
        }

        $section->addPageBreak();
    }

    /**
     * Add Executive Summary section
     */
    private function addExecutiveSummarySection(PhpWord $phpWord, array $summary): void
    {
        $section = $phpWord->addSection();
        
        $section->addText('Executive Summary', ['bold' => true, 'size' => 18], ['spaceAfter' => 200]);

        // Key metrics - all required categories
        $metrics = [
            "Rows: {$summary['overall']['total_rows']}",
            "Checked: {$summary['overall']['total_urls_checked']}",
            "Working: {$summary['overall']['working_urls']}",
            "Cannot Verify: {$summary['overall']['cannot_verify_urls']}",
            "Valid: {$summary['overall']['valid_urls']}",
            "Broken: {$summary['overall']['broken_urls']}",
            "Blank: {$summary['overall']['blank_posts']}",
            "Low: {$summary['overall']['low_content_posts']}",
            "Redirected: {$summary['overall']['redirected_urls']}",
            "Timeout: {$summary['overall']['timeout_urls']}",
            "Unique: {$summary['overall']['unique_domains']}",
            "Weeks: " . count($summary['overall']['weeks_found'])
        ];

        foreach ($metrics as $metric) {
            $section->addText($metric, ['size' => 11], ['spaceAfter' => 50]);
        }

        $section->addPageBreak();
    }

    /**
     * Add Worksheet Summary section
     */
    private function addWorksheetSummarySection(PhpWord $phpWord, array $summary): void
    {
        $section = $phpWord->addSection();
        
        $section->addText('Worksheet Summary', ['bold' => true, 'size' => 18], ['spaceAfter' => 200]);

        $table = $section->addTable(['borderSize' => 1, 'cellMargin' => 50]);
        
        // Header row - all required columns
        $table->addRow(400);
        $table->addCell(2500)->addText('Worksheet', ['bold' => true]);
        $table->addCell(1200)->addText('Rows', ['bold' => true]);
        $table->addCell(1200)->addText('Checked', ['bold' => true]);
        $table->addCell(1200)->addText('Working', ['bold' => true]);
        $table->addCell(1200)->addText('Cannot Verify', ['bold' => true]);
        $table->addCell(1200)->addText('Valid', ['bold' => true]);
        $table->addCell(1200)->addText('Broken', ['bold' => true]);
        $table->addCell(1200)->addText('Blank', ['bold' => true]);
        $table->addCell(1200)->addText('Low', ['bold' => true]);
        $table->addCell(1200)->addText('Redirected', ['bold' => true]);
        $table->addCell(1200)->addText('Timeout', ['bold' => true]);
        $table->addCell(1200)->addText('Unique', ['bold' => true]);
        $table->addCell(1200)->addText('Weeks', ['bold' => true]);

        // Data rows - all required columns
        foreach ($summary['worksheets'] as $name => $data) {
            $table->addRow(300);
            $table->addCell(2500)->addText($name);
            $table->addCell(1200)->addText((string)$data['total_rows']);
            $table->addCell(1200)->addText((string)$data['total_urls_checked']);
            $table->addCell(1200)->addText((string)$data['working_urls']);
            $table->addCell(1200)->addText((string)$data['cannot_verify_urls']);
            $table->addCell(1200)->addText((string)$data['valid_urls']);
            $table->addCell(1200)->addText((string)$data['broken_urls']);
            $table->addCell(1200)->addText((string)$data['blank_posts']);
            $table->addCell(1200)->addText((string)$data['low_content_posts']);
            $table->addCell(1200)->addText((string)$data['redirected_urls']);
            $table->addCell(1200)->addText((string)$data['timeout_urls']);
            $table->addCell(1200)->addText((string)($data['unique_domains'] ?? 0));
            $table->addCell(1200)->addText((string)count($data['weeks']));
        }

        $section->addPageBreak();
    }

    /**
     * Add Period Coverage section
     */
    private function addPeriodCoverageSection(PhpWord $phpWord, array $coverage): void
    {
        $section = $phpWord->addSection();
        
        $section->addText('Period Coverage', ['bold' => true, 'size' => 18], ['spaceAfter' => 200]);

        $table = $section->addTable(['borderSize' => 1, 'cellMargin' => 50]);
        
        $table->addRow(400);
        $table->addCell(3000)->addText('Worksheet', ['bold' => true]);
        $table->addCell(2000)->addText('Status', ['bold' => true]);
        $table->addCell(3000)->addText('Date Range', ['bold' => true]);
        $table->addCell(3000)->addText('Notes', ['bold' => true]);

        foreach ($coverage as $worksheet => $data) {
            $table->addRow(300);
            $table->addCell(3000)->addText($worksheet);
            $table->addCell(2000)->addText($data['status']);
            $table->addCell(3000)->addText(($data['start_date'] ?? '') . ' - ' . ($data['end_date'] ?? ''));
            $table->addCell(3000)->addText(implode(', ', $data['notes']));
        }

        $section->addPageBreak();
    }

    /**
     * Add Broken URLs section
     */
    private function addBrokenUrlsSection(PhpWord $phpWord, array $urls): void
    {
        $section = $phpWord->addSection();
        
        $section->addText('Broken URLs', ['bold' => true, 'size' => 18], ['spaceAfter' => 200]);

        if (empty($urls)) {
            $section->addText('No broken URLs found.');
            $section->addPageBreak();
            return;
        }

        $table = $section->addTable(['borderSize' => 1, 'cellMargin' => 50]);
        
        $table->addRow(400);
        $table->addCell(2000)->addText('Worksheet', ['bold' => true]);
        $table->addCell(1000)->addText('Row', ['bold' => true]);
        $table->addCell(4000)->addText('URL', ['bold' => true]);
        $table->addCell(2000)->addText('Error', ['bold' => true]);

        foreach ($urls as $url) {
            $table->addRow(300);
            $table->addCell(2000)->addText($url['source_worksheet']);
            $table->addCell(1000)->addText((string)$url['original_row_number']);
            $table->addCell(4000)->addText($url['original_url']);
            $table->addCell(2000)->addText($url['error']);
        }

        $section->addPageBreak();
    }

    /**
     * Add Cannot Verify URLs section
     */
    private function addCannotVerifyUrlsSection(PhpWord $phpWord, array $urls): void
    {
        $section = $phpWord->addSection();
        
        $section->addText('Cannot Verify URLs', ['bold' => true, 'size' => 18], ['spaceAfter' => 200]);

        if (empty($urls)) {
            $section->addText('No cannot verify URLs found.');
            $section->addPageBreak();
            return;
        }

        $table = $section->addTable(['borderSize' => 1, 'cellMargin' => 50]);
        
        $table->addRow(400);
        $table->addCell(2000)->addText('Worksheet', ['bold' => true]);
        $table->addCell(1000)->addText('Row', ['bold' => true]);
        $table->addCell(4000)->addText('URL', ['bold' => true]);
        $table->addCell(2000)->addText('Reason', ['bold' => true]);

        foreach ($urls as $url) {
            $table->addRow(300);
            $table->addCell(2000)->addText($url['source_worksheet']);
            $table->addCell(1000)->addText((string)$url['original_row_number']);
            $table->addCell(4000)->addText($url['original_url']);
            $table->addCell(2000)->addText($url['error']);
        }

        $section->addPageBreak();
    }

    /**
     * Add Timeout URLs section
     */
    private function addTimeoutUrlsSection(PhpWord $phpWord, array $urls): void
    {
        $section = $phpWord->addSection();

        $section->addText('Timeout URLs', ['bold' => true, 'size' => 18], ['spaceAfter' => 200]);

        if (empty($urls)) {
            $section->addText('No timeout URLs found.');
            $section->addPageBreak();
            return;
        }

        $table = $section->addTable(['borderSize' => 1, 'cellMargin' => 50]);

        $table->addRow(400);
        $table->addCell(2000)->addText('Worksheet', ['bold' => true]);
        $table->addCell(1000)->addText('Row', ['bold' => true]);
        $table->addCell(4000)->addText('URL', ['bold' => true]);
        $table->addCell(2000)->addText('Error', ['bold' => true]);

        foreach ($urls as $url) {
            $table->addRow(300);
            $table->addCell(2000)->addText($url['source_worksheet']);
            $table->addCell(1000)->addText((string)$url['original_row_number']);
            $table->addCell(4000)->addText($url['original_url']);
            $table->addCell(2000)->addText($url['error']);
        }

        $section->addPageBreak();
    }

    /**
     * Add Blank Posts section
     */
    private function addBlankPostsSection(PhpWord $phpWord, array $posts): void
    {
        $section = $phpWord->addSection();
        
        $section->addText('Blank Posts', ['bold' => true, 'size' => 18], ['spaceAfter' => 200]);

        if (empty($posts)) {
            $section->addText('No blank posts found.');
            $section->addPageBreak();
            return;
        }

        $table = $section->addTable(['borderSize' => 1, 'cellMargin' => 50]);
        
        $table->addRow(400);
        $table->addCell(2000)->addText('Worksheet', ['bold' => true]);
        $table->addCell(1000)->addText('Row', ['bold' => true]);
        $table->addCell(3000)->addText('Flag Reason', ['bold' => true]);

        foreach ($posts as $post) {
            $table->addRow(300);
            $table->addCell(2000)->addText($post['source_worksheet']);
            $table->addCell(1000)->addText((string)$post['original_row_number']);
            $table->addCell(3000)->addText($post['flag_reason']);
        }

        $section->addPageBreak();
    }

    /**
     * Add Low Content section
     */
    private function addLowContentSection(PhpWord $phpWord, array $posts): void
    {
        $section = $phpWord->addSection();
        
        $section->addText('Posts Under 50 Words', ['bold' => true, 'size' => 18], ['spaceAfter' => 200]);

        if (empty($posts)) {
            $section->addText('No low content posts found.');
            $section->addPageBreak();
            return;
        }

        $table = $section->addTable(['borderSize' => 1, 'cellMargin' => 50]);
        
        $table->addRow(400);
        $table->addCell(2000)->addText('Worksheet', ['bold' => true]);
        $table->addCell(1000)->addText('Row', ['bold' => true]);
        $table->addCell(1000)->addText('Words', ['bold' => true]);
        $table->addCell(3000)->addText('Flag Reason', ['bold' => true]);

        foreach ($posts as $post) {
            $table->addRow(300);
            $table->addCell(2000)->addText($post['source_worksheet']);
            $table->addCell(1000)->addText((string)$post['original_row_number']);
            $table->addCell(1000)->addText((string)$post['word_count']);
            $table->addCell(3000)->addText($post['flag_reason']);
        }

        $section->addPageBreak();
    }

    /**
     * Add Detailed Analysis section
     */
    private function addDetailedAnalysisSection(PhpWord $phpWord, array $checks): void
    {
        $section = $phpWord->addSection();
        
        $section->addText('Detailed Analysis', ['bold' => true, 'size' => 18], ['spaceAfter' => 200]);

        if (empty($checks)) {
            $section->addText('No detailed data available.');
            return;
        }

        // Show first 50 rows for performance
        $count = 0;
        foreach ($checks as $check) {
            if ($count >= 50) break;
            
            $section->addText(
                "Worksheet: {$check['source_worksheet']}, Row: {$check['original_row_number']}, " .
                "URL: {$check['original_url']}, Status: {$check['status']}",
                ['size' => 9],
                ['spaceAfter' => 30]
            );
            $count++;
        }

        if (count($checks) > 50) {
            $section->addText("... and " . (count($checks) - 50) . " more rows", ['italic' => true]);
        }
    }
}