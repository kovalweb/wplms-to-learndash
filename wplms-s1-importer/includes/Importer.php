<?php
namespace WPLMS_S1I;

class Importer {
    private $logger;
    private $idmap;
    private $dry_run = false;
    private $recheck = false;
    private $suppress_emails = false;
    private $stats_ref = null;
    private $term_index = [
        'course-cat' => [ 'slug' => [], 'id' => [] ],
        'course-tag' => [ 'slug' => [], 'id' => [] ],
    ];

    public function __construct( Logger $logger, IdMap $idmap ) {
        $this->logger = $logger;
        $this->idmap  = $idmap;
    }

    public function set_dry_run( $dry ) { $this->dry_run = (bool) $dry; }
    public function set_recheck( $flag ) { $this->recheck = (bool) $flag; }
    public function set_disable_emails( $flag ) { $this->suppress_emails = (bool) $flag; }

    public function run( array $payload ) {
        if ( ! is_array( $payload ) ) {
            throw new \RuntimeException( 'Invalid import payload' );
        }

        $suppress = $this->suppress_emails;
        if ( $suppress ) {
            // Temporarily suppress emails and external notifications during import.
            \add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
            \add_filter( 'learndash_notifications_enabled', '__return_false', PHP_INT_MAX );
            \add_filter( 'ld_notifications_send_emails', '__return_false', PHP_INT_MAX );
        }

        try {
        $stats = [
            'courses_created'       => 0,
            'courses_updated'       => 0,
            'lessons_created'       => 0,
            'lessons_updated'       => 0,
            'quizzes'               => 0,
            'assignments'           => 0,
            'certificates_created'  => 0,
            'certificates_updated'  => 0,
            'orphans_units'         => 0,
            'orphans_quizzes'       => 0,
            'orphans_assignments'   => 0,
            'orphans_certificates'  => 0,
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
            'course_cat_terms_created' => 0,
            'course_cat_terms_updated' => 0,
            'course_tag_terms_created' => 0,
            'course_tag_terms_updated' => 0,
            'course_terms_attached'   => 0,
            'courses_linked_to_products' => 0,
            'product_not_found_for_course' => 0,
            'courses_forced_closed_no_product' => 0,
            'courses_forced_closed_examples' => [],
            'linked_publish'          => 0,
            'linked_draft'            => 0,
            'certificates_attached'   => 0,
            'certificates_missing'    => 0,
            'certificates_already_attached' => 0,
            'certificates_missing_examples' => [],
            'orphans_imported' => [ 'units'=>0, 'quizzes'=>0, 'assignments'=>0, 'certificates'=>0 ],
            'orphans_skipped'  => [ 'units'=>0, 'quizzes'=>0, 'assignments'=>0, 'certificates'=>0 ],
        ];

        $this->stats_ref =& $stats;
        $courses = (array) array_get( $payload, 'courses', [] );

        // commerce linking preflight
        $stats['commerce_linking_preflight'] = $this->preflight_commerce_linking( $courses );

        // 0) Taxonomies
        $taxonomies = (array) array_get( $payload, 'taxonomies', [] );
        $has_tax = ! empty( array_get( $taxonomies, 'course-cat', [] ) ) || ! empty( array_get( $taxonomies, 'course-tag', [] ) );
        $stats['tax_preflight'] = $this->ensure_taxonomies();
        if ( $has_tax ) {
            $tax_stats = $this->import_taxonomies( $taxonomies );
            $stats['course_cat_terms_created'] = array_get( $tax_stats, 'course-cat.created', 0 );
            $stats['course_cat_terms_updated'] = array_get( $tax_stats, 'course-cat.updated', 0 );
            $stats['course_tag_terms_created'] = array_get( $tax_stats, 'course-tag.created', 0 );
            $stats['course_tag_terms_updated'] = array_get( $tax_stats, 'course-tag.updated', 0 );
        }

        // 1) Courses
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

                    // attach terms
                    $stats['course_terms_attached'] += $this->attach_course_terms(
                        $cid,
                        (array) array_get( $course, 'category_slugs', [] ),
                        (array) array_get( $course, 'tag_slugs', [] )
                    );

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
                        $cinfo = $this->import_certificate( $cert );
                        $cid_cert = (int) array_get( $cinfo, 'id', 0 );
                        if ( $cid_cert ) {
                            if ( array_get( $cinfo, 'created' ) ) {
                                $stats['certificates_created']++;
                            } else {
                                $stats['certificates_updated']++;
                            }
                        }
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
                $stats['orphans_imported']['units']++;
            } else {
                $stats['orphans_skipped']['units']++;
            }
        }
        foreach ( (array) array_get( $orph, 'quizzes', [] ) as $quiz ) {
            $ok = $this->import_quiz( $quiz, 0, true );
            if ( $ok ) {
                $stats['orphans_quizzes']++;
                $stats['orphans_imported']['quizzes']++;
            } else {
                $stats['orphans_skipped']['quizzes']++;
            }
        }
        foreach ( (array) array_get( $orph, 'assignments', [] ) as $assn ) {
            $ok = $this->import_assignment( $assn, 0, 0, true );
            if ( $ok ) {
                $stats['orphans_assignments']++;
                $stats['orphans_imported']['assignments']++;
            } else {
                $stats['orphans_skipped']['assignments']++;
            }
        }
        foreach ( (array) array_get( $orph, 'certificates', [] ) as $cert ) {
            $cinfo = $this->import_certificate( $cert, true );
            $cid = (int) array_get( $cinfo, 'id', 0 );
            if ( $cid ) {
                if ( array_get( $cinfo, 'created' ) ) {
                    $stats['certificates_created']++;
                } else {
                    $stats['certificates_updated']++;
                }
                $stats['orphans_certificates']++;
                $stats['orphans_imported']['certificates']++;
            } else {
                $stats['orphans_skipped']['certificates']++;
            }
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
        $result = $stats;
        $this->stats_ref = null;
        return $result;
        } finally {
            if ( $suppress ) {
                \remove_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );
                \remove_filter( 'learndash_notifications_enabled', '__return_false', PHP_INT_MAX );
                \remove_filter( 'ld_notifications_send_emails', '__return_false', PHP_INT_MAX );
            }
        }
    }

    private function preflight_commerce_linking( array $courses ) {
        $active = class_exists( '\\WC_Product' ) && (
            defined( 'LEARNDASH_WOOCOMMERCE_VERSION' ) ||
            defined( 'LEARNDASH_WOOCOMMERCE_PLUGIN_VERSION' ) ||
            defined( 'LEARNDASH_WOO_VERSION' )
        );

        $products_total = 0;
        $sample_sku     = '';
        if ( $active ) {
            $counts = \wp_count_posts( 'product' );
            if ( $counts ) {
                $products_total = array_sum( (array) $counts );
            }
            $sample = \get_posts( [
                'post_type'   => 'product',
                'post_status' => 'any',
                'meta_key'    => '_sku',
                'numberposts' => 1,
                'fields'      => 'ids',
            ] );
            if ( $sample ) {
                $sample_sku = (string) \get_post_meta( $sample[0], '_sku', true );
            }
        }

        $with_sku   = 0;
        $with_cert  = 0;
        $missing    = [];
        foreach ( $courses as $course ) {
            $sku = array_get( $course, 'commerce.product_sku', '' );
            if ( $sku ) {
                $with_sku++;
            } else {
                $slug = normalize_slug( array_get( $course, 'current_slug', array_get( $course, 'post.post_name', '' ) ) );
                if ( $slug ) {
                    $missing[] = $slug;
                }
            }

            $has_cert = false;
            if ( array_get( $course, 'certificate_ref' ) ) {
                $has_cert = true;
            }
            if ( (int) array_get( $course, 'certificate_old_id', 0 ) ) {
                $has_cert = true;
            }
            if ( (int) array_get( $course, 'certificates.0.old_id', 0 ) ) {
                $has_cert = true;
            }
            $raw = array_get( $course, 'vibe.vibe_certificate_template', 0 );
            if ( is_array( $raw ) ) { $raw = reset( $raw ); }
            if ( (int) $raw > 0 ) { $has_cert = true; }
            if ( $has_cert ) { $with_cert++; }
        }

        return [
            'woocommerce_for_learndash' => $active ? 'detected' : 'missing',
            'products_total'            => $products_total,
            'courses_in_payload'        => count( $courses ),
            'courses_with_product_sku'  => $with_sku,
            'courses_with_certificate'    => $with_cert,
            'missing_course_refs'       => array_slice( $missing, 0, 5 ),
            'sample_product_sku'        => $sample_sku,
        ];
    }

    private function ensure_taxonomies() {
        $status = [];

        $status['ld_course_category'] = taxonomy_exists( 'ld_course_category' ) ? 'exists' : 'missing';
        $status['ld_course_tag']      = taxonomy_exists( 'ld_course_tag' ) ? 'exists' : 'missing';

        if ( defined( 'WPLMS_S1_IMPORTER_DEBUG_FLUSH' ) && WPLMS_S1_IMPORTER_DEBUG_FLUSH ) {
            \flush_rewrite_rules( false );
        }

        $perm = hv_ld_get_course_category_base();

        $status['permalink_base']        = $perm['base'];
        $status['permalink_base_source'] = $perm['source'];
        $status['permalink_sample_url']  = $perm['sample_url'];

        return $status;
    }

    private function import_taxonomies( array $taxonomies ) {
        $result = [
            'course-cat' => [ 'created' => 0, 'updated' => 0 ],
            'course-tag' => [ 'created' => 0, 'updated' => 0 ],
        ];

        // course-cat -> ld_course_category
        $cats = (array) array_get( $taxonomies, 'course-cat', [] );
        if ( $cats && taxonomy_exists( 'ld_course_category' ) ) {
            usort( $cats, function ( $a, $b ) {
                return count( (array) array_get( $a, 'path', [] ) ) <=> count( (array) array_get( $b, 'path', [] ) );
            } );
            foreach ( $cats as $term ) {
                $slug = normalize_slug( array_get( $term, 'slug', '' ) );
                if ( ! $slug ) continue;
                $name = (string) array_get( $term, 'name', $slug );

                $path = (array) array_get( $term, 'path', [] );
                $parent_slug = $path ? normalize_slug( end( $path ) ) : '';
                if ( $parent_slug && ! isset( $this->term_index['course-cat']['slug'][ $parent_slug ] ) ) {
                    $p = \get_term_by( 'slug', $parent_slug, 'ld_course_category' );
                    if ( $p ) {
                        $pid = (int) $p->term_id;
                    } elseif ( $this->dry_run ) {
                        $this->logger->write( 'DRY: create parent term', [ 'taxonomy' => 'ld_course_category', 'slug' => $parent_slug ] );
                        $pid = 0;
                    } else {
                        $ins = \wp_insert_term( $parent_slug, 'ld_course_category', [ 'slug' => $parent_slug ] );
                        if ( \is_wp_error( $ins ) ) {
                            $this->logger->write( 'ld_course_category parent insert failed', [ 'slug' => $parent_slug, 'error' => $ins->get_error_message() ] );
                            $pid = 0;
                        } else {
                            $pid = (int) array_get( $ins, 'term_id', 0 );
                        }
                    }
                    $this->term_index['course-cat']['slug'][ $parent_slug ] = $pid;
                }
                $parent_id = $parent_slug ? ( $this->term_index['course-cat']['slug'][ $parent_slug ] ?? 0 ) : 0;

                $existing = \get_term_by( 'slug', $slug, 'ld_course_category' );
                if ( $existing ) {
                    $term_id = (int) $existing->term_id;
                    $this->term_index['course-cat']['slug'][ $slug ] = $term_id;
                    $this->term_index['course-cat']['id'][ (int) array_get( $term, 'term_id', 0 ) ] = $term_id;
                    if ( $existing->name !== $name || ( $parent_id && (int) $existing->parent !== $parent_id ) ) {
                        if ( $this->dry_run ) {
                            $this->logger->write( 'DRY: update term', [ 'taxonomy' => 'ld_course_category', 'slug' => $slug ] );
                        } else {
                            $args = [ 'name' => $name ];
                            if ( $parent_id ) $args['parent'] = $parent_id;
                            \wp_update_term( $term_id, 'ld_course_category', $args );
                        }
                        $result['course-cat']['updated']++;
                    }
                } else {
                    if ( $this->dry_run ) {
                        $this->logger->write( 'DRY: create term', [ 'taxonomy' => 'ld_course_category', 'slug' => $slug ] );
                        $term_id = 0;
                    } else {
                        $args = [ 'slug' => $slug ];
                        if ( $parent_id ) $args['parent'] = $parent_id;
                        $inserted = \wp_insert_term( $name, 'ld_course_category', $args );
                        if ( \is_wp_error( $inserted ) ) {
                            $this->logger->write( 'ld_course_category insert failed', [ 'slug' => $slug, 'error' => $inserted->get_error_message() ] );
                            $term_id = 0;
                        } else {
                            $term_id = (int) array_get( $inserted, 'term_id', 0 );
                        }
                    }
                    $this->term_index['course-cat']['slug'][ $slug ] = $term_id;
                    $this->term_index['course-cat']['id'][ (int) array_get( $term, 'term_id', 0 ) ] = $term_id;
                    $result['course-cat']['created']++;
                }
            }
        } elseif ( $cats ) {
            $this->logger->write( 'ld_course_category taxonomy missing', [ 'fatal' => true ] );
        }

        // course-tag -> ld_course_tag
        $tags = (array) array_get( $taxonomies, 'course-tag', [] );
        if ( $tags && taxonomy_exists( 'ld_course_tag' ) ) {
            foreach ( $tags as $term ) {
                $slug = normalize_slug( array_get( $term, 'slug', '' ) );
                if ( ! $slug ) continue;
                $name = (string) array_get( $term, 'name', $slug );

                $existing = \get_term_by( 'slug', $slug, 'ld_course_tag' );
                if ( $existing ) {
                    $term_id = (int) $existing->term_id;
                    $this->term_index['course-tag']['slug'][ $slug ] = $term_id;
                    $this->term_index['course-tag']['id'][ (int) array_get( $term, 'term_id', 0 ) ] = $term_id;
                    if ( $existing->name !== $name ) {
                        if ( $this->dry_run ) {
                            $this->logger->write( 'DRY: update term', [ 'taxonomy' => 'ld_course_tag', 'slug' => $slug ] );
                        } else {
                            \wp_update_term( $term_id, 'ld_course_tag', [ 'name' => $name ] );
                        }
                        $result['course-tag']['updated']++;
                    }
                } else {
                    if ( $this->dry_run ) {
                        $this->logger->write( 'DRY: create term', [ 'taxonomy' => 'ld_course_tag', 'slug' => $slug ] );
                        $term_id = 0;
                    } else {
                        $inserted = \wp_insert_term( $name, 'ld_course_tag', [ 'slug' => $slug ] );
                        if ( \is_wp_error( $inserted ) ) {
                            $this->logger->write( 'ld_course_tag insert failed', [ 'slug' => $slug, 'error' => $inserted->get_error_message() ] );
                            $term_id = 0;
                        } else {
                            $term_id = (int) array_get( $inserted, 'term_id', 0 );
                        }
                    }
                    $this->term_index['course-tag']['slug'][ $slug ] = $term_id;
                    $this->term_index['course-tag']['id'][ (int) array_get( $term, 'term_id', 0 ) ] = $term_id;
                    $result['course-tag']['created']++;
                }
            }
        } elseif ( $tags ) {
            $this->logger->write( 'ld_course_tag taxonomy missing', [ 'fatal' => true ] );
        }

        return $result;
    }

    private function attach_course_terms( $course_id, array $category_slugs, array $tag_slugs ) {
        $attached = 0;

        $cat_ids = [];
        foreach ( $category_slugs as $slug ) {
            $slug = normalize_slug( $slug );
            if ( isset( $this->term_index['course-cat']['slug'][ $slug ] ) ) {
                $tid = (int) $this->term_index['course-cat']['slug'][ $slug ];
                if ( $tid ) $cat_ids[] = $tid;
            } else {
                $this->logger->write( 'missing ld_course_category term for slug', [ 'slug' => $slug ] );
            }
        }
        if ( $cat_ids ) {
            if ( $this->dry_run || $course_id <= 0 ) {
                $this->logger->write( 'DRY: attach course categories', [ 'course' => $course_id, 'terms' => $cat_ids ] );
                $attached += count( $cat_ids );
            } else {
                $r = \wp_set_object_terms( $course_id, $cat_ids, 'ld_course_category', false );
                if ( \is_wp_error( $r ) ) {
                    $this->logger->write( 'ld_course_category attach failed', [ 'course' => $course_id, 'error' => $r->get_error_message() ] );
                } else {
                    $attached += count( $cat_ids );
                }
            }
        }

        $tag_ids = [];
        foreach ( $tag_slugs as $slug ) {
            $slug = normalize_slug( $slug );
            if ( isset( $this->term_index['course-tag']['slug'][ $slug ] ) ) {
                $tid = (int) $this->term_index['course-tag']['slug'][ $slug ];
                if ( $tid ) $tag_ids[] = $tid;
            } else {
                $this->logger->write( 'missing ld_course_tag term for slug', [ 'slug' => $slug ] );
            }
        }
        if ( $tag_ids ) {
            if ( $this->dry_run || $course_id <= 0 ) {
                $this->logger->write( 'DRY: attach course tags', [ 'course' => $course_id, 'terms' => $tag_ids ] );
                $attached += count( $tag_ids );
            } else {
                $r = \wp_set_object_terms( $course_id, $tag_ids, 'ld_course_tag', false );
                if ( \is_wp_error( $r ) ) {
                    $this->logger->write( 'ld_course_tag attach failed', [ 'course' => $course_id, 'error' => $r->get_error_message() ] );
                } else {
                    $attached += count( $tag_ids );
                }
            }
        }

        return $attached;
    }

    private function import_course( $course ) {
        $old_id = (int) array_get( $course, 'old_id', 0 );
        $slug   = normalize_slug( array_get( $course, 'current_slug', array_get( $course, 'post.post_name', '' ) ) );
        $existing = $this->idmap->get( 'courses', $old_id );
        if ( ! $existing && $slug ) {
            $f = get_posts( [
                'post_type'      => 'sfwd-courses',
                'name'           => $slug,
                'post_status'    => 'any',
                'numberposts'    => 1,
                'fields'         => 'ids',
            ] );
            if ( $f ) $existing = (int) $f[0];
        }

        $title   = array_get( $course, 'post.post_title', 'Untitled Course' );
        $content = ensure_oembed( array_get( $course, 'post.post_content', '' ), array_get( $course, 'embeds', [] ) );
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
                    if ( is_array( $this->stats_ref ) ) {
                        $this->stats_ref['courses_forced_closed_no_product'] = array_get( $this->stats_ref, 'courses_forced_closed_no_product', 0 ) + 1;
                        if ( count( $this->stats_ref['courses_forced_closed_examples'] ) < 10 ) {
                            $this->stats_ref['courses_forced_closed_examples'][] = $slug;
                        }
                    }
                }
            } else {
                $access = 'closed';
                $price_type = 'closed';
                $reason = 'no_product';
                if ( is_array( $this->stats_ref ) ) {
                    $this->stats_ref['courses_forced_closed_no_product'] = array_get( $this->stats_ref, 'courses_forced_closed_no_product', 0 ) + 1;
                    if ( count( $this->stats_ref['courses_forced_closed_examples'] ) < 10 ) {
                        $this->stats_ref['courses_forced_closed_examples'][] = $slug;
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

        // Link to WooCommerce product
        $sku        = array_get( $course, 'commerce.product_sku', '' );
        $product_id = 0;
        $weak_match = false;

        if ( $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
            $product_id = (int) \wc_get_product_id_by_sku( $sku );
        }
        if ( ! $product_id ) {
            $old_pid = (int) array_get( $course, 'meta.product_id', 0 );
            if ( $old_pid ) {
                $found = \get_posts( [
                    'post_type'   => 'product',
                    'post_status' => 'any',
                    'meta_key'    => '_wplms_old_product_id',
                    'meta_value'  => $old_pid,
                    'fields'      => 'ids',
                    'numberposts' => 1,
                ] );
                if ( $found ) {
                    $product_id = (int) $found[0];
                }
            }
        }
        if ( ! $product_id ) {
            if ( $slug ) {
                $found = \get_posts( [
                    'post_type'   => 'product',
                    'post_status' => 'any',
                    'name'        => $slug,
                    'fields'      => 'ids',
                    'numberposts' => 1,
                ] );
                if ( $found ) {
                    $product_id = (int) $found[0];
                    $weak_match = true;
                }
            }
            if ( ! $product_id && $title ) {
                $page = \get_page_by_title( $title, OBJECT, 'product' );
                if ( $page ) {
                    $product_id = (int) $page->ID;
                    $weak_match = true;
                }
            }
            if ( $weak_match && $product_id ) {
                $this->logger->write( 'weak_match_by_title', [ 'course' => $new_id, 'product' => $product_id ] );
            }
        }

        if ( $product_id ) {
            hv_ld_link_course_to_product( $new_id, $product_id, $this->logger );
            if ( is_array( $this->stats_ref ) ) {
                $this->stats_ref['courses_linked_to_products'] = array_get( $this->stats_ref, 'courses_linked_to_products', 0 ) + 1;
                if ( \get_post_status( $product_id ) === 'publish' ) {
                    $this->stats_ref['linked_publish'] = array_get( $this->stats_ref, 'linked_publish', 0 ) + 1;
                } else {
                    $this->stats_ref['linked_draft'] = array_get( $this->stats_ref, 'linked_draft', 0 ) + 1;
                }
            }
        } else {
            $this->logger->write( 'product_not_found_for_course', [
                'course_id'      => $new_id,
                'course_slug'    => $slug,
                'sku'            => $sku,
                'old_product_id' => (int) array_get( $course, 'meta.product_id', 0 ),
            ] );
            if ( is_array( $this->stats_ref ) ) {
                $this->stats_ref['product_not_found_for_course'] = array_get( $this->stats_ref, 'product_not_found_for_course', 0 ) + 1;
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
        $old_id = (int) array_get( $unit, 'old_id', 0 );
        $slug   = normalize_slug( array_get( $unit, 'current_slug', array_get( $unit, 'post.post_name', '' ) ) );
        $existing = $this->idmap->get( 'units', $old_id );
        if ( ! $existing && $slug ) {
            $f = get_posts( [
                'post_type'      => [ 'sfwd-lessons', 'sfwd-topic' ],
                'name'           => $slug,
                'post_status'    => 'any',
                'numberposts'    => 1,
                'fields'         => 'ids',
            ] );
            if ( $f ) $existing = (int) $f[0];
        }

        $title   = array_get( $unit, 'post.post_title', 'Untitled Lesson' );
        $content = ensure_oembed( array_get( $unit, 'post.post_content', '' ), array_get( $unit, 'embeds', [] ) );
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
            \update_post_meta( $new_id, '_hv_orphan', 1 );
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
        $old_id = (int) array_get( $quiz, 'old_id', 0 );
        $slug   = normalize_slug( array_get( $quiz, 'post.post_name', '' ) );
        $existing = $this->idmap->get( 'quizzes', $old_id );
        if ( ! $existing && $slug ) {
            $f = get_posts( [
                'post_type'   => 'sfwd-quiz',
                'name'        => $slug,
                'post_status' => 'any',
                'numberposts' => 1,
                'fields'      => 'ids',
            ] );
            if ( $f ) $existing = (int) $f[0];
        }

        $title   = array_get( $quiz, 'post.post_title', 'Untitled Quiz' );
        $content = ensure_oembed( array_get( $quiz, 'post.post_content', '' ), array_get( $quiz, 'embeds', [] ) );

        $args = [
            'post_type'    => 'sfwd-quiz',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => $content,
            'post_parent'  => (int) $course_new_id,
        ];
        if ( $slug ) $args['post_name'] = $slug;

        if ( $existing ) {
            $args['ID'] = $existing;
            if ( $this->dry_run ) {
                $this->logger->write( 'DRY: update quiz', [ 'id'=>$existing, 'course'=>$course_new_id ] );
                return true;
            }
            $new_id = \wp_update_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'quiz update failed: ' . $new_id->get_error_message(), [ 'old_id'=>$old_id ] );
                return false;
            }
            $created = false;
        } else {
            if ( $this->dry_run ) {
                $this->logger->write( 'DRY: create quiz', [ 'title'=>$title, 'course'=>$course_new_id ] );
                return true;
            }
            $new_id = \wp_insert_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'quiz insert failed: ' . $new_id->get_error_message(), [ 'old_id'=>$old_id ] );
                return false;
            }
            $created = true;
        }

        \update_post_meta( $new_id, '_wplms_old_id', $old_id );
        if ( $course_new_id ) { \update_post_meta( $new_id, 'course_id', (int) $course_new_id ); }
        if ( $is_orphan ) { \update_post_meta( $new_id, '_wplms_orphan', 1 ); \update_post_meta( $new_id, '_hv_orphan', 1 ); }
        $links = (array) array_get( $quiz, 'links', [] );
        if ( ! empty( $links ) ) { \update_post_meta( $new_id, '_wplms_s1_links', $links ); }

        try {
            sideload_featured( array_get( $quiz, 'post.featured_image', '' ), $new_id, $this->logger, $this->stats_ref );
        } catch ( \Throwable $t ) {
            $this->logger->write( 'featured sideload exception (quiz)', [ 'error' => $t->getMessage() ] );
        }

        $pro_id = $this->ensure_proquiz_link( $new_id, $title );
        if ( $created && $pro_id ) {
            $questions = (array) array_get( $quiz, 'questions', [] );
            if ( $questions ) { $this->import_quiz_questions( $pro_id, $questions ); }
        }

        $this->idmap->set( 'quizzes', $old_id, $new_id, $slug );
        return true;
    }

    private function import_assignment( $assn, $course_new_id = 0, $lesson_new_id = 0, $is_orphan = false ) {
        $old_id = (int) array_get( $assn, 'old_id', 0 );
        $slug   = normalize_slug( array_get( $assn, 'post.post_name', '' ) );
        $existing = $this->idmap->get( 'assignments', $old_id );
        if ( ! $existing && $slug ) {
            $f = get_posts( [
                'post_type'   => 'sfwd-assignment',
                'name'        => $slug,
                'post_status' => 'any',
                'numberposts' => 1,
                'fields'      => 'ids',
            ] );
            if ( $f ) $existing = (int) $f[0];
        }
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
        }

        $this->idmap->set( 'assignments', $old_id, $new_id, $slug );

        \update_post_meta( $new_id, '_wplms_s1_links', [ 'course' => (int) $course_old_id, 'unit' => (int) $lesson_old_id ] );
        if ( $course_new_id ) { \update_post_meta( $new_id, 'course_id', (int) $course_new_id ); }
        if ( $lesson_new_id ) { \update_post_meta( $new_id, 'lesson_id', (int) $lesson_new_id ); }
        if ( $is_orphan ) {
            \update_post_meta( $new_id, '_wplms_orphan', 1 );
            \update_post_meta( $new_id, '_hv_orphan', 1 );
        } else {
            \delete_post_meta( $new_id, '_wplms_orphan' );
            \delete_post_meta( $new_id, '_hv_orphan' );
        }

        return true;
    }

    private function import_certificate( $cert, $is_orphan = false ) {
        $old_id = (int) array_get( $cert, 'old_id', 0 );
        $slug   = normalize_slug( array_get( $cert, 'post.post_name', '' ) );
        if ( ! $slug ) {
            $slug = normalize_slug( array_get( $cert, 'post.post_title', '' ) );
        }
        if ( ! $slug ) {
            $slug = 'certificate-' . $old_id;
        }

        $existing = $this->idmap->get( 'certificate', $old_id );
        if ( ! $existing ) {
            $f = get_posts( [
                'post_type'   => 'sfwd-certificates',
                'post_status' => 'any',
                'numberposts' => 1,
                'fields'      => 'ids',
                'meta_key'    => '_wplms_old_id',
                'meta_value'  => $old_id,
            ] );
            if ( $f ) $existing = (int) $f[0];
        }

        $title   = array_get( $cert, 'post.post_title', 'Certificate' );
        $content = array_get( $cert, 'post.post_content', '' );

        $args = [
            'post_type'    => 'sfwd-certificates',
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => $content,
            'post_name'    => $slug,
        ];

        if ( $existing ) {
            $args['ID'] = $existing;
            if ( $this->dry_run ) {
                $this->logger->write( 'DRY: update certificate', [ 'id' => $existing ] );
                return [ 'id' => $existing, 'created' => false ];
            }
            $new_id = \wp_update_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'certificate update failed: ' . $new_id->get_error_message(), [ 'old_id' => $old_id ] );
                return [ 'id' => 0, 'created' => false ];
            }
            $created = false;
        } else {
            if ( $this->dry_run ) {
                $this->logger->write( 'DRY: create certificate', [ 'title' => $title ] );
                return [ 'id' => 0, 'created' => true ];
            }
            $new_id = \wp_insert_post( $args, true );
            if ( \is_wp_error( $new_id ) ) {
                $this->logger->write( 'certificate insert failed: ' . $new_id->get_error_message(), [ 'old_id' => $old_id ] );
                return [ 'id' => 0, 'created' => false ];
            }
            $created = true;
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

        $this->idmap->set( 'certificate', $old_id, $new_id, $slug );
        if ( $is_orphan ) {
            \update_post_meta( $new_id, '_wplms_orphan', 1 );
            \update_post_meta( $new_id, '_hv_orphan', 1 );
        }

        return [ 'id' => $new_id, 'created' => $created ];
    }

    private function attach_course_certificate( $course, $course_new_id ) {
        $course_old_id = (int) array_get( $course, 'old_id', 0 );
        $cert_old_id = 0;
        $cert_title  = '';
        $cert_slug   = '';

        $ref = array_get( $course, 'certificate_ref', null );
        if ( is_array( $ref ) && ( array_get( $ref, 'old_id' ) || array_get( $ref, 'slug' ) || array_get( $ref, 'title' ) ) ) {
            $cert_old_id = (int) array_get( $ref, 'old_id', 0 );
            $cert_title  = (string) array_get( $ref, 'title', '' );
            $cert_slug   = normalize_slug( array_get( $ref, 'slug', '' ) );
        } else {
            $cert_old_id = (int) array_get( $course, 'certificate_old_id', 0 );
            $cert_slug   = normalize_slug( array_get( $course, 'certificate_slug', '' ) );
            $cert_title  = (string) array_get( $course, 'certificate_title', '' );
            if ( $cert_old_id <= 0 && $cert_slug === '' && $cert_title === '' ) {
                $cert_old_id = (int) array_get( $course, 'certificates.0.old_id', 0 );
            }
            if ( $cert_old_id <= 0 && $cert_slug === '' && $cert_title === '' ) {
                $raw = array_get( $course, 'meta.vibe_certificate_template', 0 );
                if ( is_array( $raw ) ) { $raw = reset( $raw ); }
                $cert_old_id = (int) $raw;
            }
        }

        if ( $cert_old_id <= 0 && $cert_title === '' && $cert_slug === '' ) {
            return;
        }

        $cert_new_id = 0;
        if ( $cert_old_id > 0 ) {
            $found = \get_posts( [
                'post_type'   => 'sfwd-certificates',
                'post_status' => 'any',
                'meta_key'    => '_wplms_old_id',
                'meta_value'  => $cert_old_id,
                'fields'      => 'ids',
                'numberposts' => 1,
            ] );
            if ( $found ) {
                $cert_new_id = (int) $found[0];
            }
        }
        if ( ! $cert_new_id && $cert_title ) {
            $page = \get_page_by_title( $cert_title, OBJECT, 'sfwd-certificates' );
            if ( $page ) { $cert_new_id = (int) $page->ID; }
        }
        if ( ! $cert_new_id && $cert_slug ) {
            $found = \get_posts( [
                'post_type'   => 'sfwd-certificates',
                'post_status' => 'any',
                'name'        => $cert_slug,
                'fields'      => 'ids',
                'numberposts' => 1,
            ] );
            if ( $found ) { $cert_new_id = (int) $found[0]; }
        }

        $log = [
            'course_old_id' => $course_old_id,
            'course_new_id' => $course_new_id,
            'cert_old_id'   => $cert_old_id,
            'cert_new_id'   => $cert_new_id,
            'cert_title'    => $cert_title,
            'cert_slug'     => $cert_slug,
        ];

        if ( $this->dry_run ) {
            $this->logger->write( 'DRY: course certificate', $log );
            return;
        }

        if ( $cert_new_id > 0 ) {
            $current = 0;
            if ( function_exists( '\\learndash_get_setting' ) ) {
                $current = (int) \learndash_get_setting( $course_new_id, 'certificate' );
            } else {
                $current = (int) \get_post_meta( $course_new_id, 'certificate', true );
            }
            if ( $current === $cert_new_id ) {
                if ( is_array( $this->stats_ref ) ) {
                    $this->stats_ref['certificates_already_attached'] = array_get( $this->stats_ref, 'certificates_already_attached', 0 ) + 1;
                }
                $this->logger->write( 'course certificate already attached', $log );
                return;
            }
            $ok = hv_ld_attach_certificate_to_course( $course_new_id, $cert_new_id );
            if ( $ok ) {
                if ( is_array( $this->stats_ref ) ) {
                    $this->stats_ref['certificates_attached'] = array_get( $this->stats_ref, 'certificates_attached', 0 ) + 1;
                }
                $this->logger->write( 'course certificate attached', $log );
            } else {
                if ( is_array( $this->stats_ref ) ) {
                    $this->stats_ref['certificates_missing'] = array_get( $this->stats_ref, 'certificates_missing', 0 ) + 1;
                    if ( count( $this->stats_ref['certificates_missing_examples'] ) < 10 ) {
                        $this->stats_ref['certificates_missing_examples'][] = $log;
                    }
                }
                $this->logger->write( 'course certificate attach failed', $log );
            }
        } else {
            if ( is_array( $this->stats_ref ) ) {
                $this->stats_ref['certificates_missing'] = array_get( $this->stats_ref, 'certificates_missing', 0 ) + 1;
                if ( count( $this->stats_ref['certificates_missing_examples'] ) < 10 ) {
                    $this->stats_ref['certificates_missing_examples'][] = $log;
                }
            }
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
