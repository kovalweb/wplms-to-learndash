<?php
namespace WPLMS_S1I;

/**
 * WP-CLI commands for cleanup.
 */
class CLI {
    public static function register() {
        \WP_CLI::add_command( 'wplms-import reset', [ __CLASS__, 'cmd_reset' ] );
        \WP_CLI::add_command( 'wplms-import dedupe-certs', [ __CLASS__, 'cmd_dedupe_certs' ] );
    }

    public static function cmd_reset( $args, $assoc ) {
        $types_arg = $assoc['types'] ?? '';
        if ( ! $types_arg ) {
            \WP_CLI::error( 'Use --types=courses,lessons,quizzes' );
        }
        $input_types = array_filter( array_map( 'trim', explode( ',', $types_arg ) ) );
        if ( ! $input_types ) {
            \WP_CLI::error( 'No types specified.' );
        }
        $types = [];
        foreach ( $input_types as $t ) {
            $types[] = self::map_type_to_post_type( $t );
        }
        $scope = $assoc['scope'] ?? 'imported';
        $force = isset( $assoc['force'] );
        $del_att = isset( $assoc['delete-attachments'] );
        $dry = isset( $assoc['dry-run'] );
        $reset = new Reset();
        $result = $reset->run( $types, $scope, $force, $del_att, $dry );
        foreach ( $types as $pt ) {
            $info = $result[ $pt ] ?? [];
            \WP_CLI::log( sprintf( '%s: %d', $pt, $info['count'] ?? 0 ) );
            if ( isset( $info['csv'] ) ) {
                \WP_CLI::log( 'CSV: ' . $info['csv'] );
            }
        }
        \WP_CLI::log( 'Log: ' . ( $result['log'] ?? '' ) );
        \WP_CLI::success( $dry ? 'Dry run complete.' : 'Cleanup complete.' );
    }

    public static function cmd_dedupe_certs( $args, $assoc ) {
        $dry   = isset( $assoc['dry-run'] );
        $reset = new Reset();
        $result = $reset->dedupe_certificates( $dry );
        foreach ( $result['details'] as $old_id => $info ) {
            \WP_CLI::log( sprintf( 'old_id %s keep %d remove %s', $old_id, $info['keep'], implode( ',', $info['removed'] ) ) );
        }
        \WP_CLI::log( sprintf( 'groups: %d duplicates: %d deleted: %d', $result['groups'], $result['duplicates'], $result['deleted'] ) );
        \WP_CLI::log( 'Log: ' . ( $result['log'] ?? '' ) );
        \WP_CLI::success( $dry ? 'Dry run complete.' : 'Certificate dedupe complete.' );
    }

    private static function map_type_to_post_type( string $type ) : string {
        $map = [
            'courses'     => 'sfwd-courses',
            'lessons'     => 'sfwd-lessons',
            'topics'      => 'sfwd-topic',
            'quizzes'     => 'sfwd-quiz',
            'questions'   => 'sfwd-question',
            'assignments' => 'sfwd-assignment',
            'certificates'=> 'sfwd-certificates',
            'groups'      => 'groups',
        ];
        return $map[ $type ] ?? $type;
    }
}
