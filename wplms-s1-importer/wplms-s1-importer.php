<?php
/**
 * Plugin Name: WPLMS S1 Importer
 * Description: Proof‑of‑Concept importer for migrating content from WPLMS S1 Exporter JSON into LearnDash (sfwd-* post types). Creates Courses, Lessons, Quizzes, Assignments, Certificates; maintains ID Map; handles curriculum & orphans; sideloads media; stubs enrollments.
 * Version: 1.0.0
 * Author: Specia1ne
 * License: GPLv2 or later
 */

namespace {
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
                define( 'WPLMS_S1I_DIR', \plugin_dir_path( __FILE__ ) );
        }
        if ( ! defined( 'WPLMS_S1I_URL' ) ) {
                define( 'WPLMS_S1I_URL', \plugin_dir_url( __FILE__ ) );
        }

        // Options keys
        const WPLMS_S1I_OPT_IDMAP       = 'wplms_s1i_idmap';
        const WPLMS_S1I_OPT_RUNSTATS    = 'wplms_s1i_runstats';
        const WPLMS_S1I_OPT_ENROLL_POOL = 'wplms_s1i_enrollments_pool';
}

// -----------------------------------------------------------------------------
// Actual class implementations (kept in this file for PoC packaging convenience)
// -----------------------------------------------------------------------------

namespace WPLMS_S1I {

	// ------------------------------- Logger ------------------------------------
	class Logger {
		private $dir;
		private $file;
		public function __construct() {
			$uploads   = \wp_upload_dir();
			$this->dir = \trailingslashit( $uploads['basedir'] ) . 'wplms-s1-importer/logs/';
			\wp_mkdir_p( $this->dir );
			$stamp     = gmdate( 'Ymd-His' );
			$this->file = $this->dir . 'import-' . $stamp . '.log';
		}
		public function path() { return $this->file; }
		public function write( $msg, $context = [] ) {
			if ( ! is_string( $msg ) ) {
				$msg = print_r( $msg, true );
			}
			$line = '[' . gmdate( 'c' ) . '] ' . $msg;
			if ( $context ) {
				$line .= ' ' . \wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
			$line .= "\n";
			file_put_contents( $this->file, $line, FILE_APPEND );
		}
	}

	// ------------------------------- IdMap -------------------------------------
	class IdMap {
		private $map;
		public function __construct() {
			$this->map = \get_option( \WPLMS_S1I_OPT_IDMAP, [
				'courses'     => [],
				'units'       => [], // mapped to Lessons in LearnDash
				'quizzes'     => [],
				'assignments' => [],
				'certificates'=> [],
			] );
		}
		public function get_all() { return $this->map; }
		public function get( $type, $old_id ) { return isset( $this->map[ $type ][ $old_id ] ) ? (int) $this->map[ $type ][ $old_id ] : 0; }
		public function set( $type, $old_id, $new_id ) {
			$this->map[ $type ][ (string) $old_id ] = (int) $new_id;
			\update_option( \WPLMS_S1I_OPT_IDMAP, $this->map, false );
		}
		public function reset() {
			$this->map = [ 'courses'=>[], 'units'=>[], 'quizzes'=>[], 'assignments'=>[], 'certificates'=>[] ];
			\update_option( \WPLMS_S1I_OPT_IDMAP, $this->map, false );
		}
	}

	// ----------------------------- Helpers -------------------------------------
	function array_get( $arr, $key, $default = '' ) {
		return isset( $arr[ $key ] ) ? $arr[ $key ] : $default;
	}

	function normalize_slug( $slug ) {
		$slug = \sanitize_title( $slug );
		return $slug ?: null;
	}

	function ensure_oembed( $content, $embeds ) {
		if ( empty( $embeds ) || ! is_array( $embeds ) ) return $content;
		$lines = [];
		foreach ( $embeds as $url ) {
			$url = trim( (string) $url );
			if ( $url ) $lines[] = $url; // Let WP auto‑oEmbed handle it
		}
		if ( $lines ) {
			$content .= "\n\n<!-- WPLMS S1 embeds -->\n" . implode( "\n", $lines ) . "\n";
		}
		return $content;
	}

