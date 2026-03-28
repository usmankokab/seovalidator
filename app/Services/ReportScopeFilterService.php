<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * FR-06: Report Scope Filter Service
 * Filters rows based on report mode: complete workbook, single week, or date range.
 */
class ReportScopeFilterService
{
    public const MODE_COMPLETE = 'complete';
    public const MODE_SINGLE_WEEK = 'single_week';
    public const MODE_DATE_RANGE = 'date_range';

    public function __construct(
        private DateParserService $dateParser
    ) {}

    /**
     * Filter rows based on mode and filter values
     */
    public function filter(array $normalizedRows, string $mode, ?array $filterValues = []): array
    {
        $filtered = [];

        foreach ($normalizedRows as $row) {
            $include = match ($mode) {
                self::MODE_COMPLETE => true,
                self::MODE_SINGLE_WEEK => $this->matchesWeek($row, $filterValues['week'] ?? null),
                self::MODE_DATE_RANGE => $this->matchesDateRange($row, $filterValues['start_date'] ?? null, $filterValues['end_date'] ?? null),
                default => true
            };

            if ($include) {
                $filtered[] = $row;
            } else {
                // Mark row as excluded but keep for coverage analysis
                $row['included_in_scope'] = false;
                $filtered[] = $row;
            }
        }

        Log::info("Filtered rows: " . count($filtered) . " for mode: {$mode}");
        return $filtered;
    }

    /**
     * Check if row matches the requested week
     */
    private function matchesWeek(array $row, ?string $requestedWeek): bool
    {
        if ($requestedWeek === null) {
            return true;
        }

        if (empty($row['week'])) {
            return false;
        }

        $rowWeekNormalized = $row['week']['normalized'] ?? '';
        $requestedWeekNormalized = $this->normalizeWeekRequest($requestedWeek);

        return stripos($rowWeekNormalized, $requestedWeekNormalized) !== false ||
               $rowWeekNormalized === $requestedWeekNormalized;
    }

    /**
     * Normalize week request for comparison
     */
    private function normalizeWeekRequest(string $week): string
    {
        $normalized = strtolower(trim($week));
        $normalized = str_replace(['week', 'wk', 'st', 'nd', 'rd', 'th'], '', $normalized);
        return trim($normalized);
    }

    /**
     * Check if row's date falls within requested range
     */
    private function matchesDateRange(array $row, ?string $startDate, ?string $endDate): bool
    {
        if ($startDate === null || $endDate === null) {
            return true;
        }

        if (empty($row['date'])) {
            return false;
        }

        return $this->dateParser->isDateInRange($row['date'], $startDate, $endDate);
    }

    /**
     * Get unique weeks from filtered rows
     */
    public function getWeeksFromRows(array $rows): array
    {
        $weeks = [];
        foreach ($rows as $row) {
            if (!empty($row['week']['normalized'])) {
                $weeks[$row['week']['normalized']] = $row['week'];
            }
        }
        return $weeks;
    }

    /**
     * Get date coverage from filtered rows
     */
    public function getDateCoverage(array $rows): array
    {
        $coverage = [
            'min_date' => null,
            'max_date' => null,
            'dates' => []
        ];

        foreach ($rows as $row) {
            if (!empty($row['date'])) {
                $coverage['dates'][$row['date']] = true;
                if ($coverage['min_date'] === null || $row['date'] < $coverage['min_date']) {
                    $coverage['min_date'] = $row['date'];
                }
                if ($coverage['max_date'] === null || $row['date'] > $coverage['max_date']) {
                    $coverage['max_date'] = $row['date'];
                }
            }
        }

        return $coverage;
    }
}