<?php
require __DIR__ . '/test-stubs.php';
require __DIR__ . '/../wplms-s1-importer.php';

$cmd = WP_CLI::$commands['wplms-import'] ?? null;
if (!$cmd) { echo "command not registered\n"; exit(1); }

$root = dirname(__DIR__, 2);
$report = $root . '/reports/IMPORT_JSON_AUDIT.md';
$csv_course = $root . '/csv/json_courses_without_product_link.csv';
$csv_orphan = $root . '/csv/json_orphans_units.csv';
$files = [$report, $csv_course, $csv_orphan];
$backup = [];
foreach ($files as $f) {
    $backup[$f] = file_exists($f) ? file_get_contents($f) : null;
    if (file_exists($f)) unlink($f);
}

$json_path = __DIR__ . '/sample-audit.json';
$payload = [
    'courses' => [
        [ 'id' => 10, 'slug' => 'course-10', 'title' => 'Course 10', 'meta' => ['has_product' => false] ],
        [ 'id' => 11, 'slug' => 'course-11', 'title' => 'Course 11', 'meta' => ['has_product' => true, 'product_id' => 5] ],
    ],
    'orphans' => [
        'units' => [ [ 'old_id' => 1, 'slug' => 'unit-1', 'title' => 'Unit 1', 'status' => 'draft', 'reason' => 't' ] ],
    ],
];
file_put_contents($json_path, json_encode($payload));

try {
    $cmd->audit_json([], ['file' => $json_path]);
} catch (Exception $e) {
    foreach ($files as $f) {
        if ($backup[$f] !== null) { file_put_contents($f, $backup[$f]); } elseif (file_exists($f)) { unlink($f); }
    }
    unlink($json_path);
    echo $e->getMessage() . "\n";
    exit(1);
}

$ok = true;
if (!file_exists($report)) { echo "report missing\n"; $ok = false; }
else {
    $md = file_get_contents($report);
    if (strpos($md, '|courses_total|2|') === false || strpos($md, '|courses_without_product_link|1|') === false || strpos($md, '|orphans_units|1|') === false) {
        echo "report contents mismatch\n"; $ok = false;
    }
}
if (!file_exists($csv_course)) { echo "course csv missing\n"; $ok = false; }
else {
    $rows = array_map('str_getcsv', file($csv_course));
    if (count($rows) < 2 || $rows[1][0] !== '10') { echo "course csv mismatch\n"; $ok = false; }
}
if (!file_exists($csv_orphan)) { echo "orphan csv missing\n"; $ok = false; }
else {
    $rows2 = array_map('str_getcsv', file($csv_orphan));
    if (count($rows2) < 2 || $rows2[1][0] !== '1') { echo "orphan csv mismatch\n"; $ok = false; }
}

unlink($json_path);
foreach ($files as $f) {
    if ($backup[$f] !== null) { file_put_contents($f, $backup[$f]); } elseif (file_exists($f)) { unlink($f); }
}

if (!$ok) exit(1);
echo "audit-json test passed\n";
?>
