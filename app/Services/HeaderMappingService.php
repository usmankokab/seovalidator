<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * FR-03: Header Mapping Service
 * Detects URL, post, date, and week columns using aliases and fuzzy matching.
 */
class HeaderMappingService
{
    // Fixed column headings (exact match first)
    private array $urlFixed = [
        'our url', 'Our Url', 'OUR URL',
        'submission page', 'Submission Page', 'SUBMISSION PAGE',
        'submission url', 'Submission URL',
        'url', 'URL', 'link', 'Link',
        'website', 'Website',
        'post url', 'Post URL',
        'home page', 'Home Page', 'HOME PAGE',
        'submission page', 'Submission Page'
    ];
    
    // Fixed status column for approval
    private array $statusFixed = [
        'status', 'Status', 'STATUS', 'approval status'
    ];
    
    // Fixed keyword column
    private array $keywordFixed = [
        'keyword', 'Keyword', 'KEYWORD',
        'target keyword', 'Target Keyword',
        'main keyword', 'Main Keyword'
    ];
    
    // Column type aliases as per SRS Section 7.2
    private array $urlAliases = [
        'url', 'link', 'website', 'domain', 'landing url', 'landing urls', 
        'submission url', 'submission page', 'post url', 'post link', 
        'page url', 'main url', 'source url', 'url link', 'page', 'submission',
        'url address', 'web address', 'site url', 'site link',
        'home page', 'submission page'
    ];

    // Fixed post column headings
    private array $postFixed = [
        'submission title', 'Submission Title', 'SUBMISSION TITLE',
        'post', 'Post', 'content', 'Content', 'description', 'Description',
        'caption', 'Caption'
    ];
    
    private array $postAliases = [
        'post', 'content', 'description', 'caption', 'post content', 
        'post description', 'post text', 'article content', 'content text',
        'body', 'main content', 'story'
    ];

    // Fixed date column headings
    private array $dateFixed = [
        'submission date', 'Submission Date', 'SUBMISSION DATE',
        'date', 'Date', 'posted', 'Posted', 'publish date'
    ];
    
    private array $dateAliases = [
        'date', 'posted', 'publish date', 'published', 'post date',
        'submission date', 'created', 'created on', 'date posted',
        'publishing date', 'date published'
    ];

    // Fixed week column headings
    private array $weekFixed = [
        'week', 'Week', 'WEEK', 'week number', 'Week Number'
    ];
    
    private array $weekAliases = [
        'week', 'week number', 'week no', 'week number', 'wk',
        'week of', 'reporting week', 'week ending'
    ];

    /**
     * Map headers to known column types
     */
    public function map(array $headers): array
    {
        $mapping = [
            'url_columns' => [],
            'post_column' => null,
            'date_column' => null,
            'week_column' => null,
            'status_column' => null,
            'keyword_column' => null,
            'unmapped' => []
        ];

        foreach ($headers as $colIndex => $headerName) {
            if (empty($headerName)) {
                continue;
            }

            $normalizedHeader = $this->normalize($headerName);
            $type = $this->detectType($normalizedHeader);

            switch ($type) {
                case 'url':
                    $mapping['url_columns'][] = [
                        'index' => $colIndex,
                        'name' => $headerName,
                        'normalized' => $normalizedHeader
                    ];
                    break;
                case 'post':
                    if ($mapping['post_column'] === null) {
                        $mapping['post_column'] = [
                            'index' => $colIndex,
                            'name' => $headerName,
                            'normalized' => $normalizedHeader
                        ];
                    }
                    break;
                case 'date':
                    if ($mapping['date_column'] === null) {
                        $mapping['date_column'] = [
                            'index' => $colIndex,
                            'name' => $headerName,
                            'normalized' => $normalizedHeader
                        ];
                    }
                    break;
                case 'week':
                    if ($mapping['week_column'] === null) {
                        $mapping['week_column'] = [
                            'index' => $colIndex,
                            'name' => $headerName,
                            'normalized' => $normalizedHeader
                        ];
                    }
                    break;
                case 'status':
                    if ($mapping['status_column'] === null) {
                        $mapping['status_column'] = [
                            'index' => $colIndex,
                            'name' => $headerName,
                            'normalized' => $normalizedHeader
                        ];
                    }
                    break;
                case 'keyword':
                    if ($mapping['keyword_column'] === null) {
                        $mapping['keyword_column'] = [
                            'index' => $colIndex,
                            'name' => $headerName,
                            'normalized' => $normalizedHeader
                        ];
                    }
                    break;
                default:
                    $mapping['unmapped'][] = [
                        'index' => $colIndex,
                        'name' => $headerName,
                        'normalized' => $normalizedHeader
                    ];
                    break;
            }
        }

        Log::info("Header mapping result:", $mapping);
        return $mapping;
    }

    /**
     * Normalize header for matching
     */
    private function normalize(string $header): string
    {
        return strtolower(trim($header));
    }

    /**
     * Detect column type from normalized header
     */
    private function detectType(string $normalized): string
    {
        // Check fixed exact matches first (case-sensitive)
        if (in_array($normalized, $this->urlFixed, true)) {
            return 'url';
        }
        
        // Check exact matches in aliases
        if (in_array($normalized, $this->urlAliases)) {
            return 'url';
        }
        if (in_array($normalized, $this->postFixed, true)) {
            return 'post';
        }
        if (in_array($normalized, $this->postAliases)) {
            return 'post';
        }
        if (in_array($normalized, $this->dateFixed, true)) {
            return 'date';
        }
        if (in_array($normalized, $this->dateAliases)) {
            return 'date';
        }
        if (in_array($normalized, $this->weekFixed, true)) {
            return 'week';
        }
        if (in_array($normalized, $this->weekAliases)) {
            return 'week';
        }

        // Check partial/fuzzy matches
        if ($this->containsAny($normalized, $this->urlAliases)) {
            return 'url';
        }
        if ($this->containsAny($normalized, $this->postAliases)) {
            return 'post';
        }
        if ($this->containsAny($normalized, $this->dateAliases)) {
            return 'date';
        }
        if ($this->containsAny($normalized, $this->weekAliases)) {
            return 'week';
        }

        return 'unknown';
    }

    /**
     * Check if string contains any of the aliases
     */
    private function containsAny(string $needle, array $haystack): bool
    {
        foreach ($haystack as $alias) {
            if (str_contains($needle, $alias)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all URL column aliases (for extension/config)
     */
    public function getUrlAliases(): array
    {
        return $this->urlAliases;
    }

    /**
     * Get all post column aliases
     */
    public function getPostAliases(): array
    {
        return $this->postAliases;
    }

    /**
     * Get all date column aliases
     */
    public function getDateAliases(): array
    {
        return $this->dateAliases;
    }

    /**
     * Get all week column aliases
     */
    public function getWeekAliases(): array
    {
        return $this->weekAliases;
    }
}