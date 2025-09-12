<?php
namespace {
// minimal stubs for WordPress environment
$GLOBALS['posts'] = [];
$GLOBALS['post_meta'] = [];
$GLOBALS['options'] = [];
$GLOBALS['filters'] = [];

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    $GLOBALS['filters'][$hook][] = $callback;
}
function remove_filter($hook, $callback, $priority = 10) {
    if (isset($GLOBALS['filters'][$hook])) {
        $GLOBALS['filters'][$hook] = array_filter(
            $GLOBALS['filters'][$hook],
            fn($cb) => $cb !== $callback
        );
    }
}

function update_post_meta($post_id, $meta_key, $value) {
    $GLOBALS['post_meta'][$post_id][$meta_key] = $value;
    return true;
}
function get_post_meta($post_id, $meta_key, $single = true) {
    return $GLOBALS['post_meta'][$post_id][$meta_key] ?? ($single ? '' : []);
}
function delete_post_meta($post_id, $meta_key) {
    unset($GLOBALS['post_meta'][$post_id][$meta_key]);
}

function wp_insert_post($args, $error = false) {
    static $id = 1;
    if (!isset($args['ID'])) {
        $args['ID'] = $id++;
    }
    $GLOBALS['posts'][$args['ID']] = $args;
    return $args['ID'];
}
function wp_update_post($args, $error = false) {
    $id = $args['ID'];
    $GLOBALS['posts'][$id] = array_merge($GLOBALS['posts'][$id], $args);
    return $id;
}
function get_post($id) {
    return isset($GLOBALS['posts'][$id]) ? (object) $GLOBALS['posts'][$id] : null;
}
function get_post_field($field, $id) {
    return $GLOBALS['posts'][$id][$field] ?? '';
}
function get_post_status($id) {
    return $GLOBALS['posts'][$id]['post_status'] ?? '';
}
function get_posts($args) {
    $result = [];
    foreach ($GLOBALS['posts'] as $id => $post) {
        if (isset($args['post_type']) && $post['post_type'] !== $args['post_type']) continue;
        if (isset($args['name']) && ($post['post_name'] ?? '') !== $args['name']) continue;
        if (isset($args['meta_key'])) {
            $key = $args['meta_key'];
            $val = $args['meta_value'];
            if (($GLOBALS['post_meta'][$id][$key] ?? null) !== $val) continue;
        }
        $result[] = $id;
    }
    if (isset($args['numberposts']) && $args['numberposts'] === 1) {
        $result = array_slice($result, 0, 1);
    }
    if (($args['fields'] ?? '') === 'ids') return $result;
    return array_map(fn($id) => (object) $GLOBALS['posts'][$id], $result);
}
function get_page_by_title($title, $output = OBJECT, $post_type = 'post') {
    foreach ($GLOBALS['posts'] as $id => $post) {
        if ($post['post_title'] === $title && $post['post_type'] === $post_type) {
            return (object) $post;
        }
    }
    return null;
}
function wc_get_product_id_by_sku($sku) {
    foreach ($GLOBALS['post_meta'] as $id => $meta) {
        if (($meta['_sku'] ?? '') === $sku) return $id;
    }
    return 0;
}
function wp_count_posts($post_type) {
    $count = 0;
    foreach ($GLOBALS['posts'] as $p) {
        if ($p['post_type'] === $post_type) $count++;
    }
    return (object) ['publish' => $count];
}
function get_option($key, $default = []) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = false) { $GLOBALS['options'][$key] = $value; }
function sanitize_title($title) { $title = strtolower($title); $title = preg_replace('/[^a-z0-9]+/','-',$title); return trim($title,'-'); }
function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
function normalize_whitespace($str){ return preg_replace('/\s+/',' ',$str); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_set_object_terms($id,$terms,$tax,$append){ return true; }
function taxonomy_exists($t){ return false; }
function get_terms($args){ return []; }
function get_term_link($term,$tax){ return ''; }
function get_the_terms($id,$tax){ return []; }
function is_wp_error($v){ return false; }
function wp_upload_dir(){ return ['basedir'=>'/tmp']; }
function trailingslashit($s){ return rtrim($s,'/').'/'; }
function wp_mkdir_p($d){ return true; }
function learndash_update_setting($id,$k,$v){ update_post_meta($id,$k,$v); }
}

namespace WPLMS_S1I {
    class Logger { public function write($m,$c=[]){} }
}

namespace {
use WPLMS_S1I\Importer;
use WPLMS_S1I\IdMap;
use WPLMS_S1I\Logger;

const WPLMS_S1I_OPT_IDMAP = 'wplms_s1_map';
const WPLMS_S1I_OPT_RUNSTATS = 'wplms_s1i_runstats';
const WPLMS_S1I_OPT_ENROLL_POOL = 'wplms_s1i_enrollments_pool';

require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/linking.php';
require __DIR__ . '/../includes/IdMap.php';
require __DIR__ . '/../includes/Importer.php';

// preset products
$p1 = wp_insert_post(['post_type'=>'product','post_status'=>'publish','post_title'=>'Product 1','post_name'=>'product-1']);
update_post_meta($p1,'_sku','sku1');
$p2 = wp_insert_post(['post_type'=>'product','post_status'=>'publish','post_title'=>'Product 2','post_name'=>'product-2']);
update_post_meta($p2,'_sku','sku2');

$logger = new Logger();
$idmap = new IdMap();
$importer = new Importer($logger, $idmap);

$payload = [
    'courses' => [
        [
            'old_id' => 1,
            'current_slug' => 'course-1',
            'post' => ['post_title' => 'Course 1', 'status' => 'publish'],
            'commerce' => ['product_sku' => 'sku1'],
        ],
        [
            'old_id' => 2,
            'current_slug' => 'course-2',
            'post' => ['post_title' => 'Course 2', 'status' => 'publish'],
            'commerce' => ['product_sku' => 'sku2'],
        ],
    ],
];

$importer->run($payload);
$importer->run($payload);
$stats2 = get_option(WPLMS_S1I_OPT_RUNSTATS, []);
$expected_courses = 2;

// gather course IDs
$course_ids = [];
foreach ($GLOBALS['posts'] as $id => $post) {
    if (($post['post_type'] ?? '') === 'sfwd-courses') {
        $course_ids[] = $id;
    }
}
foreach ($course_ids as $cid) {
    if (get_post_meta($cid, 'course_price_type', true) !== 'closed') {
        echo "course_price_type not closed\n";
        exit(1);
    }
    if (isset($GLOBALS['post_meta'][$cid]['course_price'])) {
        echo "course_price persisted\n";
        exit(1);
    }
    if (get_post_meta($cid, 'ld_course_access_mode', true) !== 'closed') {
        echo "ld_course_access_mode mismatch\n";
        exit(1);
    }
}

if ($stats2['courses_created'] != 0) {
    echo "courses_created mismatch\n";
    exit(1);
}
if ($stats2['courses_updated'] != $expected_courses) {
    echo "courses_updated mismatch\n";
    exit(1);
}
if ($stats2['courses_linked_to_products'] != $expected_courses) {
    echo "courses_linked_to_products mismatch\n";
    exit(1);
}
if (($stats2['access_closed'] ?? 0) != $expected_courses) {
    echo "access_closed mismatch\n";
    exit(1);
}
if (!empty($stats2['access_paid'])) {
    echo "access_paid should be zero\n";
    exit(1);
}

echo "Importer idempotent test passed\n";
}
