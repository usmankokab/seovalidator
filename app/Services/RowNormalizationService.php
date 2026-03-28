<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * FR-04: Row Normalization Service
 * Transforms worksheet rows into internal normalized objects.
 */
class RowNormalizationService
{
    public function __construct(
        private DateParserService $dateParser
    ) {}

    /**
     * Normalize a row based on header mapping
     */
    public function normalize(
        array $rowData,
        array $headerMapping,
        string $worksheetName,
        int $rowNumber
    ): array {
        $normalized = [
            'worksheet' => $worksheetName,
            'row_number' => $rowNumber,
            'urls' => [],
            'url_columns' => [],
            'post_content' => null,
            'post_column_name' => null,
            'status' => null,
            'status_column_name' => null,
            'keyword' => null,
            'keyword_column_name' => null,
            'date' => null,
            'week' => null,
            'date_column_name' => $headerMapping['date_column']['name'] ?? null,
            'week_column_name' => $headerMapping['week_column']['name'] ?? null,
            'included_in_scope' => true,
            'notes' => []
        ];

        // ONLY process Submission page column (column index 3) - hardcoded
        $submissionPageIdx = 3;
        if (!empty($rowData[$submissionPageIdx])) {
            $normalized['urls'][] = [
                'column_name' => 'Submission page',
                'original_url' => trim($rowData[$submissionPageIdx]),
                'final_url' => null,
                'status' => null,
                'status_code' => null,
                'error' => null
            ];
        }

        // Extract post content
        if ($headerMapping['post_column'] !== null) {
            $colIndex = $headerMapping['post_column']['index'];
            $postValue = $rowData[$colIndex] ?? null;
            $normalized['post_content'] = $postValue;
            $normalized['post_column_name'] = $headerMapping['post_column']['name'];
        }

        // Extract date
        if ($headerMapping['date_column'] !== null) {
            $colIndex = $headerMapping['date_column']['index'];
            $dateValue = $rowData[$colIndex] ?? null;
            $parsedDate = $this->dateParser->parseDate($dateValue);
            $normalized['date'] = $parsedDate;
            $normalized['date_column_name'] = $headerMapping['date_column']['name'];
        }

        // Extract week
        if ($headerMapping['week_column'] !== null) {
            $colIndex = $headerMapping['week_column']['index'];
            $weekValue = $rowData[$colIndex] ?? null;
            $parsedWeek = $this->dateParser->parseWeek($weekValue);
            $normalized['week'] = $parsedWeek;
            $normalized['week_column_name'] = $headerMapping['week_column']['name'];
        }

        // Extract status if available
        if ($headerMapping['status_column'] !== null) {
            $colIndex = $headerMapping['status_column']['index'];
            $statusValue = $rowData[$colIndex] ?? null;
            $normalized['status'] = $statusValue;
            $normalized['status_column_name'] = $headerMapping['status_column']['name'];
        }

        // Extract keyword if available
        if ($headerMapping['keyword_column'] !== null) {
            $colIndex = $headerMapping['keyword_column']['index'];
            $keywordValue = $rowData[$colIndex] ?? null;
            $normalized['keyword'] = $keywordValue;
            $normalized['keyword_column_name'] = $headerMapping['keyword_column']['name'];
        }

        // If URL column exists but is empty, log it
        if (empty($normalized['urls']) && !empty($headerMapping['url_columns'])) {
            $normalized['notes'][] = 'URL columns present but empty';
        }

        return $normalized;
    }

    /**
     * Get unique weeks found in processed rows
     */
    public function getUniqueWeeks(array $normalizedRows): array
    {
        $weeks = [];
        foreach ($normalizedRows as $row) {
            if ($row['week'] && !empty($row['week']['normalized'])) {
                $weeks[$row['week']['normalized']] = true;
            }
        }
        return array_keys($weeks);
    }

    /**
     * Get unique dates found in processed rows
     */
    public function getUniqueDates(array $normalizedRows): array
    {
        $dates = [];
        foreach ($normalizedRows as $row) {
            if (!empty($row['date'])) {
                $dates[$row['date']] = true;
            }
        }
        return array_keys($dates);
    }
}