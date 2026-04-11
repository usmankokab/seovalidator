<?php
require 'bootstrap/app.php';
$app = app();

echo "\n========== QUEUE STATUS DIAGNOSTIC ==========\n\n";

echo "📊 JOBS TABLE:\n";
$jobs = DB::table('jobs')->orderBy('created_at', 'desc')->limit(10)->get();
echo "Total jobs in queue: " . DB::table('jobs')->count() . "\n";
foreach ($jobs as $job) {
    $data = json_decode($job->payload, true);
    $displayName = isset($data['displayName']) ? $data['displayName'] : 'Unknown';
    echo "  • ID: {$job->id}, Type: {$displayName}, Attempts: {$job->attempts}, Created: {$job->created_at}\n";
}

echo "\n❌ FAILED JOBS TABLE:\n";
$failed = DB::table('failed_jobs')->orderBy('created_at', 'desc')->limit(10)->get();
echo "Total failed jobs: " . DB::table('failed_jobs')->count() . "\n";
foreach ($failed as $f) {
    echo "  • ID: {$f->id}\n";
    echo "    Exception: " . substr($f->exception, 0, 200) . "...\n";
    echo "    Failed at: {$f->failed_at}\n";
}

echo "\n🔍 VERIFICATION JOBS TABLE:\n";
$verifications = DB::table('verification_jobs')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();
echo "Total verification jobs: " . DB::table('verification_jobs')->count() . "\n";
foreach ($verifications as $v) {
    echo "  • ID: {$v->id}, Status: {$v->status}, Progress: {$v->progress}%\n";
    if ($v->error_message) {
        echo "    Error: " . substr($v->error_message, 0, 150) . "\n";
    }
    echo "    Created: {$v->created_at}\n";
}

echo "\n========================================\n\n";
