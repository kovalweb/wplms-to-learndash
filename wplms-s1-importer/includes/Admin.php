<?php
namespace WPLMS_S1I;
use function esc_html;
class Admin {
		private $page_slug = 'wplms-s1-importer';

                public function hooks() {
                        \add_action( 'admin_menu', [ $this, 'menu' ] );
                        \add_action( 'admin_post_wplms_s1i_run', [ $this, 'handle_import' ] );
                        \add_action( 'admin_post_wplms_s1i_reset', [ $this, 'handle_reset' ] );
                        \add_action( 'admin_post_wplms_s1i_orphans_csv', [ $this, 'handle_orphans_csv' ] );
                }

		public function menu() {
			\add_submenu_page(
				'tools.php',
                                'WPLMS → LearnDash Importer',
                                'WPLMS → LearnDash Importer',
				'manage_options',
				$this->page_slug,
				[ $this, 'render' ]
			);
		}

                public function render() {
                        if ( ! \current_user_can( 'manage_options' ) ) return;

                        $report = null;
                        if ( isset( $_GET['report'] ) ) {
                                $key    = preg_replace( '/[^a-z0-9-]/i', '', (string) $_GET['report'] );
                                $report = \get_transient( 'wplms_s1i_last_report_' . $key );
                        }

                        $idmap        = new IdMap();
                        $stats_option = \get_option( \WPLMS_S1I_OPT_RUNSTATS, [] );
                        $en_pool      = \get_option( \WPLMS_S1I_OPT_ENROLL_POOL, [] );
                        $stats        = ( $report && ! empty( $report['dry'] ) ) ? (array) array_get( $report, 'stats', [] ) : $stats_option;
                        ?>
                        <div class="wrap">
                                <h1>WPLMS → LearnDash Importer</h1>
                                <p>Version <?php echo esc_html( \WPLMS_S1I_VER ); ?>. Use this to import the JSON created by WPLMS S1 Exporter.</p>

                                <?php $tax_pf = (array) array_get( $stats, 'tax_preflight', [] ); ?>
                                <?php if ( $tax_pf ) : ?>
                                        <h2 class="title">Taxonomy preflight</h2>
                                        <ul>
                                                <li>ld_course_category: <?php echo esc_html( array_get( $tax_pf, 'ld_course_category', 'n/a' ) ); ?></li>
                                                <li>ld_course_tag: <?php echo esc_html( array_get( $tax_pf, 'ld_course_tag', 'n/a' ) ); ?></li>
                                                <li>permalink_base: <?php echo esc_html( array_get( $tax_pf, 'permalink_base', 'n/a' ) ); ?></li>
                                                <li>permalink_sample_url: <?php echo esc_html( array_get( $tax_pf, 'permalink_sample_url', 'n/a' ) ); ?></li>
                                        </ul>
                                        <?php if ( array_get( $tax_pf, 'permalink_base' ) !== 'course-cat' ) : ?>
                                                <p><em>Set LearnDash course category base to "course-cat" for SEO friendly URLs.</em></p>
                                        <?php endif; ?>
                                <?php endif; ?>

                                <?php $cl_pf = (array) array_get( $stats, 'commerce_linking_preflight', [] ); ?>
                                <?php if ( $cl_pf ) : ?>
                                        <h2 class="title">Commerce linking preflight</h2>
                                        <ul>
                                                <li>WooCommerce for LearnDash: <?php echo esc_html( array_get( $cl_pf, 'woocommerce_for_learndash', '' ) ); ?></li>
                                                <li>Total WooCommerce products: <?php echo esc_html( array_get( $cl_pf, 'products_total', 0 ) ); ?></li>
                                                <li>Courses in payload: <?php echo esc_html( array_get( $cl_pf, 'courses_in_payload', 0 ) ); ?></li>
                                                <li>Courses with product SKU: <?php echo esc_html( array_get( $cl_pf, 'courses_with_product_sku', 0 ) ); ?></li>
                                                <li>Courses with certificate data: <?php echo esc_html( array_get( $cl_pf, 'courses_with_certificate', 0 ) ); ?></li>
                                                <li>Sample product SKU: <?php echo esc_html( array_get( $cl_pf, 'sample_product_sku', '' ) ); ?></li>
                                                <?php $missing = (array) array_get( $cl_pf, 'missing_course_refs', [] ); ?>
                                                <?php if ( $missing ) : ?>
                                                        <li>Missing course references: <?php echo esc_html( implode( ', ', $missing ) ); ?></li>
                                                <?php endif; ?>
                                        </ul>
                                <?php endif; ?>

                                <?php if ( array_get( $stats, 'courses_linked_to_products' ) || array_get( $stats, 'product_not_found_for_course' ) || array_get( $stats, 'certificates_attached' ) || array_get( $stats, 'certificates_missing' ) || array_get( $stats, 'certificates_already_attached' ) ) : ?>
                                        <h2 class="title">Commerce linking stats</h2>
                                        <ul>
                                                <li>Courses linked to products: <?php echo esc_html( array_get( $stats, 'courses_linked_to_products', 0 ) ); ?></li>
                                                <li>Courses with missing product: <?php echo esc_html( array_get( $stats, 'product_not_found_for_course', 0 ) ); ?></li>
                                                <li>Linked publish: <?php echo esc_html( array_get( $stats, 'linked_publish', 0 ) ); ?></li>
                                                <li>Linked draft: <?php echo esc_html( array_get( $stats, 'linked_draft', 0 ) ); ?></li>
                                                <li>Certificates attached: <?php echo esc_html( array_get( $stats, 'certificates_attached', 0 ) ); ?></li>
                                                <li>Certificates missing: <?php echo esc_html( array_get( $stats, 'certificates_missing', 0 ) ); ?></li>
                                                <li>Certificates already attached: <?php echo esc_html( array_get( $stats, 'certificates_already_attached', 0 ) ); ?></li>
                                        </ul>
                                <?php endif; ?>


                                <?php if ( $report ) : ?>
                                        <h2 class="title">Run Report <?php echo $report['dry'] ? '<span style="font-size:0.8em;background:#ddd;padding:2px 6px;border-radius:3px;">Dry run</span>' : '<span style="font-size:0.8em;background:#ddd;padding:2px 6px;border-radius:3px;">Real run</span>'; ?></h2>
                                        <?php if ( ! empty( $report['error'] ) ) : ?>
                                                <div class="notice notice-error"><p><?php echo esc_html( $report['error'] ); ?></p></div>
                                        <?php endif; ?>
                                        <pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px"><?php echo esc_html( print_r( array_get( $report, 'stats', [] ), true ) ); ?></pre>
                                        <p>Log file: <code><?php echo esc_html( array_get( $report, 'log', '' ) ); ?></code></p>
                                <?php endif; ?>

                                <h2 class="title">Run Import</h2>
                                <form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                                        <?php \wp_nonce_field( 'wplms_s1i_run' ); ?>
                                        <input type="hidden" name="action" value="wplms_s1i_run" />
                                        <table class="form-table" role="presentation">
                                                <tr>
                                                        <th scope="row"><label for="wplms_s1i_file">JSON file only</label></th>
                                                        <td><input type="file" name="wplms_s1i_file" id="wplms_s1i_file" accept=".json" required /></td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="wplms_s1i_dry">Dry run</label></th>
                                                        <td><label><input type="checkbox" name="dry" id="wplms_s1i_dry" value="1" /> Analyze only (no content will be created)</label></td>
                                                </tr>
                                                <tr>
                                                        <th scope="row"><label for="wplms_s1i_recheck">Recheck products</label></th>
                                                        <td><label><input type="checkbox" name="recheck" id="wplms_s1i_recheck" value="1" /> Verify product status & visibility</label></td>
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


                                <form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;">
                                        <?php \wp_nonce_field( 'wplms_s1i_orphans_csv' ); ?>
                                        <input type="hidden" name="action" value="wplms_s1i_orphans_csv" />
                                        <?php \submit_button( 'Export Orphans CSV', 'secondary' ); ?>
                                </form>


                                <h2 class="title">ID Map (summary)<?php echo ( $report && ! empty( $report['dry'] ) ) ? ' <small>(Dry run doesn\'t update ID Map)</small>' : ''; ?></h2>
                                <pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px"><?php echo esc_html( print_r( $idmap->get_all(), true ) ); ?></pre>

                                <h2 class="title">Last Run Stats</h2>
                                <pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px"><?php echo esc_html( print_r( $stats, true ) ); ?></pre>

                                <h2 class="title">Enrollments Pool (deferred)</h2>
                                <p>Stored per original course ID for later user mapping.</p>
                                <pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px"><?php echo esc_html( print_r( [ 'courses'=> count( $en_pool ) ], true ) ); ?></pre>

                        </div>
                        <?php
                }

