<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * FR-05: Date and Week Parsing Service
 * Parses Excel date serials, formatted dates, text dates, and normalizes week values.
 */
class DateParserService
{
    /**
     * Attempt to parse a date value from various formats
     * Returns ISO date string or null if unparseable
     */
    public function parseDate(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        // Handle Excel serial date
        if (is_numeric($value)) {
            // Excel serial date (days since 1899-12-30)
            if ($value > 1 && $value < 50000) { // Reasonable date range
                try {
                    $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($value);
                    return $date->format('Y-m-d');
                } catch (\Exception $e) {
                    Log::debug("Failed to parse Excel serial: {$value}");
                }
            }
        }

        // Handle string dates
        if (is_string($value)) {
            $value = trim($value);
            
            // Try common date formats
            $formats = [
                'Y-m-d',        // 2024-03-15
                'd/m/Y',        // 15/03/2024
                'm/d/Y',        // 03/15/2024
                'd-m-Y',        // 15-03-2024
                'm-d-Y',        // 03-15-2024
                'd M Y',        // 15 Mar 2024
                'M d, Y',       // Mar 15, 2024
                'F d, Y',       // March 15, 2024
                'Y/m/d',        // 2024/03/15
                'Ymd',          // 20240315
            ];

            foreach ($formats as $format) {
                $date = \DateTime::createFromFormat($format, $value);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            }

            // Try strtotime as fallback
            $timestamp = strtotime($value);
            if ($timestamp !== false && $timestamp > 0) {
                return date('Y-m-d', $timestamp);
            }
        }

        // Handle DateTime object
        if ($value instanceof \DateTime) {
            return $value->format('Y-m-d');
        }

        Log::debug("Unable to parse date: " . print_r($value, true));
        return null;
    }

    /**
     * Parse week value to normalized format
     * Returns week number (1-53) or original label
     */
    public function parseWeek(mixed $value): ?array
    {
        if (empty($value)) {
            return null;
        }

        $original = is_string($value) ? trim($value) : (string)$value;
        
        // Extract week number from various formats
        // Examples: "Week 1", "1st Week", "Week-1", "Wk1", "Week 12", "12"
        $normalized = strtolower($original);
        $normalized = str_replace(['week', 'wk', 'st', 'nd', 'rd', 'th'], '', $normalized);
        $normalized = str_replace(['-', '_', ' '], '', $normalized);
        $normalized = trim($normalized);

        // Try to extract numeric week
        if (is_numeric($normalized) && $normalized >= 1 && $normalized <= 53) {
            return [
                'week_number' => (int)$normalized,
                'original' => $original,
                'normalized' => "Week {$normalized}"
            ];
        }

        // If not numeric, return the original as label
        return [
            'week_number' => null,
            'original' => $original,
            'normalized' => $original
        ];
    }

    /**
     * Check if a date falls within a range
     */
    public function isDateInRange(string $date, string $startDate, string $endDate): bool
    {
        $dateTs = strtotime($date);
        $startTs = strtotime($startDate);
        $endTs = strtotime($endDate);

        return $dateTs >= $startTs && $dateTs <= $endTs;
    }

    /**
     * Normalize date for comparison
     */
    public function normalize(string $date): string
    {
        return date('Y-m-d', strtotime($date));
    }
}