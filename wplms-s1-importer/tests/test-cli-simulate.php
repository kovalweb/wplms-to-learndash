<?php
require __DIR__ . '/test-stubs.php';
require __DIR__ . '/../wplms-s1-importer.php';

$cmd = WP_CLI::$commands['wplms-s1'] ?? null;
if (!$cmd) { echo "command not registered\n"; exit(1); }

$root = dirname(__DIR__, 2);
$report = $root . '/reports/IMPORT_PLAN.md';
$csv_courses = $root . '/csv/plan_courses_linking.csv';
$csv_orphan = $root . '/csv/plan_orphans_units.csv';
$csv_orphan_a = $root . '/csv/plan_orphans_assignments.csv';
$csv_orphan_q = $root . '/csv/plan_orphans_quizzes.csv';
$csv_orphan_c = $root . '/csv/plan_orphans_certificates.csv';
$files = [$report, $csv_courses, $csv_orphan, $csv_orphan_a, $csv_orphan_q, $csv_orphan_c];
$backup = [];
foreach ($files as $f) {
    $backup[$f] = file_exists($f) ? file_get_contents($f) : null;
    if (file_exists($f)) unlink($f);
}

$json_path = __DIR__ . '/sample-simulate.json';
$payload = [
    'courses' => [
        [ 'old_id' => 1, 'current_slug' => 'course-a', 'post' => ['post_title' => 'Course A', 'status' => 'publish'] ],
    ],
    'orphans' => [
        'units' => [ [ 'old_id' => 100, 'current_slug' => 'orphan-u', 'post' => ['post_title' => 'Orphan U'] ] ],
    ],
];
file_put_contents($json_path, json_encode($payload));

try {
    $cmd->simulate([], ['file' => $json_path]);
} catch (Exception $e) {
    foreach ($files as $f) {
        if ($backup[$f] !== null) { file_put_contents($f, $backup[$f]); } elseif (file_exists($f)) { unlink($f); }
    }
    unlink($json_path);
    echo $e->getMessage() . "\n";
    exit(1);
}

$ok = true;
if (!file_exists($report)) { echo "plan report missing\n"; $ok = false; }
else {
    $md = file_get_contents($report);
    if (strpos($md, '|courses|1|0|0|') === false || strpos($md, '|orphans_units|1|0|0|') === false) {
        echo "plan report contents mismatch\n"; $ok = false;
    }
}
if (!file_exists($csv_courses)) { echo "plan courses csv missing\n"; $ok = false; }
else {
    $rows = array_map('str_getcsv', file($csv_courses));
    if (count($rows) < 2 || $rows[1][0] !== '1' || $rows[1][3] !== 'create' || $rows[1][6] !== 'not_found') {
        echo "plan courses csv mismatch\n"; $ok = false;
    }
}
if (!file_exists($csv_orphan)) { echo "plan orphans csv missing\n"; $ok = false; }
else {
    $rows2 = array_map('str_getcsv', file($csv_orphan));
    if (count($rows2) < 2 || $rows2[1][0] !== '100' || $rows2[1][3] !== 'create') {
        echo "plan orphans csv mismatch\n"; $ok = false;
    }
}

unlink($json_path);
foreach ($files as $f) {
    if ($backup[$f] !== null) { file_put_contents($f, $backup[$f]); } elseif (file_exists($f)) { unlink($f); }
}

if (!$ok) exit(1);
echo "simulate test passed\n";
?>
