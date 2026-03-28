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

        // Add sections as per SRS Section 9.3
        $this->addCoverPage($phpWord, $sourceFileName, $metadata);
        $this->addExecutiveSummarySection($phpWord, $summary);
        $this->addWorksheetSummarySection($phpWord, $summary);
        $this->addPeriodCoverageSection($phpWord, $coverage);
        $this->addBrokenUrlsSection($phpWord, $exceptions['broken_urls']);
        $this->addBlankPostsSection($phpWord, $exceptions['blank_posts']);
        $this->addLowContentSection($phpWord, $exceptions['low_content_posts']);
        $this->addDetailedAnalysisSection($phpWord, $exceptions['url_checks']);

        // Save file
        $outputFile = $this->outputPath . '/' . $fileName;
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($outputFile);

        Log::info("Word report exported: {$outputFile}");
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
     * Add Executive Summary section
     */
    private function addExecutiveSummarySection(PhpWord $phpWord, array $summary): void
    {
        $section = $phpWord->addSection();
        
        $section->addText('Executive Summary', ['bold' => true, 'size' => 18], ['spaceAfter' => 200]);

        // Key metrics
        $metrics = [
            "Total Rows Reviewed: {$summary['overall']['total_rows']}",
            "Total URLs Checked: {$summary['overall']['total_urls_checked']}",
            "Working URLs: {$summary['overall']['working_urls']}",
            "Broken URLs: {$summary['overall']['broken_urls']}",
            "Redirected URLs: {$summary['overall']['redirected_urls']}",
            "Invalid URLs: {$summary['overall']['invalid_urls']}",
            "Blank Posts: {$summary['overall']['blank_posts']}",
            "Posts Under 50 Words: {$summary['overall']['low_content_posts']}"
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
        
        // Header row
        $table->addRow(400);
        $table->addCell(3000)->addText('Worksheet', ['bold' => true]);
        $table->addCell(1500)->addText('Rows', ['bold' => true]);
        $table->addCell(1500)->addText('URLs', ['bold' => true]);
        $table->addCell(1500)->addText('Working', ['bold' => true]);
        $table->addCell(1500)->addText('Broken', ['bold' => true]);
        $table->addCell(1500)->addText('Blank', ['bold' => true]);
        $table->addCell(1500)->addText('Low', ['bold' => true]);

        // Data rows
        foreach ($summary['worksheets'] as $name => $data) {
            $table->addRow(300);
            $table->addCell(3000)->addText($name);
            $table->addCell(1500)->addText((string)$data['total_rows']);
            $table->addCell(1500)->addText((string)$data['total_urls_checked']);
            $table->addCell(1500)->addText((string)$data['working_urls']);
            $table->addCell(1500)->addText((string)$data['broken_urls']);
            $table->addCell(1500)->addText((string)$data['blank_posts']);
            $table->addCell(1500)->addText((string)$data['low_content_posts']);
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