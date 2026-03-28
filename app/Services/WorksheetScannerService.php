<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\Log;

/**
 * FR-02: Worksheet Discovery Service
 * Enumerates worksheets, skips empty sheets safely, preserves worksheet names.
 */
class WorksheetScannerService
{
    /**
     * Scan all worksheets in the workbook
     */
    public function scan(Spreadsheet $spreadsheet): array
    {
        $worksheets = [];
        
        foreach ($spreadsheet->getAllSheets() as $index => $sheet) {
            $sheetName = $sheet->getTitle();
            $rowCount = $sheet->getHighestDataRow();
            $colCount = $sheet->getHighestDataColumn();
            
            // Skip fully empty worksheets
            if ($rowCount === 0 || ($rowCount === 1 && $colCount === 'A')) {
                Log::info("Skipping empty worksheet: {$sheetName}");
                continue;
            }

            $worksheets[] = [
                'index' => $index,
                'name' => $sheetName,
                'row_count' => $rowCount,
                'column_count' => $this->columnLetterToIndex($colCount),
                'is_empty' => false
            ];
        }

        Log::info("Scanned " . count($worksheets) . " worksheets");
        return $worksheets;
    }

    /**
     * Get worksheet data with header row detection
     * Now auto-detects header row by finding first non-empty row
     */
    public function getWorksheetData(Worksheet $sheet, int $headerRow = 1): array
    {
        $data = [
            'headers' => [],
            'rows' => [],
            'row_count' => 0,
            'header_row' => 1
        ];

        $highestRow = $sheet->getHighestDataRow();
        $highestCol = $sheet->getHighestDataColumn();
        $colIndex = $this->columnLetterToIndex($highestCol);

        // Auto-detect header row: find first row with any non-empty cell
        $detectedHeaderRow = 1;
        for ($row = 1; $row <= min(10, $highestRow); $row++) {
            $hasContent = false;
            for ($col = 1; $col <= $colIndex; $col++) {
                $cellValue = $sheet->getCell($this->indexToCell($col) . $row)->getValue();
                if (!empty($cellValue)) {
                    $hasContent = true;
                    break;
                }
            }
            if ($hasContent) {
                $detectedHeaderRow = $row;
                break;
            }
        }
        
        $headerRow = $detectedHeaderRow;
        $data['header_row'] = $headerRow;

        // Get headers from detected header row
        for ($col = 1; $col <= $colIndex; $col++) {
            $cellAddress = $this->indexToCell($col) . $headerRow;
            $cellValue = $sheet->getCell($cellAddress)->getValue();
            $data['headers'][$col] = $cellValue ? trim($cellValue) : '';
        }

        // Get data rows (skip header row)
        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $rowData = [];
            $hasData = false;
            
            for ($col = 1; $col <= $colIndex; $col++) {
                $cellAddress = $this->indexToCell($col) . $row;
                $cellValue = $sheet->getCell($cellAddress)->getValue();
                $rowData[$col] = $cellValue;
                if (!empty($cellValue)) {
                    $hasData = true;
                }
            }
            
            if ($hasData) {
                $data['rows'][] = [
                    'row_number' => $row,
                    'data' => $rowData
                ];
            }
        }

        $data['row_count'] = count($data['rows']);
        return $data;
    }

    /**
     * Convert column letter to index (A=1, B=2, etc.)
     */
    private function columnLetterToIndex(string $letter): int
    {
        $letter = strtoupper($letter);
        $index = 0;
        $len = strlen($letter);
        
        for ($i = 0; $i < $len; $i++) {
            $index *= 26;
            $index += ord($letter[$i]) - ord('A') + 1;
        }
        
        return $index;
    }

    /**
     * Convert column index to letter (1=A, 2=B, etc.)
     */
    private function indexToCell(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $index--;
            $letter = chr(($index % 26) + 65) . $letter;
            $index = intdiv($index, 26);
        }
        return $letter;
    }

    /**
     * Get worksheet by name
     */
    public function getWorksheetByName(Spreadsheet $spreadsheet, string $name): ?Worksheet
    {
        try {
            return $spreadsheet->getSheetByName($name);
        } catch (\Exception $e) {
            Log::warning("Worksheet not found: {$name}");
            return null;
        }
    }
}