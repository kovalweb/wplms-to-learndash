<?php
namespace WPLMS_S1I;
class Importer {
		private $logger;
		private $idmap;
		private $dry_run = false;

		public function __construct( Logger $logger, IdMap $idmap ) {
			$this->logger = $logger;
			$this->idmap  = $idmap;
		}

		public function set_dry_run( $dry ) { $this->dry_run = (bool) $dry; }

                public function run( array $payload ) {
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

                        if ( ! $this->dry_run ) {
                                \update_option( \WPLMS_S1I_OPT_RUNSTATS, $stats, false );
                        }
			$this->logger->write( 'Import finished', $stats );
			return $stats;
		}



                private function import_course( $course ) {
                        $old_id   = (int) array_get( $course, 'old_id', 0 );
			$existing = $this->idmap->get( 'courses', $old_id );
			if ( $existing ) {
				$this->logger->write( 'course already imported', [ 'old_id'=>$old_id, 'new_id'=>$existing ] );
				return $existing;
			}
                        $title    = array_get( $course, 'post.post_title', 'Untitled Course' );
                        $content  = array_get( $course, 'post.post_content', '' );
                        $content  = ensure_oembed( $content, array_get( $course, 'embeds', [] ) );
                        $slug     = normalize_slug( array_get( $course, 'post.post_name', '' ) );
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
                        sideload_featured( array_get( $course, 'post.featured_image', '' ), $new_id, $this->logger );
			$this->idmap->set( 'courses', $old_id, $new_id );
			return $new_id;
		}

                private function import_lesson( $unit, $course_new_id = 0, $menu_order = 0, $is_orphan = false ) {
                        $old_id   = (int) array_get( $unit, 'old_id', 0 );
			$existing = $this->idmap->get( 'units', $old_id );
			if ( $existing ) return $existing;

                        $title   = array_get( $unit, 'post.post_title', 'Untitled Lesson' );
                        $content = ensure_oembed( array_get( $unit, 'post.post_content', '' ), array_get( $unit, 'embeds', [] ) );
                        $slug    = normalize_slug( array_get( $unit, 'post.post_name', '' ) );
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
                        sideload_featured( array_get( $unit, 'post.featured_image', '' ), $new_id, $this->logger );
			$this->idmap->set( 'units', $old_id, $new_id );

			// assignments nested under unit?
			$assignments = (array) array_get( $unit, 'assignments', [] );
			foreach ( $assignments as $assn ) {
				$this->import_assignment( $assn, $course_new_id, $new_id, $is_orphan );
			}
			return $new_id;
		}

                private function import_quiz( $quiz, $course_new_id = 0, $is_orphan = false ) {
                        $old_id   = (int) array_get( $quiz, 'old_id', 0 );
			$existing = $this->idmap->get( 'quizzes', $old_id );
			if ( $existing ) return $existing;

                        $title   = array_get( $quiz, 'post.post_title', 'Untitled Quiz' );
                        $content = ensure_oembed( array_get( $quiz, 'post.post_content', '' ), array_get( $quiz, 'embeds', [] ) );
                        $slug    = normalize_slug( array_get( $quiz, 'post.post_name', '' ) );
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
                        sideload_featured( array_get( $quiz, 'post.featured_image', '' ), $new_id, $this->logger );
			$this->idmap->set( 'quizzes', $old_id, $new_id );
			return $new_id;
		}

                private function import_assignment( $assn, $course_new_id = 0, $lesson_new_id = 0, $is_orphan = false ) {
                        $old_id   = (int) array_get( $assn, 'old_id', 0 );
			$existing = $this->idmap->get( 'assignments', $old_id );
			if ( $existing ) return $existing;

                        $title   = array_get( $assn, 'post.post_title', 'Assignment' );
                        $content = ensure_oembed( array_get( $assn, 'post.post_content', '' ), array_get( $assn, 'embeds', [] ) );
                        $slug    = normalize_slug( array_get( $assn, 'post.post_name', '' ) );
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
                        $old_id   = (int) array_get( $cert, 'old_id', 0 );
			$existing = $this->idmap->get( 'certificates', $old_id );
			if ( $existing ) return $existing;

                        $title   = array_get( $cert, 'post.post_title', 'Certificate' );
                        $content = array_get( $cert, 'post.post_content', '' );
                        $slug    = normalize_slug( array_get( $cert, 'post.post_name', '' ) );
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
                        sideload_featured( array_get( $cert, 'post.featured_image', '' ), $new_id, $this->logger );
			$bg = array_get( $cert, 'background_image', '' );
			if ( $bg ) \update_post_meta( $new_id, '_ld_certificate_background_image_url', \esc_url_raw( $bg ) );
			$this->idmap->set( 'certificates', $old_id, $new_id );
			return $new_id;
		}

                private function stash_enrollments( $course, $enrollments ) {
                        if ( $this->dry_run ) {
                                return;
                        }
                        if ( empty( $enrollments ) ) return;
                        $pool = \get_option( \WPLMS_S1I_OPT_ENROLL_POOL, [] );
                        $old_id = array_get( $course, 'old_id', null );
                        $pool[ (string) $old_id ] = $enrollments; // kept verbatim; will be resolved once users are mapped
                        \update_option( \WPLMS_S1I_OPT_ENROLL_POOL, $pool, false );
                        $this->logger->write( 'enrollments stashed', [ 'course_old_id'=>$old_id, 'count'=>count( (array) $enrollments ) ] );
                }
	}

