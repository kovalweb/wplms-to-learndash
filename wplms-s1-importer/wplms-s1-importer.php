<?php
/**
 * Plugin Name: WPLMS S1 Importer
 * Description: Proof‑of‑Concept importer for migrating content from WPLMS S1 Exporter JSON into LearnDash (sfwd-* post types).
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
const WPLMS_S1I_OPT_NEED_FLUSH  = 'wplms_s1i_need_flush';

// Autoload classes and helpers
require_once WPLMS_S1I_DIR . 'includes/autoload.php';
require_once WPLMS_S1I_DIR . 'includes/helpers.php';

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------
add_action( 'init', function () {
    // Ensure LearnDash CPTs exist (plugin can still create posts even if LD inactive, but recommended to activate LD first)
    // Minimal guard: if post type absent, register a placeholder so posts can be created (PoC only).
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

// Optional: WP‑CLI command for local usage: wp wplms-s1 import <path.json> [--dry]
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    \WP_CLI::add_command( 'wplms-s1', new class {
        /**
         * Import from a JSON exported by WPLMS S1 Exporter.
         *
         * ## OPTIONS
         *
         * <path>
         * : Absolute path to JSON file.
         *
         * [--dry]
         * : Analyze only; do not create posts.
         */
        public function import( $args, $assoc ) {
            list( $path ) = $args;
            $dry = isset( $assoc['dry'] );
            $logger   = new \WPLMS_S1I\Logger();
            $idmap    = new \WPLMS_S1I\IdMap();
            $importer = new \WPLMS_S1I\Importer( $logger, $idmap );
            $importer->set_dry_run( $dry );
            try {
                $stats = $importer->run( $path );
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
}

