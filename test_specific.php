<?php

require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$file = __DIR__ . '/../Weekly Report - Share.google_cgqKqbHXqYB05IsaG (Timeless Studio Website) (4).xlsx';

echo "Loading file...\n";

$spreadsheet = IOFactory::load($file);
$sheet = $spreadsheet->getSheetByName('High Quality Submission');

if (!$sheet) {
    echo "Sheet not found!\n";
    exit;
}

echo "Found sheet: High Quality Submission\n";

// Get the dimensions
$highestRow = $sheet->getHighestRow();
$highestCol = $sheet->getHighestColumn();
$highestColIdx = Coordinate::columnIndexFromString($highestCol);

echo "Rows: $highestRow, Cols: $highestCol ($highestColIdx)\n";

// Find header row by scanning first 10 rows
$headerRow = 1;
for ($r = 1; $r <= 10; $r++) {
    $filledCells = 0;
    for ($c = 1; $c <= min($highestColIdx, 30); $c++) {
        $colLetter = Coordinate::stringFromColumnIndex($c);
        $val = $sheet->getCell($colLetter . $r)->getValue();
        if ($val) $filledCells++;
    }
    if ($filledCells > 5) {
        $headerRow = $r;
        break;
    }
}

echo "Header row: $headerRow\n";

// Show headers
echo "\nHeaders in row $headerRow:\n";
for ($c = 1; $c <= min($highestColIdx, 30); $c++) {
    $colLetter = Coordinate::stringFromColumnIndex($c);
    $val = $sheet->getCell($colLetter . $headerRow)->getValue();
    if ($val) {
        echo "  $colLetter: $val\n";
    }
}

// Find rows with "Approved" status
echo "\n\nSearching for 'Approved' status rows...\n";
for ($r = $headerRow + 1; $r <= min($highestRow, $headerRow + 50); $r++) {
    for ($c = 1; $c <= min($highestColIdx, 30); $c++) {
        $colLetter = Coordinate::stringFromColumnIndex($c);
        $headerVal = $sheet->getCell($colLetter . $headerRow)->getValue();
        $cellVal = $sheet->getCell($colLetter . $r)->getValue();
        
        $headerLower = is_string($headerVal) ? strtolower($headerVal) : '';
        $cellLower = is_string($cellVal) ? strtolower(trim($cellVal)) : '';
        
        if ($headerLower === 'status' && $cellLower === 'approved') {
            echo "Found Approved at row $r\n";
            
            for ($c2 = 1; $c2 <= min($highestColIdx, 30); $c2++) {
                $colLetter2 = Coordinate::stringFromColumnIndex($c2);
                $header = $sheet->getCell($colLetter2 . $headerRow)->getValue();
                $val = $sheet->getCell($colLetter2 . $r)->getValue();
                $valStr = is_string($val) ? trim($val) : '';
                if (strlen($valStr) > 0) {
                    echo "  $header: " . substr($valStr, 0, 60) . "\n";
                }
            }
            break 2;
        }
    }
}

echo "\nDone.\n";