<?php
namespace WPLMS_S1I;

/**
 * Reset / cleanup utilities for LearnDash content.
 */
class Reset {
    /**
     * Map IdMap types to LearnDash post types.
     *
     * @return array
     */
    private function idmap_to_post_type_map() : array {
        return [
            'courses'     => 'sfwd-courses',
            'units'       => 'sfwd-lessons',
            'quizzes'     => 'sfwd-quiz',
            'assignments' => 'sfwd-assignment',
            'certificate' => 'sfwd-certificates',
        ];
    }

    /**
     * Gather IDs of posts that match scope/filter.
     *
     * @param array  $types   Post types to query.
     * @param string $scope   imported|all
     * @return array<int,int> Unique IDs
     */
    public function query_ids( array $types, string $scope = 'imported' ) : array {
        $ids = [];
        $args = [
            'post_type'      => $types,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ];
        if ( $scope === 'imported' ) {
            $args['meta_query'] = [
                'relation' => 'OR',
                [ 'key' => '_wplms_old_id', 'compare' => 'EXISTS' ],
                [ 'key' => '_hv_orphan', 'value' => '1', 'compare' => '=' ],
            ];
        }
        $ids = get_posts( $args );
        if ( $scope === 'imported' ) {
            // Add IDs from IdMap option
            $idmap = new IdMap();
            $map   = $idmap->get_all();
            $ptmap = $this->idmap_to_post_type_map();
            foreach ( $map as $imap_type => $entries ) {
                $pt = $ptmap[ $imap_type ] ?? '';
                if ( $pt && in_array( $pt, $types, true ) ) {
                    foreach ( (array) $entries as $entry ) {
                        $ids[] = (int) array_get( $entry, 'id', 0 );
                    }
                }
            }
        }
        $ids = array_map( 'intval', $ids );
        $ids = array_values( array_unique( $ids ) );
        return $ids;
    }

    /**
     * Run cleanup.
     *
     * @param array  $types             Post types.
     * @param string $scope             imported|all
     * @param bool   $force             Force delete (skip trash)
     * @param bool   $delete_attachments Delete attachments of posts
     * @param bool   $dry               Only preview
     * @return array<string,mixed> Results per type and log file path
     */
    public function run( array $types, string $scope = 'imported', bool $force = false, bool $delete_attachments = false, bool $dry = true ) : array {
        $results   = [];
        $timestamp = date( 'Ymd-His' );
        $upload    = wp_upload_dir();
        $base_dir  = trailingslashit( $upload['basedir'] ) . 'wplms-s1-importer';
        $csv_dir   = $base_dir . '/csv';
        $log_dir   = $base_dir . '/logs';
        if ( ! is_dir( $csv_dir ) ) {
            wp_mkdir_p( $csv_dir );
        }
        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
        $log_file = $log_dir . '/reset-' . $timestamp . '.log';
        $log_fp   = fopen( $log_file, 'a' );
        foreach ( $types as $type ) {
            $ids = $this->query_ids( [ $type ], $scope );
            $csv_path = $csv_dir . '/deleted-' . $type . '-' . $timestamp . '.csv';
            $csv_fp   = fopen( $csv_path, 'w' );
            fputcsv( $csv_fp, [ 'ID', 'post_type' ] );
            foreach ( $ids as $id ) {
                fputcsv( $csv_fp, [ $id, $type ] );
            }
            fclose( $csv_fp );
            if ( $dry ) {
                fwrite( $log_fp, sprintf( "%s: %d candidates\n", $type, count( $ids ) ) );
                $results[ $type ] = [
                    'count'  => count( $ids ),
                    'sample' => array_slice( $ids, 0, 10 ),
                    'csv'    => $csv_path,
                ];
                continue;
            }
            $deleted = 0;
            foreach ( $ids as $id ) {
                if ( $delete_attachments ) {
                    $attachments = get_children( [
                        'post_parent' => $id,
                        'post_type'   => 'attachment',
                        'fields'      => 'ids',
                    ] );
                    foreach ( $attachments as $att_id ) {
                        wp_delete_attachment( $att_id, $force );
                    }
                }
                wp_delete_post( $id, $force );
                $deleted++;
            }
            fwrite( $log_fp, sprintf( "%s: deleted %d\n", $type, $deleted ) );
            $results[ $type ] = [
                'count'   => $deleted,
                'csv'     => $csv_path,
            ];
        }
        fclose( $log_fp );
        $results['log'] = $log_file;
        return $results;
    }

    /**
     * Deduplicate certificates by WPLMS old ID.
     *
     * For each `_wplms_old_id` with multiple certificates, keep the lowest
     * post ID and delete the others. Updates IdMap to point each old ID to
     * the surviving certificate.
     *
     * @param bool $dry Only preview actions.
     * @return array<string,mixed> Summary including counts and log path.
     */
    public function dedupe_certificates( bool $dry = true ) : array {
        $timestamp = date( 'Ymd-His' );
        $upload    = wp_upload_dir();
        $base_dir  = trailingslashit( $upload['basedir'] ) . 'wplms-s1-importer';
        $log_dir   = $base_dir . '/logs';
        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
        $log_file = $log_dir . '/cert-dedupe-' . $timestamp . '.log';
        $log_fp   = fopen( $log_file, 'a' );

        $posts  = get_posts( [
            'post_type'      => 'sfwd-certificates',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );
        $groups = [];
        foreach ( $posts as $pid ) {
            $old = get_post_meta( $pid, '_wplms_old_id', true );
            if ( '' === $old ) {
                continue;
            }
            $groups[ (string) $old ][] = (int) $pid;
        }

        $idmap   = new IdMap();
        $results = [
            'groups'     => count( $groups ),
            'duplicates' => 0,
            'deleted'    => 0,
            'details'    => [],
            'log'        => $log_file,
        ];

        foreach ( $groups as $old_id => $ids ) {
            sort( $ids, SORT_NUMERIC );
            $keep = array_shift( $ids );
            $idmap->set( 'certificate', $old_id, $keep );
            if ( empty( $ids ) ) {
                continue;
            }
            $results['duplicates']++;
            $results['deleted'] += count( $ids );
            $results['details'][ $old_id ] = [
                'keep'    => $keep,
                'removed' => $ids,
            ];
            fwrite( $log_fp, sprintf( 'old_id %s keep %d remove %s' . "\n", $old_id, $keep, implode( ',', $ids ) ) );
            if ( ! $dry ) {
                foreach ( $ids as $del_id ) {
                    wp_delete_post( $del_id, true );
                }
            }
        }

        fclose( $log_fp );
        return $results;
    }
}
