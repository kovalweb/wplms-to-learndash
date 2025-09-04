<?php
namespace WPLMS_S1I;

class Importer {
    private $logger;
    private $idmap;
    private $dry_run = false;
    private $recheck = false;
    private $stats_ref = null;

    public function __construct( Logger $logger, IdMap $idmap ) {
        $this->logger = $logger;
        $this->idmap  = $idmap;
    }

    public function set_dry_run( $dry ) { $this->dry_run = (bool) $dry; }
    public function set_recheck( $flag ) { $this->recheck = (bool) $flag; }

    public function run( array $payload ) {
        if ( ! is_array( $payload ) ) {
            throw new \RuntimeException( 'Invalid import payload' );
        }

        $stats = [
            'courses_created'       => 0,
            'courses_updated'       => 0,
            'lessons_created'       => 0,
            'lessons_updated'       => 0,
            'quizzes'               => 0,
            'assignments'           => 0,
            'certificates'          => 0,
            'orphans_units'         => 0,
            'orphans_quizzes'       => 0,
            'orphans_assignments'   => 0,
            'access_free'           => 0,
            'access_paid'           => 0,
            'access_closed'         => 0,
            'access_lead'           => 0,
            'lifetime_courses'      => 0,
            'lessons_zero_duration' => 0,
            'skipped'               => 0,
            'errors'                => 0,
            'images_downloaded'     => 0,
            'images_skipped_empty'  => 0,
            'images_errors'         => 0,
        ];

        $this->stats_ref =& $stats;

        // 1) Courses
        $courses = (array) array_get( $payload, 'courses', [] );
        foreach ( $courses as $course ) {
            try {
                $cres = $this->import_course( $course );
                $cid  = (int) array_get( $cres, 'id', 0 );
                if ( $cid ) {
                    if ( array_get( $cres, 'created' ) ) {
                        $stats['courses_created']++;
                    } else {
                        $stats['courses_updated']++;
                    }
                    $stats[ 'access_' . array_get( $cres, 'access', 'closed' ) ]++;
                    if ( array_get( $cres, 'lifetime' ) ) {
                        $stats['lifetime_courses']++;
                    }

                    // lessons
                    $units = (array) array_get( $course, 'units', [] );
                    $menu_order = 0;
                    foreach ( $units as $unit ) {
                        $lres = $this->import_lesson( $unit, $cid, $menu_order++ );
                        if ( $lres ) {
                            if ( array_get( $lres, 'created' ) ) {
                                $stats['lessons_created']++;
                            } else {
                                $stats['lessons_updated']++;
                            }
                            if ( array_get( $lres, 'zero_duration' ) ) {
                                $stats['lessons_zero_duration']++;
                            }
                        }
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

                    // attach certificate to course
                    $this->attach_course_certificate( $course, $cid );

                    // enrollments (stub)
                    $enroll = (array) array_get( $course, 'enrollments', [] );
                    $this->stash_enrollments( $course, $enroll );

                    if ( ! $this->dry_run && function_exists( 'learndash_course_set_steps' ) ) {
                        \learndash_course_set_steps( $cid );
                    }
                }

            } catch ( \Throwable $e ) {
                $stats['errors']++;
                $this->logger->write( 'course import failed: ' . $e->getMessage(), [ 'trace' => $e->getTraceAsString() ] );
            }
        }

        // 2) Assignments
        $assns = (array) array_get( $payload, 'assignments', [] );
        foreach ( $assns as $assn ) {
            try {
                $course_old_id = (int) array_get( $assn, 'links.course', array_get( $assn, 'course', 0 ) );
                if ( is_array( $course_old_id ) ) { $course_old_id = reset( $course_old_id ); }
                $lesson_old_id = (int) array_get( $assn, 'links.unit', array_get( $assn, 'unit', 0 ) );
                if ( is_array( $lesson_old_id ) ) { $lesson_old_id = reset( $lesson_old_id ); }

                $course_new_id = $course_old_id ? $this->idmap->get( 'courses', $course_old_id ) : 0;
                $lesson_new_id = $lesson_old_id ? $this->idmap->get( 'units', $lesson_old_id ) : 0;
                $is_orphan     = ( $course_old_id && ! $course_new_id ) || ( $lesson_old_id && ! $lesson_new_id );
                if ( ! $course_old_id && ! $lesson_old_id ) { $is_orphan = true; }

                $ok = $this->import_assignment( $assn, $course_new_id, $lesson_new_id, $is_orphan );
                if ( $ok ) {
                    if ( $is_orphan ) {
                        $stats['orphans_assignments']++;
                    } else {
                        $stats['assignments']++;
                    }
                }
            } catch ( \Throwable $e ) {
                $stats['errors']++;
                $this->logger->write( 'assignment import failed: ' . $e->getMessage(), [ 'trace' => $e->getTraceAsString() ] );
            }
        }

        // 3) Orphans
        $orph = (array) array_get( $payload, 'orphans', [] );
        foreach ( (array) array_get( $orph, 'units', [] ) as $unit ) {
            $lres = $this->import_lesson( $unit, 0, 0, true );
            if ( $lres ) {
                if ( array_get( $lres, 'created' ) ) {
                    $stats['lessons_created']++;
                } else {
                    $stats['lessons_updated']++;
                }
                if ( array_get( $lres, 'zero_duration' ) ) {
                    $stats['lessons_zero_duration']++;
                }
                $stats['orphans_units']++;
            }
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
        $this->logger->write( sprintf(
            'Media summary: %d %d %d',
            array_get( $stats, 'images_downloaded', 0 ),
            array_get( $stats, 'images_skipped_empty', 0 ),
            array_get( $stats, 'images_errors', 0 )
        ) );
        $this->stats_ref = null;
        return $stats;
    }

    private function import_course( $course ) {
        $old_id   = (int) array_get( $course, 'old_id', 0 );
        $existing = $this->idmap->get( 'courses', $old_id );

        $title   = array_get( $course, 'post.post_title', 'Untitled Course' );
        $content = ensure_oembed( array_get( $course, 'post.post_content', '' ), array_get( $course, 'embeds', [] ) );
        $slug    = normalize_slug( array_get( $course, 'current_slug', array_get( $course, 'post.post_name', '' ) ) );
        $orig_slug = normalize_slug( array_get( $course, 'original_slug', '' ) );
        $status  = strtolower( array_get( $course, 'post.status', 'publish' ) ) === 'publish' ? 'publish' : 'draft';

        // access & price
        $access = strtolower( array_get( $course, 'access_type_final', 'closed' ) );
        $price_type = 'closed';
        if ( $access === 'free' ) {
            $price_type = 'free';
        } elseif ( $access === 'paid' ) {
            $price_type = 'buy now';
        } elseif ( $access !== 'closed' && $access !== 'lead' ) {
            $access = 'closed';
        }

        $price = (float) array_get( $course, 'sale_price', 0 );
        if ( $price <= 0 ) $price = (float) array_get( $course, 'price', 0 );
        if ( $price <= 0 ) $price = (float) array_get( $course, 'regular_price', 0 );
        $price = round( $price, 2 );
        if ( $price_type === 'buy now' ) {
            if ( $price <= 0 ) {
                $this->logger->write( 'paid course without price, forcing closed', [ 'old_id' => $old_id ] );
                $price_type = 'closed';
                $access     = 'closed';
            }
        }

        $reason = '';
        if ( $this->recheck ) {
            $pid = (int) array_get( $course, 'meta.product_id', 0 );
            if ( $pid && get_post( $pid ) ) {
                $p_status = get_post_status( $pid );
                $terms    = get_the_terms( $pid, 'product_visibility' );
                $hidden   = false;
                if ( is_array( $terms ) ) {
                    foreach ( $terms as $t ) {
                        if ( in_array( $t->slug, [ 'exclude-from-catalog', 'exclude-from-search' ], true ) ) {
                            $hidden = true;
                            break;
                        }
                    }
                }
                $p_price = null;
                $meta_vals = [ get_post_meta( $pid, '_price', true ), get_post_meta( $pid, '_sale_price', true ), get_post_meta( $pid, '_regular_price', true ) ];
                foreach ( $meta_vals as $mv ) {
                    if ( is_numeric( $mv ) ) { $p_price = (float) $mv; break; }
                }
                if ( $p_status === 'publish' && ! $hidden && $p_price !== null ) {
                    $access = 'paid';
                    $price_type = 'buy now';
                    $price = round( $p_price, 2 );
                } else {
                    $access = 'closed';
                    $price_type = 'closed';
                    $reason = 'product_not_published';
                    if ( $p_status === 'publish' && ! $hidden && $p_price === null ) {
                        $reason = 'no_price_on_product';
                    }
                }
            }
        }
        if ( $reason ) {
            $this->logger->write( 'recheck adjusted access', [ 'old_id' => $old_id, 'reason' => $reason ] );
        }

        // duration
        $duration      = (int) array_get( $course, 'duration', 0 );
        $duration_unit = (string) array_get( $course, 'duration_unit', '' );
        $lifetime      = ( $duration_unit === 'years' && $duration >= 99999999 );

        $args = [
            'post_type'    => 'sfwd-courses',
            'post_status'  => $status,
            'post_title'   => $title,
            'post_content' => $content,
        ];
        if ( $slug ) $args['post_name'] = $slug;

        if ( $existing ) {
            $args['ID'] = $existing;
            if ( $this->dry_run ) {
                $this->logger->write( 'DRY: update course', [ 'id' => $existing, 'title' => $title ] );
                return [ 'id' => $existing, 'created' => false, 'access' => $access, 'lifetime' => $lifetime ];
            }
            $new_id = \wp_update_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                throw new \RuntimeException( 'wp_update_post failed: ' . $new_id->get_error_message() );
            }
            $created = false;
        } else {
            if ( $this->dry_run ) {
                $this->logger->write( 'DRY: create course', [ 'title' => $title ] );
                return [ 'id' => 0, 'created' => true, 'access' => $access, 'lifetime' => $lifetime ];
            }
            $new_id = \wp_insert_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                throw new \RuntimeException( 'wp_insert_post failed: ' . $new_id->get_error_message() );
            }
            \update_post_meta( $new_id, '_wplms_old_id', $old_id );
            $created = true;
        }

        // slug conflict tracking
        $actual_slug = \get_post_field( 'post_name', $new_id );
        if ( $slug && $actual_slug !== $slug ) {
            \update_post_meta( $new_id, '_wplms_s1_requested_slug', $slug );
        }
        if ( $slug ) \update_post_meta( $new_id, '_wplms_s1_current_slug', $slug );
        if ( $orig_slug ) \update_post_meta( $new_id, '_wplms_s1_original_slug', $orig_slug );

        // LearnDash settings
        if ( function_exists( 'learndash_update_setting' ) ) {
            \learndash_update_setting( $new_id, 'course_price_type', $price_type );
            if ( $price_type === 'buy now' && $price > 0 ) {
                \learndash_update_setting( $new_id, 'course_price', $price );
            } else {
                \learndash_update_setting( $new_id, 'course_price', '' );
            }
        } else {
            \update_post_meta( $new_id, 'course_price_type', $price_type );
            if ( $price_type === 'buy now' && $price > 0 ) {
                \update_post_meta( $new_id, 'course_price', $price );
            } else {
                \delete_post_meta( $new_id, 'course_price' );
            }
        }

        // service meta
        \update_post_meta( $new_id, '_wplms_s1_duration', $duration );
        \update_post_meta( $new_id, '_wplms_s1_duration_unit', $duration_unit );
        \update_post_meta( $new_id, '_wplms_s1_product_status', array_get( $course, 'meta.product_status', '' ) );
        \update_post_meta( $new_id, '_wplms_s1_product_catalog_visibility', array_get( $course, 'meta.product_catalog_visibility', '' ) );
        \update_post_meta( $new_id, '_wplms_s1_product_type', array_get( $course, 'meta.product_type', '' ) );
        \update_post_meta( $new_id, '_wplms_s1_product_inconsistent', array_get( $course, 'product_inconsistent', '' ) );
        if ( $reason ) { \update_post_meta( $new_id, '_wplms_s1_reason', $reason ); }

        // store raw curriculum for later orphan attachment
        \update_post_meta( $new_id, '_wplms_s1_curriculum_raw', (array) array_get( $course, 'curriculum_raw', [] ) );

        // featured image (м’яко)
        try {
            sideload_featured( array_get( $course, 'post.featured_image', '' ), $new_id, $this->logger, $this->stats_ref );
        } catch ( \Throwable $t ) {
            $this->logger->write( 'featured sideload exception (course)', [ 'error' => $t->getMessage() ] );
        }

        $this->idmap->set( 'courses', $old_id, $new_id, $slug );
        return [ 'id' => $new_id, 'created' => $created, 'access' => $access, 'lifetime' => $lifetime ];
    }

