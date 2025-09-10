<?php
/**
 * Plugin Name: WPLMS S1 Importer
 * Description: Importer for migrating content from WPLMS S1 Exporter JSON into LearnDash (sfwd-* post types).
 * Version: 1.0.0
 * Author: Specia1ne
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// -----------------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------------
if ( ! defined( 'WPLMS_S1I_VER' ) ) {
    define( 'WPLMS_S1I_VER', '1.0.0' );
}
if ( ! defined( 'WPLMS_S1I_FILE' ) ) {
    define( 'WPLMS_S1I_FILE', __FILE__ );
}
if ( ! defined( 'WPLMS_S1I_DIR' ) ) {
    define( 'WPLMS_S1I_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WPLMS_S1I_URL' ) ) {
    define( 'WPLMS_S1I_URL', plugin_dir_url( __FILE__ ) );
}

// Options keys
const WPLMS_S1I_OPT_IDMAP       = 'wplms_s1_map';
const WPLMS_S1I_OPT_RUNSTATS    = 'wplms_s1i_runstats';
const WPLMS_S1I_OPT_ENROLL_POOL = 'wplms_s1i_enrollments_pool';

// Autoload classes and helpers
require_once WPLMS_S1I_DIR . 'includes/autoload.php';
require_once WPLMS_S1I_DIR . 'includes/helpers.php';
require_once WPLMS_S1I_DIR . 'includes/linking.php';

// Hide payment buttons if linked product is not published
add_filter( 'learndash_payment_buttons', function ( $html, $course_id ) {
    $product_id = \WPLMS_S1I\hv_get_linked_product_id_for_course( $course_id );
    if ( $product_id && 'publish' !== get_post_status( $product_id ) ) {
        return '';
    }
    return $html;
}, 10, 2 );

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------
add_action( 'init', function () {
    // Ensure LearnDash CPTs exist (plugin can still create posts even if LD inactive, but recommended to activate LD first)
    // Minimal guard: if post type absent, register a placeholder so posts can be created.
    $needed = [ 'sfwd-courses', 'sfwd-lessons', 'sfwd-quiz', 'sfwd-assignment', 'sfwd-certificates' ];
    // Re-check after other plugins have registered their post types.
    $missing = array_filter( $needed, function ( $pt ) {
        return ! post_type_exists( $pt );
    } );
    if ( $missing ) {
        foreach ( $missing as $pt ) {
            register_post_type( $pt, [
                'label' => strtoupper( $pt ),
                'public' => true,
                'show_ui' => true,
                'supports' => [ 'title','editor','thumbnail','page-attributes' ],
            ] );
        }
    }
}, 20 );

add_action( 'init', function () {
    // Admin UI
    if ( is_admin() ) {
        $admin = new \WPLMS_S1I\Admin();
        $admin->hooks();
    }
} );

// Optional: WP‑CLI command for local usage: wp wplms-s1 import --file=<path.json> [--dry]
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WP_CLI::add_command( 'wplms-s1', new class {
        /**
         * Import from a JSON exported by WPLMS S1 Exporter.
         *
         * ## OPTIONS
         *
         * --file=<path>
         * : Absolute path to JSON file.
         *
         * [--dry]
         * : Analyze only; do not create posts.
         */
        public function import( $args, $assoc ) {
            $path = $assoc['file'] ?? '';
            if ( ! $path ) {
                \WP_CLI::error( 'Missing --file parameter.' );
            }
            if ( ! file_exists( $path ) ) {
                \WP_CLI::error( 'File not found.' );
            }
            $payload = json_decode( file_get_contents( $path ), true );
            if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
                \WP_CLI::error( 'Failed to decode JSON: ' . json_last_error_msg() );
            }

            $dry      = isset( $assoc['dry'] );
            $logger   = new \WPLMS_S1I\Logger();
            $idmap    = new \WPLMS_S1I\IdMap();
            $importer = new \WPLMS_S1I\Importer( $logger, $idmap );
            $importer->set_dry_run( $dry );
            try {
                $stats = $importer->run( $payload );
                \WP_CLI::success( sprintf(
                    'Media: %d %d %d',
                    $stats['images_downloaded'] ?? 0,
                    $stats['images_skipped_empty'] ?? 0,
                    $stats['images_errors'] ?? 0
                ) );
                \WP_CLI::log( 'Log file: ' . $logger->path() );
            } catch ( \Throwable $e ) {
                \WP_CLI::error( $e->getMessage() );
            }
        }
    } );
    \WP_CLI::add_command( 'wplms2ld', new class {
        public function dedupe_certificates() {
            $idmap   = new \WPLMS_S1I\IdMap();
            $summary = \WPLMS_S1I\cleanup_duplicate_certificates( $idmap );
            \WP_CLI::success( sprintf( 'Groups: %d, deleted: %d', (int) \WPLMS_S1I\array_get( $summary, 'groups', 0 ), (int) \WPLMS_S1I\array_get( $summary, 'deleted', 0 ) ) );
        }
    } );
    \WP_CLI::add_command( 'wplms-import', new class {
        /**
         * Audit an export JSON and generate reports.
         *
         * ## OPTIONS
         *
         * --file=<path>
         * : Absolute path to JSON file to audit.
         */
        public function audit_json( $args, $assoc ) {
            $path = $assoc['file'] ?? '';
            if ( ! $path ) {
                \WP_CLI::error( 'Missing --file parameter.' );
            }
            if ( ! file_exists( $path ) ) {
                \WP_CLI::error( 'File not found.' );
            }
            $data = json_decode( file_get_contents( $path ), true );
            if ( ! is_array( $data ) ) {
                \WP_CLI::error( 'Invalid JSON.' );
            }

            $courses   = is_array( $data['courses'] ?? null ) ? $data['courses'] : [];
            $orphans   = is_array( $data['orphans'] ?? null ) ? $data['orphans'] : [];
            $root_dir  = dirname( WPLMS_S1I_DIR );
            $csv_dir   = $root_dir . '/csv';
            $report_dir = $root_dir . '/reports';
            if ( ! is_dir( $csv_dir ) ) {
                wp_mkdir_p( $csv_dir );
            }
            if ( ! is_dir( $report_dir ) ) {
                wp_mkdir_p( $report_dir );
            }

            $courses_with_product    = 0;
            $courses_without_product = [];
            foreach ( $courses as $course ) {
                $has_product = ! empty( $course['meta']['has_product'] ) && ! empty( $course['meta']['product_id'] );
                if ( $has_product ) {
                    $courses_with_product++;
                } else {
                    $courses_without_product[] = [
                        'id'    => $course['id'] ?? ( $course['old_id'] ?? '' ),
                        'slug'  => $course['slug'] ?? '',
                        'title' => $course['title'] ?? '',
                    ];
                }
            }
            $courses_total       = count( $courses );
            $courses_without_cnt = $courses_total - $courses_with_product;

            // Metrics and report
            $metrics = [
                'courses_total'               => $courses_total,
                'courses_with_product_link'   => $courses_with_product,
                'courses_without_product_link'=> $courses_without_cnt,
            ];
            foreach ( $orphans as $type => $items ) {
                if ( is_array( $items ) ) {
                    $metrics[ 'orphans_' . $type ] = count( $items );
                }
            }

            $report = "# Import JSON Audit\n\n|Metric|Count|\n|---|---|\n";
            foreach ( $metrics as $key => $val ) {
                $report .= sprintf( "|%s|%d|\n", $key, $val );
            }
            file_put_contents( $report_dir . '/IMPORT_JSON_AUDIT.md', $report );

            // CSV for courses without product link
            $fh = fopen( $csv_dir . '/json_courses_without_product_link.csv', 'w' );
            if ( $fh ) {
                fputcsv( $fh, [ 'id', 'slug', 'title' ] );
                foreach ( $courses_without_product as $row ) {
                    fputcsv( $fh, $row );
                }
                fclose( $fh );
            }

            // CSVs for orphans
            foreach ( $orphans as $type => $items ) {
                if ( ! is_array( $items ) ) {
                    continue;
                }
                $path = sprintf( '%s/json_orphans_%s.csv', $csv_dir, $type );
                $fh   = fopen( $path, 'w' );
                if ( ! $fh ) {
                    continue;
                }
                fputcsv( $fh, [ 'old_id', 'slug', 'title', 'status', 'reason', 'edit_link' ] );
                foreach ( $items as $item ) {
                    fputcsv( $fh, [
                        $item['old_id'] ?? '',
                        $item['slug'] ?? '',
                        $item['title'] ?? '',
                        $item['status'] ?? '',
                        $item['reason'] ?? '',
                        $item['edit_link'] ?? '',
                    ] );
                }
                fclose( $fh );
            }

            \WP_CLI::success( 'Audit complete' );
        }
    } );
}

