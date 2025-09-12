<?php
require __DIR__ . '/test-stubs.php';
require __DIR__ . '/../wplms-s1-importer.php';

$cmd = WP_CLI::$commands['wplms-import'] ?? null;
if ( ! $cmd ) {
    echo "command not registered\n";
    exit(1);
}

$root   = dirname( __DIR__, 2 );
$report = $root . '/reports/IMPORT_RESULT.md';
$files  = [ $report ];
$backup = [];
foreach ( $files as $f ) {
    $backup[ $f ] = file_exists( $f ) ? file_get_contents( $f ) : null;
    if ( file_exists( $f ) ) {
        unlink( $f );
    }
}

$payload = [
    'mode'    => 'discover_related',
    'orphans' => [
        'units'   => [ [ 'old_id' => 1, 'post' => [ 'post_title' => 'U1' ] ] ],
        'quizzes' => [ [ 'old_id' => 2, 'post' => [ 'post_title' => 'Q1' ] ] ],
    ],
];
$json_path = __DIR__ . '/sample-ignore.json';
file_put_contents( $json_path, json_encode( $payload ) );

$ok = true;
try {
    $cmd->run( [], [ 'file' => $json_path ] );
} catch ( Exception $e ) {
    echo $e->getMessage() . "\n";
    $ok = false;
}

if ( ! file_exists( $report ) ) {
    echo "report missing\n";
    $ok = false;
} else {
    $md = file_get_contents( $report );
    if (
        strpos( $md, '## ignored_orphans_in_related_mode' ) === false ||
        strpos( $md, '|units|1|' ) === false ||
        strpos( $md, '|quizzes|1|' ) === false
    ) {
        echo "ignored orphans table mismatch\n";
        $ok = false;
    }
}

unlink( $json_path );
foreach ( $files as $f ) {
    if ( $backup[ $f ] !== null ) {
        file_put_contents( $f, $backup[ $f ] );
    } elseif ( file_exists( $f ) ) {
        unlink( $f );
    }
}

if ( ! $ok ) {
    exit(1);
}

echo "cli ignore orphans report test passed\n";
