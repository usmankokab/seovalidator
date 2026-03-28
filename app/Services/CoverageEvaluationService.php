<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * FR-07: Coverage Evaluation Service
 * Evaluates period coverage per worksheet for reporting modes.
 */
class CoverageEvaluationService
{
    public const STATUS_PRESENT = 'Present';
    public const STATUS_MISSING = 'Missing';
    public const STATUS_CANNOT_VERIFY = 'Cannot Verify';

    public function __construct(
        private DateParserService $dateParser
    ) {}

    /**
     * Evaluate period coverage for each worksheet
     */
    public function evaluateWorksheetCoverage(
        array $normalizedRows,
        string $reportMode,
        ?array $filterValues
    ): array {
        $coverage = [
            'status' => self::STATUS_PRESENT,
            'has_date_column' => false,
            'has_week_column' => false,
            'start_date' => null,
            'end_date' => null,
            'weeks_found' => [],
            'dates_found' => [],
            'notes' => []
        ];

        // Check for date and week columns
        foreach ($normalizedRows as $row) {
            if (!empty($row['date_column_name']) && !$coverage['has_date_column']) {
                $coverage['has_date_column'] = true;
            }
            if (!empty($row['week_column_name']) && !$coverage['has_week_column']) {
                $coverage['has_week_column'] = true;
            }
        }

        // Collect dates and weeks from included rows only
        foreach ($normalizedRows as $row) {
            if (isset($row['included_in_scope']) && !$row['included_in_scope']) {
                continue;
            }

            if (!empty($row['week'])) {
                $weekKey = $row['week']['normalized'] ?? $row['week']['original'];
                $coverage['weeks_found'][$weekKey] = true;
            }

            if (!empty($row['date'])) {
                $coverage['dates_found'][$row['date']] = true;
            }
        }

        // Determine date range
        if (!empty($coverage['dates_found'])) {
            $dates = array_keys($coverage['dates_found']);
            sort($dates);
            $coverage['start_date'] = reset($dates);
            $coverage['end_date'] = end($dates);
        }

        // Evaluate status based on mode
        switch ($reportMode) {
            case ReportScopeFilterService::MODE_SINGLE_WEEK:
                $this->evaluateWeekCoverage($coverage, $filterValues['week'] ?? null);
                break;
            case ReportScopeFilterService::MODE_DATE_RANGE:
                $this->evaluateDateRangeCoverage($coverage, $filterValues['start_date'] ?? null, $filterValues['end_date'] ?? null);
                break;
            // Complete mode always shows Present if there's any data
        }

        return $coverage;
    }

    /**
     * Evaluate single week coverage
     */
    private function evaluateWeekCoverage(array &$coverage, ?string $requestedWeek): void
    {
        if ($requestedWeek === null) {
            return;
        }

        if (!$coverage['has_week_column']) {
            $coverage['status'] = self::STATUS_CANNOT_VERIFY;
            $coverage['notes'][] = 'No week column available';
            return;
        }

        // Check if requested week exists in data
        $weekFound = false;
        foreach (array_keys($coverage['weeks_found']) as $week) {
            if (stripos($week, $requestedWeek) !== false) {
                $weekFound = true;
                break;
            }
        }

        if (!$weekFound && !empty($coverage['weeks_found'])) {
            $coverage['status'] = self::STATUS_MISSING;
            $coverage['notes'][] = "Week '{$requestedWeek}' not found";
        }
    }

    /**
     * Evaluate date range coverage
     */
    private function evaluateDateRangeCoverage(array &$coverage, ?string $startDate, ?string $endDate): void
    {
        if ($startDate === null || $endDate === null) {
            return;
        }

        if (!$coverage['has_date_column']) {
            $coverage['status'] = self::STATUS_CANNOT_VERIFY;
            $coverage['notes'][] = 'No date column available';
            return;
        }

        // Check if any date in range exists
        $hasDateInRange = false;
        foreach (array_keys($coverage['dates_found']) as $date) {
            if ($this->dateParser->isDateInRange($date, $startDate, $endDate)) {
                $hasDateInRange = true;
                break;
            }
        }

        if (!$hasDateInRange && !empty($coverage['dates_found'])) {
            $coverage['status'] = self::STATUS_MISSING;
            $coverage['notes'][] = "No data in date range {$startDate} to {$endDate}";
        }
    }

    /**
     * Get all unique worksheet statuses
     */
    public function getStatuses(array $worksheetCoverages): array
    {
        $statuses = [];
        foreach ($worksheetCoverages as $worksheet => $coverage) {
            $statuses[$worksheet] = $coverage['status'];
        }
        return $statuses;
    }
}