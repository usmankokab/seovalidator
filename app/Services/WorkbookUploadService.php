<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * FR-01: Workbook Upload Service
 * Handles .xlsx file upload, validation, and temporary storage.
 */
class WorkbookUploadService
{
    private string $uploadPath;
    private ?string $lastError = null;

    public function __construct()
    {
        $this->uploadPath = storage_path('app/workbooks');
        if (!is_dir($this->uploadPath)) {
            mkdir($this->uploadPath, 0755, true);
        }
    }

    /**
     * Validate and store uploaded workbook
     */
    public function store(UploadedFile $file): array
    {
        $result = [
            'success' => false,
            'file_path' => null,
            'file_name' => null,
            'sheet_count' => 0,
            'error' => null
        ];

        // Validate file extension
        if ($file->getClientOriginalExtension() !== 'xlsx') {
            $this->lastError = 'Only .xlsx files are accepted.';
            $result['error'] = $this->lastError;
            return $result;
        }

        // Validate MIME type
        $mimeType = $file->getMimeType();
        if (!in_array($mimeType, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'])) {
            $this->lastError = 'Invalid file type. Please upload an Excel workbook.';
            $result['error'] = $this->lastError;
            return $result;
        }

        // Validate file size (max 50MB)
        if ($file->getSize() > 50 * 1024 * 1024) {
            $this->lastError = 'File size exceeds 50MB limit.';
            $result['error'] = $this->lastError;
            return $result;
        }

        try {
            $fileName = time() . '_' . $file->getClientOriginalName();
            $destinationPath = $this->uploadPath . '/' . $fileName;
            
            // Store file
            $file->move($this->uploadPath, $fileName);
            
            // Verify file is readable
            $spreadsheet = IOFactory::load($destinationPath);
            $sheetCount = count($spreadsheet->getAllSheets());
            
            if ($sheetCount === 0) {
                $this->lastError = 'The workbook contains no worksheets.';
                $result['error'] = $this->lastError;
                @unlink($destinationPath);
                return $result;
            }

            $result['success'] = true;
            $result['file_path'] = $destinationPath;
            $result['file_name'] = $file->getClientOriginalName();
            $result['sheet_count'] = $sheetCount;

            Log::info("Workbook uploaded: {$file->getClientOriginalName()}, Sheets: {$sheetCount}");
            
        } catch (\Exception $e) {
            $this->lastError = 'Failed to read workbook: ' . $e->getMessage();
            $result['error'] = $this->lastError;
            Log::error("Workbook upload error: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Load workbook for processing
     */
    public function load(string $filePath): ?Spreadsheet
    {
        try {
            return IOFactory::load($filePath);
        } catch (\Exception $e) {
            Log::error("Failed to load workbook: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get last error message
     */
    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Clean up temporary files
     */
    public function cleanup(string $filePath): void
    {
        if (file_exists($filePath)) {
            @unlink($filePath);
            Log::info("Cleaned up workbook: {$filePath}");
        }
    }
}