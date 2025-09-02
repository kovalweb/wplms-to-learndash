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

        // 1) Courses
        $courses = (array) array_get( $payload, 'courses', [] );
        foreach ( $courses as $course ) {
            try {
                $cid = $this->import_course( $course );
                $stats['courses']++;

                // lessons
                $units = (array) array_get( $course, 'units', [] );
                $menu_order = 0;
                foreach ( $units as $unit ) {
                    $ok = $this->import_lesson( $unit, $cid, $menu_order++ );
                    if ( $ok ) $stats['lessons']++;
                }

                // quizzes
                $quizzes = (array) array_get( $course, 'quizzes', [] );
                foreach ( $quizzes as $quiz ) {
                    $ok = $this->import_quiz( $quiz, $cid );
                    if ( $ok ) $stats['quizzes']++;
                }

                // certificates
                $certs = (array) array_get( $course, 'certificates', [] );
                foreach ( $certs as $cert ) {
                    $ok = $this->import_certificate( $cert );
                    if ( $ok ) $stats['certificates']++;
                }

                // enrollments (stub)
                $enroll = (array) array_get( $course, 'enrollments', [] );
                $this->stash_enrollments( $course, $enroll );
            } catch ( \Throwable $e ) {
                $stats['errors']++;
                $this->logger->write( 'course import failed: ' . $e->getMessage(), [ 'trace' => $e->getTraceAsString() ] );
            }
        }

        // 2) Orphans
        $orph = (array) array_get( $payload, 'orphans', [] );
        foreach ( (array) array_get( $orph, 'units', [] ) as $unit ) {
            $ok = $this->import_lesson( $unit, 0, 0, true );
            if ( $ok ) $stats['orphans_units']++;
        }
        foreach ( (array) array_get( $orph, 'quizzes', [] ) as $quiz ) {
            $ok = $this->import_quiz( $quiz, 0, true );
            if ( $ok ) $stats['orphans_quizzes']++;
        }
        foreach ( (array) array_get( $orph, 'assignments', [] ) as $assn ) {
            $ok = $this->import_assignment( $assn, 0, 0, true );
            if ( $ok ) $stats['orphans_assignments']++;
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

        \update_post_meta( $new_id, '_wplms_old_id', $old_id );

        // featured image (м’яко)
        try {
            sideload_featured( array_get( $course, 'post.featured_image', '' ), $new_id, $this->logger );
        } catch ( \Throwable $t ) {
            $this->logger->write( 'featured sideload exception (course)', [ 'error' => $t->getMessage() ] );
        }

        $this->idmap->set( 'courses', $old_id, $new_id );
        return $new_id;
    }

    private function import_lesson( $unit, $course_new_id = 0, $menu_order = 0, $is_orphan = false ) {
        $old_id   = (int) array_get( $unit, 'old_id', 0 );
        $existing = $this->idmap->get( 'units', $old_id );
        if ( $existing ) return true;

        $title   = array_get( $unit, 'post.post_title', 'Untitled Lesson' );
        $content = ensure_oembed( array_get( $unit, 'post.post_content', '' ), array_get( $unit, 'embeds', [] ) );
        $slug    = normalize_slug( array_get( $unit, 'post.post_name', '' ) );

        $args = [
            'post_type'    => 'sfwd-lessons',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => $content,
            'menu_order'   => (int) $menu_order,
            'post_parent'  => (int) $course_new_id,
        ];
        if ( $slug ) $args['post_name'] = $slug;

        $new_id = 0;

        if ( $this->dry_run ) {
            $this->logger->write( 'DRY: create lesson', [ 'title'=>$title, 'course'=>$course_new_id ] );
        } else {
            $new_id = \wp_insert_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'lesson insert failed: ' . $new_id->get_error_message(), [ 'old_id'=>$old_id ] );
                return false;
            }
            \update_post_meta( $new_id, '_wplms_old_id', $old_id );
            if ( $course_new_id ) {
                \update_post_meta( $new_id, 'course_id', (int) $course_new_id );
            }
            if ( $is_orphan ) {
                \update_post_meta( $new_id, '_wplms_orphan', 1 );
            }

            try {
                sideload_featured( array_get( $unit, 'post.featured_image', '' ), $new_id, $this->logger );
            } catch ( \Throwable $t ) {
                $this->logger->write( 'featured sideload exception (lesson)', [ 'error' => $t->getMessage() ] );
            }

            $this->idmap->set( 'units', $old_id, $new_id );
        }

        // assignments nested
        $assignments = (array) array_get( $unit, 'assignments', [] );
        foreach ( $assignments as $assn ) {
            $this->import_assignment( $assn, $course_new_id, $new_id, $is_orphan );
        }

        return true;
    }

    private function import_quiz( $quiz, $course_new_id = 0, $is_orphan = false ) {
        $old_id   = (int) array_get( $quiz, 'old_id', 0 );
        $existing = $this->idmap->get( 'quizzes', $old_id );
        if ( $existing ) return true;

        $title   = array_get( $quiz, 'post.post_title', 'Untitled Quiz' );
        $content = ensure_oembed( array_get( $quiz, 'post.post_content', '' ), array_get( $quiz, 'embeds', [] ) );
        $slug    = normalize_slug( array_get( $quiz, 'post.post_name', '' ) );

        $args = [
            'post_type'    => 'sfwd-quiz',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => $content,
            'post_parent'  => (int) $course_new_id,
        ];
        if ( $slug ) $args['post_name'] = $slug;

        $new_id = 0;

        if ( $this->dry_run ) {
            $this->logger->write( 'DRY: create quiz', [ 'title'=>$title, 'course'=>$course_new_id ] );
        } else {
            $new_id = \wp_insert_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'quiz insert failed: ' . $new_id->get_error_message(), [ 'old_id'=>$old_id ] );
                return false;
            }

            \update_post_meta( $new_id, '_wplms_old_id', $old_id );
            if ( $course_new_id ) {
                \update_post_meta( $new_id, 'course_id', (int) $course_new_id );
            }
            if ( $is_orphan ) {
                \update_post_meta( $new_id, '_wplms_orphan', 1 );
            }

            try {
                sideload_featured( array_get( $quiz, 'post.featured_image', '' ), $new_id, $this->logger );
            } catch ( \Throwable $t ) {
                $this->logger->write( 'featured sideload exception (quiz)', [ 'error' => $t->getMessage() ] );
            }

            // >>> ProQuiz master record & link (fixes "Missing ProQuiz Associated Settings")
            $this->ensure_proquiz_link( $new_id, $title );

            $this->idmap->set( 'quizzes', $old_id, $new_id );
        }

        return true;
    }

    private function import_assignment( $assn, $course_new_id = 0, $lesson_new_id = 0, $is_orphan = false ) {
        $old_id   = (int) array_get( $assn, 'old_id', 0 );
        $existing = $this->idmap->get( 'assignments', $old_id );
        if ( $existing ) return true;

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
        } else {
            $new_id = \wp_insert_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'assignment insert failed: ' . $new_id->get_error_message(), [ 'old_id'=>$old_id ] );
                return false;
            }

            \update_post_meta( $new_id, '_wplms_old_id', $old_id );
            if ( $course_new_id ) \update_post_meta( $new_id, 'course_id', (int) $course_new_id );
            if ( $lesson_new_id ) \update_post_meta( $new_id, 'lesson_id', (int) $lesson_new_id );
            if ( $is_orphan ) \update_post_meta( $new_id, '_wplms_orphan', 1 );

            $this->idmap->set( 'assignments', $old_id, $new_id );
        }
        return true;
    }

    private function import_certificate( $cert ) {
        $old_id   = (int) array_get( $cert, 'old_id', 0 );
        $existing = $this->idmap->get( 'certificates', $old_id );
        if ( $existing ) return true;

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
        } else {
            $new_id = \wp_insert_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'certificate insert failed: ' . $new_id->get_error_message(), [ 'old_id'=>$old_id ] );
                return false;
            }

            \update_post_meta( $new_id, '_wplms_old_id', $old_id );

            try {
                sideload_featured( array_get( $cert, 'post.featured_image', '' ), $new_id, $this->logger );
            } catch ( \Throwable $t ) {
                $this->logger->write( 'featured sideload exception (certificate)', [ 'error' => $t->getMessage() ] );
            }

            $bg_raw = array_get( $cert, 'background_image', array_get( $cert, 'post.background_image', '' ) );
            $bg_url = extract_url( $bg_raw );
            if ( $bg_url !== '' ) {
                \update_post_meta( $new_id, '_ld_certificate_background_image_url', \esc_url_raw( $bg_url ) );
            } else {
                $this->logger->write( 'certificate background skipped: empty URL' );
            }

            $this->idmap->set( 'certificates', $old_id, $new_id );
        }
        return true;
    }

    private function stash_enrollments( $course, $enrollments ) {
        if ( $this->dry_run ) return;
        if ( empty( $enrollments ) ) return;
        $pool   = \get_option( \WPLMS_S1I_OPT_ENROLL_POOL, [] );
        $old_id = array_get( $course, 'old_id', null );
        $pool[ (string) $old_id ] = $enrollments;
        \update_option( \WPLMS_S1I_OPT_ENROLL_POOL, $pool, false );
        $this->logger->write( 'enrollments stashed', [ 'course_old_id'=>$old_id, 'count'=>count( (array) $enrollments ) ] );
    }

    /**
     * Creates a WP-Pro-Quiz master-record and links it to the sfwd-quiz post.
     * Works only if ProQuiz is loaded (LD is active).
     */
    private function ensure_proquiz_link( $quiz_post_id, $title ) {
        if ( ! class_exists( '\WP_Pro_Quiz_Model_Quiz' ) || ! class_exists( '\WP_Pro_Quiz_Model_QuizMapper' ) ) {
            $this->logger->write( 'ProQuiz not available, skipping link', [ 'quiz_post_id' => $quiz_post_id ] );
            return false;
        }
        try {
            $quiz   = new \WP_Pro_Quiz_Model_Quiz();
            $quiz->setName( $title ?: 'Quiz' );
            $mapper = new \WP_Pro_Quiz_Model_QuizMapper();
            $pro_id = $mapper->save( $quiz );
            if ( $pro_id ) {
                \update_post_meta( $quiz_post_id, 'quiz_pro', $pro_id );
                \update_post_meta( $quiz_post_id, 'quiz_pro_id', $pro_id );
                if ( function_exists( '\ld_update_quiz_meta' ) ) {
                    \ld_update_quiz_meta( $quiz_post_id, 'ld_quiz_pro', $pro_id );
                } else {
                    \update_post_meta( $quiz_post_id, 'ld_quiz_pro', $pro_id );
                }
                return true;
            }
        } catch ( \Throwable $e ) {
            $this->logger->write( 'create proquiz failed: ' . $e->getMessage(), [ 'quiz_post_id' => $quiz_post_id ] );
        }
        return false;
    }
}
