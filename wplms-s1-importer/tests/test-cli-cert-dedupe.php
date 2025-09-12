<?php
require __DIR__ . '/test-stubs.php';
function wp_delete_post( $id, $force = false ) { unset( $GLOBALS['posts'][$id] ); return true; }
require __DIR__ . '/../wplms-s1-importer.php';

$cmd = WP_CLI::$commands['wplms-import dedupe-certs'] ?? null;
if ( ! $cmd ) { echo "command not registered\n"; exit(1); }

$id1 = wp_insert_post( [ 'post_type' => 'sfwd-certificates', 'post_status' => 'publish', 'post_title' => 'Cert1' ] );
update_post_meta( $id1, '_wplms_old_id', 10 );
$id2 = wp_insert_post( [ 'post_type' => 'sfwd-certificates', 'post_status' => 'publish', 'post_title' => 'Cert2' ] );
update_post_meta( $id2, '_wplms_old_id', 10 );
$id3 = wp_insert_post( [ 'post_type' => 'sfwd-certificates', 'post_status' => 'publish', 'post_title' => 'Cert3' ] );
update_post_meta( $id3, '_wplms_old_id', 20 );
$id4 = wp_insert_post( [ 'post_type' => 'sfwd-certificates', 'post_status' => 'publish', 'post_title' => 'Cert4' ] );
update_post_meta( $id4, '_wplms_old_id', 20 );

call_user_func( $cmd, [], [] );

$ok = true;
if ( isset( $GLOBALS['posts'][ $id2 ] ) ) { echo "duplicate 2 not deleted\n"; $ok = false; }
if ( isset( $GLOBALS['posts'][ $id4 ] ) ) { echo "duplicate 4 not deleted\n"; $ok = false; }
$map = get_option( WPLMS_S1I_OPT_IDMAP );
if ( ( $map['certificate']['10']['id'] ?? 0 ) !== $id1 ) { echo "idmap old_id 10 mismatch\n"; $ok = false; }
if ( ( $map['certificate']['20']['id'] ?? 0 ) !== $id3 ) { echo "idmap old_id 20 mismatch\n"; $ok = false; }
if ( ! $ok ) exit(1);
echo "cert dedupe CLI test passed\n";
?>