    private function import_lesson( $unit, $course_new_id = 0, $menu_order = 0, $is_orphan = false ) {
        $old_id   = (int) array_get( $unit, 'old_id', 0 );
        $existing = $this->idmap->get( 'units', $old_id );

        $title   = array_get( $unit, 'post.post_title', 'Untitled Lesson' );
        $content = ensure_oembed( array_get( $unit, 'post.post_content', '' ), array_get( $unit, 'embeds', [] ) );
        $slug    = normalize_slug( array_get( $unit, 'current_slug', array_get( $unit, 'post.post_name', '' ) ) );
        $orig_slug = normalize_slug( array_get( $unit, 'original_slug', '' ) );
        $status = strtolower( array_get( $unit, 'post.status', 'publish' ) ) === 'publish' ? 'publish' : 'draft';

        $duration      = (int) array_get( $unit, 'duration', 0 );
        $duration_unit = (string) array_get( $unit, 'duration_unit', '' );
        $zero_duration = ( $duration <= 0 );

        $args = [
            'post_type'    => 'sfwd-lessons',
            'post_status'  => $status,
            'post_title'   => $title,
            'post_content' => $content,
            'menu_order'   => (int) $menu_order,
            'post_parent'  => (int) $course_new_id,
        ];
        if ( $slug ) $args['post_name'] = $slug;

        if ( $existing ) {
            $args['ID'] = $existing;
            if ( $this->dry_run ) {
                $this->logger->write( 'DRY: update lesson', [ 'id' => $existing, 'course' => $course_new_id ] );
                return [ 'id' => $existing, 'created' => false, 'zero_duration' => $zero_duration ];
            }
            $new_id = \wp_update_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'lesson update failed: ' . $new_id->get_error_message(), [ 'old_id' => $old_id ] );
                return false;
            }
            $created = false;
        } else {
            if ( $this->dry_run ) {
                $this->logger->write( 'DRY: create lesson', [ 'title' => $title, 'course' => $course_new_id ] );
                return [ 'id' => 0, 'created' => true, 'zero_duration' => $zero_duration ];
            }
            $new_id = \wp_insert_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'lesson insert failed: ' . $new_id->get_error_message(), [ 'old_id' => $old_id ] );
                return false;
            }
            \update_post_meta( $new_id, '_wplms_old_id', $old_id );
            if ( $course_new_id ) {
                \update_post_meta( $new_id, 'course_id', (int) $course_new_id );
            }
            $created = true;
        }

        if ( $is_orphan ) {
            \update_post_meta( $new_id, '_wplms_orphan', 1 );
        }

        // slug metadata
        $actual_slug = \get_post_field( 'post_name', $new_id );
        if ( $slug && $actual_slug !== $slug ) {
            \update_post_meta( $new_id, '_wplms_s1_requested_slug', $slug );
        }
        if ( $slug ) \update_post_meta( $new_id, '_wplms_s1_current_slug', $slug );
        if ( $orig_slug ) \update_post_meta( $new_id, '_wplms_s1_original_slug', $orig_slug );

        // duration meta
        \update_post_meta( $new_id, '_wplms_s1_duration', $duration );
        \update_post_meta( $new_id, '_wplms_s1_duration_unit', $duration_unit );

        // featured image
        try {
            sideload_featured( array_get( $unit, 'post.featured_image', '' ), $new_id, $this->logger, $this->stats_ref );
        } catch ( \Throwable $t ) {
            $this->logger->write( 'featured sideload exception (lesson)', [ 'error' => $t->getMessage() ] );
        }

        $this->idmap->set( 'units', $old_id, $new_id, $slug );

        // assignments nested
        $assignments = (array) array_get( $unit, 'assignments', [] );
        foreach ( $assignments as $assn ) {
            $this->import_assignment( $assn, $course_new_id, $new_id, $is_orphan );
        }

        return [ 'id' => $new_id, 'created' => $created, 'zero_duration' => $zero_duration ];
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
            $links = (array) array_get( $quiz, 'links', [] );
            if ( ! empty( $links ) ) { \update_post_meta( $new_id, '_wplms_s1_links', $links ); }

            try {
                sideload_featured( array_get( $quiz, 'post.featured_image', '' ), $new_id, $this->logger, $this->stats_ref );
            } catch ( \Throwable $t ) {
                $this->logger->write( 'featured sideload exception (quiz)', [ 'error' => $t->getMessage() ] );
            }

            // >>> ProQuiz master record & link (fixes "Missing ProQuiz Associated Settings")
            $pro_id = $this->ensure_proquiz_link( $new_id, $title );

            // Import questions if provided and ProQuiz master exists.
            if ( $pro_id ) {
                $questions = (array) array_get( $quiz, 'questions', [] );
                if ( $questions ) {
                    $this->import_quiz_questions( $pro_id, $questions );
                }
            }

            $this->idmap->set( 'quizzes', $old_id, $new_id, $slug );
        }

        return true;
    }

    private function import_assignment( $assn, $course_new_id = 0, $lesson_new_id = 0, $is_orphan = false ) {
        $old_id   = (int) array_get( $assn, 'old_id', 0 );
        $existing = $this->idmap->get( 'assignments', $old_id );
        $course_old_id = 0;
        $lesson_old_id = 0;

        // Resolve parents from payload if not explicitly provided.
        if ( ! $course_new_id ) {
            $course_old_id = array_get( $assn, 'links.course', array_get( $assn, 'course', 0 ) );
            if ( is_array( $course_old_id ) ) { $course_old_id = reset( $course_old_id ); }
            $course_new_id = $course_old_id ? $this->idmap->get( 'courses', (int) $course_old_id ) : 0;
        }
        if ( ! $lesson_new_id ) {
            $lesson_old_id = array_get( $assn, 'links.unit', array_get( $assn, 'unit', 0 ) );
            if ( is_array( $lesson_old_id ) ) { $lesson_old_id = reset( $lesson_old_id ); }
            $lesson_new_id = $lesson_old_id ? $this->idmap->get( 'units', (int) $lesson_old_id ) : 0;
        }
        if ( ! $is_orphan && ! $course_new_id && ! $lesson_new_id ) {
            $is_orphan = true;
        }

        $title   = array_get( $assn, 'post.post_title', 'Assignment' );
        $content = ensure_oembed( array_get( $assn, 'post.post_content', '' ), array_get( $assn, 'embeds', [] ) );
        $slug    = normalize_slug( array_get( $assn, 'post.post_name', '' ) );
        $status  = strtolower( array_get( $assn, 'post.status', 'publish' ) ) === 'publish' ? 'publish' : 'draft';

        $args = [
            'post_type'    => 'sfwd-assignment',
            'post_status'  => $status,
            'post_title'   => $title,
            'post_content' => $content,
            'post_parent'  => (int) ( $lesson_new_id ?: $course_new_id ),
        ];
        if ( $slug ) { $args['post_name'] = $slug; }

        if ( $existing ) {
            $args['ID'] = $existing;
            if ( $this->dry_run ) {
                $this->logger->write( 'DRY: update assignment', [ 'id' => $existing, 'course' => $course_new_id, 'lesson' => $lesson_new_id ] );
                return true;
            }
            $new_id = \wp_update_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'assignment update failed: ' . $new_id->get_error_message(), [ 'old_id' => $old_id ] );
                return false;
            }
            $new_id = (int) $new_id;
        } else {
            if ( $this->dry_run ) {
                $this->logger->write( 'DRY: create assignment', [ 'title' => $title, 'course' => $course_new_id, 'lesson' => $lesson_new_id ] );
                return true;
            }
            $new_id = \wp_insert_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'assignment insert failed: ' . $new_id->get_error_message(), [ 'old_id' => $old_id ] );
                return false;
            }
            \update_post_meta( $new_id, '_wplms_old_id', $old_id );
            $this->idmap->set( 'assignments', $old_id, $new_id, $slug );
        }

        \update_post_meta( $new_id, '_wplms_s1_links', [ 'course' => (int) $course_old_id, 'unit' => (int) $lesson_old_id ] );
        if ( $course_new_id ) { \update_post_meta( $new_id, 'course_id', (int) $course_new_id ); }
        if ( $lesson_new_id ) { \update_post_meta( $new_id, 'lesson_id', (int) $lesson_new_id ); }
        if ( $is_orphan ) {
            \update_post_meta( $new_id, '_wplms_orphan', 1 );
        } else {
            \delete_post_meta( $new_id, '_wplms_orphan' );
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

            $fimg = array_get( $cert, 'post.featured_image', '' );
            if ( extract_url( $fimg ) !== '' ) {
                try {
                    sideload_featured( $fimg, $new_id, $this->logger, $this->stats_ref );
                } catch ( \Throwable $t ) {
                    $this->logger->write( 'featured sideload exception (certificate)', [ 'error' => $t->getMessage() ] );
                }
            } else {
                if ( is_array( $this->stats_ref ) ) {
                    $this->stats_ref['images_skipped_empty'] = array_get( $this->stats_ref, 'images_skipped_empty', 0 ) + 1;
                }
            }

            $bg_raw = array_get( $cert, 'background_image', array_get( $cert, 'post.background_image', '' ) );
            $bg_url = extract_url( $bg_raw );
            if ( $bg_url !== '' ) {
                \update_post_meta( $new_id, '_ld_certificate_background_image_url', \esc_url_raw( $bg_url ) );
            }

            $this->idmap->set( 'certificates', $old_id, $new_id, $slug );
        }
        return true;
    }

    private function attach_course_certificate( $course, $course_new_id ) {
        $course_old_id = (int) array_get( $course, 'old_id', 0 );
        $raw = array_get( $course, 'vibe.vibe_certificate_template', 0 );
        if ( is_array( $raw ) ) {
            $raw = reset( $raw );
        }
        $cert_old_id = (int) $raw;
        if ( $cert_old_id <= 0 ) {
            return;
        }
        $cert_new_id = (int) $this->idmap->get( 'certificates', $cert_old_id );
        $log = [
            'course_old_id' => $course_old_id,
            'course_new_id' => $course_new_id,
            'cert_old_id'   => $cert_old_id,
            'cert_new_id'   => $cert_new_id,
        ];
        if ( $this->dry_run ) {
            $this->logger->write( 'DRY: course certificate', $log );
            return;
        }
        if ( $cert_new_id > 0 ) {
            if ( function_exists( '\learndash_update_setting' ) ) {
                \learndash_update_setting( $course_new_id, 'course_certificate', $cert_new_id );
            } else {
                \update_post_meta( $course_new_id, 'course_certificate', $cert_new_id );
            }
            $this->logger->write( 'course certificate attached', $log );
        } else {
            $this->logger->write( 'course certificate missing', $log );
        }
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

    private function import_quiz_questions( $pro_id, array $questions ) {
        $sort = 0;

        foreach ( $questions as $q ) {
            $type = strtolower( array_get( $q, 'type', '' ) );
            if ( ! in_array( $type, [ 'single', 'multiple' ], true ) ) {
                continue;
            }

            $title    = (string) array_get( $q, 'title', '' );
            $question = (string) array_get( $q, 'question', '' );
            $points   = (int) array_get( $q, 'points', 0 );
            $sort_val = (int) array_get( $q, 'sort', $sort++ );
            $answers  = (array) array_get( $q, 'answers', [] );
            $correct  = array_get( $q, 'correct', [] );
            if ( ! is_array( $correct ) ) {
                $correct = [ $correct ];
            }

            if ( class_exists( '\WpProQuiz_Model_Question' ) && class_exists( '\WpProQuiz_Model_QuestionMapper' ) && class_exists( '\WpProQuiz_Model_AnswerTypes' ) ) {
                try {
                    $qobj = new \WpProQuiz_Model_Question();
                    $qobj->setQuizId( $pro_id );
                    $qobj->setTitle( $title );
                    $qobj->setQuestion( $question );
                    $qobj->setPoints( $points );
                    $qobj->setSort( $sort_val );
                    $qobj->setAnswerType( $type === 'multiple' ? 'multiple' : 'single' );

                    $ans_objs = [];
                    foreach ( $answers as $idx => $ans ) {
                        $ao = new \WpProQuiz_Model_AnswerTypes();
                        $ao->setAnswer( (string) $ans );
                        $ao->setCorrect( in_array( $idx, $correct ) );
                        $ao->setPoints( $points );
                        $ans_objs[] = $ao;
                    }
                    $qobj->setAnswerData( $ans_objs );

                    $mapper = new \WpProQuiz_Model_QuestionMapper();
                    $mapper->save( $qobj );
                } catch ( \Throwable $e ) {
                    $this->logger->write( 'question insert failed: ' . $e->getMessage(), [ 'quiz' => $pro_id ] );
                }
                continue;
            }

            // Fallback direct insert if ProQuiz classes unavailable.
            global $wpdb;
            $table = $wpdb->prefix . 'wp_pro_quiz_question';
            $answer_data = [];
            foreach ( $answers as $idx => $ans ) {
                $answer_data[] = [
                    'answer'  => (string) $ans,
                    'correct' => in_array( $idx, $correct ) ? 1 : 0,
                    'points'  => $points,
                ];
            }
            $wpdb->insert( $table, [
                'quiz_id'        => $pro_id,
                'title'          => $title,
                'question'       => $question,
                'points'         => $points,
                'sort'           => $sort_val,
                'answer_type'    => $type === 'multiple' ? 'multiple' : 'single',
                'answer_data'    => maybe_serialize( $answer_data ),
                'correct_answer' => maybe_serialize( $correct ),
            ] );
        }
    }

    /**
     * Creates a WP-Pro-Quiz master-record and links it to the sfwd-quiz post.
     * Works only if ProQuiz is loaded (LD is active).
     *
     * @return int Master quiz ID or 0 on failure.
     */
    private function ensure_proquiz_link( $quiz_post_id, $title ) {
        $existing = function_exists( '\learndash_get_setting' )
            ? (int) \learndash_get_setting( $quiz_post_id, 'ld_pro_quiz' )
            : (int) \get_post_meta( $quiz_post_id, 'ld_pro_quiz', true );

        if ( $existing > 0 ) {
            return $existing;
        }

        try {
            $pro_id = create_proquiz_master( $quiz_post_id, [
                'name'  => $title ?: 'Quiz',
                'title' => $title ?: 'Quiz',
            ] );
            if ( $pro_id ) {
                \update_post_meta( $quiz_post_id, 'quiz_pro', $pro_id );
                \update_post_meta( $quiz_post_id, 'quiz_pro_id', $pro_id );
                if ( function_exists( '\learndash_update_setting' ) ) {
                    \learndash_update_setting( $quiz_post_id, 'ld_pro_quiz', $pro_id );
                } else {
                    \update_post_meta( $quiz_post_id, 'ld_pro_quiz', $pro_id );
                }
                return $pro_id;
            }
        } catch ( \Throwable $e ) {
            $this->logger->write( 'create proquiz failed: ' . $e->getMessage(), [ 'quiz_post_id' => $quiz_post_id ] );
        }

        return 0;
    }
}
