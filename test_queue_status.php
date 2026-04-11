<?php
require 'bootstrap/app.php';

echo "=== Queue Status ===\n";
echo "Jobs in queue: " . DB::table('jobs')->count() . "\n";
echo "Failed jobs: " . DB::table('failed_jobs')->count() . "\n";
echo "Pending verifications (waiting): " . DB::table('verification_jobs')->where('status', 'waiting')->count() . "\n";
echo "Pending verifications (running): " . DB::table('verification_jobs')->where('status', 'running')->count() . "\n";
echo "\nRecent jobs:\n";
$jobs = DB::table('jobs')->orderBy('created_at', 'desc')->limit(5)->get();
foreach ($jobs as $job) {
    $data = json_decode($job->payload, true);
    echo "  - ID: {$job->id}, Type: " . ($data['displayName'] ?? 'unknown') . ", Created: {$job->created_at}\n";
}

echo "\nRecent verification_jobs:\n";
$verifications = DB::table('verification_jobs')->orderBy('created_at', 'desc')->limit(5)->get();
foreach ($verifications as $v) {
    echo "  - ID: {$v->id}, Status: {$v->status}, Progress: {$v->progress}%, Created: {$v->created_at}\n";
}
