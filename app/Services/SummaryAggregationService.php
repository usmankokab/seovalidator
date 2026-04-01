<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * FR-11: Summary Aggregation Service
 * Computes worksheet metrics, week/date coverage, and overall totals.
 */
class SummaryAggregationService
{
    private UrlValidationService $urlValidator;

    public function __construct(UrlValidationService $urlValidator)
    {
        $this->urlValidator = $urlValidator;
    }

    /**
     * Generate complete run summary
     */
    public function generateSummary(array $processedData, string $reportMode, array $filterValues): array
    {
        $summary = [
            'mode' => $reportMode,
            'filter' => $filterValues,
            'worksheets' => [],
            'overall' => [
                'total_rows' => 0,
                'total_urls_checked' => 0,
                'working_urls' => 0,
                'valid_urls' => 0,
                'broken_urls' => 0,
                'redirected_urls' => 0,
                'invalid_urls' => 0,
                'cannot_verify_urls' => 0,
                'timeout_urls' => 0,
                'blank_posts' => 0,
                'low_content_posts' => 0,
                'valid_posts' => 0,
                'weeks_found' => [],
                'date_range' => [
                    'start' => null,
                    'end' => null
                ]
            ]
        ];

        // Aggregate per worksheet
        foreach ($processedData['by_worksheet'] as $worksheetName => $rows) {
            // Ensure all rows have required keys
            foreach ($rows as &$row) {
                if (!isset($row['worksheet'])) {
                    $row['worksheet'] = $worksheetName;
                }
                if (!isset($row['included_in_scope'])) {
                    $row['included_in_scope'] = true;
                }
                if (!isset($row['urls'])) {
                    $row['urls'] = [];
                }
            }
            $worksheetSummary = $this->generateWorksheetSummary($rows, $worksheetName);
            $summary['worksheets'][$worksheetName] = $worksheetSummary;
            
            // Add to overall
            $summary['overall']['total_rows'] += $worksheetSummary['total_rows'];
            $summary['overall']['total_urls_checked'] += $worksheetSummary['total_urls_checked'];
            $summary['overall']['working_urls'] += $worksheetSummary['working_urls'];
            $summary['overall']['valid_urls'] += $worksheetSummary['valid_urls'];
            $summary['overall']['broken_urls'] += $worksheetSummary['broken_urls'];
            $summary['overall']['redirected_urls'] += $worksheetSummary['redirected_urls'];
            $summary['overall']['invalid_urls'] += $worksheetSummary['invalid_urls'];
            $summary['overall']['cannot_verify_urls'] += $worksheetSummary['cannot_verify_urls'];
            $summary['overall']['timeout_urls'] += $worksheetSummary['timeout_urls'];
            $summary['overall']['blank_posts'] += $worksheetSummary['blank_posts'];
            $summary['overall']['low_content_posts'] += $worksheetSummary['low_content_posts'];
            $summary['overall']['valid_posts'] += $worksheetSummary['valid_posts'];
        }

        // Get week/date coverage - ensure all_rows exist first
        if (isset($processedData['all_rows']) && is_array($processedData['all_rows'])) {
            // Ensure all all_rows have required keys
            foreach ($processedData['all_rows'] as &$row) {
                if (!isset($row['included_in_scope'])) {
                    $row['included_in_scope'] = true;
                }
            }
            $coverageData = $this->extractCoverageData($processedData['all_rows']);
            $summary['overall']['weeks_found'] = array_keys($coverageData['weeks']);
            $summary['overall']['date_range'] = $coverageData['date_range'];
        }

        return $summary;
    }

    /**
     * Generate per-worksheet summary
     */
    private function generateWorksheetSummary(array $rows, string $worksheetName): array
    {
        $summary = [
            'worksheet' => $worksheetName,
            'total_rows' => count($rows),
            'total_urls_checked' => 0,
            'working_urls' => 0,
            'valid_urls' => 0,
            'broken_urls' => 0,
            'redirected_urls' => 0,
            'invalid_urls' => 0,
            'cannot_verify_urls' => 0,
            'timeout_urls' => 0,
            'blank_posts' => 0,
            'low_content_posts' => 0,
            'valid_posts' => 0,
            'weeks' => [],
            'coverage' => [
                'min_date' => null,
                'max_date' => null
            ]
        ];

        $urlStatuses = [
            UrlValidationService::STATUS_WORKING => 'working_urls',
            UrlValidationService::STATUS_BROKEN => 'broken_urls',
            UrlValidationService::STATUS_REDIRECTED => 'redirected_urls',
            UrlValidationService::STATUS_INVALID => 'invalid_urls',
            UrlValidationService::STATUS_CANNOT_VERIFY => 'cannot_verify_urls',
            UrlValidationService::STATUS_TIMEOUT => 'timeout_urls'
        ];

        foreach ($rows as $row) {
            if (isset($row['included_in_scope']) && !$row['included_in_scope']) {
                continue;
            }

            // Count URLs
            foreach ($row['urls'] as $url) {
                $summary['total_urls_checked']++;
                $status = $url['status'] ?? '';
                $key = $urlStatuses[$status] ?? 'broken_urls';
                $summary[$key]++;
            }

            // Count posts - valid_posts for post analysis (posts with content)
            if (isset($row['post_analysis'])) {
                if ($row['post_analysis']['is_blank']) {
                    $summary['blank_posts']++;
                } elseif ($row['post_analysis']['is_low_content']) {
                    $summary['low_content_posts']++;
                } else {
                    // Not blank, not low content - count as valid
                    $summary['valid_posts']++;
                }
            } else {
                // No post_analysis means it's valid (has content)
                $summary['valid_posts']++;
            }

            // Collect weeks
            if (!empty($row['week']['normalized'])) {
                $summary['weeks'][$row['week']['normalized']] = true;
            }

            // Collect dates
            if (!empty($row['date'])) {
                if ($summary['coverage']['min_date'] === null || $row['date'] < $summary['coverage']['min_date']) {
                    $summary['coverage']['min_date'] = $row['date'];
                }
                if ($summary['coverage']['max_date'] === null || $row['date'] > $summary['coverage']['max_date']) {
                    $summary['coverage']['max_date'] = $row['date'];
                }
            }
        }

        // Calculate valid_urls AFTER all rows are processed
        // Valid = Checked - Broken - Blank - Low - Redirected - Timeout
        $checked = $summary['total_urls_checked'];
        $invalidCount = $summary['broken_urls'] + $summary['redirected_urls'] + 
                        $summary['timeout_urls'] + $summary['blank_posts'] + 
                        $summary['low_content_posts'];
        $summary['valid_urls'] = max(0, $checked - $invalidCount);

        $summary['weeks'] = array_keys($summary['weeks']);

        return $summary;
    }

