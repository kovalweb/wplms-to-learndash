<?php
/**
 * Plugin Name: WPLMS S1 Exporter
 * Description: Export WPLMS courses → JSON (curriculum, units+embeds, quizzes+questions, assignments, certificates, media, enrollments). Also finds quizzes via quiz meta and lists orphan units/assignments/quizzes. Compatible with PHP 7/8 (no strict type-hints).
 * Author:      Specia1ne
 * Version:     1.3.0
 * License:     GPL-2.0-or-later
 * Text Domain: wplms-s1-exporter
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WPLMS_S1_Exporter' ) ) {

class WPLMS_S1_Exporter {
    const MENU_SLUG = 'wplms-s1-exporter';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_wplms_s1_export_all', array($this, 'handle_export'));
    }

    public function menu() {
        add_management_page(
            'WPLMS S1 Exporter',
            'WPLMS S1 Exporter',
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_page')
        );
    }

    private function sanitize_ids_list( $raw ) {
        $ids = array_filter(array_map('intval', preg_split('/[,\s]+/', (string)$raw)));
        return array_values(array_unique($ids));
    }

    public function render_page() {
        if ( ! current_user_can('manage_options') ) return; ?>
        <div class="wrap">
            <h1>WPLMS S1 Exporter</h1>
            <p>Export WPLMS courses into a JSON file. You can export all courses or only selected IDs.</p>

            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                <?php wp_nonce_field('wplms_s1_export_all'); ?>
                <input type="hidden" name="action" value="wplms_s1_export_all">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="course_ids">Course IDs (optional)</label></th>
                        <td>
                            <input type="text" id="course_ids" name="course_ids" class="regular-text" placeholder="e.g. 90, 138">
                            <p class="description">Comma or space separated. Leave empty to export all.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="limit">Limit (optional)</label></th>
                        <td>
                            <input type="number" id="limit" name="limit" min="0" step="1" value="0">
                            <p class="description">Export only the first N matching courses. 0 means no limit.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Include enrollments</th>
                        <td>
                            <label><input type="checkbox" name="include_enrollments" value="1"> Yes — include user progress</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Exclude raw meta</th>
                        <td>
                            <label><input type="checkbox" name="exclude_raw_meta" value="1"> Yes — do not include meta.raw dumps (smaller files)</label>
                        </td>
                    </tr>
                </table>

                <p><button class="button button-primary">Export JSON</button></p>
            </form>
        </div>
        <?php
    }

    public function handle_export() {
        if ( ! current_user_can('manage_options') ) wp_die('Forbidden');
        check_admin_referer('wplms_s1_export_all');

        if ( function_exists('ob_get_level') ) { while (ob_get_level()) ob_end_clean(); }

        $ids   = isset($_POST['course_ids']) ? $this->sanitize_ids_list($_POST['course_ids']) : array();
        $limit = isset($_POST['limit']) ? max(0, intval($_POST['limit'])) : 0;
        $include_enrollments = ! empty($_POST['include_enrollments']);
        $exclude_raw_meta    = ! empty($_POST['exclude_raw_meta']);

        $args = array(
            'post_type'        => 'course',
            'post_status'      => array('publish','draft','pending','private'),
            'posts_per_page'   => -1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => false,
        );

        if ( ! empty($ids) ) {
            $args['post__in']         = $ids;
            $args['orderby']          = 'post__in';
            $args['posts_per_page']   = -1;
            $args['suppress_filters'] = true;
        }

        $courses = get_posts($args);
        if ( $limit > 0 && count($courses) > $limit ) {
            $courses = array_slice($courses, 0, $limit);
        }

        $warnings  = array();
        $discovery = array( 'quiz_from_curriculum'=>0, 'quiz_from_units'=>0, 'quiz_from_quizmeta'=>0, 'courses_with_assignments'=>0 );
        $stats     = array( 'courses'=>count($courses), 'units'=>0, 'quizzes'=>0, 'questions'=>0, 'assignments'=>0, 'certificates'=>0, 'media'=>0, 'courses_with_quizzes'=>0 );

        if ( !empty($ids) ) {
            $found_ids = array();
            foreach ($courses as $c) $found_ids[] = intval($c->ID);
            $missed = array_diff($ids, $found_ids);
            if ( !empty($missed) ) {
                $warnings[] = 'Selected posts not found by query: '.implode(',', array_map('intval', $missed));
            }
        }

        $unit_to_courses = $this->build_unit_to_courses_map();

        $export = array(
            'export_meta' => array(
                'source'       => 'WPLMS',
                'exported_at'  => current_time('c'),
                'version'      => '1.3.0',
                'scope'        => !empty($ids) ? 'selected' : 'all',
                'selected_ids' => $ids,
                'limit'        => $limit,
                'count'        => count($courses),
                'include_enrollments' => $include_enrollments ? 1 : 0,
                'exclude_raw_meta'    => $exclude_raw_meta ? 1 : 0,
                'stats'       => null,
                'discovery'   => null,
                'warnings'    => null,
            ),
            'courses' => array(),
            'orphans' => array( 'units'=>array(), 'assignments'=>array(), 'quizzes'=>array() ),
        );

        $analysis = array(
            'paid_without_price'    => array(),
            'with_cta'              => array(),
            'courses_without_duration' => array(),
            'lessons_without_duration' => array(),
            'stats' => array(
                'total_courses'     => count($courses),
                'access_type'       => array(),
                'access_type_final' => array(),
                'statuses'          => array(),
                'subscriptions'     => 0,
            ),
        );

        $used_units = array();
        $used_assignments = array();

        foreach ( $courses as $course ) {
            $one = $this->export_single_course($course, $include_enrollments, !$exclude_raw_meta, $discovery, $warnings, $unit_to_courses, $analysis);
            $stats['units']       += count($one['units']);
            $stats['quizzes']     += count($one['quizzes']);
            if (count($one['quizzes'])>0) $stats['courses_with_quizzes'] += 1;
            foreach ($one['quizzes'] as $qq) $stats['questions'] += count($qq['questions']);
            $stats['assignments'] += count($one['assignments']);
            $stats['certificates']+= count($one['certificates']);
            $stats['media']       += count($one['media']);

            foreach ($one['units'] as $u) $used_units[$u['old_id']] = true;
            foreach ($one['assignments'] as $a) $used_assignments[$a['old_id']] = true;

            $export['courses'][] = $one;
        }

        // Orphan units with assignments but not in any course
        $units_all = get_posts(array(
            'post_type'=>'unit',
            'post_status'=>array('publish','draft','pending','private'),
            'numberposts'=>-1,
            'fields'=>'ids',
            'suppress_filters'=>true,
        ));
        foreach ($units_all as $uid) {
            if ( isset($used_units[$uid]) ) continue;
            $ass_ids = $this->extract_assignments_from_unit($uid, $warnings);
            if ($ass_ids) {
                $in_any_course = !empty($unit_to_courses[$uid]);
                if ( ! $in_any_course ) {
                    $up = get_post($uid);
                    if ($up) {
                        $export['orphans']['units'][] = array(
                            'old_id' => (int)$up->ID,
                            'post'   => array( 'post_title'=>$up->post_title, 'status'=>$up->post_status ),
                            'assignments' => array_values(array_unique($ass_ids)),
                        );
                    }
                    foreach ($ass_ids as $aid) { if ( !isset($used_assignments[$aid]) ) $used_assignments[$aid] = false; }
                }
            }
        }

        // Orphan assignments not used anywhere
        $assign_posts = get_posts(array(
            'post_type'=>'wplms-assignment',
            'post_status'=>array('publish','draft','pending','private'),
            'numberposts'=>-1,
            'suppress_filters'=>true,
        ));
        foreach ($assign_posts as $ap) {
            if ( ! isset($used_assignments[$ap->ID]) ) {
                $export['orphans']['assignments'][] = array(
                    'old_id' => (int)$ap->ID,
                    'post'   => array( 'post_title'=>$ap->post_title, 'status'=>$ap->post_status ),
                );
            }
        }

        // Orphan quizzes that reference missing courses
        $exported_course_ids = array_map(function($c){ return (int)$c['old_id']; }, $export['courses']);
        $export['orphans']['quizzes'] = $this->find_quizzes_referencing_missing_courses($exported_course_ids, $warnings);

        // add orphans to analysis and finalize meta stats
        $analysis['orphans'] = $export['orphans'];
        $export['analysis'] = $analysis;

        $export['export_meta']['stats']     = $stats;
        $export['export_meta']['discovery'] = $discovery;
        $export['export_meta']['warnings']  = array_values(array_unique($warnings));

        $filename = 'wplms_s1_export_' . date('Ymd_His') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        echo wp_json_encode($export, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        exit;
    }

    private function export_single_course($course, $include_enrollments, $include_raw_meta, &$discovery, &$warnings, $unit_to_courses, &$analysis) {
        $raw_meta = get_post_meta($course->ID);
        list($vibe, $vibe_extra, $third_party) = $this->bucketize_meta($raw_meta);

        // slugs and redirects
        $current_slug  = $course->post_name;
        $original_slug = $current_slug;
        $redirected_to = null;
        $old_slugs = get_post_meta($course->ID, '_wp_old_slug');
        if ( !empty($old_slugs) ) {
            $original_slug = is_array($old_slugs) ? reset($old_slugs) : $old_slugs;
            $redirected_to = $current_slug;
        }

        // course duration normalization
        $dur = $this->extract_duration_pair_from_meta($vibe, array('vibe_course_duration_parameter', 'vibe_duration_parameter'));
        if ($dur) {
            $duration      = $dur['duration'];
            $duration_unit = $dur['duration_unit'];
        } else {
            $duration = 0;
            $duration_unit = 'minutes';
            $analysis['courses_without_duration'][] = array('id'=>(int)$course->ID,'title'=>$course->post_title,'slug'=>$current_slug);
        }
        $vibe['duration']      = $duration;
        $vibe['duration_unit'] = $duration_unit;

        $access_type = $this->detect_access_type($vibe, $vibe_extra);
        if (!isset($analysis['stats']['access_type'][$access_type])) $analysis['stats']['access_type'][$access_type] = 0;
        $analysis['stats']['access_type'][$access_type]++;
        if (!isset($analysis['stats']['statuses'][$course->post_status])) $analysis['stats']['statuses'][$course->post_status] = 0;
        $analysis['stats']['statuses'][$course->post_status]++;

        // Woo product info
        $has_product = false;
        $product_id = null;
        $product_status = null;
        $product_catalog_visibility = null;
        $product_type = null;
        $price = null;
        $regular_price = null;
        $sale_price = null;
        $subscription_price = null;
        $subscription_period = null;
        $subscription_interval = null;
        $renewal_enabled = false;

        if ( ! empty($vibe['vibe_product']) ) {
            $product_id = is_array($vibe['vibe_product']) ? intval(reset($vibe['vibe_product'])) : intval($vibe['vibe_product']);
            if ( $product_id ) {
                $has_product = true;
                $p = get_post($product_id);
                if ($p) $product_status = $p->post_status;
                $regular_price = get_post_meta($product_id, '_regular_price', true);
                $sale_price    = get_post_meta($product_id, '_sale_price', true);
                $price         = get_post_meta($product_id, '_price', true);
                if (!is_numeric($regular_price)) $regular_price = null; else $regular_price = (float)$regular_price;
                if (!is_numeric($sale_price))    $sale_price    = null; else $sale_price = (float)$sale_price;
                if (!is_numeric($price))         $price         = null; else $price = (float)$price;

                if ( function_exists('wc_get_product') ) {
                    $wc_product = wc_get_product($product_id);
                    if ($wc_product) {
                        if (method_exists($wc_product,'get_type')) $product_type = $wc_product->get_type();
                        if (method_exists($wc_product,'get_catalog_visibility')) $product_catalog_visibility = $wc_product->get_catalog_visibility();
                        if ($wc_product->is_type('subscription')) {
                            $renewal_enabled = true;
                            $sub_price = get_post_meta($product_id, '_subscription_price', true);
                            if (is_numeric($sub_price)) $subscription_price = (float)$sub_price;
                            $sub_period = get_post_meta($product_id, '_subscription_period', true);
                            if (!empty($sub_period)) $subscription_period = $sub_period;
                            $sub_interval = get_post_meta($product_id, '_subscription_period_interval', true);
                            if (is_numeric($sub_interval)) $subscription_interval = (int)$sub_interval;
                            $analysis['stats']['subscriptions']++;
                        }
                    }
                }
            }
        }

        // subscription flags without WC product
        if ( !$renewal_enabled ) {
            if ( $this->is_meta_flag_on($vibe_extra, 'vibe_subscription1') || $this->is_meta_flag_on($vibe_extra, 'vibe_mycred_subscription') ) {
                $renewal_enabled = true;
            }
        }

        // product duration
        $product_duration = null;
        $product_duration_unit = null;
        if ( isset($vibe['vibe_product_duration']) ) {
            $pd = is_array($vibe['vibe_product_duration']) ? reset($vibe['vibe_product_duration']) : $vibe['vibe_product_duration'];
            $pd_param = isset($vibe['vibe_product_duration_parameter']) ? $vibe['vibe_product_duration_parameter'] : null;
            if (is_array($pd_param)) $pd_param = reset($pd_param);
            if (is_numeric($pd) && is_numeric($pd_param)) {
                $product_duration = (int)$pd;
                $product_duration_unit = $this->map_duration_unit((int)$pd_param);
            }
        }

        // course access expires - mirror product duration if set
        $course_access_expires = false;
        $course_access_expires_value = null;
        $course_access_expires_unit = null;
        if ($product_duration !== null) {
            $course_access_expires = true;
            $course_access_expires_value = $product_duration;
            $course_access_expires_unit = $product_duration_unit;
        }

        // CTA detection
        $cta_label = null;
        $cta_url   = null;
        $label_keys = array('vibe_apply_button_label','vibe_cta_label','cta_label');
        $url_keys   = array('vibe_apply_button_url','vibe_cta_url','cta_url');
        foreach ($label_keys as $k) {
            if (!empty($vibe_extra[$k])) { $cta_label = $vibe_extra[$k]; break; }
            if (!empty($vibe[$k])) { $cta_label = $vibe[$k]; break; }
        }
        foreach ($url_keys as $k) {
            if (!empty($vibe_extra[$k])) { $cta_url = $vibe_extra[$k]; break; }
            if (!empty($vibe[$k])) { $cta_url = $vibe[$k]; break; }
        }

        // final access classification
        $access_type_final = $access_type;
        $paid_reason = null;
        if ( $access_type === 'paid' ) {
            if ( ! $has_product || ! $product_id ) {
                $access_type_final = 'closed';
                $paid_reason = 'no_product';
            } elseif ( $product_status !== 'publish' || $product_catalog_visibility === 'hidden' || $product_catalog_visibility === null ) {
                $access_type_final = 'closed';
                $paid_reason = 'product_not_published';
            } elseif ( $price === null && $regular_price === null && $sale_price === null ) {
                $access_type_final = 'closed';
                $paid_reason = 'no_price_on_product';
            }
            if ( $paid_reason ) {
                $analysis['paid_without_price'][] = array('id'=>(int)$course->ID,'title'=>$course->post_title,'slug'=>$current_slug,'reason'=>$paid_reason);
            }
        }

        if ( $cta_url && $cta_label ) {
            if ( strpos($cta_url,'checkout')===false && strpos($cta_url,'cart')===false && strpos($cta_url,'add-to-cart')===false ) {
                $access_type_final = 'lead';
                $analysis['with_cta'][] = array('id'=>(int)$course->ID,'title'=>$course->post_title,'slug'=>$current_slug,'cta_label'=>$cta_label,'cta_url'=>$cta_url);
            }
        }
        if (!isset($analysis['stats']['access_type_final'][$access_type_final])) $analysis['stats']['access_type_final'][$access_type_final] = 0;
        $analysis['stats']['access_type_final'][$access_type_final]++;
        $product_inconsistent = false;
        if ( $access_type_final === 'free' && $has_product ) {
            if ( $product_status !== 'publish' || $product_catalog_visibility === 'hidden' || $product_catalog_visibility === null || $price !== null || $regular_price !== null || $sale_price !== null ) {
                $product_inconsistent = true;
            }
        }

        $thumb_id = get_post_thumbnail_id($course->ID);
        $featured = $thumb_id ? $this->get_attachment_payload($thumb_id) : null;

        list($curriculum, $unit_ids, $quiz_ids, $curriculum_raw) = $this->parse_curriculum($course->ID, $warnings);
        if (!empty($quiz_ids)) $discovery['quiz_from_curriculum']++;

        // Units
        $units = array();
        if ($unit_ids) {
            $unit_posts = get_posts(array(
                'post_type'=>'unit',
                'post__in'=>$unit_ids,
                'orderby'=>'post__in',
                'numberposts'=>-1,
                'suppress_filters'=>true,
            ));
            foreach ($unit_posts as $u) {
                $embeds = $this->extract_embeds_from_content( (string)$u->post_content );
                $raw    = get_post_meta($u->ID);
                $dur    = $this->extract_duration_pair_from_meta($raw, array('vibe_unit_duration_parameter', 'vibe_duration_parameter'));
                if ($dur) {
                    $u_duration = $dur['duration'];
                    $u_unit     = $dur['duration_unit'];
                } else {
                    $u_duration = 0;
                    $u_unit     = 'minutes';
                    $analysis['lessons_without_duration'][] = array('id'=>(int)$u->ID,'title'=>$u->post_title,'slug'=>$u->post_name,'course_id'=>(int)$course->ID);
                }
                $meta   = array('duration'=>$u_duration,'duration_unit'=>$u_unit);
                if ($include_raw_meta) {
                    $meta['raw'] = $raw;
                }
                $units[] = array(
                    'old_id' => (int)$u->ID,
                    'post'   => array(
                        'post_title'   => $u->post_title,
                        'post_name'    => $u->post_name,
                        'post_content' => $u->post_content,
                        'menu_order'   => (int)$u->menu_order,
                        'status'       => $u->post_status,
                    ),
                    'embeds' => $embeds,
                    'meta'   => $meta,
                );
            }
        }

        // Quizzes via curriculum + via quiz meta
        $quiz_ids_meta = $this->find_quizzes_linked_to_course_meta((int)$course->ID);
        if ($quiz_ids_meta) {
            $quiz_ids = array_values(array_unique(array_merge($quiz_ids, $quiz_ids_meta)));
            if (!empty($quiz_ids_meta)) $discovery['quiz_from_quizmeta']++;
        }

        $quizzes = array();
        if ($quiz_ids) {
            $quiz_posts = get_posts(array(
                'post_type'=>'quiz',
                'post__in'=>$quiz_ids,
                'orderby'=>'post__in',
                'numberposts'=>-1,
                'suppress_filters'=>true,
            ));
            foreach ($quiz_posts as $q) {
                $q_entry = array(
                    'old_id'    => (int)$q->ID,
                    'post'      => array(
                        'post_title'   => $q->post_title,
                        'post_content' => $q->post_content,
                        'menu_order'   => (int)$q->menu_order,
                        'status'       => $q->post_status,
                    ),
                    'meta'      => $include_raw_meta ? array( 'raw' => get_post_meta($q->ID) ) : (object) array(),
                    'questions' => array(),
                );
                $qset = get_post_meta($q->ID, 'vibe_quiz_questions', true);
                if ( is_array($qset) && $qset ) {
                    $questions = get_posts(array(
                        'post_type'=>'question',
                        'post__in'=>array_map('intval',$qset),
                        'orderby'=>'post__in',
                        'numberposts'=>-1,
                        'suppress_filters'=>true,
                    ));
                    foreach ($questions as $qq) {
                        $q_entry['questions'][] = array(
                            'old_id'        => (int)$qq->ID,
                            'question_type' => get_post_meta($qq->ID, 'vibe_question_type', true),
                            'post'          => array( 'post_title'=>$qq->post_title, 'post_content'=>$qq->post_content ),
                            'answers'       => $this->extract_wplms_answers($qq->ID),
                            'meta'          => $include_raw_meta ? array( 'raw' => get_post_meta($qq->ID) ) : (object) array(),
                        );
                    }
                }
                $quizzes[] = $q_entry;
            }
        }

        // Assignments via units
        $assignments = array();
        if ( ! empty($unit_ids) ) {
            foreach ($unit_ids as $uid) {
                $ass_ids = $this->extract_assignments_from_unit($uid, $warnings);
                foreach ( array_unique($ass_ids) as $aid ) {
                    if ( get_post_type($aid) === 'wplms-assignment' ) {
                        $ap = get_post($aid);
                        if ($ap) {
                            $assignments[] = array(
                                'old_id' => (int)$ap->ID,
                                'post'   => array(
                                    'post_title'   => $ap->post_title,
                                    'post_content' => $ap->post_content,
                                    'status'       => $ap->post_status,
                                ),
                                'meta'   => $include_raw_meta ? array( 'raw' => get_post_meta($ap->ID) ) : (object) array(),
                            );
                        }
                    }
                }
            }
            if (!empty($assignments)) $discovery['courses_with_assignments']++;
        }

        // Certificates
        $certificates = array();
        $cert_ids = array();
        $cid1 = (int) get_post_meta($course->ID, 'certificate_id', true);
        if ($cid1) $cert_ids[] = $cid1;
        $cid2 = (int) get_post_meta($course->ID, 'vibe_certificate_template', true);
        if ($cid2) $cert_ids[] = $cid2;
        $cert_ids = array_values(array_unique(array_filter($cert_ids)));
        foreach ($cert_ids as $cid) {
            $cert = get_post($cid);
            if ($cert) {
                $bg_id  = (int) get_post_meta($cert->ID, 'vibe_background_image', true);
                $bg_url = $bg_id ? wp_get_attachment_url($bg_id) : null;
                $certificates[] = array(
                    'old_id' => (int)$cert->ID,
                    'post'   => array(
                        'post_title'=>$cert->post_title,
                        'post_content'=>$cert->post_content
                    ),
                    'background_image' => array(
                        'id'  => $bg_id ?: null,
                        'url' => $bg_url ?: null,
                    ),
                    'meta'   => $include_raw_meta ? array( 'raw' => get_post_meta($cert->ID) ) : (object) array(),
                );
            }
        }

        $enrollments = $include_enrollments ? $this->extract_enrollments_from_numeric_meta($raw_meta) : array();

        $media_map = array();
        if ( $thumb_id ) $media_map[$thumb_id] = $featured;
        foreach ( $this->collect_attachment_payloads_from_content( (string)$course->post_content ) as $p ) {
            if ( ! empty($p['id']) ) $media_map[ (int)$p['id'] ] = $p;
        }
        foreach ( $this->collect_attachment_ids_from_meta( $raw_meta ) as $mid ) {
            $media_map[$mid] = $this->get_attachment_payload($mid);
        }
        $media = array_values($media_map);

        $course_entry = array(
            'old_id'        => (int)$course->ID,
            'id'            => (int)$course->ID,
            'slug'          => $current_slug,
            'original_slug' => $original_slug,
            'current_slug'  => $current_slug,
            'redirected_to' => $redirected_to,
            'access_type_final' => $access_type_final,
            'paid_without_price_reason' => $paid_reason,
            'product_inconsistent' => $product_inconsistent,
            'cta_label' => $cta_label,
            'cta_url'   => $cta_url,
            'post'   => array(
                'post_title'     => $course->post_title,
                'post_name'      => $course->post_name,
                'post_content'   => $course->post_content,
                'post_excerpt'   => $course->post_excerpt,
                'menu_order'     => (int)$course->menu_order,
                'status'         => $course->post_status,
                'featured_media' => $thumb_id ? (int)$thumb_id : null,
                'featured_image' => $featured,
            ),
            'meta' => array(
                'access_type'            => $access_type,
                'duration'               => $duration,
                'duration_unit'          => $duration_unit,
                'has_product'            => $has_product,
                'product_id'             => $product_id,
                'product_status'         => $product_status,
                'product_catalog_visibility' => $product_catalog_visibility,
                'product_type'           => $product_type,
                'price'                  => $price,
                'regular_price'          => $regular_price,
                'sale_price'             => $sale_price,
                'subscription_price'     => $subscription_price,
                'subscription_period'    => $subscription_period,
                'subscription_interval'  => $subscription_interval,
                'renewal_enabled'        => $renewal_enabled,
                'product_duration'       => $product_duration,
                'product_duration_unit'  => $product_duration_unit,
                'course_access_expires'       => $course_access_expires,
                'course_access_expires_value' => $course_access_expires_value,
                'course_access_expires_unit'  => $course_access_expires_unit,
                'vibe'        => $vibe,
                'vibe_extra'  => $vibe_extra,
                'misc'        => array( 'third_party' => $third_party ),
            ),
            'curriculum'     => $curriculum,
            'curriculum_raw' => $curriculum_raw,
            'units'          => $units,
            'quizzes'        => $quizzes,
            'assignments'    => $assignments,
            'certificates'   => $certificates,
            'enrollments'    => $enrollments,
            'media'          => $media,
        );
        if ( $include_raw_meta ) {
            $course_entry['meta']['raw'] = $raw_meta;
        }
        if ( ! $course_entry['product_inconsistent'] ) {
            unset( $course_entry['product_inconsistent'] );
        }

        return $course_entry;
    }

    /** ---------- Orphan quizzes detection ---------- */

    private function find_quizzes_referencing_missing_courses(array $exported_course_ids, array &$warnings) {
        $out = array();
        $qids = get_posts(array(
            'post_type'        => 'quiz',
            'post_status'      => array('publish','draft','pending','private','trash'),
            'numberposts'      => -1,
            'fields'           => 'ids',
            'suppress_filters' => true,
        ));
        foreach ($qids as $qid) {
            $links = $this->read_quiz_course_links($qid); // ['meta_key'=>[ids]]
            if (empty($links)) continue;

            $all = array();
            foreach ($links as $k => $ids) {
                foreach ((array)$ids as $cid) if (is_int($cid)) $all[] = $cid;
            }
            $all = array_values(array_unique($all));
            if (empty($all)) continue;

            $missing = array();
            foreach ($all as $cid) {
                if ( ! get_post($cid) ) $missing[] = (int)$cid;
            }
            if (!empty($missing)) {
                $out[] = array(
                    'old_id'          => (int)$qid,
                    'links'           => $links,
                    'missing_courses' => array_values(array_unique($missing)),
                );
            }
        }
        return $out;
    }

    private function read_quiz_course_links($quiz_id) {
        $keys = array('vibe_quiz_course','quiz_course','course_id');
        $links = array();
        foreach ($keys as $k) {
            $v = get_post_meta($quiz_id, $k, true);
            $ids = $this->normalize_ids_from_meta_value($v);
            if (!empty($ids)) $links[$k] = $ids;
        }
        return $links;
    }

    private function normalize_ids_from_meta_value($v) {
        $ids = array();
        if (is_numeric($v)) {
            $ids[] = (int)$v;
        } elseif (is_string($v) && $v !== '') {
            $maybe = @unserialize($v);
            if (is_array($maybe)) {
                foreach ($maybe as $it) if (is_numeric($it)) $ids[] = (int)$it;
            } else {
                $maybeJson = json_decode($v, true);
                if (is_array($maybeJson)) {
                    foreach ($maybeJson as $it) if (is_numeric($it)) $ids[] = (int)$it;
                } else {
                    $parts = preg_split('/[,\s]+/', $v);
                    foreach ($parts as $it) if (is_numeric($it)) $ids[] = (int)$it;
                }
            }
        } elseif (is_array($v)) {
            foreach ($v as $it) if (is_numeric($it)) $ids[] = (int)$it;
        }
        $ids = array_values(array_unique(array_filter($ids, 'is_int')));
        return $ids;
    }

    /** ---------- Helpers ---------- */

    private function extract_embeds_from_content( $html ) {
        $out = array();
        if ( preg_match_all('/<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $m, PREG_SET_ORDER) ) {
            foreach ($m as $mm) {
                $src = $mm[1];
                $type = (strpos($src,'youtube')!==false || strpos($src,'youtu.be')!==false) ? 'youtube' : ((strpos($src,'vimeo')!==false)?'vimeo':'iframe');
                $w = null; $h = null;
                if ( preg_match('/width=["\'](\d+)["\']/', $mm[0], $mw) ) $w = (int)$mw[1];
                if ( preg_match('/height=["\'](\d+)["\']/', $mm[0], $mh) ) $h = (int)$mh[1];
                $out[] = array( 'src'=>$src, 'type'=>$type, 'width'=>$w, 'height'=>$h );
            }
        }
        return $out;
    }

    private function build_unit_to_courses_map() {
        $map = array();
        $courses = get_posts( array(
            'post_type'=>'course',
            'post_status'=>array('publish','draft','pending','private'),
            'numberposts'=>-1,
            'fields'=>'ids',
            'suppress_filters'=>true,
        ) );
        foreach ($courses as $cid) {
            $raw = get_post_meta($cid, 'vibe_course_curriculum', true);
            $arr = $this->unserialize_curriculum($raw);
            if ($arr) {
                foreach ($arr as $item) {
                    if (is_numeric($item)) {
                        $pid = intval($item);
                        if ( get_post_type($pid)==='unit' ) {
                            if (!isset($map[$pid])) $map[$pid]=array();
                            $map[$pid][] = $cid;
                        }
                    }
                }
            }
        }
        return $map;
    }

    private function find_quizzes_linked_to_course_meta( $course_id ) {
        $ids = array();
        $q_posts = get_posts( array(
            'post_type' => 'quiz',
            'post_status' => array('publish','draft','pending','private'),
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters'=>true,
        ));
        $keys = array('vibe_quiz_course','quiz_course','course_id');
        foreach ($q_posts as $qid) {
            foreach ($keys as $k) {
                $v = get_post_meta($qid, $k, true);
                if ( is_numeric($v) && intval($v) === intval($course_id) ) $ids[] = $qid;
                elseif ( is_string($v) ) {
                    $maybe = @unserialize($v);
                    if ( is_array($maybe) ) {
                        $ints = array_map('intval',$maybe);
                        if ( in_array(intval($course_id), $ints, true) ) $ids[] = $qid;
                    }
                } elseif ( is_array($v) ) {
                    $ints = array_map('intval',$v);
                    if ( in_array(intval($course_id), $ints, true) ) $ids[] = $qid;
                }
            }
        }
        return array_values(array_unique($ids));
    }

    private function extract_assignments_from_unit( $unit_id, &$warnings ) {
        $meta = get_post_meta($unit_id);
        $keys = array('vibe_assignment','assignment','vibe_unit_assignment');
        $cands = array();
        foreach ($keys as $k) {
            if ( isset($meta[$k]) ) {
                $val = is_array($meta[$k]) ? reset($meta[$k]) : $meta[$k];
                if ( is_numeric($val) ) $cands[] = intval($val);
                elseif ( is_string($val) ) {
                    $maybe = @unserialize($val);
                    if ( is_array($maybe) ) {
                        foreach ($maybe as $p) if ( is_numeric($p) ) $cands[] = intval($p);
                    } elseif ($val !== '') {
                        $warnings[] = "Unserialize unit assignment failed: unit {$unit_id} key {$k}";
                    }
                }
            }
        }
        return array_values(array_unique($cands));
    }

    private function parse_curriculum( $course_id, &$warnings ) {
        $curriculum = array();
        $unit_ids   = array();
        $quiz_ids   = array();
        $curriculum_raw = array();

        $val = get_post_meta($course_id, 'vibe_course_curriculum', true);
        $arr = $this->unserialize_curriculum($val, $warnings, $course_id);

        if ( $arr ) {
            foreach ($arr as $item) {
                if ( is_numeric($item) ) {
                    $pid = (int)$item;
                    $curriculum_raw[] = array( 'kind'=>'id', 'value'=>$pid );
                    if ( $pid > 0 ) {
                        $ptype = get_post_type($pid);
                        if ( $ptype === 'unit' ) {
                            $curriculum[] = array('type'=>'lesson', 'old_id'=>$pid);
                            $unit_ids[]   = $pid;
                        } elseif ( $ptype === 'quiz' ) {
                            $curriculum[] = array('type'=>'quiz', 'old_id'=>$pid);
                            $quiz_ids[]   = $pid;
                        }
                    }
                } else {
                    $curriculum_raw[] = array( 'kind'=>'title', 'value'=> (string)$item );
                }
            }
        }
        $unit_ids = array_values(array_unique($unit_ids));
        $quiz_ids = array_values(array_unique($quiz_ids));
        return array($curriculum, $unit_ids, $quiz_ids, $curriculum_raw);
    }

    private function unserialize_curriculum($val, &$warnings = array(), $course_id = 0) {
        if (is_array($val)) return $val;
        if (is_string($val)) {
            $maybe = @unserialize($val);
            if (is_array($maybe)) return $maybe;
            if ($val !== '') $warnings[] = "Unserialize curriculum failed for course {$course_id}";
        }
        return array();
    }

    private function extract_wplms_answers( $question_id ) {
        $answers = array();
        $options = get_post_meta($question_id, 'vibe_question_options', true);
        $correct = get_post_meta($question_id, 'vibe_question_answer',  true);

        if (is_array($options)) {
            foreach ($options as $idx => $txt) {
                $is_correct = false;
                if ( is_array($correct) ) {
                    $ints = array_map('intval',$correct);
                    $is_correct = in_array((int)$idx, $ints, true);
                } else {
                    $is_correct = ((int)$correct === (int)$idx);
                }
                $answers[] = array(
                    'text'       => is_string($txt) ? $txt : wp_json_encode($txt),
                    'is_correct' => $is_correct,
                );
            }
        }
        return $answers;
    }

    private function get_attachment_payload( $id ) {
        $url_full = wp_get_attachment_url($id);
        $meta     = wp_get_attachment_metadata($id);
        $mime     = get_post_mime_type($id);
        $alt      = get_post_meta($id, '_wp_attachment_image_alt', true);
        $file     = get_attached_file($id);

        $payload = array(
            'id'        => (int)$id,
            'url'       => $url_full,
            'mime_type' => $mime ? $mime : '',
            'alt'       => is_string($alt) ? $alt : '',
            'filename'  => $file ? wp_basename($file) : '',
        );

        if ( is_array($meta) ) {
            if ( isset($meta['width']) )  $payload['width']  = (int)$meta['width'];
            if ( isset($meta['height']) ) $payload['height'] = (int)$meta['height'];
            if ( isset($meta['sizes']) && is_array($meta['sizes']) ) {
                $sizes = array();
                foreach ($meta['sizes'] as $size_key => $size_data) {
                    $sizes[$size_key] = wp_get_attachment_image_url($id, $size_key);
                }
                if ($sizes) $payload['sizes'] = $sizes;
            }
        }
        return $payload;
    }

    private function collect_attachment_payloads_from_content( $html ) {
        $ids = array();
        if ( preg_match_all("/wp-image-(\d+)/", $html, $m) ) {
            foreach ($m[1] as $id) { $ids[] = (int)$id; }
        }
        if ( preg_match_all("#/(wp-content/uploads/[^\"'\\s]+)#i", $html, $m) ) {
            foreach ($m[1] as $path) {
                $url = home_url( '/' . ltrim($path, '/') );
                $att_id = attachment_url_to_postid( $url );
                if ( $att_id ) $ids[] = (int)$att_id;
            }
        }
        $ids = array_values( array_unique( array_filter($ids) ) );
        $out = array();
        foreach ($ids as $aid) $out[] = $this->get_attachment_payload($aid);
        return $out;
    }

    private function collect_attachment_ids_from_meta( $raw_meta ) {
        $ids = array();
        foreach ($raw_meta as $key => $vals) {
            if ( is_string($key) && substr($key, -13) === '_thumbnail_id' ) {
                $v = is_array($vals) ? reset($vals) : $vals;
                if ( is_numeric($v) ) $ids[] = (int)$v;
            }
        }
        return array_values( array_unique( array_filter($ids) ) );
    }

    private function bucketize_meta( $raw ) {
        $vibe        = array();
        $vibe_extra  = array();
        $third_party = array();

        $known_vibe_prefixes = array(
            'vibe_course_', 'vibe_duration', 'vibe_product',
            'vibe_certificate_template', 'vibe_badge', 'vibe_students',
            'vibe_drip_', 'vibe_partial_free_course'
        );

        foreach ($raw as $key => $values) {
            if ( $this->is_numeric_key($key) ) continue; // enrollments (handled separately)
            $one = ( is_array($values) && count($values) === 1 ) ? $values[0] : $values;

            $is_vibe_known = false;
            foreach ($known_vibe_prefixes as $p) {
                if ( strpos((string)$key, $p) === 0 ) { $is_vibe_known = true; break; }
            }

            if ($is_vibe_known) {
                $vibe[$key] = $one;
            } elseif (strpos((string)$key, 'vibe_') === 0) {
                $vibe_extra[$key] = $one;
            } else {
                $third_party[$key] = $one;
            }
        }
        return array($vibe, $vibe_extra, $third_party);
    }

    private function extract_duration_pair_from_meta(array $meta, array $param_keys) {
        if (!isset($meta['vibe_duration'])) return null;
        $dur = $meta['vibe_duration'];
        if (is_array($dur)) $dur = reset($dur);
        if (!is_numeric($dur)) return null;

        $param = null;
        foreach ($param_keys as $k) {
            if (isset($meta[$k])) {
                $param = $meta[$k];
                if (is_array($param)) $param = reset($param);
                if (is_numeric($param)) { $param = (int)$param; break; }
            }
        }
        if (!is_numeric($param)) return null;

        return array(
            'duration'      => (int)$dur,
            'duration_unit' => $this->map_duration_unit((int)$param),
        );
    }

    private function map_duration_unit($seconds) {
        switch ((int)$seconds) {
            case 31536000: return 'years';
            case 2592000:  return 'months';
            case 604800:   return 'weeks';
            case 86400:    return 'days';
            case 3600:     return 'hours';
            case 60:       return 'minutes';
            default:       return 'seconds';
        }
    }

    private function is_meta_flag_on( $arr, $key ) {
        if ( !isset($arr[$key]) ) return false;
        $val = $arr[$key];
        if ( is_array($val) ) $val = reset($val);
        if ( is_bool($val) ) return $val;
        $val = strtolower((string)$val);
        return in_array($val, array('1','true','yes','on','s'), true);
    }

    private function detect_access_type( $vibe, $vibe_extra ) {
        if ( $this->is_meta_flag_on($vibe, 'vibe_course_free') ) {
            return 'free';
        }
        if ( !empty($vibe['vibe_product']) ) {
            return 'paid';
        }
        if (
            $this->is_meta_flag_on($vibe_extra, 'vibe_subscription') ||
            $this->is_meta_flag_on($vibe_extra, 'vibe_subscription1') ||
            $this->is_meta_flag_on($vibe_extra, 'vibe_mycred_subscription')
        ) {
            return 'subscribe';
        }
        if ( $this->is_meta_flag_on($vibe, 'vibe_course_apply') ) {
            return 'closed';
        }
        return 'free';
    }

    private function extract_enrollments_from_numeric_meta( $raw ) {
        $out = array();

        foreach ($raw as $key => $values) {
            if ( ! $this->is_numeric_key($key) ) continue;
            // WPLMS зберігає прогрес як meta_key = user_id, meta_value = 0..100
            $user_id = (int)$key;

            // фільтр на «некоректні» значення
            $val = ( is_array($values) && !empty($values) ) ? reset($values) : $values;
            if ( is_array($val) ) continue;           // ігноруємо масиви
            if ( $val === '' || $val === null ) continue;
            if ( !is_numeric($val) ) continue;

            $progress = (int)$val;
            if ( $progress < 0 )   $progress = 0;
            if ( $progress > 100 ) $progress = 100;

            $status = ($progress >= 100) ? 'completed' : ( ($progress > 0) ? 'in_progress' : 'enrolled' );

            $out[] = array(
                'user_id'  => $user_id,
                'progress' => $progress,
                'status'   => $status,
            );
        }
        return $out;
    }

    private function is_numeric_key( $k ) {
        if (is_int($k)) return true;
        return ( preg_match('/^\d+$/', (string)$k) === 1 );
    }
}

}

new WPLMS_S1_Exporter();