	function sideload_featured( $url, $attach_to_post_id, Logger $logger ) {
		if ( ! $url ) return 0;
		// favor core helper; fall back to manual sideload
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$timeout = 60;
		$tmp = \download_url( $url, $timeout );
		if ( \is_wp_error( $tmp ) ) {
			$logger->write( 'media download failed', [ 'url' => $url, 'error' => $tmp->get_error_message() ] );
			return 0;
		}
		$filename = \wp_basename( parse_url( $url, PHP_URL_PATH ) );
		$file     = [
			'name'     => $filename ?: 'remote-file',
			'tmp_name' => $tmp,
		];
		$overrides = [ 'test_form' => false ];
		$results   = \wp_handle_sideload( $file, $overrides );
		if ( isset( $results['error'] ) ) {
			@unlink( $tmp );
			$logger->write( 'media sideload failed', [ 'url' => $url, 'error' => $results['error'] ] );
			return 0;
		}
		$attachment = [
			'post_mime_type' => $results['type'],
			'post_title'     => \sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];
		$attach_id = \wp_insert_attachment( $attachment, $results['file'], $attach_to_post_id );
		if ( ! \is_wp_error( $attach_id ) ) {
			\wp_update_attachment_metadata( $attach_id, \wp_generate_attachment_metadata( $attach_id, $results['file'] ) );
			\set_post_thumbnail( $attach_to_post_id, $attach_id );
			return $attach_id;
		}
		$logger->write( 'attachment insert failed', [ 'url' => $url, 'error' => $attach_id->get_error_message() ] );
		return 0;
	}

	// ----------------------------- Importer ------------------------------------
	class Importer {
		private $logger;
		private $idmap;
		private $dry_run = false;

		public function __construct( Logger $logger, IdMap $idmap ) {
			$this->logger = $logger;
			$this->idmap  = $idmap;
		}

		public function set_dry_run( $dry ) { $this->dry_run = (bool) $dry; }

		public function run( $payload ) {
			if ( is_string( $payload ) ) {
				$payload = $this->load_payload( $payload );
			}
			if ( ! is_array( $payload ) ) {
				throw new \RuntimeException( 'Invalid import payload' );
			}

			$stats = [
				'courses'=>0,'lessons'=>0,'quizzes'=>0,'assignments'=>0,'certificates'=>0,
				'orphans_units'=>0,'orphans_quizzes'=>0,'orphans_assignments'=>0,
				'skipped'=>0,'errors'=>0,
			];

			// 1) Courses first (with nested units/quizzes if provided)
			$courses = (array) array_get( $payload, 'courses', [] );
			foreach ( $courses as $course ) {
				try {
					$cid = $this->import_course( $course );
					$stats['courses']++;
					// curriculum: units → lessons
					$units = (array) array_get( $course, 'units', [] );
					$menu_order = 0;
					foreach ( $units as $unit ) {
						$lid = $this->import_lesson( $unit, $cid, $menu_order++ );
						if ( $lid ) $stats['lessons']++;
					}
					// quizzes
					$quizzes = (array) array_get( $course, 'quizzes', [] );
					foreach ( $quizzes as $quiz ) {
						$qid = $this->import_quiz( $quiz, $cid );
						if ( $qid ) $stats['quizzes']++;
					}
					// certificates (optional per course)
					$certs = (array) array_get( $course, 'certificates', [] );
					foreach ( $certs as $cert ) {
						$ceid = $this->import_certificate( $cert );
						if ( $ceid ) $stats['certificates']++;
					}
					// enrollments (stub only)
					$enroll = (array) array_get( $course, 'enrollments', [] );
					$this->stash_enrollments( $course, $enroll );
				} catch ( \Throwable $e ) {
					$stats['errors']++;
					$this->logger->write( 'course import failed: ' . $e->getMessage(), [ 'trace' => $e->getTraceAsString() ] );
				}
			}

			// 2) Orphans (units, quizzes, assignments)
			$orph = (array) array_get( $payload, 'orphans', [] );
			foreach ( (array) array_get( $orph, 'units', [] ) as $unit ) {
				$lid = $this->import_lesson( $unit, 0, 0, true );
				if ( $lid ) $stats['orphans_units']++;
			}
			foreach ( (array) array_get( $orph, 'quizzes', [] ) as $quiz ) {
				$qid = $this->import_quiz( $quiz, 0, true );
				if ( $qid ) $stats['orphans_quizzes']++;
			}
			foreach ( (array) array_get( $orph, 'assignments', [] ) as $assn ) {
				$aid = $this->import_assignment( $assn, 0, 0, true );
				if ( $aid ) $stats['orphans_assignments']++;
			}

			\update_option( \WPLMS_S1I_OPT_RUNSTATS, $stats, false );
			$this->logger->write( 'Import finished', $stats );
			return $stats;
		}

