<?php
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Services\WorksheetScannerService;
use App\Services\HeaderMappingService;

$file = __DIR__ . '/../Weekly Report - Share.google_cgqKqbHXqYB05IsaG (Timeless Studio Website) (4).xlsx';

if (!file_exists($file)) {
    echo "File not found: $file\n";
    exit;
}

$spreadsheet = IOFactory::load($file);
$scanner = new WorksheetScannerService();
$headerService = new HeaderMappingService();

// Get High Quality Submission sheet
$sheet = $spreadsheet->getSheetByName('High Quality Submission');

if (!$sheet) {
    echo "Sheet 'High Quality Submission' not found\n";
    exit;
}

echo "=== Testing worksheet scanner with auto header detection ===\n";

// Test the getWorksheetData method (which now auto-detects header row)
$sheetData = $scanner->getWorksheetData($sheet);

echo "Detected header row: " . $sheetData['header_row'] . "\n";
echo "Headers found (" . count($sheetData['headers']) . "):\n";
foreach ($sheetData['headers'] as $col => $header) {
    echo "  Col $col: [$header]\n";
}

echo "\n=== Testing header mapping ===\n";
$mapping = $headerService->map($sheetData['headers']);

echo "URL columns found (" . count($mapping['url_columns']) . "):\n";
foreach ($mapping['url_columns'] as $col) {
    echo "  - " . $col['name'] . " (index: " . $col['index'] . ")\n";
}

echo "Status column: " . ($mapping['status_column']['name'] ?? 'null') . "\n";
echo "Keyword column: " . ($mapping['keyword_column']['name'] ?? 'null') . "\n";
echo "Week column: " . ($mapping['week_column']['name'] ?? 'null') . "\n";
echo "Date column: " . ($mapping['date_column']['name'] ?? 'null') . "\n";

echo "\nRows found: " . count($sheetData['rows']) . "\n";