<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Log;

/**
 * FR-08: URL Validation Service
 * Validates URL format, performs requests, follows redirects, and captures outcomes.
 * Also fetches HTML and analyzes content for Our Url hyperlinks and keywords.
 */
class UrlValidationService
{
    private Client $client;
    private array $urlCache = [];
    private int $timeout = 10;
    private int $maxRetries = 2;

    // Status constants as per SRS Section 7.4
    public const STATUS_WORKING = 'Working';
    public const STATUS_REDIRECTED = 'Redirected';
    public const STATUS_BROKEN = 'Broken';
    public const STATUS_TIMEOUT = 'Timeout';
    public const STATUS_INVALID = 'Invalid';
    public const STATUS_CANNOT_VERIFY = 'Cannot Verify';

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => $this->timeout,
            'allow_redirects' => [
                'max' => 10,
                'track_redirects' => true
            ],
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language' => 'en-US,en;q=0.9,en;q=0.8',
                'Connection' => 'keep-alive',
                'Upgrade-Insecure-Requests' => '1',
                'Sec-Fetch-Dest' => 'document',
                'Sec-Fetch-Mode' => 'navigate',
                'Sec-Fetch-Site' => 'none',
                'Sec-Fetch-User' => '?1',
                'Sec-CH-UA' => '"Chromium";v="123", "Google Chrome";v="123", "Not=A?Brand";v="99"',
                'Sec-CH-UA-Mobile' => '?0',
                'Sec-CH-UA-Platform' => '"Windows"',
                'Sec-CH-UA-Arch' => '"x86"',
                'Sec-CH-UA-Bitness' => '"64"',
                'Referer' => 'https://www.google.com/',
                'Origin' => 'https://substack.com',
                'Cache-Control' => 'max-age=0'
            ],
            'cookies' => true,
            'verify' => true
        ]);
    }

    /**
     * Validate a URL and return result
     * @param string $url The URL to validate
     * @param string|null $ourUrl Optional - Our Url to search for in the page
     * @param string|null $keyword Optional - Keyword to search in page content
     */
    public function validate(string $url, ?string $ourUrl = null, ?string $keyword = null): array
    {
        // Check cache first
        $normalizedUrl = $this->normalizeUrl($url);
        if (isset($this->urlCache[$normalizedUrl])) {
            $cached = $this->urlCache[$normalizedUrl];
            $cached['cached'] = true;
            return $cached;
        }

        // Validate format
        if (!$this->isValidUrl($url)) {
            $result = $this->createResult($url, self::STATUS_INVALID, 0, 'Invalid URL format');
            $this->urlCache[$normalizedUrl] = $result;
            return $result;
        }

        // Attempt request with retry logic
        $result = $this->attemptRequest($url, $normalizedUrl);
        
        // If URL is working, fetch and analyze HTML
        // We ALWAYS analyze HTML to detect JavaScript SPAs (Cannot Verify)
        if ($result['status'] === self::STATUS_WORKING) {
            $htmlAnalysis = $this->analyzePageContent($url, $ourUrl, $keyword);
            $result = array_merge($result, $htmlAnalysis);
            
            // If content analysis shows page requires JavaScript/authentication, update status
            if (isset($htmlAnalysis['cannot_verify']) && $htmlAnalysis['cannot_verify']) {
                $result['status'] = self::STATUS_CANNOT_VERIFY;
                $result['cannot_verify'] = true;
                $result['status_code'] = 0;
                $result['error'] = $htmlAnalysis['reason'] ?? 'Cannot verify - page requires JavaScript rendering or authentication';
            }
            
            // Check if page is Blank (HTTP 200 but no content found)
            // A blank page means the page loaded successfully (200) but has no meaningful content
            if ($result['status'] === self::STATUS_WORKING && isset($htmlAnalysis['is_blank_page']) && $htmlAnalysis['is_blank_page']) {
                // Keep as Working status but mark as blank - PostAnalysis will handle this
                $result['is_blank'] = true;
                $result['error'] = 'Blank page - HTTP 200 but no content found on the page';
            }
            
            // If ourUrl is provided but not found in page hyperlinks, or keyword not found in page content
            // It could be a placeholder page or broken content - mark as Broken
            // BUT don't override Cannot Verify or Blank status
            if ((!isset($result['cannot_verify']) || !$result['cannot_verify']) && (!isset($result['is_blank']) || !$result['is_blank'])) {
                if (!empty($ourUrl) && isset($htmlAnalysis['our_url_found']) && !$htmlAnalysis['our_url_found']) {
                    $result['status'] = self::STATUS_BROKEN;
                    $result['status_code'] = 0;
                    $result['error'] = 'Our URL not found in page hyperlinks - page may be broken or placeholder';
                }
                
                if (!empty($keyword) && isset($htmlAnalysis['keyword_found']) && !$htmlAnalysis['keyword_found']) {
                    $result['status'] = self::STATUS_BROKEN;
                    $result['status_code'] = 0;
                    $result['error'] = 'Keyword not found in page content - page may be broken or placeholder';
                }
            }
        }
        
        // If status is Cannot Verify (e.g., HTTP 403), ensure cannot_verify flag is set
        if ($result['status'] === self::STATUS_CANNOT_VERIFY) {
            $result['cannot_verify'] = true;
        }

        // Cache result
        $this->urlCache[$normalizedUrl] = $result;
        return $result;
    }

    /**
     * Analyze page HTML content - search for Our Url hyperlinks and keywords
     */
    private function analyzePageContent(string $url, ?string $ourUrl, ?string $keyword): array
    {
        try {
            // Fetch HTML content using GET (need body for parsing)
            $response = $this->client->get($url, [
                'timeout' => $this->timeout,
                'http_errors' => false
            ]);
            
            $html = (string)$response->getBody();
            
            if (empty($html)) {
                return ['html_found' => false, 'our_url_found' => false, 'keyword_found' => false];
            }
            
            // Parse HTML with DOMDocument
            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();
            @$dom->loadHTML($html);
            libxml_clear_errors();
            
            $analysis = [
                'html_found' => true,
                'our_url_found' => false,
                'keyword_found' => false,
                'our_url_links' => [],
                'excerpt' => ''
            ];
            
            // Check if page content is essentially navigation/menu (SPA or JS-rendered page)
            $body = $dom->getElementsByTagName('body');
            if ($body->length > 0) {
                $bodyText = trim($body->item(0)->textContent);
                $wordCount = count(array_filter(preg_split('/\s+/', $bodyText)));
                
                // If word count is low (< 150 words), check if this is a JavaScript SPA
                // SPAs typically show only navigation/auth UI without actual content
                if ($wordCount < 150) {
                    // Check for JavaScript SPA patterns - navigation/auth UI without real content
                    // Common patterns: login pages, auth gates, SPA shells
                    $bodyTextLower = strtolower($bodyText);
                    $isJsSpa = $this->detectJavaScriptSpa($bodyTextLower, $wordCount);
                    
                    if ($isJsSpa) {
                        $analysis['cannot_verify'] = true;
                        $analysis['reason'] = 'Cannot verify - page requires JavaScript rendering or authentication (JavaScript SPA detected)';
                        return $analysis;
                    }
                    
                    // Not a SPA but low content - check if page is completely blank
                    // A blank page is when wordCount is 0 (no content at all, only headers/menu)
                    if ($wordCount === 0) {
                        $analysis['is_blank_page'] = true;
                        $analysis['reason'] = 'Blank page - HTTP 200 but no content found on the page';
                        return $analysis;
                    }
                }
            }
            
            // Search for Our Url in hyperlinks
            if (!empty($ourUrl)) {
                $xpath = new \DOMXPath($dom);
                $ourUrlLower = strtolower($ourUrl);
                
                // Find all anchor tags
                $links = $dom->getElementsByTagName('a');
                foreach ($links as $link) {
                    $href = $link->getAttribute('href');
                    if (!empty($href) && str_contains(strtolower($href), $ourUrlLower)) {
                        $analysis['our_url_found'] = true;
                        $analysis['our_url_links'][] = $href;
                    }
                }
            }
            
            // Search for keyword in page text
            if (!empty($keyword)) {
                $htmlLower = strtolower($html);
                $keywordLower = strtolower($keyword);
                
                if (str_contains($htmlLower, $keywordLower)) {
                    $analysis['keyword_found'] = true;
                    
                    // Extract excerpt around keyword
                    $pos = strpos($htmlLower, $keywordLower);
                    if ($pos !== false) {
                        $start = max(0, $pos - 50);
                        $length = strlen($keywordLower) + 100;
                        $analysis['excerpt'] = substr($html, $start, $length);
                    }
                }
            }
            
            return $analysis;
            
        } catch (\Exception $e) {
            Log::warning("HTML analysis failed for $url: " . $e->getMessage());
            return ['html_found' => false, 'our_url_found' => false, 'keyword_found' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Validate multiple URLs with analysis
     */
    public function validateWithAnalysis(array $urls, array $analysisParams = []): array
    {
        $results = [];
        foreach ($urls as $urlData) {
            $url = is_array($urlData) ? ($urlData['url'] ?? '') : $urlData;
            $ourUrl = $analysisParams['our_url'] ?? ($urlData['our_url'] ?? null);
            $keyword = $analysisParams['keyword'] ?? ($urlData['keyword'] ?? null);
            
            if (!empty($url)) {
                $results[] = $this->validate($url, $ourUrl, $keyword);
            }
        }
        return $results;
    }

    /**
     * Check if URL is valid format
     */
    private function isValidUrl(string $url): bool
    {
        $url = trim($url);
        
        // Must start with http:// or https://
        if (!preg_match('/^https?:\/\//i', $url)) {
            return false;
        }

        // Basic format check
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Attempt HTTP request with redirect following
     */
    private function attemptRequest(string $url, string $normalizedUrl): array
    {
        try {
            // Try GET first instead of HEAD - some sites block HEAD requests
            // GET is more reliable for sites like Medium, Medium blocks HEAD requests
            $response = $this->client->get($url, [
                'timeout' => $this->timeout,
                'http_errors' => false
            ]);

            $statusCode = $response->getStatusCode();
            // With allow_redirects enabled, final URL is already resolved to original since Guzzle follows redirects
            $effectiveUri = $url;

            // Check for redirect
            if ($statusCode >= 300 && $statusCode < 400) {
                return $this->createResult($url, self::STATUS_REDIRECTED, $statusCode, null, $effectiveUri);
            }

            // Check for success
            if ($statusCode >= 200 && $statusCode < 300) {
                return $this->createResult($url, self::STATUS_WORKING, $statusCode, null, $effectiveUri);
            }

            // Client or server error
            if ($statusCode === 403) {
                // HTTP 403 could be bot protection or auth required
                // Check if it's likely bot protection (Medium, Cloudflare, etc.)
                return $this->createResult($url, self::STATUS_CANNOT_VERIFY, $statusCode, 'Access blocked - likely bot protection or requires authentication (HTTP 403)');
            }
            return $this->createResult($url, self::STATUS_BROKEN, $statusCode, "HTTP {$statusCode}", $effectiveUri);

        } catch (RequestException $e) {
            return $this->handleRequestException($e, $url);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            
            // DNS resolution failure
            if (str_contains($message, 'dns') || str_contains($message, 'Name or service not known') || str_contains($message, 'Could not resolve host')) {
                return $this->createResult($url, self::STATUS_BROKEN, 0, 'DNS error: Could not resolve host');
            }
            
            return $this->createResult($url, self::STATUS_BROKEN, 0, $e->getMessage());
        }
    }

    /**
     * Handle request exceptions
     */
    private function handleRequestException(RequestException $e, string $url): array
    {
        $message = $e->getMessage();
        
        if (str_contains($message, 'timeout')) {
            return $this->createResult($url, self::STATUS_TIMEOUT, 0, 'Request timed out');
        }
        
        if (str_contains($message, 'Connection refused') || str_contains($message, 'cURL error 7')) {
            return $this->createResult($url, self::STATUS_BROKEN, 0, 'Connection refused');
        }
        
        if (str_contains($message, 'SSL') || str_contains($message, 'SSL')) {
            return $this->createResult($url, self::STATUS_BROKEN, 0, 'SSL error: ' . $message);
        }
        
        if (str_contains($message, 'dns') || str_contains($message, 'Name or service not known') || str_contains($message, 'Could not resolve host')) {
            return $this->createResult($url, self::STATUS_BROKEN, 0, 'DNS error: Could not resolve host');
        }
        
        return $this->createResult($url, self::STATUS_BROKEN, 0, substr($message, 0, 100));
    }

    /**
     * Create standardized result array
     */
    private function createResult(
        string $originalUrl,
        string $status,
        int $statusCode,
        ?string $error,
        ?string $finalUrl = null
    ): array {
        return [
            'original_url' => $originalUrl,
            'final_url' => $finalUrl ?? $originalUrl,
            'status' => $status,
            'status_code' => $statusCode,
            'error' => $error,
            'redirected' => $status === self::STATUS_REDIRECTED,
            'cached' => false
        ];
    }

    /**
     * Normalize URL for cache key
     */
    private function normalizeUrl(string $url): string
    {
        return strtolower(trim($url));
    }

    /**
     * Get cache stats
     */
    public function getCacheStats(): array
    {
        $stats = [
            'total_cached' => count($this->urlCache),
            'by_status' => []
        ];

        foreach ($this->urlCache as $result) {
            $status = $result['status'];
            if (!isset($stats['by_status'][$status])) {
                $stats['by_status'][$status] = 0;
            }
            $stats['by_status'][$status]++;
        }

        return $stats;
    }

    /**
     * Clear cache
     */
    public function clearCache(): void
    {
        $this->urlCache = [];
    }

    /**
     * Detect if the page is a JavaScript SPA based on content patterns
     * Returns true if the page appears to require JavaScript rendering (SPA, auth gate, etc.)
     */
    private function detectJavaScriptSpa(string $bodyTextLower, int $wordCount): bool
    {
        // JavaScript SPA patterns - pages that require browser rendering
        // These typically show navigation/auth UI without actual content
        $spaPatterns = [
            // Auth-related terms
            'sign in', 'sign up', 'login', 'logout', 'signup', 'log in',
            // Auth UI elements
            'go pro', 'go pro', 'account', 'my account', 'create account',
            // SPA/App shell indicators
            'mobile apps', 'penzu', 'journal prompts', 'support',
            // Social auth patterns
            'continue with', 'sign in with', 'connect with',
        ];
        
        // Count how many SPA patterns are found
        $patternMatches = 0;
        foreach ($spaPatterns as $pattern) {
            if (str_contains($bodyTextLower, $pattern)) {
                $patternMatches++;
            }
        }
        
        // If multiple SPA patterns found (3+) and very low word count, likely a JavaScript SPA
        // High ratio of navigation/auth words compared to actual content
        if ($patternMatches >= 3 && $wordCount < 100) {
            return true;
        }
        
        // Very low word count with most content being navigation/auth terms
        // This indicates the actual content is loaded via JavaScript
        if ($wordCount < 100) {
            // Check if most words are auth/nav related
            $authNavTerms = ['sign', 'in', 'up', 'login', 'logout', 'account', 'pro', 'support', 'help', 'faqs', 'mobile', 'apps', 'journal'];
            $authNavCount = 0;
            $words = preg_split('/\s+/', $bodyTextLower);
            foreach ($words as $word) {
                $word = trim($word);
                if (in_array($word, $authNavTerms)) {
                    $authNavCount++;
                }
            }
            
            // If more than 50% of words are auth/nav terms, it's likely a SPA
            if (count($words) > 0 && ($authNavCount / count($words)) > 0.5) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Batch validate multiple URLs concurrently
     * @param array $items Array of [url => string, ourUrl => string, keyword => string]
     * @param int $concurrency Max concurrent requests (default 15)
     * @return array Results indexed by key
     */
    public function batchValidate(array $items, int $concurrency = 15): array
    {
        $results = [];
        if (empty($items)) {
            return $results;
        }

        // Build request generator
        $requests = function () use ($items) {
            foreach ($items as $i => $item) {
                $url = $item['url'] ?? '';
                if (!empty($url) && $this->isValidUrl($url)) {
                    yield $i => new Request('HEAD', $url);
                }
            }
        };

        // Container for responses
        $responses = [];

        // Use Pool with concurrency
        $pool = new Pool($this->client, $requests(), [
            'concurrency' => $concurrency,
            'fulfilled' => function ($response, $index) use (&$responses) {
                $responses[$index] = $response->getStatusCode();
            },
            'rejected' => function ($reason, $index) use (&$responses) {
                $responses[$index] = 0;
            }
        ]);

        $pool->promise()->wait();

        // Map to results
        foreach ($items as $i => $item) {
            $url = $item['url'] ?? '';
            $statusCode = $responses[$i] ?? 0;
            if ($statusCode === 0) {
                $status = self::STATUS_BROKEN;
            } elseif ($statusCode >= 200 && $statusCode < 300) {
                $status = self::STATUS_WORKING;
            } elseif ($statusCode >= 300 && $statusCode < 400) {
                $status = self::STATUS_REDIRECTED;
            } else {
                $status = self::STATUS_BROKEN;
            }
            $normalized = $this->normalizeUrl($url);
            $result = [
                'original_url' => $url,
                'final_url' => $url,
                'status' => $status,
                'status_code' => $statusCode,
                'error' => null,
                'cached' => false
            ];
            $this->urlCache[$normalized] = $result;
            $results[$i] = $result;
        }

        return $results;
    }
}