		private function load_payload( $path ) {
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( 'zip' === $ext ) {
				if ( ! class_exists( '\\ZipArchive' ) ) {
					throw new \RuntimeException( 'ZipArchive not available on server.' );
				}
				$zip = new \ZipArchive();
				if ( true !== $zip->open( $path ) ) {
					throw new \RuntimeException( 'Unable to open zip file.' );
				}
				$json = null;
				for ( $i = 0; $i < $zip->numFiles; $i++ ) {
					$name = $zip->getNameIndex( $i );
					if ( substr( $name, -5 ) === '.json' ) {
						$json = $zip->getFromIndex( $i );
						break;
					}
				}
				$zip->close();
				if ( ! $json ) throw new \RuntimeException( 'No JSON found inside zip.' );
				$payload = json_decode( $json, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					throw new \RuntimeException( 'Invalid JSON in zip: ' . json_last_error_msg() );
				}
				return $payload;
			}
			if ( 'json' === $ext ) {
				$raw = file_get_contents( $path );
				$payload = json_decode( $raw, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					throw new \RuntimeException( 'Invalid JSON: ' . json_last_error_msg() );
				}
				return $payload;
			}
			throw new \RuntimeException( 'Unsupported file type: ' . $ext );
		}

		private function import_course( $course ) {
			$old_id   = (int) array_get( $course, 'id', 0 );
			$existing = $this->idmap->get( 'courses', $old_id );
			if ( $existing ) {
				$this->logger->write( 'course already imported', [ 'old_id'=>$old_id, 'new_id'=>$existing ] );
				return $existing;
			}
			$title    = array_get( $course, 'title', 'Untitled Course' );
			$content  = array_get( $course, 'content', '' );
			$content  = ensure_oembed( $content, array_get( $course, 'embeds', [] ) );
			$slug     = normalize_slug( array_get( $course, 'post_name', '' ) );
			$args = [
				'post_type'    => 'sfwd-courses',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			];
			if ( $slug ) $args['post_name'] = $slug;

			if ( $this->dry_run ) {
				$this->logger->write( 'DRY: create course', [ 'title'=>$title ] );
				return 0;
			}
			$new_id = \wp_insert_post( $args, true );
			if ( \is_wp_error( $new_id ) ) {
				throw new \RuntimeException( 'wp_insert_post failed: ' . $new_id->get_error_message() );
			}
			// mark original id
			\update_post_meta( $new_id, '_wplms_old_id', $old_id );
			// featured image if present
			sideload_featured( array_get( $course, 'featured_image', '' ), $new_id, $this->logger );
			$this->idmap->set( 'courses', $old_id, $new_id );
			return $new_id;
		}

		private function import_lesson( $unit, $course_new_id = 0, $menu_order = 0, $is_orphan = false ) {
			$old_id   = (int) array_get( $unit, 'id', 0 );
			$existing = $this->idmap->get( 'units', $old_id );
			if ( $existing ) return $existing;

			$title   = array_get( $unit, 'title', 'Untitled Lesson' );
			$content = ensure_oembed( array_get( $unit, 'content', '' ), array_get( $unit, 'embeds', [] ) );
			$slug    = normalize_slug( array_get( $unit, 'post_name', '' ) );
			$args = [
				'post_type'    => 'sfwd-lessons',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
				'menu_order'   => (int) $menu_order,
				'post_parent'  => (int) $course_new_id, // basic hierarchy for PoC
			];
			if ( $slug ) $args['post_name'] = $slug;

			if ( $this->dry_run ) {
				$this->logger->write( 'DRY: create lesson', [ 'title'=>$title, 'course'=>$course_new_id ] );
				return 0;
			}
			$new_id = \wp_insert_post( $args, true );
			if ( \is_wp_error( $new_id ) ) {
				$this->logger->write( 'lesson insert failed: ' . $new_id->get_error_message(), [ 'old_id'=>$old_id ] );
				return 0;
			}
			\update_post_meta( $new_id, '_wplms_old_id', $old_id );
			if ( $course_new_id ) {
				\update_post_meta( $new_id, 'course_id', (int) $course_new_id ); // LD recognizes this
			}
			if ( $is_orphan ) {
				\update_post_meta( $new_id, '_wplms_orphan', 1 );
			}
			// featured image if present
			sideload_featured( array_get( $unit, 'featured_image', '' ), $new_id, $this->logger );
			$this->idmap->set( 'units', $old_id, $new_id );

			// assignments nested under unit?
			$assignments = (array) array_get( $unit, 'assignments', [] );
			foreach ( $assignments as $assn ) {
				$this->import_assignment( $assn, $course_new_id, $new_id, $is_orphan );
			}
			return $new_id;
		}

