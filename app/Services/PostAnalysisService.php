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
     * ONLY check URL body content - do NOT check workbook content
     * Blank = HTTP 200-299 AND body has 0 content
     */
    public function analyze(array &$row): void
    {
        $flagReason = null;
        
        // Only check URLs for blank detection - ignore workbook content
        if (!empty($row['urls'])) {
            $firstUrl = $row['urls'][0] ?? [];
            $urlStatus = $firstUrl['status'] ?? '';
            
            // Only check for blank if URL returns 200-299
            if ($urlStatus === 'Working') {
                // Check if URL body has content
                if (!empty($firstUrl['is_blank']) && $firstUrl['is_blank'] === true) {
                    $flagReason = 'Blank Post';
                }
            }
        }
        
        // Update row with analysis results - no content analysis, only URL check
        $row['post_analysis'] = [
            'source' => 'url_check',
            'text' => '',
            'word_count' => 0,
            'excerpt' => '',
            'flag_reason' => $flagReason,
            'is_blank' => ($flagReason === 'Blank Post'),
            'is_low_content' => false,
            'exceeds_threshold' => ($flagReason === null)
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

    /**
     * Detect if content looks like a JavaScript-based login/authentication UI
     * These are typically short, lack meaningful content, and have auth-related patterns
     */
    private function isJavaScriptLoginUi(string $text): bool
    {
        if (empty($text)) {
            return false;
        }

        // Convert to lowercase for pattern matching
        $lowerText = strtolower($text);

        // Check for common login/authentication UI patterns
        $authPatterns = [
            'sign in',
            'signin',
            'login',
            'log in',
            'password',
            'email address',
            'username',
            'forgot password',
            'create account',
            'sign up',
            'google',
            'facebook',
            'continue',
            'welcome back',
            'authenticate',
            'session',
            'token',
            'oauth',
            'verify your identity',
            'two-factor',
            '2fa',
            'captcha',
            'reCAPTCHA',
            'human verification',
        ];

        $patternMatches = 0;
        foreach ($authPatterns as $pattern) {
            if (strpos($lowerText, $pattern) !== false) {
                $patternMatches++;
            }
        }

        // If multiple auth patterns found, likely a login UI
        if ($patternMatches >= 2) {
            return true;
        }

        // Check for typical login UI phrases
        $loginPhrases = [
            '/sign\s*in/i',
            '/log\s*in/i',
            '/login/i',
            '/password/i',
            '/email/i',
            '/create\s*account/i',
        ];

        foreach ($loginPhrases as $phrase) {
            if (preg_match($phrase, $text)) {
                return true;
            }
        }

        return false;
    }
}