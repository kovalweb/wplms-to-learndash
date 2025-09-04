<?php
namespace WPLMS_S1I;
class Admin {
		private $page_slug = 'wplms-s1-importer';

                public function hooks() {
                        \add_action( 'admin_menu', [ $this, 'menu' ] );
                        \add_action( 'admin_post_wplms_s1i_run', [ $this, 'handle_import' ] );
                        \add_action( 'admin_post_wplms_s1i_reset', [ $this, 'handle_reset' ] );
                        \add_action( 'admin_post_wplms_s1i_repair_proquiz', [ $this, 'handle_repair_proquiz' ] );
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

                        $report = null;
                        if ( isset( $_GET['report'] ) ) {
                                $key    = preg_replace( '/[^a-z0-9-]/i', '', (string) $_GET['report'] );
                                $report = \get_transient( 'wplms_s1i_last_report_' . $key );
                        }

                        $repair_summary = \get_transient( 'wplms_s1i_repair_summary' );
                        if ( $repair_summary ) {
                                \delete_transient( 'wplms_s1i_repair_summary' );
                        }

                        $idmap        = new IdMap();
                        $stats_option = \get_option( \WPLMS_S1I_OPT_RUNSTATS, [] );
                        $en_pool      = \get_option( \WPLMS_S1I_OPT_ENROLL_POOL, [] );
                        $stats        = ( $report && ! empty( $report['dry'] ) ) ? (array) array_get( $report, 'stats', [] ) : $stats_option;
                        ?>
                        <div class="wrap">
                                <h1>WPLMS → LearnDash Importer (PoC)</h1>
                                <p>Version <?php echo \esc_html( \WPLMS_S1I_VER ); ?>. Use this to import the JSON created by WPLMS S1 Exporter.</p>

                                <?php if ( $repair_summary ) : ?>
                                        <div class="notice notice-success"><p><?php echo \esc_html( 'ProQuiz repair fixed ' . array_get( $repair_summary, 'fixed', 0 ) . ( array_get( $repair_summary, 'ids' ) ? ': ' . implode( ', ', array_map( 'intval', (array) array_get( $repair_summary, 'ids', [] ) ) ) : '' ) ); ?></p></div>
                                <?php endif; ?>

                                <?php if ( $report ) : ?>
                                        <h2 class="title">Run Report <?php echo $report['dry'] ? '<span style="font-size:0.8em;background:#ddd;padding:2px 6px;border-radius:3px;">Dry run</span>' : '<span style="font-size:0.8em;background:#ddd;padding:2px 6px;border-radius:3px;">Real run</span>'; ?></h2>
                                        <?php if ( ! empty( $report['error'] ) ) : ?>
                                                <div class="notice notice-error"><p><?php echo \esc_html( $report['error'] ); ?></p></div>
                                        <?php endif; ?>
                                        <pre style="max-height:300px;overflow:auto;background:#fff;border:1px solid #ddd;padding:10px"><?php echo \esc_html( print_r( array_get( $report, 'stats', [] ), true ) ); ?></pre>
                                        <p>Log file: <code><?php echo \esc_html( array_get( $report, 'log', '' ) ); ?></code></p>
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
                                        <?php \wp_nonce_field( 'wplms_s1i_repair_proquiz' ); ?>
                                        <input type="hidden" name="action" value="wplms_s1i_repair_proquiz" />
                                        <?php \submit_button( 'Repair ProQuiz Links', 'secondary' ); ?>
                                </form>

                                <h2 class="title">ID Map (summary)<?php echo ( $report && ! empty( $report['dry'] ) ) ? ' <small>(Dry run doesn\'t update ID Map)</small>' : ''; ?></h2>
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
				\wp_die( 'Invalid JSON: ' . \esc_html( \json_last_error_msg() ) );
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

                public function handle_repair_proquiz() {
                        if ( ! \current_user_can( 'manage_options' ) ) \wp_die( 'Unauthorized' );
                        \check_admin_referer( 'wplms_s1i_repair_proquiz' );

                        $fixed = [];
                        global $wpdb;
                        $table = $wpdb->prefix . 'wp_pro_quiz_master';

                        $quizzes = \get_posts( [
                                'post_type'      => 'sfwd-quiz',
                                'post_status'    => 'any',
                                'posts_per_page' => -1,
                                'fields'         => 'ids',
                        ] );

                        foreach ( $quizzes as $qid ) {
                                $existing = function_exists( '\learndash_get_setting' ) ? (int) \learndash_get_setting( $qid, 'ld_pro_quiz' ) : (int) \get_post_meta( $qid, 'ld_pro_quiz', true );
                                if ( $existing > 0 ) {
                                        $row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $existing ) );
                                        if ( $row > 0 ) {
                                                continue; // Already linked
                                        }
                                }

                                $title  = \get_the_title( $qid );
                                $master = create_proquiz_master( $qid, [ 'name' => $title, 'title' => $title ] );
                                if ( $master ) {
                                        \update_post_meta( $qid, 'quiz_pro', $master );
                                        \update_post_meta( $qid, 'quiz_pro_id', $master );
                                        if ( function_exists( '\learndash_update_setting' ) ) {
                                                \learndash_update_setting( $qid, 'ld_pro_quiz', $master );
                                        } else {
                                                \update_post_meta( $qid, 'ld_pro_quiz', $master );
                                        }
                                        $fixed[] = $qid;
                                }
                        }

                        \set_transient( 'wplms_s1i_repair_summary', [ 'fixed' => count( $fixed ), 'ids' => $fixed ], 60 );
                        \wp_safe_redirect( \add_query_arg( [ 'page' => $this->page_slug, 'repair' => 1 ], \admin_url( 'tools.php' ) ) );
                        exit;
                }
        }