		public function handle_import() {
			if ( ! \current_user_can( 'manage_options' ) ) \wp_die( 'Unauthorized' );
			\check_admin_referer( 'wplms_s1i_run' );

                        $dry = isset( $_POST['dry'] ) && $_POST['dry'] == '1';
                        $recheck = isset( $_POST['recheck'] ) && $_POST['recheck'] == '1';

			// handle upload
			if ( empty( $_FILES['wplms_s1i_file'] ) || empty( $_FILES['wplms_s1i_file']['tmp_name'] ) || empty( $_FILES['wplms_s1i_file']['size'] ) ) {
				\wp_die( 'No file uploaded or file is empty' );
			}
			if ( ! \is_uploaded_file( $_FILES['wplms_s1i_file']['tmp_name'] ) ) {
				\wp_die( 'Upload error: not a valid uploaded file.' );
			}
			
			$name = (string) $_FILES['wplms_s1i_file']['name'];
			$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( 'json' !== $ext ) {
				\wp_die( 'Invalid file type. JSON required.' );
			}
			
			$size = (int) $_FILES['wplms_s1i_file']['size'];
			if ( $size > 50 * 1024 * 1024 ) {
				\wp_die( 'File too large. Maximum size is 50MB.' );
			}
			
			$raw = \file_get_contents( $_FILES['wplms_s1i_file']['tmp_name'] );
			if ( false === $raw || '' === trim( $raw ) ) {
				\wp_die( 'Uploaded file is empty or unreadable.' );
			}
			
			$data = \json_decode( $raw, true );
			if ( \json_last_error() !== JSON_ERROR_NONE ) {
				\wp_die( 'Invalid JSON: ' . esc_html( \json_last_error_msg() ) );
			}

                        $logger   = new Logger();
                        $idmap    = new IdMap();
                        $importer = new Importer( $logger, $idmap );
                        $importer->set_dry_run( $dry );
                        $importer->set_recheck( $recheck );

                        $report = [
                                'dry'   => $dry,
                                'stats' => [],
                                'log'   => '',
                        ];

                        try {
                                $stats          = $importer->run( $data );
                                $report['stats'] = $stats;
                                $report['log']   = $logger->path();
                        } catch ( \Throwable $e ) {
                                $report['error'] = $e->getMessage();
                                $report['log']   = $logger->path();
                                $logger->write( 'import failed: ' . $e->getMessage(), [ 'trace' => $e->getTraceAsString() ] );
                        }

                        $key = \wp_generate_uuid4();
                        \set_transient( 'wplms_s1i_last_report_' . $key, $report, 15 * \MINUTE_IN_SECONDS );
                        \wp_safe_redirect( \add_query_arg( [ 'page' => $this->page_slug, 'report' => $key ], \admin_url( 'tools.php' ) ) );
                        exit;
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

                private function build_unit_course_map() {
                        $map = [];
                        $courses = \get_posts( [
                                'post_type'      => 'sfwd-courses',
                                'post_status'    => 'any',
                                'posts_per_page' => -1,
                                'fields'         => 'ids',
                        ] );
                        foreach ( $courses as $cid ) {
                                $old_course = (int) \get_post_meta( $cid, '_wplms_old_id', true );
                                $raw        = \get_post_meta( $cid, '_wplms_s1_curriculum_raw', true );
                                if ( is_array( $raw ) ) {
                                        foreach ( $raw as $item ) {
                                                if ( isset( $item['kind'] ) && $item['kind'] === 'id' ) {
                                                        $map[ (int) $item['value'] ] = [ 'course_new_id' => $cid, 'course_old_id' => $old_course ];
                                                }
                                        }
                                }
                        }
                        return $map;
                }

                public function handle_orphans_csv() {
                        if ( ! \current_user_can( 'manage_options' ) ) \wp_die( 'Unauthorized' );
                        \check_admin_referer( 'wplms_s1i_orphans_csv' );

                        $idmap    = new IdMap();
                        $unit_map = $this->build_unit_course_map();
                        $rows     = [];

                        $lessons = \get_posts( [ 'post_type' => 'sfwd-lessons', 'meta_key' => '_wplms_orphan', 'meta_value' => 1, 'posts_per_page' => -1, 'fields' => 'ids' ] );
                        foreach ( $lessons as $lid ) {
                                $old = (int) \get_post_meta( $lid, '_wplms_old_id', true );
                                $parent_old = isset( $unit_map[ $old ] ) ? $unit_map[ $old ]['course_old_id'] : '';
                                $rows[] = [ 'unit', $old, \get_the_title( $lid ), $parent_old ];
                        }

                        $quizzes = \get_posts( [ 'post_type' => 'sfwd-quiz', 'meta_key' => '_wplms_orphan', 'meta_value' => 1, 'posts_per_page' => -1, 'fields' => 'ids' ] );
                        foreach ( $quizzes as $qid ) {
                                $old = (int) \get_post_meta( $qid, '_wplms_old_id', true );
                                $links = (array) \get_post_meta( $qid, '_wplms_s1_links', true );
                                $parent_old = '';
                                foreach ( $links as $vals ) {
                                        foreach ( (array) $vals as $cid ) if ( is_numeric( $cid ) ) { $parent_old = (int) $cid; break 2; }
                                }
                                $rows[] = [ 'quiz', $old, \get_the_title( $qid ), $parent_old ];
                        }

                        $assigns = \get_posts( [ 'post_type' => 'sfwd-assignment', 'meta_key' => '_wplms_orphan', 'meta_value' => 1, 'posts_per_page' => -1, 'fields' => 'ids' ] );
                        foreach ( $assigns as $aid ) {
                                $old = (int) \get_post_meta( $aid, '_wplms_old_id', true );
                                $links = (array) \get_post_meta( $aid, '_wplms_s1_links', true );
                                $parent_old = '';
                                if ( ! empty( $links['unit'] ) ) {
                                        $parent_old = is_array( $links['unit'] ) ? reset( $links['unit'] ) : $links['unit'];
                                } elseif ( ! empty( $links['course'] ) ) {
                                        $parent_old = is_array( $links['course'] ) ? reset( $links['course'] ) : $links['course'];
                                }
                                $rows[] = [ 'assignment', $old, \get_the_title( $aid ), $parent_old ];
                        }

                        header( 'Content-Type: text/csv; charset=utf-8' );
                        header( 'Content-Disposition: attachment; filename="wplms_orphans.csv"' );
                        $out = fopen( 'php://output', 'w' );
                        fputcsv( $out, [ 'type', 'old_id', 'title', 'suggested_parent_old_id_or_slug' ] );
                        foreach ( $rows as $r ) { fputcsv( $out, $r ); }
                        fclose( $out );
                        exit;
                }
        }
