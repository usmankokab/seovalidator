<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;

$file = __DIR__ . '/../Weekly Report - Share.google_cgqKqbHXqYB05IsaG (Timeless Studio Website) (4).xlsx';

if (!file_exists($file)) {
    echo "File not found: $file\n";
    exit;
}

echo "Loading: $file\n";

// Load normally
$reader = new Xlsx();
$spreadsheet = $reader->load($file);
$sheetNames = $spreadsheet->getSheetNames();
echo "Sheets: " . implode(", ", $sheetNames) . "\n";

$sheet = $spreadsheet->getSheetByName('High Quality Submission');
if (!$sheet) {
    echo "Sheet 'High Quality Submission' not found\n";
    
    // Try to find similar name
    foreach ($sheetNames as $name) {
        if (stripos($name, 'High Quality') !== false || stripos($name, 'Submission') !== false) {
            echo "Found similar: $name\n";
            $sheet = $spreadsheet->getSheetByName($name);
            break;
        }
    }
}

if ($sheet) {
    echo "Using sheet: " . $sheet->getTitle() . "\n";
    
    // Check multiple rows for headers (sometimes row 1 is empty)
    $headerRow = 1;
    for ($r = 1; $r <= 5; $r++) {
        $hasData = false;
        for ($col = 'A'; $col <= 'Z'; $col++) {
            $cell = $sheet->getCell($col . $r);
            if ($cell->getValue()) {
                $hasData = true;
                break;
            }
        }
        if ($hasData) {
            $headerRow = $r;
            break;
        }
    }
    echo "Header found at row: $headerRow\n";
    
    // Get headers
    $headers = [];
    $maxCol = $sheet->getHighestColumn();
    $maxColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($maxCol);
    
    for ($col = 1; $col <= $maxColIdx; $col++) {
        $cell = $sheet->getCell(
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $headerRow
        );
        $val = $cell->getValue();
        if ($val) {
            $headers[$col] = $val;
        }
    }
    
    echo "Headers (" . count($headers) . "): \n";
    foreach ($headers as $idx => $header) {
        echo "  Col $idx: $header\n";
    }
    
    // First data row
    echo "\nRow 2 (first data):\n";
    for ($col = 1; $col <= min($maxColIdx, 15); $col++) {
        $cell = $sheet->getCell(
            \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . '2'
        );
        $val = $cell->getValue();
        $header = $headers[$col] ?? "Col$col";
        if ($val) {
            echo "  $header: " . substr(strval($val), 0, 50) . "\n";
        }
    }
} else {
    echo "No suitable sheet found\n";
}