		private function import_quiz( $quiz, $course_new_id = 0, $is_orphan = false ) {
			$old_id   = (int) array_get( $quiz, 'id', 0 );
			$existing = $this->idmap->get( 'quizzes', $old_id );
			if ( $existing ) return $existing;

			$title   = array_get( $quiz, 'title', 'Untitled Quiz' );
			$content = ensure_oembed( array_get( $quiz, 'content', '' ), array_get( $quiz, 'embeds', [] ) );
			$slug    = normalize_slug( array_get( $quiz, 'post_name', '' ) );
			$args = [
				'post_type'    => 'sfwd-quiz',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
				'post_parent'  => (int) $course_new_id, // basic hierarchy
			];
			if ( $slug ) $args['post_name'] = $slug;

			if ( $this->dry_run ) {
				$this->logger->write( 'DRY: create quiz', [ 'title'=>$title, 'course'=>$course_new_id ] );
				return 0;
			}
			$new_id = \wp_insert_post( $args, true );
			if ( \is_wp_error( $new_id ) ) {
				$this->logger->write( 'quiz insert failed: ' . $new_id->get_error_message(), [ 'old_id'=>$old_id ] );
				return 0;
			}
			\update_post_meta( $new_id, '_wplms_old_id', $old_id );
			if ( $course_new_id ) {
				\update_post_meta( $new_id, 'course_id', (int) $course_new_id );
			}
			if ( $is_orphan ) {
				\update_post_meta( $new_id, '_wplms_orphan', 1 );
			}
			sideload_featured( array_get( $quiz, 'featured_image', '' ), $new_id, $this->logger );
			$this->idmap->set( 'quizzes', $old_id, $new_id );
			return $new_id;
		}

		private function import_assignment( $assn, $course_new_id = 0, $lesson_new_id = 0, $is_orphan = false ) {
			$old_id   = (int) array_get( $assn, 'id', 0 );
			$existing = $this->idmap->get( 'assignments', $old_id );
			if ( $existing ) return $existing;

			$title   = array_get( $assn, 'title', 'Assignment' );
			$content = ensure_oembed( array_get( $assn, 'content', '' ), array_get( $assn, 'embeds', [] ) );
			$slug    = normalize_slug( array_get( $assn, 'post_name', '' ) );
			$args = [
				'post_type'    => 'sfwd-assignment',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
				'post_parent'  => (int) ( $lesson_new_id ?: $course_new_id ),
			];
			if ( $slug ) $args['post_name'] = $slug;

			if ( $this->dry_run ) {
				$this->logger->write( 'DRY: create assignment', [ 'title'=>$title, 'course'=>$course_new_id, 'lesson'=>$lesson_new_id ] );
				return 0;
			}
			$new_id = \wp_insert_post( $args, true );
			if ( \is_wp_error( $new_id ) ) {
				$this->logger->write( 'assignment insert failed: ' . $new_id->get_error_message(), [ 'old_id'=>$old_id ] );
				return 0;
			}
			\update_post_meta( $new_id, '_wplms_old_id', $old_id );
			if ( $course_new_id ) \update_post_meta( $new_id, 'course_id', (int) $course_new_id );
			if ( $lesson_new_id ) \update_post_meta( $new_id, 'lesson_id', (int) $lesson_new_id );
			if ( $is_orphan ) \update_post_meta( $new_id, '_wplms_orphan', 1 );
			$this->idmap->set( 'assignments', $old_id, $new_id );
			return $new_id;
		}

