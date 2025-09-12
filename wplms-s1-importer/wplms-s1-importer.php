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

// Hide payment buttons if linked product is not published or lacks a price
add_filter( 'learndash_payment_buttons', function ( $html, $course_id ) {
    $product_id = \WPLMS_S1I\hv_get_linked_product_id_for_course( $course_id );
    if ( ! $product_id ) {
        return $html;
    }

    $status = get_post_status( $product_id );
    // Look for any numeric price meta value.
    $price = get_post_meta( $product_id, '_price', true );
    if ( '' === $price || ! is_numeric( $price ) ) {
        $price = get_post_meta( $product_id, '_sale_price', true );
    }
    if ( '' === $price || ! is_numeric( $price ) ) {
        $price = get_post_meta( $product_id, '_regular_price', true );
    }

    if ( 'publish' !== $status || ! is_numeric( $price ) ) {
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
        $reset_admin = new \WPLMS_S1I\ResetAdmin();
        $reset_admin->hooks();
    }
} );

// Optional: WP‑CLI commands
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WPLMS_S1I\CLI::register();
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
         *
         * [--run-ld-upgrades]
         * : Run LearnDash data-upgrade routines after import.

         * [--import-orphan-certificates]
         * : Import certificates not referenced by selected courses.
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

            $dry             = isset( $assoc['dry'] );
            $run_ld_upgrades = isset( $assoc['run-ld-upgrades'] );
            if ( function_exists( 'WP_CLI\\Utils\\get_flag_value' ) ) {
                $import_orphans = \WP_CLI\Utils\get_flag_value( $assoc, 'import-orphan-certificates', null );
            } else {
                $import_orphans = array_key_exists( 'import-orphan-certificates', $assoc ) ? (bool) $assoc['import-orphan-certificates'] : null;
            }
            $logger          = new \WPLMS_S1I\Logger();
            $idmap           = new \WPLMS_S1I\IdMap();
            $importer        = new \WPLMS_S1I\Importer( $logger, $idmap );
            $importer->set_dry_run( $dry );
            if ( null !== $import_orphans ) {
                $importer->set_import_orphan_certificates( $import_orphans );
            }
            try {
                $stats = $importer->run( $payload );
                $cl    = (array) ( $stats['commerce_linking_preflight'] ?? [] );
                if ( $cl ) {
                    $sell = (int) ( $cl['sellable_courses'] ?? 0 );
                    $uns  = array_sum( (array) ( $cl['unsellable_reasons'] ?? [] ) );
                    \WP_CLI::log( sprintf( 'Preflight sellable=%d unsellable=%d', $sell, $uns ) );
                    foreach ( (array) ( $cl['unsellable_examples'] ?? [] ) as $ex ) {
                        \WP_CLI::log( sprintf( ' - %s (%s)', $ex['course_slug'] ?? '', $ex['reason'] ?? '' ) );
                    }
                }
                if ( $run_ld_upgrades ) {
                    $callbacks = [
                        'quizzes'   => 'learndash_data_upgrades_quizzes',
                        'questions' => 'learndash_data_upgrades_questions',
                    ];
                    foreach ( $callbacks as $label => $fn ) {
                        if ( function_exists( $fn ) ) {
                            try {
                                $fn();
                                \WP_CLI::log( sprintf( 'LearnDash upgrade %s: success', $label ) );
                            } catch ( \Throwable $e ) {
                                \WP_CLI::warning( sprintf( 'LearnDash upgrade %s error: %s', $label, $e->getMessage() ) );
                            }
                        } else {
                            \WP_CLI::warning( sprintf( 'LearnDash upgrade %s missing function', $label ) );
                        }
                    }
                }
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

        /**
         * Simulate an import and output planned actions.
         *
         * ## OPTIONS
         *
         * --file=<path>
         * : Absolute path to JSON file.
         *
         * [--import-orphan-certificates]
         * : Import certificates not referenced by selected courses.
         */
        public function simulate( $args, $assoc ) {
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

            if ( function_exists( 'WP_CLI\\Utils\\get_flag_value' ) ) {
                $import_orphans = \WP_CLI\Utils\get_flag_value( $assoc, 'import-orphan-certificates', null );
            } else {
                $import_orphans = array_key_exists( 'import-orphan-certificates', $assoc ) ? (bool) $assoc['import-orphan-certificates'] : null;
            }
            $logger   = new \WPLMS_S1I\Logger();
            $idmap    = new \WPLMS_S1I\IdMap();
            $importer = new \WPLMS_S1I\Importer( $logger, $idmap );
            $importer->set_dry_run( true );
            if ( null !== $import_orphans ) {
                $importer->set_import_orphan_certificates( $import_orphans );
            }
            try {
                $stats = $importer->run( $payload );
                $cl    = (array) ( $stats['commerce_linking_preflight'] ?? [] );
                if ( $cl ) {
                    $sell = (int) ( $cl['sellable_courses'] ?? 0 );
                    $uns  = array_sum( (array) ( $cl['unsellable_reasons'] ?? [] ) );
                    \WP_CLI::log( sprintf( 'Preflight sellable=%d unsellable=%d', $sell, $uns ) );
                    foreach ( (array) ( $cl['unsellable_examples'] ?? [] ) as $ex ) {
                        \WP_CLI::log( sprintf( ' - %s (%s)', $ex['course_slug'] ?? '', $ex['reason'] ?? '' ) );
                    }
                }
            } catch ( \Throwable $e ) {
                \WP_CLI::error( $e->getMessage() );
            }

            $root_dir   = dirname( WPLMS_S1I_DIR );
            $csv_dir    = $root_dir . '/csv';
            $report_dir = $root_dir . '/reports';
            if ( ! is_dir( $csv_dir ) ) {
                wp_mkdir_p( $csv_dir );
            }
            if ( ! is_dir( $report_dir ) ) {
                wp_mkdir_p( $report_dir );
            }

            $counts = [
                'courses'             => [ 'create' => 0, 'update' => 0, 'skip' => 0 ],
                'orphans_units'       => [ 'create' => 0, 'update' => 0, 'skip' => 0 ],
                'orphans_quizzes'     => [ 'create' => 0, 'update' => 0, 'skip' => 0 ],
                'orphans_assignments' => [ 'create' => 0, 'update' => 0, 'skip' => 0 ],
                'orphans_certificates'=> [ 'create' => 0, 'update' => 0, 'skip' => 0 ],
            ];

            $courses      = (array) \WPLMS_S1I\array_get( $payload, 'courses', [] );
            $course_rows  = [];
            $link_reasons = [];

            foreach ( $courses as $course ) {
                $old_id = (int) \WPLMS_S1I\array_get( $course, 'old_id', 0 );
                $slug   = \WPLMS_S1I\normalize_slug( \WPLMS_S1I\array_get( $course, 'current_slug', \WPLMS_S1I\array_get( $course, 'post.post_name', '' ) ) );
                $title  = \WPLMS_S1I\array_get( $course, 'title', \WPLMS_S1I\array_get( $course, 'post.post_title', '' ) );
                $existing = $idmap->get( 'courses', $old_id );
                if ( ! $existing && $slug ) {
                    $f = get_posts( [
                        'post_type'   => 'sfwd-courses',
                        'name'        => $slug,
                        'post_status' => 'any',
                        'numberposts' => 1,
                        'fields'      => 'ids',
                    ] );
                    if ( $f ) {
                        $existing = (int) $f[0];
                    }
                }
                if ( ! $slug ) {
                    $action = 'skip';
                } elseif ( $existing ) {
                    $action = 'update';
                } else {
                    $action = 'create';
                }
                $counts['courses'][ $action ]++;

                $sku        = \WPLMS_S1I\array_get( $course, 'commerce.product_sku', '' );
                $product_id = 0;
                $reason     = 'none';
                if ( $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
                    $pid = (int) \wc_get_product_id_by_sku( $sku );
                    if ( $pid ) {
                        $product_id = $pid;
                        $reason     = 'sku';
                    } else {
                        $reason = 'sku_not_found';
                    }
                }
                if ( ! $product_id ) {
                    $old_pid = (int) \WPLMS_S1I\array_get( $course, 'meta.product_id', 0 );
                    if ( $old_pid ) {
                        $found = get_posts( [
                            'post_type'   => 'product',
                            'post_status' => 'any',
                            'meta_key'    => '_wplms_old_product_id',
                            'meta_value'  => $old_pid,
                            'fields'      => 'ids',
                            'numberposts' => 1,
                        ] );
                        if ( $found ) {
                            $product_id = (int) $found[0];
                            $reason     = 'old_id';
                        }
                    }
                }
                if ( ! $product_id && $slug ) {
                    $found = get_posts( [
                        'post_type'   => 'product',
                        'post_status' => 'any',
                        'name'        => $slug,
                        'fields'      => 'ids',
                        'numberposts' => 1,
                    ] );
                    if ( $found ) {
                        $product_id = (int) $found[0];
                        $reason     = 'slug';
                    }
                }
                if ( ! $product_id && $title ) {
                    $page = get_page_by_title( $title, OBJECT, 'product' );
                    if ( $page ) {
                        $product_id = (int) $page->ID;
                        $reason     = 'title';
                    }
                }
                if ( ! $product_id && $reason === 'none' ) {
                    $reason = 'not_found';
                }
                $status = $product_id ? get_post_status( $product_id ) : '';

                $link_reasons[ $reason ] = ( $link_reasons[ $reason ] ?? 0 ) + 1;
                $course_rows[] = [ $old_id, $slug, $title, $action, $product_id, $status, $reason ];
            }

            $fh = fopen( $csv_dir . '/plan_courses_linking.csv', 'w' );
            if ( $fh ) {
                fputcsv( $fh, [ 'old_id', 'slug', 'title', 'action', 'product_id', 'product_status', 'reason' ] );
                foreach ( $course_rows as $row ) {
                    fputcsv( $fh, $row );
                }
                fclose( $fh );
            }

            $orphans = (array) \WPLMS_S1I\array_get( $payload, 'orphans', [] );
            $map_orphans = [
                'units'        => 'sfwd-lessons',
                'quizzes'      => 'sfwd-quiz',
                'assignments'  => 'sfwd-assignment',
                'certificates' => 'sfwd-certificates',
            ];

            foreach ( $map_orphans as $key => $ptype ) {
                $rows = [];
                foreach ( (array) \WPLMS_S1I\array_get( $orphans, $key, [] ) as $item ) {
                    $old_id = (int) \WPLMS_S1I\array_get( $item, 'old_id', 0 );
                    $slug   = \WPLMS_S1I\normalize_slug( \WPLMS_S1I\array_get( $item, 'current_slug', \WPLMS_S1I\array_get( $item, 'slug', \WPLMS_S1I\array_get( $item, 'post.post_name', '' ) ) ) );
                    $title  = \WPLMS_S1I\array_get( $item, 'title', \WPLMS_S1I\array_get( $item, 'post.post_title', '' ) );
                    $map_key = $key === 'units' ? 'units' : $key;
                    $existing = $idmap->get( $map_key, $old_id );
                    if ( ! $existing && $slug ) {
                        $f = get_posts( [
                            'post_type'   => $ptype,
                            'name'        => $slug,
                            'post_status' => 'any',
                            'numberposts' => 1,
                            'fields'      => 'ids',
                        ] );
                        if ( $f ) {
                            $existing = (int) $f[0];
                        }
                    }
                    if ( ! $slug ) {
                        $action = 'skip';
                    } elseif ( $existing ) {
                        $action = 'update';
                    } else {
                        $action = 'create';
                    }
                    $counts[ 'orphans_' . $key ][ $action ]++;
                    $rows[] = [ $old_id, $slug, $title, $action ];
                }
                $fh = fopen( $csv_dir . '/plan_orphans_' . $key . '.csv', 'w' );
                if ( $fh ) {
                    fputcsv( $fh, [ 'old_id', 'slug', 'title', 'action' ] );
                    foreach ( $rows as $r ) {
                        fputcsv( $fh, $r );
                    }
                    fclose( $fh );
                }
            }

            $report = "# Import Plan\n\n|Entity|Create|Update|Skip|\n|---|---|---|---|\n";
            foreach ( $counts as $entity => $c ) {
                $report .= sprintf( "|%s|%d|%d|%d|\n", $entity, $c['create'], $c['update'], $c['skip'] );
            }
            $report .= "\n## Product Linking\n\n|Reason|Count|\n|---|---|\n";
            foreach ( $link_reasons as $reason => $cnt ) {
                $report .= sprintf( "|%s|%d|\n", $reason, $cnt );
            }
            file_put_contents( $report_dir . '/IMPORT_PLAN.md', $report );

            \WP_CLI::success( 'Simulation complete' );
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

        /**
         * Run the importer from a JSON file and generate post-import reports.
         *
         * ## OPTIONS
         *
         * --file=<path>
         * : Absolute path to JSON file to import.
         *
         * [--no-emails]
         * : Suppress email and notification hooks during import.
         *
         * [--run-ld-upgrades]
         * : Run LearnDash data-upgrade routines after import.
         */
        public function run( $args, $assoc ) {
            $path = $assoc['file'] ?? '';
            if ( ! $path ) {
                \WP_CLI::error( 'Missing --file parameter.' );
            }
            if ( ! file_exists( $path ) ) {
                \WP_CLI::error( 'File not found.' );
            }
            $payload = json_decode( file_get_contents( $path ), true );
            if ( ! is_array( $payload ) ) {
                \WP_CLI::error( 'Invalid JSON.' );
            }

            $no_emails       = isset( $assoc['no-emails'] );
            $run_ld_upgrades = isset( $assoc['run-ld-upgrades'] );
            $logger          = new \WPLMS_S1I\Logger();
            $idmap           = new \WPLMS_S1I\IdMap();
            $importer        = new \WPLMS_S1I\Importer( $logger, $idmap );
            if ( $no_emails ) {
                $importer->set_disable_emails( true );
            }

            $stats             = $importer->run( $payload );
            $cl               = (array) ( $stats['commerce_linking_preflight'] ?? [] );
            if ( $cl ) {
                $sell = (int) ( $cl['sellable_courses'] ?? 0 );
                $uns  = array_sum( (array) ( $cl['unsellable_reasons'] ?? [] ) );
                \WP_CLI::log( sprintf( 'Preflight sellable=%d unsellable=%d', $sell, $uns ) );
                foreach ( (array) ( $cl['unsellable_examples'] ?? [] ) as $ex ) {
                    \WP_CLI::log( sprintf( ' - %s (%s)', $ex['course_slug'] ?? '', $ex['reason'] ?? '' ) );
                }
            }
            $ld_upgrade_status = [];
            if ( $run_ld_upgrades ) {
                $callbacks = [
                    'quizzes'   => 'learndash_data_upgrades_quizzes',
                    'questions' => 'learndash_data_upgrades_questions',
                ];
                foreach ( $callbacks as $label => $fn ) {
                    if ( function_exists( $fn ) ) {
                        try {
                            $fn();
                            $ld_upgrade_status[ $label ] = 'success';
                        } catch ( \Throwable $e ) {
                            $ld_upgrade_status[ $label ] = 'error: ' . $e->getMessage();
                        }
                    } else {
                        $ld_upgrade_status[ $label ] = 'missing';
                    }
                }
            }

            $root_dir   = dirname( WPLMS_S1I_DIR );
            $csv_dir    = $root_dir . '/csv';
            $report_dir = $root_dir . '/reports';
            if ( ! is_dir( $csv_dir ) ) {
                wp_mkdir_p( $csv_dir );
            }
            if ( ! is_dir( $report_dir ) ) {
                wp_mkdir_p( $report_dir );
            }

            // Metrics report
            $metrics = [
                'courses_created'              => (int) ( $stats['courses_created'] ?? 0 ),
                'courses_updated'              => (int) ( $stats['courses_updated'] ?? 0 ),
                'lessons_created'              => (int) ( $stats['lessons_created'] ?? 0 ),
                'lessons_updated'              => (int) ( $stats['lessons_updated'] ?? 0 ),
                'certificates_created'         => (int) ( $stats['certificates_created'] ?? 0 ),
                'certificates_updated'         => (int) ( $stats['certificates_updated'] ?? 0 ),
                'skipped'                      => (int) ( $stats['skipped'] ?? 0 ),
                'courses_linked_to_products'   => (int) ( $stats['courses_linked_to_products'] ?? 0 ),
                'product_not_found_for_course' => (int) ( $stats['product_not_found_for_course'] ?? 0 ),
                'courses_forced_closed_no_product' => (int) ( $stats['courses_forced_closed_no_product'] ?? 0 ),
                'certificates_attached'        => (int) ( $stats['certificates_attached'] ?? 0 ),
                'certificates_missing'         => (int) ( $stats['certificates_missing'] ?? 0 ),
                'certificates_already_attached'=> (int) ( $stats['certificates_already_attached'] ?? 0 ),
                'preflight_sellable_courses'   => (int) ( $cl['sellable_courses'] ?? 0 ),
                'preflight_unsellable_no_product' => (int) ( $cl['unsellable_reasons']['no_product'] ?? 0 ),
                'preflight_unsellable_not_publish' => (int) ( $cl['unsellable_reasons']['not_publish'] ?? 0 ),
                'preflight_unsellable_no_price' => (int) ( $cl['unsellable_reasons']['no_price'] ?? 0 ),
            ];
            $report = "# Import Result\n\n|Metric|Count|\n|---|---|\n";
            foreach ( $metrics as $key => $val ) {
                $report .= sprintf( "|%s|%d|\n", $key, $val );
            }
            if ( ! empty( $stats['courses_forced_closed_examples'] ) ) {
                $report .= "\n## courses_forced_closed_examples\n\n";
                foreach ( (array) $stats['courses_forced_closed_examples'] as $slug ) {
                    $report .= sprintf( "- %s\n", $slug );
                }
            }
            if ( ! empty( $cl['unsellable_examples'] ) ) {
                $report .= "\n## preflight_unsellable_examples\n\n";
                foreach ( (array) $cl['unsellable_examples'] as $ex ) {
                    $report .= sprintf( "- %s (%s)\n", $ex['course_slug'] ?? '', $ex['reason'] ?? '' );
                }
            }
            // orphans_imported counts will be added later

            // Courses without product link
            $course_ids = get_posts( [
                'post_type'   => 'sfwd-courses',
                'post_status' => 'any',
                'numberposts' => -1,
                'fields'      => 'ids',
            ] );
            $fh = fopen( $csv_dir . '/post_courses_without_product_link.csv', 'w' );
            if ( $fh ) {
                fputcsv( $fh, [ 'id', 'slug', 'title' ] );
                foreach ( $course_ids as $cid ) {
                    $pid = get_post_meta( $cid, 'ld_product_id', true );
                    if ( empty( $pid ) ) {
                        fputcsv( $fh, [ $cid, get_post_field( 'post_name', $cid ), get_post_field( 'post_title', $cid ) ] );
                    }
                }
                fclose( $fh );
            }

            // Courses missing certificates
            $fh = fopen( $csv_dir . '/post_certificates_missing.csv', 'w' );
            if ( $fh ) {
                fputcsv( $fh, [ 'id', 'slug', 'title' ] );
                foreach ( $course_ids as $cid ) {
                    $cert = 0;
                    if ( function_exists( 'learndash_get_setting' ) ) {
                        $cert = (int) learndash_get_setting( $cid, 'certificate' );
                    } else {
                        $cert = (int) get_post_meta( $cid, 'certificate', true );
                    }
                    if ( ! $cert ) {
                        fputcsv( $fh, [ $cid, get_post_field( 'post_name', $cid ), get_post_field( 'post_title', $cid ) ] );
                    }
                }
                fclose( $fh );
            }

            // Orphans imported
            $orphan_types  = [
                'units'        => 'sfwd-lessons',
                'quizzes'      => 'sfwd-quiz',
                'assignments'  => 'sfwd-assignment',
                'certificates' => 'sfwd-certificates',
            ];
            $orphan_counts = [];
            foreach ( $orphan_types as $key => $ptype ) {
                $items = get_posts( [
                    'post_type'   => $ptype,
                    'post_status' => 'any',
                    'numberposts' => -1,
                    'fields'      => 'ids',
                    'meta_key'    => '_hv_orphan',
                    'meta_value'  => '1',
                ] );
                $orphan_counts[ $key ] = count( $items );
                $fh = fopen( $csv_dir . "/post_orphans_imported_{$key}.csv", 'w' );
                if ( $fh ) {
                    fputcsv( $fh, [ 'id', 'slug', 'title', 'status', 'note' ] );
                    foreach ( $items as $id ) {
                        $note     = '';
                        $noteKeys = [ '_hv_relink_note', '_hv_relink_notes', '_hv_relink' ];
                        foreach ( $noteKeys as $nk ) {
                            $note = get_post_meta( $id, $nk, true );
                            if ( is_array( $note ) ) {
                                $note = wp_json_encode( $note );
                            }
                            if ( ! empty( $note ) ) {
                                break;
                            }
                        }
                        fputcsv( $fh, [
                            $id,
                            get_post_field( 'post_name', $id ),
                            get_post_field( 'post_title', $id ),
                            get_post_status( $id ),
                            $note,
                        ] );
                    }
                    fclose( $fh );
                }
            }

            if ( $orphan_counts ) {
                $report .= "\n## orphans_imported\n\n|Type|Count|\n|---|---|\n";
                foreach ( $orphan_counts as $k => $v ) {
                    $report .= sprintf( "|%s|%d|\n", $k, $v );
                }
            }

            if ( $ld_upgrade_status ) {
                $report .= "\n## learndash_upgrades\n\n|Routine|Status|\n|---|---|\n";
                foreach ( $ld_upgrade_status as $routine => $status ) {
                    $report .= sprintf( "|%s|%s|\n", $routine, $status );
                }
            }

            file_put_contents( $report_dir . '/IMPORT_RESULT.md', $report );

            // Idempotency check
            $importer2 = new \WPLMS_S1I\Importer( $logger, new \WPLMS_S1I\IdMap() );
            if ( $no_emails ) {
                $importer2->set_disable_emails( true );
            }
            $stats2 = $importer2->run( $payload );
            $created_sum = (
                (int) ( $stats2['courses_created'] ?? 0 ) +
                (int) ( $stats2['lessons_created'] ?? 0 ) +
                (int) ( $stats2['certificates_created'] ?? 0 ) +
                (int) ( $stats2['assignments'] ?? 0 ) +
                (int) ( $stats2['quizzes'] ?? 0 ) +
                (int) ( $stats2['orphans_imported']['units'] ?? 0 ) +
                (int) ( $stats2['orphans_imported']['quizzes'] ?? 0 ) +
                (int) ( $stats2['orphans_imported']['assignments'] ?? 0 ) +
                (int) ( $stats2['orphans_imported']['certificates'] ?? 0 )
            );
            if ( $created_sum > 0 ) {
                \WP_CLI::warning( 'Idempotency check: created > 0' );
                \WP_CLI::log( \wp_json_encode( $stats2 ) );
            } else {
                \WP_CLI::success( 'Idempotency check passed' );
            }

            \WP_CLI::log( 'Log file: ' . $logger->path() );
            \WP_CLI::success( 'Import complete' );
        }
    } );
}

