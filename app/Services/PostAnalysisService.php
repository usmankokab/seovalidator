<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * FR-09: Post Analysis Service
 * Analyzes post content, detects blank and low-content posts.
 */
class PostAnalysisService
{
    private const MIN_WORD_COUNT = 50;

    public function __construct(
        private ContentExtractionService $contentExtractor
    ) {}

    /**
     * Analyze post content for a row
     */
    public function analyze(array &$row): void
    {
        $postText = $row['post_content'] ?? '';
        $source = 'workbook';
        $text = $postText;
        $wordCount = 0;
        $flagReason = null;

        // Check if workbook content is empty
        if (empty(trim($postText))) {
            // Try to extract from URL
            if (!empty($row['urls'])) {
                $firstUrl = $row['urls'][0]['original_url'];
                $extracted = $this->contentExtractor->extractFromUrl($firstUrl);
                
                if ($extracted['success']) {
                    $text = $extracted['text'];
                    $wordCount = $extracted['word_count'];
                    $source = 'extracted';
                    
                    // Check extracted content
                    if ($wordCount === 0) {
                        $flagReason = 'Blank Post (Extracted)';
                    } elseif ($wordCount < self::MIN_WORD_COUNT) {
                        $flagReason = 'Low Content (Extracted)';
                    }
                } else {
                    $flagReason = 'Blank Post (No Content Available)';
                }
            } else {
                $flagReason = 'Blank Post';
            }
        } else {
            // Analyze workbook content
            $text = $this->cleanText($postText);
            $wordCount = $this->countWords($text);

            if ($wordCount === 0) {
                $flagReason = 'Blank Post';
            } elseif ($wordCount < self::MIN_WORD_COUNT) {
                $flagReason = 'Low Content';
            }
        }

        // Update row with analysis results
        $row['post_analysis'] = [
            'source' => $source,
            'text' => $text,
            'word_count' => $wordCount,
            'excerpt' => $this->createExcerpt($text),
            'flag_reason' => $flagReason,
            'is_blank' => $wordCount === 0,
            'is_low_content' => $wordCount > 0 && $wordCount < self::MIN_WORD_COUNT,
            'exceeds_threshold' => $wordCount >= self::MIN_WORD_COUNT
        ];
    }

    /**
     * Analyze multiple rows
     */
    public function analyzeMultiple(array &$rows): void
    {
        foreach ($rows as &$row) {
            $this->analyze($row);
        }
    }

    /**
     * Clean text content
     */
    private function cleanText(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        // Strip HTML tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Count words in text
     */
    private function countWords(string $text): int
    {
        if (empty(trim($text))) {
            return 0;
        }

        $words = array_filter(preg_split('/\s+/', $text));
        return count($words);
    }

    /**
     * Create excerpt
     */
    private function createExcerpt(string $text, int $length = 200): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        $excerpt = substr($text, 0, $length);
        $lastSpace = strrpos($excerpt, ' ');
        
        if ($lastSpace !== false) {
            $excerpt = substr($excerpt, 0, $lastSpace);
        }

        return $excerpt . '...';
    }

    /**
     * Get minimum word count threshold
     */
    public function getMinWordCount(): int
    {
        return self::MIN_WORD_COUNT;
    }
}