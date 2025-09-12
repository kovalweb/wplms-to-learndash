<?php
require __DIR__ . '/test-stubs.php';
require __DIR__ . '/../wplms-s1-importer.php';

$cmd = WP_CLI::$commands['wplms-import'] ?? null;
if ( ! $cmd ) { echo "command not registered\n"; exit(1); }

$root = dirname(__DIR__, 2);
$report = $root . '/reports/IMPORT_RESULT.md';
$csv_courses_missing = $root . '/csv/post_courses_without_product_link.csv';
$csv_cert_missing   = $root . '/csv/post_certificates_missing.csv';
$csv_ou_units       = $root . '/csv/post_orphans_imported_units.csv';
$csv_ou_quizzes     = $root . '/csv/post_orphans_imported_quizzes.csv';
$csv_ou_assign      = $root . '/csv/post_orphans_imported_assignments.csv';
$csv_ou_cert        = $root . '/csv/post_orphans_imported_certificates.csv';
$files = [$report,$csv_courses_missing,$csv_cert_missing,$csv_ou_units,$csv_ou_quizzes,$csv_ou_assign,$csv_ou_cert];
$backup = [];
foreach ($files as $f) {
    $backup[$f] = file_exists($f) ? file_get_contents($f) : null;
    if (file_exists($f)) unlink($f);
}

$p1 = wp_insert_post(['post_type'=>'product','post_status'=>'publish','post_title'=>'Product','post_name'=>'product']);
update_post_meta($p1, '_sku', 'sku1');

$json_path = __DIR__ . '/sample-run.json';
$payload = [
    'mode' => 'discover_all',
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
    $cmd->run([], ['file'=>$json_path, 'run-ld-upgrades'=>true]);
} catch (Exception $e) {
    foreach ($files as $f) {
        if ($backup[$f] !== null) { file_put_contents($f, $backup[$f]); } elseif (file_exists($f)) { unlink($f); }
    }
    unlink($json_path);
    echo $e->getMessage() . "\n";
    exit(1);
}

$ok = true;
if (!file_exists($report)) { echo "report missing\n"; $ok=false; }
else {
    $md = file_get_contents($report);
    if (strpos($md, '|quizzes|success|') === false) { echo "quizzes upgrade missing\n"; $ok=false; }
    if (strpos($md, '|questions|success|') === false) { echo "questions upgrade missing\n"; $ok=false; }
}

unlink($json_path);
foreach ($files as $f) {
    if ($backup[$f] !== null) { file_put_contents($f, $backup[$f]); } elseif (file_exists($f)) { unlink($f); }
}

if (!$ok) exit(1);
echo "run upgrades flag test passed\n";
?>
