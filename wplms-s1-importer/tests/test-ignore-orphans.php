<?php
require __DIR__ . '/test-stubs.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/linking.php';
require __DIR__ . '/../includes/IdMap.php';
require __DIR__ . '/../includes/Logger.php';
require __DIR__ . '/../includes/Importer.php';

use WPLMS_S1I\Logger;
use WPLMS_S1I\IdMap;
use WPLMS_S1I\Importer;

const WPLMS_S1I_OPT_IDMAP = 'wplms_s1_map';
const WPLMS_S1I_OPT_RUNSTATS = 'wplms_s1i_runstats';
const WPLMS_S1I_OPT_ENROLL_POOL = 'wplms_s1i_enrollments_pool';

$logger   = new Logger();
$idmap    = new IdMap();
$importer = new Importer( $logger, $idmap );

$payload = [
    'mode' => 'discover_related',
    'orphans' => [
        'units' => [
            [ 'old_id' => 1, 'current_slug' => 'u1', 'post' => [ 'post_title' => 'U1' ] ],
            [ 'old_id' => 2, 'post' => [ 'post_title' => 'U2' ] ],
        ],
        'quizzes' => [
            [ 'old_id' => 3, 'post' => [ 'post_title' => 'Q1' ] ],
        ],
        'assignments' => [
            [ 'old_id' => 4, 'post' => [ 'post_title' => 'A1' ] ],
        ],
        'certificates' => [
            [ 'old_id' => 5, 'post' => [ 'post_title' => 'C1' ] ],
        ],
    ],
];

$stats = $importer->run( $payload );

$ok = true;
if ( ( $stats['ignored_orphans_in_related_mode']['units'] ?? 0 ) !== 2 ) {
    echo "ignored units count mismatch\n";
    $ok = false;
}
if ( ( $stats['ignored_orphans_in_related_mode']['quizzes'] ?? 0 ) !== 1 ) {
    echo "ignored quizzes count mismatch\n";
    $ok = false;
}
if ( ( $stats['ignored_orphans_in_related_mode']['assignments'] ?? 0 ) !== 1 ) {
    echo "ignored assignments count mismatch\n";
    $ok = false;
}
if ( ( $stats['ignored_orphans_in_related_mode']['certificates'] ?? 0 ) !== 1 ) {
    echo "ignored certificates count mismatch\n";
    $ok = false;
}
$examples_u = $stats['ignored_orphans_in_related_mode_examples']['units'] ?? [];
if ( count( $examples_u ) !== 2 || $examples_u[0] !== 'u1' || $examples_u[1] !== 'id-2' ) {
    echo "ignored units examples mismatch\n";
    $ok = false;
}
$examples_q = $stats['ignored_orphans_in_related_mode_examples']['quizzes'] ?? [];
if ( count( $examples_q ) !== 1 || $examples_q[0] !== 'id-3' ) {
    echo "ignored quizzes examples mismatch\n";
    $ok = false;
}
$examples_a = $stats['ignored_orphans_in_related_mode_examples']['assignments'] ?? [];
if ( count( $examples_a ) !== 1 || $examples_a[0] !== 'id-4' ) {
    echo "ignored assignments examples mismatch\n";
    $ok = false;
}
$examples_c = $stats['ignored_orphans_in_related_mode_examples']['certificates'] ?? [];
if ( count( $examples_c ) !== 1 || $examples_c[0] !== 'id-5' ) {
    echo "ignored certificates examples mismatch\n";
    $ok = false;
}

if ( ! $ok ) {
    exit(1);
}

echo "ignored orphans test passed\n";