		private function import_certificate( $cert ) {
			$old_id   = (int) array_get( $cert, 'id', 0 );
			$existing = $this->idmap->get( 'certificates', $old_id );
			if ( $existing ) return $existing;

			$title   = array_get( $cert, 'title', 'Certificate' );
			$content = array_get( $cert, 'content', '' );
			$slug    = normalize_slug( array_get( $cert, 'post_name', '' ) );
			$args = [
				'post_type'    => 'sfwd-certificates',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
			];
			if ( $slug ) $args['post_name'] = $slug;

			if ( $this->dry_run ) {
				$this->logger->write( 'DRY: create certificate', [ 'title'=>$title ] );
				return 0;
			}
			$new_id = \wp_insert_post( $args, true );
			if ( \is_wp_error( $new_id ) ) {
				$this->logger->write( 'certificate insert failed: ' . $new_id->get_error_message(), [ 'old_id'=>$old_id ] );
				return 0;
			}
			\update_post_meta( $new_id, '_wplms_old_id', $old_id );
			// featured & background images
			sideload_featured( array_get( $cert, 'featured_image', '' ), $new_id, $this->logger );
			$bg = array_get( $cert, 'background_image', '' );
			if ( $bg ) \update_post_meta( $new_id, '_ld_certificate_background_image_url', \esc_url_raw( $bg ) );
			$this->idmap->set( 'certificates', $old_id, $new_id );
			return $new_id;
		}

		private function stash_enrollments( $course, $enrollments ) {
			if ( empty( $enrollments ) ) return;
			$pool = \get_option( \WPLMS_S1I_OPT_ENROLL_POOL, [] );
			$old_id = array_get( $course, 'id', null );
			$pool[ (string) $old_id ] = $enrollments; // kept verbatim; will be resolved once users are mapped
			\update_option( \WPLMS_S1I_OPT_ENROLL_POOL, $pool, false );
			$this->logger->write( 'enrollments stashed', [ 'course_old_id'=>$old_id, 'count'=>count( (array) $enrollments ) ] );
		}
	}

	// ------------------------------ Admin UI -----------------------------------
	class Admin {
		private $page_slug = 'wplms-s1-importer';

		public function hooks() {
			\add_action( 'admin_menu', [ $this, 'menu' ] );
			\add_action( 'admin_post_wplms_s1i_run', [ $this, 'handle_import' ] );
			\add_action( 'admin_post_wplms_s1i_reset', [ $this, 'handle_reset' ] );
		}

		public function menu() {
			\add_submenu_page(
				'tools.php',
				'WPLMS S1 Importer',
				'WPLMS S1 Importer',
				'manage_options',
				$this->page_slug,
				[ $this, 'render' ]
			);
		}

		public function render() {
			if ( ! \current_user_can( 'manage_options' ) ) return;
			$idmap   = new IdMap();
			$stats   = \get_option( \WPLMS_S1I_OPT_RUNSTATS, [] );
			$en_pool = \get_option( \WPLMS_S1I_OPT_ENROLL_POOL, [] );
			?>
			<div class="wrap">
				<h1>WPLMS → LearnDash Importer (PoC)</h1>
				<p>Version <?php echo \esc_html( \WPLMS_S1I_VER ); ?>. Use this to import the JSON created by WPLMS S1 Exporter.</p>

				<h2 class="title">Run Import</h2>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php \wp_nonce_field( 'wplms_s1i_run' ); ?>
					<input type="hidden" name="action" value="wplms_s1i_run" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wplms_s1i_file">JSON or ZIP file</label></th>
							<td><input type="file" name="wplms_s1i_file" id="wplms_s1i_file" accept=".json,.zip" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="wplms_s1i_dry">Dry run</label></th>
							<td><label><input type="checkbox" name="dry" id="wplms_s1i_dry" value="1" /> Analyze only (no content will be created)</label></td>
						</tr>
					</table>
					<?php \submit_button( 'Start Import' ); ?>
				</form>

				<h2 class="title">Utilities</h2>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
					<?php \wp_nonce_field( 'wplms_s1i_reset' ); ?>
					<input type="hidden" name="action" value="wplms_s1i_reset" />
					<?php \submit_button( 'Reset ID Map & Stats', 'delete' ); ?>
				</form>

				<h2 class="title">ID Map (summary)</h2>
				<pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px"><?php echo \esc_html( print_r( $idmap->get_all(), true ) ); ?></pre>

				<h2 class="title">Last Run Stats</h2>
				<pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px"><?php echo \esc_html( print_r( $stats, true ) ); ?></pre>

				<h2 class="title">Enrollments Pool (deferred)</h2>
				<p>Stored per original course ID for later user mapping.</p>
				<pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px"><?php echo \esc_html( print_r( [ 'courses'=> count( $en_pool ) ], true ) ); ?></pre>

			</div>
			<?php
		}

