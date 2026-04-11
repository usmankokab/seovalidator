<?php

require 'vendor/autoload.php';

// Initialize Laravel
$app = require_once 'bootstrap/app.php';
$kernel = $app->make('Illuminate\Contracts\Console\Kernel');
$kernel->bootstrap();

// Test job serialization
use App\Models\VerificationJob;
use App\Jobs\ProcessWorkbookVerification;

try {
    // Create a mock VerificationJob
    $job = VerificationJob::create([
        'job_id' => 'test-' . time(),
        'status' => 'pending',
        'progress' => 0,
        'workbook_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'report_mode' => 'complete',
        'filter_values' => []
    ]);

    // Create the queue job
    $workbookJob = new ProcessWorkbookVerification(
        $job,
        storage_path('app/private/test.xlsx'),
        'test.xlsx'
    );

    // Try to serialize it
    echo "Attempting to serialize job...\n";
    $serialized = serialize($workbookJob);
    echo "✓ Serialization successful!\n";
    echo "Serialized size: " . strlen($serialized) . " bytes\n";

    // Try to unserialize it
    echo "\nAttempting to unserialize job...\n";
    $unserialized = unserialize($serialized);
    echo "✓ Unserialization successful!\n";

    echo "\n✓✓✓ Queue serialization test PASSED!\n";
    
    // Cleanup
    $job->delete();

} catch (\Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
