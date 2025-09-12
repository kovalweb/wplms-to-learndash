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

// Pre-create certificate with old_id 200
$cert_id = wp_insert_post( [ 'post_type' => 'sfwd-certificates', 'post_status' => 'publish', 'post_title' => 'Cert 200' ] );
update_post_meta( $cert_id, '_wplms_old_id', 200 );

$payload = [
    'courses' => [
        [
            'old_id' => 688,
            'current_slug' => 'course-688',
            'post' => [ 'post_title' => 'Course 688', 'status' => 'publish' ],
            'meta' => [ 'vibe_certificate_template' => 200 ],
        ],
    ],
];

$importer->run( $payload );

$course_new_id = $idmap->get( 'courses', 688 );
$cert_attached = get_post_meta( $course_new_id, 'certificate', true );
$stats = get_option( WPLMS_S1I_OPT_RUNSTATS, [] );
$log_contents = file_get_contents( $logger->path() );

$ok = true;
if ( (int) $cert_attached !== $cert_id ) {
    echo "certificate not attached\n";
    $ok = false;
}
if ( ($stats['certificates_attached'] ?? 0) !== 1 ) {
    echo "certificates_attached mismatch\n";
    $ok = false;
}
if ( strpos( $log_contents, '"source":"meta_fallback"' ) === false ) {
    echo "log missing meta_fallback\n";
    $ok = false;
}

if ( ! $ok ) { exit(1); }
echo "certificate meta fallback test passed\n";
}
