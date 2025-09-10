<?php
require __DIR__ . '/test-stubs.php';
require __DIR__ . '/../wplms-s1-importer.php';

$cmd = WP_CLI::$commands['wplms-import'] ?? null;
if (!$cmd) { echo "command not registered\n"; exit(1); }

$root = dirname(__DIR__, 2);
$report = $root . '/reports/IMPORT_RESULT.md';
$csv_courses_missing = $root . '/csv/post_courses_without_product_link.csv';
$csv_cert_missing = $root . '/csv/post_certificates_missing.csv';
$csv_ou_units = $root . '/csv/post_orphans_imported_units.csv';
$csv_ou_quizzes = $root . '/csv/post_orphans_imported_quizzes.csv';
$csv_ou_assign = $root . '/csv/post_orphans_imported_assignments.csv';
$csv_ou_cert = $root . '/csv/post_orphans_imported_certificates.csv';
$files = [$report, $csv_courses_missing, $csv_cert_missing, $csv_ou_units, $csv_ou_quizzes, $csv_ou_assign, $csv_ou_cert];
$backup = [];
foreach ($files as $f) {
    $backup[$f] = file_exists($f) ? file_get_contents($f) : null;
    if (file_exists($f)) unlink($f);
}

// preset product
$p1 = wp_insert_post(['post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Product', 'post_name' => 'product']);
update_post_meta($p1, '_sku', 'sku1');

$json_path = __DIR__ . '/sample-run.json';
$payload = [
    'courses' => [
        [
            'old_id' => 1,
            'current_slug' => 'course-a',
            'post' => ['post_title' => 'Course A', 'status' => 'publish'],
            'commerce' => ['product_sku' => 'sku1'],
        ],
    ],
];
file_put_contents($json_path, json_encode($payload));

try {
    $cmd->run([], ['file' => $json_path]);
} catch (Exception $e) {
    foreach ($files as $f) {
        if ($backup[$f] !== null) { file_put_contents($f, $backup[$f]); } elseif (file_exists($f)) { unlink($f); }
    }
    unlink($json_path);
    echo $e->getMessage() . "\n";
    exit(1);
}

$ok = true;
if (!file_exists($report)) { echo "result report missing\n"; $ok = false; }
else {
    $md1 = file_get_contents($report);
    if (strpos($md1, '|courses_created|1|') === false) { echo "first run report mismatch\n"; $ok = false; }
}
if (!file_exists($csv_courses_missing)) { echo "courses_missing csv missing\n"; $ok = false; }
else {
    $lines = array_filter(array_map('trim', file($csv_courses_missing)));
    if (count($lines) !== 1) { echo "courses_missing csv not empty\n"; $ok = false; }
}

// second run should produce zero creations
$cmd->run([], ['file' => $json_path]);
if (!file_exists($report)) { echo "second report missing\n"; $ok = false; }
else {
    $md2 = file_get_contents($report);
    if (strpos($md2, '|courses_created|0|') === false) { echo "second run not idempotent\n"; $ok = false; }
}

unlink($json_path);
foreach ($files as $f) {
    if ($backup[$f] !== null) { file_put_contents($f, $backup[$f]); } elseif (file_exists($f)) { unlink($f); }
}

if (!$ok) exit(1);
echo "run command test passed\n";
?>