    /**
     * Extract week and date coverage data
     */
    private function extractCoverageData(array $rows): array
    {
        $result = [
            'weeks' => [],
            'date_range' => [
                'start' => null,
                'end' => null
            ]
        ];

        foreach ($rows as $row) {
            if (isset($row['included_in_scope']) && !$row['included_in_scope']) {
                continue;
            }

            if (!empty($row['week']['normalized'])) {
                $result['weeks'][$row['week']['normalized']] = true;
            }

            if (!empty($row['date'])) {
                if ($result['date_range']['start'] === null || $row['date'] < $result['date_range']['start']) {
                    $result['date_range']['start'] = $row['date'];
                }
                if ($result['date_range']['end'] === null || $row['date'] > $result['date_range']['end']) {
                    $result['date_range']['end'] = $row['date'];
                }
            }
        }

        return $result;
    }

    /**
     * Generate exception lists for reports
     */
    public function generateExceptions(array $processedData): array
    {
        $exceptions = [
            'broken_urls' => [],
            'cannot_verify_urls' => [],
            'blank_posts' => [],
            'low_content_posts' => [],
            'url_checks' => []
        ];

        // Ensure all all_rows have required keys
        if (!isset($processedData['all_rows'])) {
            return $exceptions;
        }
        foreach ($processedData['all_rows'] as &$row) {
            if (!isset($row['worksheet'])) {
                $row['worksheet'] = '';
            }
            if (!isset($row['included_in_scope'])) {
                $row['included_in_scope'] = true;
            }
            if (!isset($row['urls'])) {
                $row['urls'] = [];
            }
        }
        foreach ($processedData['all_rows'] as $row) {
            if (isset($row['included_in_scope']) && !$row['included_in_scope']) {
                continue;
            }

            $worksheet = $row['worksheet'];
            $rowNum = $row['row_number'];

            // Collect URLs
            foreach ($row['urls'] as $urlIndex => $url) {
                if (in_array($url['status'], [UrlValidationService::STATUS_BROKEN, UrlValidationService::STATUS_TIMEOUT])) {
                    $exceptions['broken_urls'][] = $this->formatUrlException($row, $url);
                }
                if ($url['status'] === UrlValidationService::STATUS_CANNOT_VERIFY) {
                    $exceptions['cannot_verify_urls'][] = $this->formatUrlException($row, $url);
                }
                $exceptions['url_checks'][] = $this->formatUrlException($row, $url);
            }

            // Collect post issues
            if (isset($row['post_analysis'])) {
                if ($row['post_analysis']['is_blank']) {
                    $exceptions['blank_posts'][] = $this->formatPostException($row);
                } elseif ($row['post_analysis']['is_low_content']) {
                    $exceptions['low_content_posts'][] = $this->formatPostException($row);
                }
            }
        }

        return $exceptions;
    }

    /**
     * Format URL exception for output
     */
    private function formatUrlException(array $row, array $url): array
    {
        return [
            'source_worksheet' => $row['worksheet'],
            'original_row_number' => $row['row_number'],
            'week_value' => $row['week']['normalized'] ?? $row['week']['original'] ?? '',
            'detected_date' => $row['date'] ?? '',
            'reporting_scope' => 'filtered',
            'period_status' => 'Present',
            'url_column_name' => $url['column_name'],
            'original_url' => $url['original_url'],
            'final_url' => $url['final_url'],
            'status' => $url['status'],
            'status_code' => $url['status_code'],
            'error' => $url['error'] ?? ''
        ];
    }

    /**
     * Format post exception for output
     */
    private function formatPostException(array $row): array
    {
        $analysis = $row['post_analysis'] ?? [];
        return [
            'source_worksheet' => $row['worksheet'],
            'original_row_number' => $row['row_number'],
            'week_value' => $row['week']['normalized'] ?? $row['week']['original'] ?? '',
            'detected_date' => $row['date'] ?? '',
            'reporting_scope' => 'filtered',
            'period_status' => 'Present',
            'post_column_name' => $row['post_column_name'] ?? '',
            'post_text' => substr($analysis['text'] ?? '', 0, 500),
            'word_count' => $analysis['word_count'] ?? 0,
            'flag_reason' => $analysis['flag_reason'] ?? ''
        ];
    }
}