		public function handle_import() {
			if ( ! \current_user_can( 'manage_options' ) ) \wp_die( 'Unauthorized' );
			\check_admin_referer( 'wplms_s1i_run' );

			$dry = isset( $_POST['dry'] ) && $_POST['dry'] == '1';

			// handle upload
			if ( empty( $_FILES['wplms_s1i_file'] ) || empty( $_FILES['wplms_s1i_file']['tmp_name'] ) ) {
				\wp_die( 'No file uploaded' );
			}
			$overrides = [ 'test_form' => false ];
			$uploaded = \wp_handle_upload( $_FILES['wplms_s1i_file'], $overrides );
			if ( isset( $uploaded['error'] ) ) {
				\wp_die( 'Upload error: ' . \esc_html( $uploaded['error'] ) );
			}

			$logger   = new Logger();
			$idmap    = new IdMap();
			$importer = new Importer( $logger, $idmap );
			$importer->set_dry_run( $dry );

			try {
				$stats = $importer->run( $uploaded['file'] );
				$url   = \add_query_arg( [ 'page'=>$this->page_slug, 'done'=>1, 'log'=>rawurlencode( $logger->path() ) ], \admin_url( 'tools.php' ) );
				\wp_safe_redirect( $url );
				exit;
			} catch ( \Throwable $e ) {
				\wp_die( 'Import failed: ' . \esc_html( $e->getMessage() ) );
			}
		}

                public function handle_reset() {
                        if ( ! \current_user_can( 'manage_options' ) ) \wp_die( 'Unauthorized' );
                        \check_admin_referer( 'wplms_s1i_reset' );
                        $idmap = new IdMap();
                        $idmap->reset();
                        \delete_option( \WPLMS_S1I_OPT_RUNSTATS );
                        \delete_option( \WPLMS_S1I_OPT_ENROLL_POOL );
                        \wp_safe_redirect( \add_query_arg( [ 'page'=>$this->page_slug, 'reset'=>1 ], \admin_url( 'tools.php' ) ) );
                        exit;
                }
        }

}

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------
namespace {
        \add_action( 'init', function () {
                // Ensure LearnDash CPTs exist (plugin can still create posts even if LD inactive, but recommended to activate LD first)
                // Minimal guard: if post type absent, register a placeholder so posts can be created (PoC only).
                $needed = [ 'sfwd-courses', 'sfwd-lessons', 'sfwd-quiz', 'sfwd-assignment', 'sfwd-certificates' ];
                // Re-check after other plugins have registered their post types.
                $missing = array_filter( $needed, function ( $pt ) {
                        return ! \post_type_exists( $pt );
                } );
                if ( $missing ) {
                        foreach ( $missing as $pt ) {
                                \register_post_type( $pt, [
                                        'label' => strtoupper( $pt ),
                                        'public' => true,
                                        'show_ui' => true,
                                        'supports' => [ 'title','editor','thumbnail','page-attributes' ],
                                ] );
                        }
                }
        }, 20 );

	\add_action( 'init', function () {
		// Admin UI
		if ( \is_admin() ) {
			$admin = new \WPLMS_S1I\Admin();
			$admin->hooks();
		}
	} );

	// Optional: WP‑CLI command for local usage: wp wplms-s1 import <path.json|zip> [--dry]
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'wplms-s1', new class {
			/**
			 * Import from a JSON or ZIP exported by WPLMS S1 Exporter.
			 *
			 * ## OPTIONS
			 *
			 * <path>
			 * : Absolute path to JSON or ZIP file.
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
					\WP_CLI::success( 'Done: ' . json_encode( $stats ) );
					\WP_CLI::log( 'Log file: ' . $logger->path() );
				} catch ( \Throwable $e ) {
					\WP_CLI::error( $e->getMessage() );
				}
			}
		} );
	}
}
