<?php
namespace {
const WPLMS_S1I_OPT_IDMAP = 'wplms_s1_map';
const WPLMS_S1I_OPT_RUNSTATS = 'wplms_s1i_runstats';
const WPLMS_S1I_OPT_ENROLL_POOL = 'wplms_s1i_enrollments_pool';

require __DIR__ . '/test-stubs.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/linking.php';
require __DIR__ . '/../includes/IdMap.php';
require __DIR__ . '/../includes/Logger.php';
require __DIR__ . '/../includes/Importer.php';

use WPLMS_S1I\Logger;
use WPLMS_S1I\IdMap;
use WPLMS_S1I\Importer;

$logger = new Logger();
$idmap  = new IdMap();
$importer = new Importer( $logger, $idmap );

$payload = [
    'courses' => [
        [
            'old_id' => 1,
            'current_slug' => 'course-1',
            'post' => [ 'post_title' => 'Course 1', 'status' => 'publish' ],
        ],
        [
            'old_id' => 2,
            'current_slug' => 'course-2',
            'post' => [ 'post_title' => 'Course 2', 'status' => 'publish' ],
            'certificate_ref' => [ 'old_id' => 999 ],
        ],
    ],
];

$importer->run( $payload );
$stats = get_option( WPLMS_S1I_OPT_RUNSTATS, [] );
$ok = true;
if ( ($stats['certificates_missing'] ?? 0) !== 2 ) {
    echo "certificates_missing mismatch\n";
    $ok = false;
}
$examples = $stats['certificates_missing_examples'] ?? [];
if ( count( $examples ) !== 2 ) {
    echo "certificates_missing_examples count mismatch\n";
    $ok = false;
} else {
    $reasons = array_column( $examples, 'reason' );
    if ( $reasons[0] !== 'missing_reference' || $reasons[1] !== 'id_not_found' ) {
        echo "reasons mismatch\n";
        $ok = false;
    }
}

if ( ! $ok ) { exit(1); }
echo "certificate missing test passed\n";
}
