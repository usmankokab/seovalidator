<?php
require 'bootstrap/app.php';

echo "\n========== QUEUE CLEANUP & DIAGNOSTICS ==========\n\n";

// Check and remove stuck jobs older than 1 hour
$stuckJobs = DB::table('jobs')
    ->where('created_at', '<', now()->subHour())
    ->get();

if ($stuckJobs->count() > 0) {
    echo "⚠️  Found " . $stuckJobs->count() . " stuck jobs (older than 1 hour)\n";
    echo "Removing stuck jobs...\n";
    
    foreach ($stuckJobs as $job) {
        DB::table('jobs')->where('id', $job->id)->delete();
        echo "  ✓ Removed job ID: {$job->id}\n";
    }
}

// Mark any verification jobs stuck in "processing" as failed if their job no longer exists
$processingJobs = DB::table('verification_jobs')
    ->where('status', 'processing')
    ->where('started_at', '<', now()->subMinutes(120))  // Running for more than 2 hours
    ->get();

if ($processingJobs->count() > 0) {
    echo "\n⚠️  Found " . $processingJobs->count() . " jobs stuck in 'processing' for 2+ hours\n";
    echo "Marking as failed...\n";
    
    foreach ($processingJobs as $job) {
        DB::table('verification_jobs')
            ->where('id', $job->id)
            ->update([
                'status' => 'failed',
                'error_message' => 'Job exceeded timeout (2+ hours processing). Queue worker may have crashed.',
                'completed_at' => now()
            ]);
        echo "  ✓ Marked job {$job->job_id} as failed\n";
    }
}

// Summary
echo "\n✅ CURRENT STATUS:\n";
echo "  Jobs in queue: " . DB::table('jobs')->count() . "\n";
echo "  Failed jobs: " . DB::table('failed_jobs')->count() . "\n";
echo "  Pending verifications: " . DB::table('verification_jobs')->where('status', 'pending')->count() . "\n";
echo "  Running verifications: " . DB::table('verification_jobs')->where('status', 'processing')->count() . "\n";
echo "  Completed verifications: " . DB::table('verification_jobs')->where('status', 'completed')->count() . "\n";
echo "  Failed verifications: " . DB::table('verification_jobs')->where('status', 'failed')->count() . "\n";

echo "\n📋 RECENT VERIFICATION JOBS:\n";
$recent = DB::table('verification_jobs')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

foreach ($recent as $v) {
    $status = match($v->status) {
        'pending' => '⏳',
        'processing' => '⚙️',
        'completed' => '✅',
        'failed' => '❌',
        'cancelled' => '❌',
        default => '❓'
    };
    
    echo "  {$status} {$v->job_id}\n";
    echo "     Workbook: {$v->workbook_name}, Mode: {$v->report_mode}\n";
    echo "     Status: {$v->status}, Progress: {$v->progress}%\n";
    
    if ($v->error_message) {
        echo "     Error: " . substr($v->error_message, 0, 100) . "\n";
    }
    
    if ($v->started_at && $v->completed_at) {
        $duration = \Carbon\Carbon::parse($v->completed_at)->diffInSeconds(\Carbon\Carbon::parse($v->started_at));
        echo "     Duration: {$duration}s\n";
    }
}

echo "\n=========================================\n\n";

// Return success
exit(0);
