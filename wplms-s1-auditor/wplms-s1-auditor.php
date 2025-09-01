<?php
/**
 * Plugin Name: WPLMS S1 Auditor
 * Description: Deep audit for WPLMS → checks course 138 visibility, quiz↔course meta links, orphan assignments/units and targeted IDs.
 * Author: Specia1ne
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

class WPLMS_S1_Auditor {
  const SLUG   = 'wplms-s1-auditor';
  const ACTION = 'wplms_s1_auditor';

  public function __construct() {
    add_action('admin_menu', [$this,'menu']);
    add_action('admin_post_' . self::ACTION, [$this,'handle']);
  }

  public function menu() {
    add_management_page('WPLMS S1 Auditor','WPLMS S1 Auditor','manage_options',self::SLUG,[$this,'page']);
  }

  public function page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
      <h1>WPLMS S1 Auditor</h1>
      <p>Checks course #138 visibility, scans quizzes→courses meta, and audits targeted IDs.</p>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field(self::ACTION); ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
        <table class="form-table">
          <tr>
            <th scope="row"><label for="quiz_ids">Quiz IDs</label></th>
            <td><input type="text" id="quiz_ids" name="quiz_ids" class="regular-text" value="9184,132,9182,9533,31321,144,265,264"></td>
          </tr>
          <tr>
            <th scope="row"><label for="assign_ids">Assignment IDs</label></th>
            <td><input type="text" id="assign_ids" name="assign_ids" class="regular-text" value="65855,64677,66538"></td>
          </tr>
        </table>
        <p><button class="button button-primary">Run audit</button></p>
      </form>
      <?php if (isset($_GET['result'])): ?>
        <h2>Result</h2>
        <textarea style="width:100%;height:360px;"><?php echo esc_textarea(base64_decode($_GET['result'])); ?></textarea>
        <p><a class="button" download="wplms_audit_<?php echo esc_attr(date('Ymd_His')); ?>.json"
              href="data:application/json;base64,<?php echo esc_attr($_GET['result']); ?>">Download JSON</a></p>
      <?php endif; ?>
    </div>
    <?php
  }

  private function ids_from($raw) {
    return array_values(array_unique(array_filter(array_map('intval', preg_split('/[,\s]+/', (string)$raw)))));
  }

  public function handle() {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('wplms_s1_auditor');

    $quiz_ids   = isset($_POST['quiz_ids'])   ? $this->ids_from($_POST['quiz_ids'])   : [];
    $assign_ids = isset($_POST['assign_ids']) ? $this->ids_from($_POST['assign_ids']) : [];

    $now   = current_time('mysql');
    $out   = [
      'generated_at' => $now,
      'env'          => [
        'site_url' => site_url(),
        'home_url' => home_url(),
        'php'      => PHP_VERSION,
        'wp'       => get_bloginfo('version'),
      ],
      'course_138_visibility' => $this->probe_course_138(),
      'quiz_meta_links'       => $this->scan_quiz_meta_links(),
      'targeted'              => $this->targeted_block($quiz_ids, $assign_ids),
      'orphans'               => $this->orphans_snapshot(),
    ];

    $b64 = base64_encode(wp_json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    wp_safe_redirect(add_query_arg(['page'=>self::SLUG,'result'=>$b64], admin_url('tools.php')));
    exit;
  }

  private function probe_course_138() {
    $id = 138;
    $res = [
      'get_post' => null,
      'get_posts_any' => null,
      'wp_query_selected' => null,
      'post_type' => null,
      'post_status' => null,
      'exists' => false,
    ];

    // 1) get_post
    $p = get_post($id);
    if ($p) {
      $res['get_post']   = ['ID'=>$p->ID, 'post_type'=>$p->post_type, 'post_status'=>$p->post_status, 'post_title'=>$p->post_title];
      $res['post_type']  = $p->post_type;
      $res['post_status']= $p->post_status;
      $res['exists']     = true;
    }

    // 2) get_posts (status any + suppress_filters)
    $arr = get_posts([
      'post_type'        => 'course',
      'post__in'         => [$id],
      'post_status'      => ['publish','draft','pending','private','future','inherit','trash','auto-draft'],
      'orderby'          => 'post__in',
      'posts_per_page'   => -1,
      'suppress_filters' => true,
    ]);
    $res['get_posts_any'] = array_map(function($pp){ return ['ID'=>$pp->ID,'post_status'=>$pp->post_status]; }, $arr);

    // 3) WP_Query (selected-mode args, як у нашому експортері)
    $q = new WP_Query([
      'post_type'        => 'course',
      'post__in'         => [$id],
      'post_status'      => ['publish','draft','pending','private'],
      'orderby'          => 'post__in',
      'posts_per_page'   => -1,
      'suppress_filters' => true,
      'no_found_rows'    => true,
    ]);
    $res['wp_query_selected'] = array_map(function($pp){ return ['ID'=>$pp->ID,'post_status'=>$pp->post_status]; }, $q->posts);

    return $res;
  }

  private function scan_quiz_meta_links() {
      $out  = [];
      $keys = ['vibe_quiz_course','quiz_course','course_id'];

      $qids = get_posts([
          'post_type'        => 'quiz',
          'post_status'      => ['publish','draft','pending','private','trash'],
          'numberposts'      => -1,
          'fields'           => 'ids',
          'suppress_filters' => true,
      ]);

      foreach ($qids as $qid) {
          $links = [];

          foreach ($keys as $k) {
              $v = get_post_meta($qid, $k, true);

              // Нормалізація у масив цілих
              $ids = [];
              if (is_numeric($v)) {
                  $ids[] = (int)$v;

              } elseif (is_string($v) && $v !== '') {
                  // 1) Спроба unserialize()
                  $maybe = @unserialize($v);
                  if (is_array($maybe)) {
                      foreach ($maybe as $itm) {
                          if (is_numeric($itm)) $ids[] = (int)$itm;
                      }
                  } else {
                      // 2) JSON масив?
                      $maybeJson = json_decode($v, true);
                      if (is_array($maybeJson)) {
                          foreach ($maybeJson as $itm) {
                              if (is_numeric($itm)) $ids[] = (int)$itm;
                          }
                      } else {
                          // 3) Кома-відділений рядок
                          $parts = preg_split('/[,\s]+/', $v);
                          foreach ($parts as $itm) {
                              if (is_numeric($itm)) $ids[] = (int)$itm;
                          }
                          // якщо нічого не знайшли — позначимо тип значення для прозорості
                          if (empty($ids)) {
                              $ids = ['non-numeric-string'];
                          }
                      }
                  }

              } elseif (is_array($v)) {
                  foreach ($v as $itm) {
                      if (is_numeric($itm)) $ids[] = (int)$itm;
                  }
              }

              // Унікалізація
              if (!empty($ids)) {
                  // збережемо тільки валідні значення + маркер, якщо він є
                  $numericIds = array_values(array_filter($ids, 'is_int'));
                  $links[$k] = !empty($numericIds) ? array_values(array_unique($numericIds)) : $ids; // може містити 'non-numeric-string'
              }
          }

          // Безпечне злиття усіх знайдених course_id
          $all = [];
          foreach ($links as $arr) {
              foreach ((array)$arr as $val) {
                  if (is_int($val)) $all[] = $val;
              }
          }
          $all = array_values(array_unique($all));

          $out[$qid] = [
              'quiz_post_exists'       => (get_post_type($qid) === 'quiz'),
              'links'                  => $links,
              'resolves_to_course_138' => in_array(138, $all, true),
          ];
      }

      return $out;
  }


  private function targeted_block(array $quiz_ids, array $assign_ids) {
    $out = [
      'quizzes' => [
        'input_ids' => $quiz_ids,
        'found'     => [],
        'not_found' => [],
      ],
      'assignments' => [
        'input_ids' => $assign_ids,
        'found'     => [],
        'not_found' => [],
      ],
    ];

    // quizzes
    foreach ($quiz_ids as $qid) {
      $exists = (get_post_type($qid)==='quiz');
      $row = [
        'in_course_curriculum'   => [], // WPLMS зазвичай ні — але лишимо поле для повної сумісності
        'in_unit_meta'           => [],
        'in_quiz_meta_course'    => [],
        'quiz_post_exists'       => $exists,
      ];

      if ($exists) {
        // зчитаємо мета-зв’язки курсів
        $keys = ['vibe_quiz_course','quiz_course','course_id'];
        foreach ($keys as $k) {
          $v = get_post_meta($qid, $k, true);
          if (is_numeric($v)) $row['in_quiz_meta_course'][] = (int)$v;
          elseif (is_string($v)) {
            $maybe = @unserialize($v);
            if (is_array($maybe)) $row['in_quiz_meta_course'] = array_values(array_unique(array_merge($row['in_quiz_meta_course'], array_map('intval',$maybe))));
          } elseif (is_array($v)) {
            $row['in_quiz_meta_course'] = array_values(array_unique(array_merge($row['in_quiz_meta_course'], array_map('intval',$v))));
          }
        }
        $out['quizzes']['found'][$qid] = $row;
      } else {
        $out['quizzes']['not_found'][] = ['quiz_id'=>$qid, 'quiz_post_exists'=>false];
      }
    }

    // assignments
    foreach ($assign_ids as $aid) {
      $exists  = (get_post_type($aid)==='wplms-assignment');
      $used_in = [];

      if ($exists) {
        // пошук юнітів, де під’єднаний assignment
        $units = get_posts([
          'post_type'        => 'unit',
          'post_status'      => ['publish','draft','pending','private'],
          'numberposts'      => -1,
          'fields'           => 'ids',
          'suppress_filters' => true,
          'meta_query'       => [
            'relation' => 'OR',
            ['key'=>'vibe_assignment','value'=>$aid,'compare'=>'='],
            ['key'=>'assignment','value'=>$aid,'compare'=>'='],
            ['key'=>'vibe_unit_assignment','value'=>$aid,'compare'=>'='],
          ],
        ]);
        $used_in = array_values(array_map('intval',$units));
        $out['assignments']['found'][$aid] = [
          'assignment_post_exists' => true,
          'used_in_units' => $used_in,
          'via_courses'   => [], // якщо треба — можемо додати зворотну карту unit→course
        ];
      } else {
        $out['assignments']['not_found'][] = ['assignment_id'=>$aid, 'assignment_post_exists'=>false];
      }
    }

    return $out;
  }

  private function orphans_snapshot() {
    // Юніти з підключеним assignment, які не входять у жоден курс
    $map = $this->build_unit_to_courses_map();
    $units = get_posts([
      'post_type'=>'unit',
      'post_status'=>['publish','draft','pending','private'],
      'numberposts'=>-1,
      'suppress_filters'=>true,
    ]);

    $out_units = [];
    $out_assign = [];

    foreach ($units as $u) {
      $ass = $this->extract_assignments_from_unit($u->ID);
      if ($ass && empty($map[$u->ID])) {
        $out_units[] = [
          'old_id' => (int)$u->ID,
          'post'   => [ 'post_title'=>$u->post_title, 'status'=>$u->post_status ],
          'assignments' => array_values(array_unique($ass)),
        ];
        foreach ($ass as $aid) $out_assign[$aid] = true;
      }
    }

    // Асайнменти, що ніде не використовуються
    $assign_posts = get_posts([
      'post_type'=>'wplms-assignment',
      'post_status'=>['publish','draft','pending','private'],
      'numberposts'=>-1,
      'suppress_filters'=>true,
    ]);

    $orph_assign = [];
    foreach ($assign_posts as $ap) {
      if (!isset($out_assign[$ap->ID])) {
        $orph_assign[] = [
          'old_id' => (int)$ap->ID,
          'post'   => [ 'post_title'=>$ap->post_title, 'status'=>$ap->post_status ],
        ];
      }
    }

    return ['units'=>$out_units, 'assignments'=>$orph_assign];
  }

  // ===== helpers from exporter logic (локальні спрощені) =====
  private function build_unit_to_courses_map() {
    $map = [];
    $courses = get_posts([
      'post_type'=>'course',
      'post_status'=>['publish','draft','pending','private'],
      'numberposts'=>-1,
      'fields'=>'ids',
      'suppress_filters'=>true,
    ]);
    foreach ($courses as $cid) {
      $raw = get_post_meta($cid, 'vibe_course_curriculum', true);
      $arr = $this->unserialize_curriculum($raw);
      foreach ($arr as $item) {
        if (is_numeric($item)) {
          $pid = (int)$item;
          if (get_post_type($pid)==='unit') {
            if (!isset($map[$pid])) $map[$pid]=[];
            $map[$pid][] = (int)$cid;
          }
        }
      }
    }
    return $map;
  }

  private function extract_assignments_from_unit($unit_id) {
    $meta = get_post_meta($unit_id);
    $keys = ['vibe_assignment','assignment','vibe_unit_assignment'];
    $ids = [];
    foreach ($keys as $k) if (isset($meta[$k])) {
      $val = is_array($meta[$k]) ? reset($meta[$k]) : $meta[$k];
      if (is_numeric($val)) $ids[] = (int)$val;
      elseif (is_string($val)) {
        $maybe = @unserialize($val);
        if (is_array($maybe)) foreach ($maybe as $p) if (is_numeric($p)) $ids[] = (int)$p;
      }
    }
    return array_values(array_unique($ids));
  }

  private function unserialize_curriculum($val) {
    if (is_array($val)) return $val;
    if (is_string($val)) {
      $maybe = @unserialize($val);
      if (is_array($maybe)) return $maybe;
    }
    return [];
  }
}

new WPLMS_S1_Auditor();