<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
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

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => $this->timeout,
            'allow_redirects' => [
                'max' => 10,
                'track_redirects' => true
            ],
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ],
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
        if ($result['status'] === self::STATUS_WORKING && (!empty($ourUrl) || !empty($keyword))) {
            $htmlAnalysis = $this->analyzePageContent($url, $ourUrl, $keyword);
            $result = array_merge($result, $htmlAnalysis);
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
            // Try HEAD first (more efficient)
            $response = $this->client->head($url, [
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
            return $this->createResult($url, self::STATUS_BROKEN, $statusCode, "HTTP {$statusCode}", $effectiveUri);

        } catch (RequestException $e) {
            return $this->handleRequestException($e, $url);
        } catch (\Exception $e) {
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
        
        if (str_contains($message, 'dns') || str_contains($message, 'Name or service not known')) {
            return $this->createResult($url, self::STATUS_BROKEN, 0, 'DNS error: Host not found');
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
}