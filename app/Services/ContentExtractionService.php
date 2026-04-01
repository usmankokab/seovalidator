<?php

namespace App\Services;

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use Illuminate\Support\Facades\Log;

/**
 * FR-10: Content Extraction Service
 * Fetches page content and extracts visible text when workbook post is missing.
 */
class ContentExtractionService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 15,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ]
        ]);
    }

    /**
     * Extract visible text content from a URL
     */
    public function extractFromUrl(string $url): array
    {
        $result = [
            'success' => false,
            'text' => '',
            'excerpt' => '',
            'word_count' => 0,
            'error' => null
        ];

        try {
            $response = $this->client->get($url, [
                'timeout' => 15,
                'http_errors' => false
            ]);

            if ($response->getStatusCode() !== 200) {
                $result['error'] = "HTTP {$response->getStatusCode()}";
                return $result;
            }

            $html = (string)$response->getBody();
            $crawler = new Crawler($html);

            // Remove script and style elements
            $crawler->filter('script, style, nav, header, footer, aside')->each(function (Crawler $node) {
                foreach ($node as $nodeElement) {
                    $nodeElement->parentNode?->removeChild($nodeElement);
                }
            });

            // Get body text
            $body = $crawler->filter('body');
            if ($body->count() > 0) {
                $text = $body->text();
            } else {
                $text = $crawler->text();
            }

            // Clean and normalize text
            $text = $this->cleanText($text);

            $result['success'] = true;
            $result['text'] = $text;
            $result['word_count'] = $this->countWords($text);
            $result['excerpt'] = $this->createExcerpt($text);

        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            Log::debug("Content extraction failed for {$url}: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Clean extracted text
     */
    private function cleanText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Only remove truly irrelevant content, not actual page content
        // Removed aggressive nav word removal that was causing false blanks
        
        return trim($text);
    }

    /**
     * Count visible words
     */
    private function countWords(string $text): int
    {
        if (empty(trim($text))) {
            return 0;
        }

        // Split by whitespace and filter empty strings
        $words = array_filter(preg_split('/\s+/', $text));
        return count($words);
    }

    /**
     * Create short excerpt for review
     */
    private function createExcerpt(string $text, int $length = 200): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        // Find last space before length
        $excerpt = substr($text, 0, $length);
        $lastSpace = strrpos($excerpt, ' ');
        
        if ($lastSpace !== false) {
            $excerpt = substr($excerpt, 0, $lastSpace);
        }

        return $excerpt . '...';
    }
}