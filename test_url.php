<?php

require __DIR__ . '/vendor/autoload.php';

use App\Services\UrlValidationService;

// URLs to test
$urls = [
    'https://penzu.com/p/08d2f334cbee587a',
    'https://www.patreon.com/posts/creative-agency-135542937?utm_medium=clipboard_copy&utm_source=copyLink&utm_campaign=postshare_creator&utm_content=join_link',
    'https://ca.pinterest.com/pin/1047086982120576537',
    'https://wakelet.com/wake/PVLdYUyFp9eclv_vHEA4T',
    'https://docs.google.com/document/d/1N1V8awnKdCDfR4REFHsCMTkeovUlh_kP35G10BCRSQs/edit?usp=sharing',
    'https://medium.com/@chandrashekharpanwar1000/digital-marketing-agency-in-canada-dd33fae1f34c',
    'https://hackmd.io/@vlmheVbrQtqJY-HJ0mbm4Q/rJnCdPoPxg',
    'https://www.dropbox.com/scl/fi/fgas845wcvbnwr76puvyc/Untitled-3.paper?rlkey=c9r9yf1uz92pa2ewsvdy8dh19&st=8yz0w1u2&dl=0',
    'https://rehan18.substack.com/p/logo-design-near-me-fort-st-john-048',
    'https://justpaste.it/4svew',
    'https://calisthenics.mn.co/posts/94184967?utm_source=manual',
    'https://open.substack.com/pub/sagavan904m/p/social-media-marketing-fort-st-john?r=741jxk&utm_campaign=post&utm_medium=web&showWelcomeOnShare=true',
    'https://www.dropbox.com/scl/fi/31bd7iiczgmndye891guj/Untitled-16.paper?rlkey=3x7ihm008ptiepi8lacxu71yr&st=gh46k5ir&dl=0',
    'https://open.substack.com/pub/sagavan904m/p/social-media-marketing-fort-st-john-2bd?r=741jxk&utm_campaign=post&utm_medium=web&showWelcomeOnShare=true',
];

echo "=========================================\n";
echo "URL VALIDATION TEST - " . date('Y-m-d H:i:s') . "\n";
echo "=========================================\n\n";

$results = [];

foreach ($urls as $index => $testUrl) {
    echo "[" . ($index + 1) . "/" . count($urls) . "] Testing: $testUrl\n";
    echo str_repeat("-", 80) . "\n";

    // Create new instance to avoid cache
    $validator = new UrlValidationService();
    $validator->clearCache();

    // Test with analysis - looking for actual content (using null for keyword)
    $result = $validator->validate($testUrl, null);

    $status = $result['status'];
    $statusCode = $result['status_code'];
    $cannotVerify = $result['cannot_verify'] ?? false;
    $keywordFound = $result['keyword_found'] ?? false;
    $htmlFound = $result['html_found'] ?? false;
    $error = $result['error'] ?? 'none';

    echo "Status: " . $status . "\n";
    echo "Status Code: " . $statusCode . "\n";
    echo "HTML Found: " . ($htmlFound ? '1' : '0') . "\n";
    echo "Cannot Verify: " . ($cannotVerify ? '1' : '0') . "\n";
    echo "Keyword Found: " . ($keywordFound ? '1' : '0') . "\n";
    echo "Error: " . $error . "\n";

    if (!empty($result['reason'])) {
        echo "Reason: " . $result['reason'] . "\n";
    }

    $results[] = [
        'url' => $testUrl,
        'status' => $status,
        'status_code' => $statusCode,
        'cannot_verify' => $cannotVerify,
        'keyword_found' => $keywordFound,
        'html_found' => $htmlFound,
        'error' => $error,
        'reason' => $result['reason'] ?? '',
    ];

    echo "\n";
}

// Summary
echo "=========================================\n";
echo "SUMMARY\n";
echo "=========================================\n";

$counts = [
    'Working' => 0,
    'Broken' => 0,
    'Cannot Verify' => 0,
    'Redirected' => 0,
    'Invalid' => 0,
];

foreach ($results as $r) {
    $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
}

echo "\nStatus Counts:\n";
foreach ($counts as $status => $count) {
    if ($count > 0) {
        echo "  $status: $count\n";
    }
}

echo "\nDetailed Results:\n";
echo str_repeat("-", 120) . "\n";
printf("%-5s %-15s %-10s %-15s %-10s %s\n", "#", "Status", "Code", "Cannot Verify", "Has HTML", "URL");
echo str_repeat("-", 120) . "\n";
foreach ($results as $index => $r) {
    $shortUrl = strlen($r['url']) > 60 ? substr($r['url'], 0, 57) . '...' : $r['url'];
    printf("%-5d %-15s %-10s %-15s %-10s %s\n", 
        $index + 1, 
        $r['status'], 
        $r['status_code'],
        $r['cannot_verify'] ? 'Yes' : 'No',
        $r['html_found'] ? 'Yes' : 'No',
        $shortUrl
    );
}

echo "\n\nDone.\n";
