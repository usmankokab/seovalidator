<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VerificationJob extends Model
{
    protected $table = 'verification_jobs';

    protected $fillable = [
        'job_id',
        'status',
        'progress',
        'workbook_name',
        'file_path',
        'report_mode',
        'filter_values',
        'excel_file',
        'word_file',
        'pdf_file',
        'summary',
        'coverage',
        'exceptions',
        'error_message',
        'elapsed_time',
        'started_at',
        'completed_at'
    ];

    protected $casts = [
        'filter_values' => 'array',
        'summary' => 'array',
        'coverage' => 'array',
        'exceptions' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get display status with human-readable text
     */
    public function getDisplayStatus(): string
    {
        return match($this->status) {
            'pending' => 'Waiting to start...',
            'processing' => "Processing ({$this->progress}%)...",
            'completed' => 'Completed Successfully',
            'failed' => 'Failed - See Error',
            default => 'Unknown'
        };
    }

    /**
     * Check if job is complete
     */
    public function isComplete(): bool
    {
        return in_array($this->status, ['completed', 'failed']);
    }

    /**
     * Check if job is successful
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if job failed
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }
}
